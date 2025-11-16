<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Repository\OrderIntentRepository;
use App\Trading\Dto\OrderDto;
use App\Trading\Dto\PositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Storage\OrderStateRepositoryInterface;
use App\Trading\Storage\PositionStateRepositoryInterface;
use App\Trading\Sync\TradingStateSyncRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'provider:position-history',
    description: 'Affiche les positions (ouvertes/fermées) et les ordres ouverts depuis la BDD après synchronisation'
)]
final class PositionHistoryCommand extends Command
{
    public function __construct(
        private readonly TradingStateSyncRunner $syncRunner,
        private readonly PositionStateRepositoryInterface $positionStateRepository,
        private readonly OrderStateRepositoryInterface $orderStateRepository,
        private readonly OrderIntentRepository $orderIntentRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Filtrer par symbole (ex: BTCUSDT, ETHUSDT).')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filtrer par statut (OPEN|CLOSED)', 'OPEN')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (table|json)', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limite de résultats', '50')
            ->setHelp('

Affiche les positions depuis la BDD (après synchronisation) avec les informations suivantes :

- Symbole et côté (LONG/SHORT)

- Prix d\'entrée

- Stop Loss (SL) - retrouvé via OrderIntent ou metadata

- Take Profit (TP) - retrouvé via OrderIntent ou metadata

- Levier (approx. depuis la position)

- Marge estimée

- Taille

- Dates d\'ouverture / fermeture

Options:

  --symbol, -s    Filtrer par symbole spécifique (optionnel)

  --status        Filtrer par statut: OPEN (positions ouvertes) ou CLOSED (positions fermées)

  --format, -f    Format de sortie: table (par défaut) ou json

  --limit, -l     Limite de résultats (défaut: 50)

NB: Les ordres affichés sont les ordres OUVERTS persistés en base (pour le statut OPEN).

            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $status = strtoupper((string)$input->getOption('status'));
        $format = $input->getOption('format');
        $limit  = (int)$input->getOption('limit');

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error("Format invalide. Utilisez 'table' ou 'json'.");
            return Command::FAILURE;
        }

        if (!in_array($status, ['OPEN', 'CLOSED'], true)) {
            $io->error("Statut invalide. Utilisez 'OPEN' ou 'CLOSED'.");
            return Command::FAILURE;
        }

        $symbols = $symbol !== null ? [strtoupper((string)$symbol)] : null;

        // 1) Synchronisation avant lecture
        $io->section('Synchronisation des positions/ordres (BitMart → BDD)...');
        try {
            $this->syncRunner->syncAndDispatch('position_history_command', $symbols);
        } catch (\Throwable $e) {
            $io->warning(sprintf('Synchronisation échouée: %s', $e->getMessage()));
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            // On continue malgré tout, en lisant l'état actuel de la BDD
        }

        // 2) Lecture depuis la BDD
        $io->section('Lecture des données depuis la BDD...');
        $positionRows = [];
        $orderRows    = [];

        if ($status === 'OPEN') {
            // Positions ouvertes
            $openPositions = $this->positionStateRepository->findLocalOpenPositions($symbols);
            foreach ($openPositions as $position) {
                $row = $this->buildRowFromOpenPosition($position, $symbol);
                if ($row !== null) {
                    $positionRows[] = $row;
                }
            }

            // Ordres ouverts (associer au même filtre de symboles)
            $openOrders = $this->orderStateRepository->findLocalOpenOrders($symbols);
            foreach ($openOrders as $order) {
                $orderRows[] = $this->buildRowFromOrder($order);
            }
        } else {
            // Positions fermées (historique)
            $from = null; // tu peux plus tard exposer --from/--to si besoin
            $to   = null;
            $closedPositions = $this->positionStateRepository->findLocalClosedPositions($symbols, $from, $to);
            foreach ($closedPositions as $history) {
                $row = $this->buildRowFromClosedPosition($history, $symbol);
                if ($row !== null) {
                    $positionRows[] = $row;
                }
            }
        }

        // Appliquer limite globale
        if ($limit > 0) {
            $positionRows = array_slice($positionRows, 0, $limit);
        }

        if ($positionRows === [] && $orderRows === []) {
            $io->note('Aucune donnée trouvée pour les critères demandés.');
            return Command::SUCCESS;
        }

        // 3) Sortie JSON
        if ($format === 'json') {
            $payload = [
                'status'          => $status,
                'symbol_filter'   => $symbol,
                'positions_count' => count($positionRows),
                'orders_count'    => count($orderRows),
                'positions'       => $positionRows,
                'orders'          => $orderRows,
            ];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        // 4) Sortie table
        $io->title(sprintf(
            'Positions %s (%d résultat(s))',
            $status,
            count($positionRows)
        ));
        $io->table(
            ['Symbole', 'Côté', 'Entrée', 'SL', 'TP', 'Levier', 'Marge (USDT)', 'Taille', 'Ouvert le', 'Fermé le', 'PnL réalisé'],
            array_map(static fn (array $row) => [
                $row['symbol'],
                $row['side'],
                $row['entry_price'] ?? 'N/A',
                $row['stop_loss'] ?? 'N/A',
                $row['take_profit'] ?? 'N/A',
                $row['leverage'] ?? 'N/A',
                $row['margin'] ?? 'N/A',
                $row['size'] ?? 'N/A',
                $row['opened_at'] ?? 'N/A',
                $row['closed_at'] ?? 'N/A',
                $row['realized_pnl'] ?? 'N/A',
            ], $positionRows)
        );

        if ($status === 'OPEN' && $orderRows !== []) {
            $io->section(sprintf('Ordres OUVERTS (%d)', count($orderRows)));
            $io->table(
                ['Symbole', 'Side', 'Type', 'Statut', 'Prix', 'Qté', 'Qté filled', 'Créé le', 'MàJ le'],
                array_map(static fn (array $row) => [
                    $row['symbol'],
                    $row['side'],
                    $row['type'],
                    $row['status'],
                    $row['price'],
                    $row['quantity'],
                    $row['filled_quantity'],
                    $row['created_at'] ?? 'N/A',
                    $row['updated_at'] ?? 'N/A',
                ], $orderRows)
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Construit une ligne à partir d'une position OUVERTE (PositionDto).
     */
    private function buildRowFromOpenPosition(PositionDto $position, ?string $filterSymbol = null): ?array
    {
        $symbol = $position->symbol;
        if ($filterSymbol && strtoupper($symbol) !== strtoupper($filterSymbol)) {
            return null;
        }

        $side       = strtoupper($position->side->value); // 'LONG'/'SHORT'
        $entryPrice = $position->entryPrice;
        $size       = $position->size;
        $leverage   = $position->leverage;

        // Marge approximative : notional / leverage (contrat USDT avec contract_size=1)
        $notional = $entryPrice->multipliedBy($size);
        $margin   = ($leverage->isGreaterThan(0)) ? $notional->dividedBy($leverage) : \Brick\Math\BigDecimal::zero();

        // SL / TP via OrderIntent ou metadata
        [$stopLoss, $takeProfit] = $this->resolveProtectionsForPosition($symbol, $side);

        $openedAt = $position->openedAt->format('Y-m-d H:i:s');

        return [
            'symbol'       => $symbol,
            'side'         => $side,
            'entry_price'  => $this->formatFloat($entryPrice, 8),
            'stop_loss'    => $stopLoss !== null ? $this->formatFloat(\Brick\Math\BigDecimal::of((string)$stopLoss), 8) : null,
            'take_profit'  => $takeProfit !== null ? $this->formatFloat(\Brick\Math\BigDecimal::of((string)$takeProfit), 8) : null,
            'leverage'     => $this->formatFloat($leverage, 2),
            'margin'       => $this->formatFloat($margin, 2),
            'size'         => $this->formatFloat($size, 4),
            'opened_at'    => $openedAt,
            'closed_at'    => null,
            'realized_pnl' => null,
        ];
    }

    /**
     * Construit une ligne à partir d'une position FERMÉE (PositionHistoryEntryDto).
     */
    private function buildRowFromClosedPosition(PositionHistoryEntryDto $history, ?string $filterSymbol = null): ?array
    {
        $symbol = $history->symbol;
        if ($filterSymbol && strtoupper($symbol) !== strtoupper($filterSymbol)) {
            return null;
        }

        $side      = strtoupper($history->side->value); // 'LONG'/'SHORT'
        $entry     = $history->entryPrice;
        $exit      = $history->exitPrice;
        $size      = $history->size;
        $realized  = $history->realizedPnl;

        $openedAt  = $history->openedAt->format('Y-m-d H:i:s');
        $closedAt  = $history->closedAt->format('Y-m-d H:i:s');

        $margin    = null; // tu peux le dériver de ton snapshot si tu le stockes

        // On peut réutiliser la même logique pour SL/TP (OrderIntent + metadata éventuelle)
        [$stopLoss, $takeProfit] = $this->resolveProtectionsForPosition($symbol, $side);

        return [
            'symbol'       => $symbol,
            'side'         => $side,
            'entry_price'  => $this->formatFloat($entry, 8),
            'stop_loss'    => $stopLoss !== null ? $this->formatFloat(\Brick\Math\BigDecimal::of((string)$stopLoss), 8) : null,
            'take_profit'  => $takeProfit !== null ? $this->formatFloat(\Brick\Math\BigDecimal::of((string)$takeProfit), 8) : null,
            'leverage'     => null, // optionnel, si tu le stockes dans l'historique
            'margin'       => $margin !== null ? $this->formatFloat($margin, 2) : null,
            'size'         => $this->formatFloat($size, 4),
            'opened_at'    => $openedAt,
            'closed_at'    => $closedAt,
            'realized_pnl' => $this->formatFloat($realized, 8),
        ];
    }

    /**
     * Construit une ligne d'ordre (OrderDto) pour le tableau des ordres ouverts.
     */
    private function buildRowFromOrder(OrderDto $order): array
    {
        return [
            'symbol'          => $order->symbol,
            'side'            => strtoupper($order->side->value),
            'type'            => strtolower($order->type->value),
            'status'          => strtoupper($order->status->value),
            'price'           => $this->formatFloat($order->price, 8),
            'quantity'        => $this->formatFloat($order->quantity, 4),
            'filled_quantity' => $this->formatFloat($order->filledQuantity, 4),
            'created_at'      => $order->createdAt?->format('Y-m-d H:i:s'),
            'updated_at'      => $order->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Retrouve SL/TP via OrderIntent (puis éventuels metadata si tu les ajoutes aux snapshots).
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function resolveProtectionsForPosition(string $symbol, string $sideUpper): array
    {
        $stopLoss   = null;
        $takeProfit = null;

        // Mapping côté position -> "side" numérique OrderIntent (1=open_long, 4=open_short)
        $orderIntentSide = (strtoupper($sideUpper) === 'LONG') ? 1 : 4;

        $orderIntents = $this->orderIntentRepository->createQueryBuilder('oi')
            ->where('oi.symbol = :symbol')
            ->andWhere('oi.side = :side')
            ->setParameter('symbol', $symbol)
            ->setParameter('side', $orderIntentSide)
            ->orderBy('oi.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        foreach ($orderIntents as $orderIntent) {
            $protections = $orderIntent->getProtections();
            foreach ($protections as $protection) {
                $price = (float)$protection->getPrice();
                if ($protection->isStopLoss() && $stopLoss === null) {
                    $stopLoss = $price;
                } elseif ($protection->isTakeProfit() && $takeProfit === null) {
                    $takeProfit = $price;
                }
            }

            if ($stopLoss !== null && $takeProfit !== null) {
                break;
            }
        }

        return [$stopLoss, $takeProfit];
    }

    private function formatFloat(\Brick\Math\BigDecimal $value, int $decimals): string
    {
        return $value->toScale($decimals, \Brick\Math\RoundingMode::DOWN)->__toString();
    }
}
