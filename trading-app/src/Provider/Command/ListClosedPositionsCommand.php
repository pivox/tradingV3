<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPrivate;
use App\Provider\Repository\ContractRepository;
use App\Repository\OrderIntentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'provider:list-closed-positions',
    description: 'Affiche l’historique des ordres BitMart (vue proche mobile/web) et un winrate approximatif'
)]
final class ListClosedPositionsCommand extends Command
{
    public function __construct(
        private readonly ?AccountProviderInterface $accountProvider,
        private readonly ?OrderProviderInterface $orderProvider,
        private readonly ContractRepository $contractRepository,
        private readonly ?OrderIntentRepository $orderIntentRepository = null,
        private readonly ?BitmartHttpClientPrivate $bitmartClient = null,
        private readonly ?LoggerInterface $logger = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_OPTIONAL, 'Nombre d\'heures en arrière pour filtrer les ordres', '24')
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Filtrer par symbole(s) (ex: BTCUSDT,ETHUSDT). Si non spécifié, utilise les symboles des positions ouvertes ou des contrats actifs.')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (table|json)', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limite de résultats par symbole (max BitMart: 200)', '100')
            ->setHelp('
Affiche l’historique des ordres BitMart pour une période donnée (proche de la vue mobile / web) :

Colonnes :
- Heure
- Symbole
- Type (Limite, Marché, Déclencheur, ...)
- Direction (Longue 5x, Courte 8x, Clôturer la position longue/courte, ...)
- Prix moyen / Prix ordre
- Qté transaction / Qté ordre
- Profits et pertes
- Déclencheur (prix SL/TP)
- Statut (Terminé, Annulé, Créé, ...)

Si aucun --symbol n’est fourni, la commande calcule en plus un winrate approximatif
en reconstruisant les trades (open_long/open_short → close_long/close_short).

Exemples:
  # Historique 24h pour les symboles avec positions ouvertes
  php bin/console provider:list-closed-positions

  # Historique 48h pour un symbole
  php bin/console provider:list-closed-positions --hours=48 --symbol=BTCUSDT

  # Historique pour plusieurs symboles
  php bin/console provider:list-closed-positions --hours=24 --symbol=BTCUSDT,ETHUSDT,ADAUSDT

  # Format JSON
  php bin/console provider:list-closed-positions --format=json
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $hours  = (int) $input->getOption('hours');
        $symbol = $input->getOption('symbol');
        $format = $input->getOption('format');
        $limit  = (int) $input->getOption('limit');

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error("Format invalide. Utilisez 'table' ou 'json'.");
            return Command::FAILURE;
        }

        if ($hours <= 0) {
            $io->error('Le nombre d\'heures doit être positif.');
            return Command::FAILURE;
        }

        if (!$this->orderProvider && !$this->bitmartClient) {
            $io->error('Aucun provider d’ordres disponible.');
            return Command::FAILURE;
        }

        // timestamp de début (UTC)
        $sinceDateTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d hours', $hours));
        $sinceTimestamp = $sinceDateTime->getTimestamp();

        // 1. Déterminer les symboles à traiter
        $symbols = [];
        if ($symbol) {
            $symbols = array_map(
                static fn (string $s) => strtoupper(trim($s)),
                explode(',', $symbol)
            );
            $symbols = array_filter($symbols, static fn (string $s) => $s !== '');
        } else {
            // sinon on se base en priorité sur les ordres réellement envoyés (order_intent),
            // puis en fallback sur les positions ouvertes / contrats actifs
            if ($this->orderIntentRepository) {
                try {
                    $symbols = $this->orderIntentRepository->findDistinctSymbolsSince($sinceDateTime, 50);
                } catch (\Throwable $e) {
                    $this->logger?->warning('Impossible de récupérer les symboles depuis order_intent', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (empty($symbols) && $this->accountProvider) {
                try {
                    $openPositions = $this->accountProvider->getOpenPositions();
                    $symbols       = array_unique(array_map(static fn ($p) => $p->symbol, $openPositions));
                } catch (\Throwable $e) {
                    $this->logger?->warning('Impossible de récupérer les positions ouvertes', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (empty($symbols)) {
                $contracts = $this->contractRepository->findActiveContracts();
                $symbols   = array_map(static fn ($c) => $c->getSymbol(), $contracts);
            }

            if (empty($symbols)) {
                $io->warning('Aucun symbole trouvé. Utilise --symbol pour en fournir un.');
                return Command::SUCCESS;
            }

            $maxSymbols = 20;
            if (count($symbols) > $maxSymbols) {
                $io->warning(sprintf(
                    'Trop de symboles (%d). Limitation à %d symboles. Utilise --symbol pour cibler.',
                    count($symbols),
                    $maxSymbols
                ));
                $symbols = array_slice($symbols, 0, $maxSymbols);
            } else {
                $io->note(sprintf('Traitement de %d symbole(s).', count($symbols)));
            }
        }

        $io->section(sprintf(
            'Récupération de l’historique des ordres depuis %d heure(s)...',
            $hours
        ));

        $allRawOrders = [];

        $progressBar = $io->createProgressBar(count($symbols));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Traitement en cours...');
        $progressBar->start();

        $endTime   = time();
        $startTime = $sinceTimestamp;

        foreach ($symbols as $idx => $sym) {
            $progressBar->setMessage("Symbole $sym");

            try {
                // BitMart limite à 200 ordres
                $apiLimit = min(max($limit, 1), 200);
                $orders   = $this->getOrderHistoryRaw($sym, $apiLimit, $startTime, $endTime);

                foreach ($orders as $order) {
                    $ts = $this->getOrderTimestamp($order);
                    if ($ts === null || $ts < $sinceTimestamp) {
                        continue;
                    }
                    $allRawOrders[] = $order;
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Erreur BitMart order-history', [
                    'symbol' => $sym,
                    'error'  => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
            if ($idx < count($symbols) - 1) {
                // petit sleep pour éviter les rate-limit
                sleep(1);
            }
        }

        $progressBar->setMessage('Terminé');
        $progressBar->finish();
        $io->newLine(2);

        if (empty($allRawOrders)) {
            $io->note('Aucun ordre trouvé dans la période.');
            return Command::SUCCESS;
        }

        // 2. Construction des lignes "vue BitMart"
        $rowsForDisplay = [];
        foreach ($allRawOrders as $order) {
            $rowsForDisplay[] = $this->buildUiRowFromOrder($order);
        }

        // tri décroissant (plus récents en haut, comme l’UI)
        usort(
            $rowsForDisplay,
            static fn (array $a, array $b) => $b['timestamp'] <=> $a['timestamp']
        );

        // 3. Winrate approximatif uniquement si aucun --symbol n’est passé
        if ($symbol === null) {
            $stats = $this->computeWinRateFromOrders($allRawOrders);
            $this->displayWinRateFromOrders($io, $stats);
        }

        // 4. Affichage
        if ($format === 'json') {
            $payload = array_map(
                static fn (array $row) => array_diff_key($row, ['timestamp' => true]),
                $rowsForDisplay
            );
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $this->displayOrdersTable($io, $rowsForDisplay);

        return Command::SUCCESS;
    }

    /**
     * Appelle /contract/private/order-history et retourne les ordres bruts.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getOrderHistoryRaw(string $symbol, int $limit, ?int $startTime = null, ?int $endTime = null): array
    {
        try {
            if ($this->bitmartClient) {
                $response = $this->bitmartClient->getOrderHistory($symbol, $limit, $startTime, $endTime);

                $orders = null;
                if (isset($response['data']['orders']) && is_array($response['data']['orders'])) {
                    $orders = $response['data']['orders'];
                } elseif (isset($response['data']) && is_array($response['data']) && isset($response['data'][0]['order_id'])) {
                    $orders = $response['data'];
                }

                if ($orders !== null) {
                    return $orders;
                }
            }

            if ($this->orderProvider) {
                $orders = $this->orderProvider->getOrderHistory($symbol, $limit);

                return array_map(function ($order) {
                    if ($order instanceof \App\Contract\Provider\Dto\OrderDto) {
                        return $this->orderDtoToArray($order);
                    }

                    return $order;
                }, $orders);
            }

            return [];
        } catch (\Throwable $e) {
            $this->logger?->error('Erreur order-history', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Convertit un OrderDto en tableau brut proche du JSON BitMart.
     */
    private function orderDtoToArray(\App\Contract\Provider\Dto\OrderDto $order): array
    {
        return [
            'order_id'        => $order->orderId,
            'client_order_id' => $order->clientOrderId ?? null,
            'symbol'          => $order->symbol,
            'side'            => $this->extractSideValue($order->side),
            'type'            => $order->type->value ?? null,
            'status'          => $order->status->value ?? null,
            'price'           => $order->price?->toFloat() ?? null,
            'size'            => $order->quantity->toFloat() ?? null,
            'deal_size'       => $order->filledQuantity->toFloat() ?? null,
            'deal_avg_price'  => $order->averagePrice?->toFloat() ?? null,
            'create_time'     => $order->createdAt?->getTimestamp() ?? null,
            'update_time'     => $order->updatedAt?->getTimestamp() ?? null,
            'metadata'        => $order->metadata ?? [],
        ];
    }

    /**
     * Transforme un ordre brut en ligne "vue BitMart".
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function buildUiRowFromOrder(array $order): array
    {
        $symbol    = (string) ($order['symbol'] ?? '');
        $timestamp = $this->getOrderTimestamp($order);
        $timeStr   = $this->formatTimestamp($timestamp);

        $typeLabel      = $this->mapOrderTypeLabel((string) ($order['type'] ?? ''));
        $directionLabel = $this->mapSideToDirectionLabel($order);

        $avgPrice   = $order['deal_avg_price'] ?? null;
        $orderPrice = $order['price'] ?? null;
        $pricePair  = $this->formatPricePair($avgPrice, $orderPrice);

        $size     = $order['size'] ?? $order['quantity'] ?? null;
        $dealSize = $order['deal_size'] ?? $order['filled_quantity'] ?? null;
        $qtyPair  = $this->formatQtyPair($symbol, $dealSize, $size);

        // P&L : pour l’instant on met "--"
        $pnlLabel = '--';

        $triggerPrice = $order['trigger_price'] ?? null;
        $triggerLabel = $triggerPrice !== null && $triggerPrice !== ''
            ? 'Prix ≥/≤ ' . number_format((float) $triggerPrice, 6) . ' USDT'
            : '--';

        $statusLabel = $this->mapOrderStatusLabel($order);

        return [
            'timestamp'          => $timestamp ?? 0,
            'time'               => $timeStr,
            'symbol'             => $symbol,
            'type'               => $typeLabel,
            'direction'          => $directionLabel,
            'price_pair'         => $pricePair,
            'qty_pair'           => $qtyPair,
            'pnl'                => $pnlLabel,
            'trigger'            => $triggerLabel,
            'status'             => $statusLabel,
            'order_id'           => $order['order_id'] ?? null,
            'client_order_id'    => $order['client_order_id'] ?? null,
        ];
    }

    private function mapOrderTypeLabel(string $type): string
    {
        $type = strtolower($type);

        return match ($type) {
            'limit'      => 'Limite',
            'market'     => 'Marché',
            'planorder',
            'trailing'   => 'Déclencheur',
            default      => $type !== '' ? ucfirst($type) : 'Inconnu',
        };
    }

    /**
     * Retourne un libellé lisible pour la colonne "Direction"
     * (Longue 5x, Courte 10x, Clôturer la position longue/courte)
     */
    private function mapSideToDirectionLabel(array $order): string
    {
        $side     = $order['side'] ?? null;
        $leverage = $order['leverage'] ?? ($order['metadata']['leverage'] ?? null);

        if (!is_numeric($side)) {
            $side = $this->extractSideValue($side);
        } else {
            $side = (int) $side;
        }

        $levLabel = null;
        if ($leverage !== null && $leverage !== '') {
            $levLabel = rtrim(rtrim((string) $leverage, '0'), '.');
            $levLabel = sprintf('%sx', $levLabel);
        }

        // Convention BitMart Futures:
        // 1 = open_long, 2 = close_long, 3 = close_short, 4 = open_short
        return match ($side) {
            1       => $levLabel ? sprintf('Longue %s', $levLabel) : 'Longue',
            4       => $levLabel ? sprintf('Courte %s', $levLabel) : 'Courte',
            2       => 'Clôturer la position longue',
            3       => 'Clôturer la position courte',
            default => 'Inconnu',
        };
    }

    /**
     * Statut lisible ("Terminé", "Annulé", "Créé") en combinant state / deal_size / execution.
     */
    private function mapOrderStatusLabel(array $order): string
    {
        $state          = $order['state'] ?? $order['status'] ?? null;
        $type           = strtolower((string) ($order['type'] ?? ''));
        $dealSize       = isset($order['deal_size']) ? (float) $order['deal_size'] : null;
        $size           = isset($order['size']) ? (float) $order['size'] : null;
        $executionPrice = $order['execution_price'] ?? $order['exec_price'] ?? null;
        $hasExecPrice   = $executionPrice !== null && $executionPrice !== '';

        $isPlan = in_array($type, ['planorder', 'trailing'], true)
            || isset($order['trigger_price']);

        // Plan orders (TP/SL)
        if ($isPlan) {
            if (is_numeric($state) && (int) $state === 1) {
                return 'Créé';
            }

            $hasFill = $dealSize !== null && $dealSize > 0;
            if ($hasFill || $hasExecPrice) {
                return 'Terminé'; // déclenché/exécuté
            }

            return 'Annulé';
        }

        // Ordres "normaux"
        if (is_numeric($state)) {
            $stateInt = (int) $state;

            if ($stateInt === 1) {
                return 'Créé';
            }

            if ($stateInt === 5) {
                return 'Annulé';
            }

            // états "finis" (2 = filled, 4 = part-filled, 3 = autre fini)
            if (in_array($stateInt, [2, 3, 4], true)) {
                if ($dealSize !== null && $dealSize > 0) {
                    return 'Terminé';
                }

                // Aucun fill → annulé/expiré
                return 'Annulé';
            }
        } elseif (is_string($state) && $state !== '') {
            $upper = strtoupper($state);

            return match ($upper) {
                'NEW', 'PENDING'               => 'Créé',
                'FILLED', 'PARTIALLY_FILLED'   => 'Terminé',
                'CANCELED', 'CANCELLED'        => 'Annulé',
                default                        => ucfirst(strtolower($state)),
            };
        }

        return 'Inconnu';
    }

    private function formatPricePair(mixed $avg, mixed $orderPrice): string
    {
        $avgStr = ($avg !== null && (float) $avg > 0)
            ? number_format((float) $avg, 6) . ' USDT'
            : '--';

        $orderStr = ($orderPrice !== null && (float) $orderPrice > 0)
            ? number_format((float) $orderPrice, 6) . ' USDT'
            : '--';

        return sprintf('%s / %s', $avgStr, $orderStr);
    }

    private function formatQtyPair(string $symbol, mixed $dealSize, mixed $size): string
    {
        $coin = $this->guessBaseCurrency($symbol);

        $dealStr = ($dealSize !== null && (float) $dealSize > 0)
            ? sprintf('%s %s', rtrim(rtrim(number_format((float) $dealSize, 4, '.', ''), '0'), '.'), $coin)
            : '0 ' . $coin;

        $sizeStr = ($size !== null && (float) $size > 0)
            ? sprintf('%s %s', rtrim(rtrim(number_format((float) $size, 4, '.', ''), '0'), '.'), $coin)
            : '0 ' . $coin;

        return sprintf('%s / %s', $dealStr, $sizeStr);
    }

    private function guessBaseCurrency(string $symbol): string
    {
        return str_ends_with($symbol, 'USDT')
            ? substr($symbol, 0, -4)
            : $symbol;
    }

    /**
     * Retourne le timestamp (sec) d’un ordre BitMart.
     */
    private function getOrderTimestamp(array $order): ?int
    {
        $timestamp = $order['update_time']
            ?? $order['update_time_ms']
            ?? $order['create_time']
            ?? $order['create_time_ms']
            ?? $order['created_at']
            ?? $order['updated_at']
            ?? null;

        if ($timestamp === null) {
            return null;
        }

        if (is_numeric($timestamp) && $timestamp < 1e10) {
            return (int) $timestamp;
        }

        if (is_numeric($timestamp) && $timestamp > 1e10) {
            return (int) ((int) $timestamp / 1000);
        }

        if (is_string($timestamp)) {
            try {
                $dt = new \DateTimeImmutable($timestamp);
                return $dt->getTimestamp();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function formatTimestamp(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Convertit side (string/enum) → entier BitMart.
     */
    private function extractSideValue(mixed $side): ?int
    {
        if (is_int($side) || is_float($side) || ctype_digit((string) $side)) {
            return (int) $side;
        }

        if (is_object($side) && method_exists($side, 'value')) {
            $value = $side->value;
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        if (is_string($side)) {
            $s = strtolower($side);

            return match ($s) {
                'open_long', 'buy', '1'   => 1,
                'close_long', '2'         => 2,
                'close_short', '3'        => 3,
                'open_short', '4'         => 4,
                default                   => null,
            };
        }

        return null;
    }

    /**
     * Affichage “vue BitMart” en tableau.
     *
     * @param array<int,array<string,mixed>> $orders
     */
    private function displayOrdersTable(SymfonyStyle $io, array $orders): void
    {
        $rows = [];

        foreach ($orders as $order) {
            $rows[] = [
                $order['time'] ?? 'N/A',
                $order['symbol'],
                $order['type'],
                $order['direction'],
                $order['price_pair'],
                $order['qty_pair'],
                $order['pnl'],
                $order['trigger'],
                $order['status'],
            ];
        }

        $io->title(sprintf('Historique des ordres (%d ligne(s))', count($rows)));

        $io->table(
            [
                'Heure',
                'Symbole',
                'Type',
                'Direction',
                'Prix moyen / Prix ordre',
                'Qté transaction / Qté ordre',
                'Profits et pertes',
                'Déclencheur',
                'Statut',
            ],
            $rows
        );
    }

    /**
     * Calcule un winrate approximatif à partir de la séquence d’ordres.
     *
     * - On reconstruit les positions par symbole :
     *   1 = open_long, 4 = open_short
     *   2 = close_long, 3 = close_short
     * - On ignore les plan orders (type=planorder/trailing ou trigger_price présent).
     *
     * @param array<int,array<string,mixed>> $ordersRaw
     * @return array{
     *   total:int,
     *   wins:int,
     *   losses:int,
     *   winrate:float|null,
     *   per_symbol:array<string,array{total:int,wins:int,losses:int,winrate:float|null}>
     * }
     */
    private function computeWinRateFromOrders(array $ordersRaw): array
    {
        $summary = [
            'total' => 0,
            'wins' => 0,
            'losses' => 0,
            'winrate' => null,
            'per_symbol' => [],
        ];

        // Regrouper par symbole
        $bySymbol = [];
        foreach ($ordersRaw as $order) {
            $symbol = (string) ($order['symbol'] ?? 'UNKNOWN');
            $bySymbol[$symbol][] = $order;
        }

        foreach ($bySymbol as $symbol => $orders) {
            // Trier par temps croissant
            usort(
                $orders,
                fn (array $a, array $b) =>
                    ($this->getOrderTimestamp($a) ?? 0) <=> ($this->getOrderTimestamp($b) ?? 0)
            );

            $positionSide  = null;   // 'LONG' | 'SHORT' | null
            $entryPrice    = null;   // float|null

            foreach ($orders as $order) {
                $sideVal = $this->extractSideValue($order['side'] ?? null);
                if ($sideVal === null) {
                    continue;
                }

                $type = strtolower((string) ($order['type'] ?? ''));
                $isPlan = in_array($type, ['planorder', 'trailing'], true)
                    || isset($order['trigger_price']);

                if ($isPlan) {
                    continue; // on ignore SL/TP planifiés pour ce calcul
                }

                $dealSize = (float) ($order['deal_size'] ?? $order['filled_quantity'] ?? 0.0);
                if ($dealSize <= 0) {
                    continue; // pas exécuté → pas de trade
                }

                $priceRaw = $order['deal_avg_price'] ?? $order['price'] ?? null;
                if ($priceRaw === null) {
                    continue;
                }
                $price = (float) $priceRaw;
                if ($price <= 0.0) {
                    continue;
                }

                // Ouvrir une position
                if ($sideVal === 1 || $sideVal === 4) {
                    // Si une position est déjà ouverte, on la considère close->open
                    if ($positionSide !== null && $entryPrice !== null) {
                        // On force une clôture implicite avec ce prix (rare, mais on évite de perdre des trades)
                        $isWin = $positionSide === 'LONG'
                            ? ($price > $entryPrice)
                            : ($price < $entryPrice);

                        $summary['total']++;
                        $summary['per_symbol'][$symbol]['total'] = ($summary['per_symbol'][$symbol]['total'] ?? 0) + 1;
                        if ($isWin) {
                            $summary['wins']++;
                            $summary['per_symbol'][$symbol]['wins'] = ($summary['per_symbol'][$symbol]['wins'] ?? 0) + 1;
                        } else {
                            $summary['losses']++;
                            $summary['per_symbol'][$symbol]['losses'] = ($summary['per_symbol'][$symbol]['losses'] ?? 0) + 1;
                        }
                    }

                    $positionSide = $sideVal === 1 ? 'LONG' : 'SHORT';
                    $entryPrice   = $price;

                    continue;
                }

                // Fermer une position
                if (($sideVal === 2 && $positionSide === 'LONG') || ($sideVal === 3 && $positionSide === 'SHORT')) {
                    if ($entryPrice === null) {
                        continue;
                    }

                    $isWin = $positionSide === 'LONG'
                        ? ($price > $entryPrice)
                        : ($price < $entryPrice);

                    $summary['total']++;
                    $summary['per_symbol'][$symbol]['total'] = ($summary['per_symbol'][$symbol]['total'] ?? 0) + 1;
                    if ($isWin) {
                        $summary['wins']++;
                        $summary['per_symbol'][$symbol]['wins'] = ($summary['per_symbol'][$symbol]['wins'] ?? 0) + 1;
                    } else {
                        $summary['losses']++;
                        $summary['per_symbol'][$symbol]['losses'] = ($summary['per_symbol'][$symbol]['losses'] ?? 0) + 1;
                    }

                    // Position fermée
                    $positionSide = null;
                    $entryPrice   = null;
                }
            }
        }

        if ($summary['total'] > 0) {
            $summary['winrate'] = $summary['wins'] / $summary['total'];
        }

        foreach ($summary['per_symbol'] as $symbol => &$s) {
            $s['wins']    = $s['wins'] ?? 0;
            $s['losses']  = $s['losses'] ?? 0;
            $s['total']   = $s['total'] ?? ($s['wins'] + $s['losses']);

            if ($s['total'] > 0) {
                $s['winrate'] = $s['wins'] / $s['total'];
            } else {
                $s['winrate'] = null;
            }
        }
        unset($s);

        return $summary;
    }

    private function displayWinRateFromOrders(SymfonyStyle $io, array $stats): void
    {
        if ($stats['total'] === 0) {
            $io->note('Impossible de calculer un winrate (aucun trade détecté).');
            return;
        }

        $io->section('Winrate approximatif (reconstruit depuis les ordres, sans frais)');

        $rows = [];
        foreach ($stats['per_symbol'] as $symbol => $s) {
            $rows[] = [
                $symbol,
                $s['total'] ?? 0,
                $s['wins'] ?? 0,
                $s['losses'] ?? 0,
                isset($s['winrate']) && $s['winrate'] !== null
                    ? sprintf('%.2f %%', $s['winrate'] * 100)
                    : 'N/A',
            ];
        }

        $io->table(
            ['Symbole', 'Trades', 'Gagnants', 'Perdants', 'Winrate'],
            $rows
        );

        $io->success(sprintf(
            'Winrate global: %.2f %% (%d trades gagnants / %d)',
            $stats['winrate'] * 100,
            $stats['wins'],
            $stats['total']
        ));
    }
}
