<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPrivate;
use App\Provider\Repository\ContractRepository;
use App\Repository\OrderIntentRepository;
use Brick\Math\RoundingMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'provider:position-history',
    description: 'Affiche l\'historique des positions avec entrée, SL, TP, levier et marge depuis BitMart'
)]
final class PositionHistoryCommand extends Command
{
    public function __construct(
        private readonly ?AccountProviderInterface $accountProvider,
        private readonly ?OrderProviderInterface $orderProvider,
        private readonly OrderIntentRepository $orderIntentRepository,
        private readonly ContractRepository $contractRepository,
        private readonly ?BitmartHttpClientPrivate $bitmartClient = null,
        private readonly ?LoggerInterface $logger = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Filtrer par symbole (ex: BTCUSDT, ETHUSDT). Si non spécifié, liste tous les symboles actifs.')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filtrer par statut (OPEN|CLOSED)', 'OPEN')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (table|json)', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limite de résultats par symbole', '50')
            ->setHelp('
Affiche l\'historique des positions depuis BitMart avec les informations suivantes :
- Symbole et côté (LONG/SHORT)
- Prix d\'entrée
- Stop Loss (SL) - récupéré depuis la BDD
- Take Profit (TP) - récupéré depuis la BDD
- Levier
- Marge calculée

Options:
  --symbol, -s    Filtrer par symbole spécifique (optionnel)
  --status        Filtrer par statut: OPEN (positions ouvertes) ou CLOSED (positions fermées)
  --format, -f    Format de sortie: table (par défaut) ou json
  --limit, -l     Limite de résultats par symbole (défaut: 50)

Exemples:
  # Lister toutes les positions ouvertes
  php bin/console provider:position-history

  # Lister les positions ouvertes pour un symbole spécifique
  php bin/console provider:position-history --symbol=BTCUSDT

  # Lister les positions fermées pour un symbole
  php bin/console provider:position-history --status=CLOSED --symbol=BTCUSDT

  # Lister toutes les positions fermées (limité à 20 symboles pour éviter rate limiting)
  php bin/console provider:position-history --status=CLOSED

  # Format JSON avec limite
  php bin/console provider:position-history --format=json --limit=100 --symbol=ETHUSDT
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $status = $input->getOption('status');
        $format = $input->getOption('format');
        $limit = (int) $input->getOption('limit');

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error("Format invalide. Utilisez 'table' ou 'json'.");
            return Command::FAILURE;
        }

        if (!in_array($status, ['OPEN', 'CLOSED'], true)) {
            $io->error("Statut invalide. Utilisez 'OPEN' ou 'CLOSED'.");
            return Command::FAILURE;
        }

        if (!$this->accountProvider) {
            $io->error('AccountProvider non disponible');
            return Command::FAILURE;
        }

        try {
            $io->section('Récupération des positions depuis BitMart...');

            $positions = [];
            if ($status === 'OPEN') {
                // Récupérer les positions ouvertes depuis BitMart
                $positions = $this->accountProvider->getOpenPositions($symbol);
            } else {
                // Pour les positions CLOSED, utiliser l'historique des ordres
                // Note: BitMart ne retourne que les positions ouvertes, donc on utilise l'historique des ordres
                $io->note('Récupération des positions CLOSED depuis l\'historique des ordres...');
                
                // Récupérer l'historique des ordres depuis BitMart
                if (!$this->orderProvider) {
                    $io->error('OrderProvider non disponible pour récupérer l\'historique des ordres');
                    return Command::FAILURE;
                }

                $allPositions = [];
                
                if ($symbol) {
                    // Un seul symbole spécifié
                    $symbols = [$symbol];
                } else {
                    // Récupérer tous les symboles actifs depuis les contrats
                    $contracts = $this->contractRepository->findActiveContracts();
                    $symbols = array_map(fn($c) => $c->getSymbol(), $contracts);
                    
                    if (empty($symbols)) {
                        // Fallback: récupérer les symboles depuis les positions ouvertes
                        $openPositions = $this->accountProvider->getOpenPositions();
                        $symbols = array_unique(array_map(fn($p) => $p->symbol, $openPositions));
                    }
                    
                    if (empty($symbols)) {
                        $io->warning('Aucun symbole trouvé. Veuillez spécifier un symbole avec --symbol');
                        return Command::SUCCESS;
                    }
                    
                    $io->note(sprintf('Récupération des positions CLOSED pour %d symbole(s)...', count($symbols)));
                }

                // Limiter le nombre de symboles pour éviter le rate limiting
                $maxSymbols = 20; // Limite réduite pour éviter trop de requêtes
                if (count($symbols) > $maxSymbols && !$symbol) {
                    $io->warning(sprintf('Trop de symboles (%d). Limitation à %d symboles pour éviter le rate limiting.', count($symbols), $maxSymbols));
                    $symbols = array_slice($symbols, 0, $maxSymbols);
                }

                // Pour chaque symbole, récupérer l'historique avec délai pour éviter rate limiting
                $progressBar = $io->createProgressBar(count($symbols));
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
                $progressBar->setMessage('Traitement en cours...');
                $progressBar->start();

                foreach ($symbols as $index => $sym) {
                    $progressBar->setMessage("Traitement de $sym");
                    
                    try {
                        // Utiliser getTradeHistory qui accède directement à l'API BitMart
                        // Note: getTradeHistory utilise getOrderHistory mais cherche 'trades' au lieu de 'orders'
                        $trades = $this->accountProvider->getTradeHistory($sym, $limit * 2);
                        
                        if (empty($trades)) {
                            // Si getTradeHistory ne retourne rien, accéder directement à la réponse brute de l'API
                            // pour éviter la conversion en OrderDto qui perd l'information du side numérique (1,2,3,4)
                            try {
                                if ($this->bitmartClient) {
                                    // Accéder directement au client HTTP pour obtenir les données brutes
                                    $response = $this->bitmartClient->getOrderHistory($sym, $limit * 2);
                                    
                                    // BitMart retourne data directement comme tableau d'ordres
                                    if (isset($response['data']) && is_array($response['data'])) {
                                        // Vérifier si c'est un tableau d'ordres
                                        if (!empty($response['data']) && isset($response['data'][0]['order_id'])) {
                                            $trades = $response['data'];
                                        }
                                    }
                                } else {
                                    // Fallback: utiliser getOrderHistory mais on perd l'info du side numérique
                                    $orders = $this->orderProvider->getOrderHistory($sym, $limit * 2);
                                    if (!empty($orders) && !($orders[0] instanceof \App\Contract\Provider\Dto\OrderDto)) {
                                        $trades = $orders;
                                    }
                                }
                            } catch (\Exception $e) {
                                if ($this->logger) {
                                    $this->logger->debug('Récupération des ordres bruts échouée', [
                                        'symbol' => $sym,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                        
                        if (empty($trades)) {
                            $progressBar->advance();
                            // Délai même si pas de trades pour respecter le rate limit
                            if ($index < count($symbols) - 1) {
                                sleep(2); // 2 secondes entre chaque requête
                            }
                            continue; // Passer au symbole suivant
                        }
                        
                        // Debug: afficher la structure des trades si verbose
                        if ($output->isVerbose() && $this->logger) {
                            $this->logger->debug('Trades/Orders récupérés pour symbole', [
                                'symbol' => $sym,
                                'count' => count($trades),
                                'first_keys' => !empty($trades) ? array_keys($trades[0]) : [],
                                'first_sample' => !empty($trades) ? array_slice($trades[0], 0, 15) : [],
                            ]);
                        }
                        
                        // Reconstruire les positions depuis les trades/ordres
                        // Essayer d'abord avec la méthode orders (plus précise)
                        $symbolPositions = [];
                        if (!empty($trades) && isset($trades[0]['side']) && is_numeric($trades[0]['side'])) {
                            // Si on a des ordres avec side numérique, utiliser reconstructPositionsFromOrders
                            $symbolPositions = $this->reconstructPositionsFromOrders($trades, $sym);
                        }
                        
                        // Si pas de résultats, utiliser la méthode trades
                        if (empty($symbolPositions)) {
                            $symbolPositions = $this->reconstructPositionsFromTrades($trades, $sym);
                        }
                        
                        if (!empty($symbolPositions)) {
                            $allPositions = array_merge($allPositions, $symbolPositions);
                        }
                        
                    } catch (\Exception $e) {
                        if ($this->logger) {
                            $this->logger->error('Erreur lors de la récupération des positions CLOSED pour un symbole', [
                                'symbol' => $sym,
                                'error' => $e->getMessage(),
                            ]);
                        }
                        // Continuer avec le symbole suivant
                    }
                    
                    $progressBar->advance();
                    
                    // Délai entre les requêtes pour éviter le rate limiting (sauf pour le dernier)
                    // BitMart limite à ~10 requêtes/seconde, donc on attend 2 secondes
                    if ($index < count($symbols) - 1) {
                        sleep(2); // 2 secondes entre chaque requête
                    }
                }
                
                $progressBar->setMessage('Terminé');
                $progressBar->finish();
                $io->newLine(2);
                
                $positions = $allPositions;
                
                if (empty($positions)) {
                    $io->note('Aucune position CLOSED trouvée' . ($symbol ? " pour $symbol" : ''));
                    return Command::SUCCESS;
                }
            }

            if (empty($positions)) {
                $io->note('Aucune position trouvée' . ($symbol ? " pour $symbol" : '') . " avec le statut $status");
                return Command::SUCCESS;
            }

            $io->section('Traitement des données des positions...');

            $results = [];
            foreach ($positions as $position) {
                $result = $this->buildPositionDataFromDto($position, $symbol);
                if ($result !== null) {
                    $results[] = $result;
                }
            }

            // Limiter les résultats
            $results = array_slice($results, 0, $limit);

            if (empty($results)) {
                $io->note('Aucune donnée de position valide trouvée');
                return Command::SUCCESS;
            }

            if ($format === 'json') {
                $output->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }

            // Affichage en tableau
            $io->title(sprintf('Historique des positions (%d position(s))', count($results)));

            $rows = [];
            foreach ($results as $result) {
                $rows[] = [
                    $result['symbol'],
                    $result['side'],
                    $result['entry_price'] ?? 'N/A',
                    $result['stop_loss'] ?? 'N/A',
                    $result['take_profit'] ?? 'N/A',
                    $result['leverage'] ?? 'N/A',
                    $result['margin'] ?? 'N/A',
                    $result['size'] ?? 'N/A',
                    $result['opened_at'] ?? 'N/A',
                ];
            }

            $io->table(
                ['Symbole', 'Côté', 'Entrée', 'SL', 'TP', 'Levier', 'Marge (USDT)', 'Taille', 'Ouvert le'],
                $rows
            );

            // Statistiques
            $totalMargin = array_sum(array_filter(array_column($results, 'margin'), fn($v) => $v !== null && $v !== 'N/A'));
            $io->section('Statistiques');
            $io->listing([
                sprintf('Nombre de positions: %d', count($results)),
                sprintf('Marge totale: %s USDT', number_format($totalMargin, 2)),
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Erreur lors de la récupération de l\'historique: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Construit les données d'une position depuis un PositionDto (BitMart) avec SL/TP depuis la BDD
     */
    private function buildPositionDataFromDto(\App\Contract\Provider\Dto\PositionDto $position, ?string $filterSymbol = null): ?array
    {
        $symbol = $position->symbol;
        
        // Filtrer par symbole si spécifié
        if ($filterSymbol && strtoupper($symbol) !== strtoupper($filterSymbol)) {
            return null;
        }

        $side = $position->side->value;
        $entryPrice = $position->entryPrice->toScale(8, RoundingMode::DOWN)->toFloat();
        $size = $position->size->toScale(8, RoundingMode::DOWN)->toFloat();
        $leverage = $position->leverage->toScale(2, RoundingMode::DOWN)->toFloat();
        $margin = $position->margin->toScale(8, RoundingMode::DOWN)->toFloat();

        $stopLoss = null;
        $takeProfit = null;

        // Chercher les OrderIntent pour ce symbole et côté
        // Convertir le côté Position (long/short) en côté OrderIntent (1=open_long, 4=open_short)
        $orderIntentSide = (strtolower($side) === 'long') ? 1 : 4;
        
        $orderIntents = $this->orderIntentRepository->createQueryBuilder('oi')
            ->where('oi.symbol = :symbol')
            ->andWhere('oi.side = :side')
            ->setParameter('symbol', $symbol)
            ->setParameter('side', $orderIntentSide)
            ->orderBy('oi.createdAt', 'DESC')
            ->setMaxResults(10) // Limiter pour performance
            ->getQuery()
            ->getResult();

        // Pour chaque OrderIntent, chercher les protections (SL/TP)
        foreach ($orderIntents as $orderIntent) {
            $protections = $orderIntent->getProtections();
            
            foreach ($protections as $protection) {
                $price = (float) $protection->getPrice();
                if ($protection->isStopLoss() && $stopLoss === null) {
                    $stopLoss = $price;
                } elseif ($protection->isTakeProfit() && $takeProfit === null) {
                    $takeProfit = $price;
                }
            }
            
            // Si on a trouvé les deux, on peut arrêter
            if ($stopLoss !== null && $takeProfit !== null) {
                break;
            }
        }

        // Si pas de SL/TP trouvés via OrderIntent, chercher dans les metadata de la position
        if ($stopLoss === null || $takeProfit === null) {
            $metadata = $position->metadata;
            if (isset($metadata['stop_loss'])) {
                $stopLoss = (float) $metadata['stop_loss'];
            }
            if (isset($metadata['take_profit'])) {
                $takeProfit = (float) $metadata['take_profit'];
            }
        }

        return [
            'symbol' => $symbol,
            'side' => strtoupper($side),
            'entry_price' => number_format($entryPrice, 8, '.', ''),
            'stop_loss' => $stopLoss !== null ? number_format($stopLoss, 8, '.', '') : null,
            'take_profit' => $takeProfit !== null ? number_format($takeProfit, 8, '.', '') : null,
            'leverage' => number_format($leverage, 2, '.', ''),
            'margin' => number_format($margin, 2, '.', ''),
            'size' => number_format($size, 4, '.', ''),
            'opened_at' => $position->openedAt->format('Y-m-d H:i:s'),
            'closed_at' => $position->closedAt?->format('Y-m-d H:i:s'),
            'status' => $position->closedAt ? 'CLOSED' : 'OPEN',
        ];
    }

    /**
     * Reconstruit les positions depuis l'historique des trades
     * Note: Cette méthode est simplifiée et peut nécessiter des améliorations
     */
    private function reconstructPositionsFromTrades(array $trades, string $symbol): array
    {
        $positions = [];
        
        if (empty($trades)) {
            return [];
        }
        
        // Grouper les trades par côté (long/short) et reconstruire les positions
        $longTrades = [];
        $shortTrades = [];
        
        foreach ($trades as $trade) {
            // BitMart peut utiliser différents formats pour le côté
            $side = $trade['side'] ?? $trade['position_side'] ?? $trade['order_side'] ?? null;
            
            // Si c'est un nombre, convertir
            if (is_numeric($side)) {
                $sideNum = (int)$side;
                // BitMart: 1=open_long, 2=close_long, 3=close_short, 4=open_short
                if ($sideNum == 1 || $sideNum == 2) {
                    $longTrades[] = $trade;
                    continue;
                } elseif ($sideNum == 3 || $sideNum == 4) {
                    $shortTrades[] = $trade;
                    continue;
                }
            }
            
            // Si c'est une string
            if ($side) {
                $side = strtolower($side);
                if (in_array($side, ['long', '1', 'buy', 'open_long', 'close_long'], true)) {
                    $longTrades[] = $trade;
                } elseif (in_array($side, ['short', '4', 'sell', 'open_short', 'close_short'], true)) {
                    $shortTrades[] = $trade;
                }
            }
        }
        
        // Reconstruire les positions long
        if (!empty($longTrades)) {
            $position = $this->buildPositionFromTrades($longTrades, $symbol, 'long');
            if ($position) {
                $positions[] = $position;
            }
        }
        
        // Reconstruire les positions short
        if (!empty($shortTrades)) {
            $position = $this->buildPositionFromTrades($shortTrades, $symbol, 'short');
            if ($position) {
                $positions[] = $position;
            }
        }
        
        return $positions;
    }

    /**
     * Construit une position depuis un groupe de trades
     */
    private function buildPositionFromTrades(array $trades, string $symbol, string $side): ?\App\Contract\Provider\Dto\PositionDto
    {
        if (empty($trades)) {
            return null;
        }
        
        // Trier les trades par timestamp (plus ancien en premier)
        usort($trades, function($a, $b) {
            $tsA = $a['create_time'] ?? $a['timestamp'] ?? $a['open_timestamp'] ?? 0;
            $tsB = $b['create_time'] ?? $b['timestamp'] ?? $b['open_timestamp'] ?? 0;
            return $tsA <=> $tsB;
        });
        
        $firstTrade = $trades[0];
        $lastTrade = end($trades);
        
        // Calculer les valeurs moyennes
        $totalSize = 0;
        $totalValue = 0;
        $totalPnl = 0;
        $totalMargin = 0;
        $leverage = 1;
        
        foreach ($trades as $trade) {
            $size = (float)($trade['size'] ?? $trade['current_amount'] ?? 0);
            $price = (float)($trade['price'] ?? $trade['entry_price'] ?? $trade['open_avg_price'] ?? 0);
            $pnl = (float)($trade['realized_pnl'] ?? $trade['realized_value'] ?? 0);
            $margin = (float)($trade['margin'] ?? $trade['initial_margin'] ?? 0);
            $lev = (float)($trade['leverage'] ?? 1);
            
            $totalSize += abs($size);
            $totalValue += abs($size) * $price;
            $totalPnl += $pnl;
            $totalMargin += $margin;
            $leverage = max($leverage, $lev);
        }
        
        $avgEntryPrice = $totalSize > 0 ? $totalValue / $totalSize : 0;
        
        // Créer un PositionDto simplifié
        try {
            return \App\Contract\Provider\Dto\PositionDto::fromArray([
                'symbol' => $symbol,
                'side' => $side,
                'size' => (string)$totalSize,
                'entry_price' => (string)$avgEntryPrice,
                'mark_price' => (string)$avgEntryPrice, // Approximation
                'unrealized_pnl' => '0',
                'realized_pnl' => (string)$totalPnl,
                'margin' => (string)$totalMargin,
                'leverage' => (string)$leverage,
                'open_timestamp' => $firstTrade['create_time'] ?? $firstTrade['timestamp'] ?? $firstTrade['open_timestamp'] ?? time() * 1000,
                'closed_at' => $lastTrade['create_time'] ?? $lastTrade['timestamp'] ?? null,
                'metadata' => [],
            ]);
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Erreur lors de la reconstruction de la position depuis les trades', [
                    'symbol' => $symbol,
                    'side' => $side,
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }
    }

    /**
     * Reconstruit les positions depuis l'historique des ordres
     */
    private function reconstructPositionsFromOrders(array $orders, string $symbol): array
    {
        $positions = [];
        
        if (empty($orders)) {
            return [];
        }
        
        // Filtrer les ordres fermés (status = FILLED ou CLOSED)
        // Pour les positions CLOSED, on cherche les ordres de fermeture (close_long, close_short)
        $closedOrders = array_filter($orders, function($order) {
            // Gérer OrderDto ou tableau brut
            if ($order instanceof \App\Contract\Provider\Dto\OrderDto) {
                $status = strtoupper($order->status->value ?? '');
                $side = $order->side->value ?? null;
                // OrderSide peut être un enum, extraire la valeur numérique si possible
                if (is_object($side) && method_exists($side, 'value')) {
                    $side = $side->value;
                }
            } else {
                $statusRaw = $order['status'] ?? $order['order_status'] ?? $order['state'] ?? '';
                $status = is_string($statusRaw) ? strtoupper($statusRaw) : (string)$statusRaw;
                $side = $order['side'] ?? $order['position_side'] ?? null;
            }
            
            // Accepter les ordres FILLED ou CLOSED
            // BitMart utilise state: 2=FILLED, 4=FILLED (selon les logs)
            $isFilled = in_array($status, ['FILLED', 'CLOSED', 'PARTIALLY_FILLED', '2', '4'], true);
            
            // Pour les positions fermées, on cherche les ordres de fermeture (side = 2 ou 3)
            // BitMart: 1=open_long, 2=close_long, 3=close_short, 4=open_short
            $isCloseOrder = false;
            if ($side !== null) {
                $sideNum = is_numeric($side) ? (int)$side : null;
                if ($sideNum !== null) {
                    $isCloseOrder = ($sideNum == 2 || $sideNum == 3); // close_long ou close_short
                } else {
                    // Si c'est une string, vérifier
                    $sideStr = strtolower((string)$side);
                    $isCloseOrder = in_array($sideStr, ['close_long', 'close_short', '2', '3'], true);
                }
            }
            
            return $isFilled && $isCloseOrder;
        });
        
        if (empty($closedOrders)) {
            return [];
        }
        
        // Grouper les ordres par côté (long/short) basé sur le type de fermeture
        // BitMart: 2=close_long (ferme une position long), 3=close_short (ferme une position short)
        $longCloseOrders = [];
        $shortCloseOrders = [];
        
        foreach ($closedOrders as $order) {
            // Gérer OrderDto ou tableau brut
            if ($order instanceof \App\Contract\Provider\Dto\OrderDto) {
                $side = $order->side->value ?? null;
            } else {
                $side = $order['side'] ?? $order['position_side'] ?? null;
            }
            
            if (!$side) {
                continue;
            }
            
            // BitMart: 2=close_long (ferme une position long), 3=close_short (ferme une position short)
            $sideValue = is_numeric($side) ? (int)$side : (is_string($side) ? strtolower($side) : null);
            
            if ($sideValue == 2 || $sideValue === 'close_long' || $sideValue === '2') {
                // Ordre de fermeture d'une position LONG
                $longCloseOrders[] = $order;
            } elseif ($sideValue == 3 || $sideValue === 'close_short' || $sideValue === '3') {
                // Ordre de fermeture d'une position SHORT
                $shortCloseOrders[] = $order;
            }
        }
        
        // Reconstruire les positions long fermées
        if (!empty($longCloseOrders)) {
            $position = $this->buildPositionFromOrders($longCloseOrders, $symbol, 'long');
            if ($position) {
                $positions[] = $position;
            }
        }
        
        // Reconstruire les positions short fermées
        if (!empty($shortCloseOrders)) {
            $position = $this->buildPositionFromOrders($shortCloseOrders, $symbol, 'short');
            if ($position) {
                $positions[] = $position;
            }
        }
        
        return $positions;
    }

    /**
     * Construit une position depuis un groupe d'ordres
     */
    private function buildPositionFromOrders(array $orders, string $symbol, string $side): ?\App\Contract\Provider\Dto\PositionDto
    {
        if (empty($orders)) {
            return null;
        }
        
        // Trier les ordres par timestamp
        usort($orders, function($a, $b) {
            $tsA = $a['create_time'] ?? $a['timestamp'] ?? $a['create_time_ms'] ?? 0;
            $tsB = $b['create_time'] ?? $b['timestamp'] ?? $b['create_time_ms'] ?? 0;
            return $tsA <=> $tsB;
        });
        
        $firstOrder = $orders[0];
        $lastOrder = end($orders);
        
        // Calculer les valeurs
        $totalSize = 0;
        $totalValue = 0;
        $totalPnl = 0;
        $totalMargin = 0;
        $leverage = 1;
        
        // Pour les positions fermées, on a besoin de trouver l'ordre d'ouverture correspondant
        // Pour l'instant, on utilise les données de l'ordre de fermeture
        $entryPrice = null;
        $exitPrice = null;
        
        foreach ($orders as $order) {
            // Gérer OrderDto ou tableau brut
            if ($order instanceof \App\Contract\Provider\Dto\OrderDto) {
                $size = $order->filledQuantity?->toFloat() ?? $order->quantity?->toFloat() ?? 0.0;
                $price = $order->averagePrice?->toFloat() ?? $order->price?->toFloat() ?? 0.0;
                $pnl = 0.0; // OrderDto n'a pas de PnL directement
                $margin = 0.0; // OrderDto n'a pas de margin directement
                $lev = 1; // OrderDto n'a pas de leverage
                $createTime = $order->createdAt?->getTimestamp() * 1000 ?? time() * 1000;
                $filledAt = $order->filledAt?->getTimestamp() * 1000 ?? $createTime;
                
                // Extraire depuis metadata si disponible
                $metadata = $order->metadata ?? [];
                $pnl = (float)($metadata['realized_pnl'] ?? $metadata['realized_value'] ?? 0.0);
                $margin = (float)($metadata['margin'] ?? $metadata['initial_margin'] ?? 0.0);
                $lev = (float)($metadata['leverage'] ?? 1);
            } else {
                $size = (float)($order['filled_size'] ?? $order['filled_quantity'] ?? $order['size'] ?? $order['quantity'] ?? 0);
                $price = (float)($order['avg_price'] ?? $order['average_price'] ?? $order['filled_price'] ?? $order['price'] ?? 0);
                $pnl = (float)($order['realized_pnl'] ?? $order['realized_value'] ?? $order['pnl'] ?? 0);
                $margin = (float)($order['margin'] ?? $order['initial_margin'] ?? 0);
                $lev = (float)($order['leverage'] ?? 1);
                $createTime = $order['create_time'] ?? $order['created_at'] ?? $order['timestamp'] ?? $order['create_time_ms'] ?? time() * 1000;
                $filledAt = $order['filled_at'] ?? $order['update_time'] ?? $createTime;
            }
            
            // Pour un ordre de fermeture, le prix est le prix de sortie
            if ($exitPrice === null) {
                $exitPrice = $price;
            }
            
            $totalSize += abs($size);
            $totalValue += abs($size) * $price;
            $totalPnl += $pnl;
            $totalMargin += $margin;
            $leverage = max($leverage, $lev);
        }
        
        // Le prix d'entrée doit être calculé depuis le PnL et le prix de sortie
        // PnL = (exit_price - entry_price) * size * contract_size (pour long)
        // Pour short: PnL = (entry_price - exit_price) * size * contract_size
        // Approximation: utiliser le prix de sortie moins le PnL par unité
        if ($totalSize > 0 && $exitPrice > 0) {
            $pnlPerUnit = $totalPnl / $totalSize;
            if (strtolower($side) === 'long') {
                $entryPrice = $exitPrice - $pnlPerUnit;
            } else {
                $entryPrice = $exitPrice + $pnlPerUnit;
            }
        } else {
            $entryPrice = $totalSize > 0 ? $totalValue / $totalSize : 0;
        }
        
        // Récupérer les timestamps
        $firstOrderData = $firstOrder instanceof \App\Contract\Provider\Dto\OrderDto 
            ? ['create_time' => $firstOrder->createdAt?->getTimestamp() * 1000 ?? time() * 1000, 'filled_at' => $firstOrder->filledAt?->getTimestamp() * 1000]
            : $firstOrder;
        $lastOrderData = $lastOrder instanceof \App\Contract\Provider\Dto\OrderDto
            ? ['create_time' => $lastOrder->filledAt?->getTimestamp() * 1000 ?? $lastOrder->createdAt?->getTimestamp() * 1000 ?? null]
            : $lastOrder;
        
        try {
            // Convertir le timestamp de fermeture en DateTimeImmutable si disponible
            $closedAtTimestamp = $lastOrderData['filled_at'] ?? $lastOrderData['create_time'] ?? $lastOrderData['timestamp'] ?? $lastOrderData['create_time_ms'] ?? null;
            $closedAt = null;
            if ($closedAtTimestamp) {
                // Convertir millisecondes en secondes si nécessaire
                $tsSeconds = is_numeric($closedAtTimestamp) && $closedAtTimestamp > 1e10 
                    ? (int)($closedAtTimestamp / 1000) 
                    : (int)$closedAtTimestamp;
                $closedAt = new \DateTimeImmutable('@' . $tsSeconds);
            }
            
            // PositionDto::fromArray attend closed_at comme string ou null
            $closedAtString = null;
            if ($closedAtTimestamp) {
                // Convertir millisecondes en secondes si nécessaire
                $tsSeconds = is_numeric($closedAtTimestamp) && $closedAtTimestamp > 1e10 
                    ? (int)($closedAtTimestamp / 1000) 
                    : (int)$closedAtTimestamp;
                $closedAtString = (new \DateTimeImmutable('@' . $tsSeconds))->format('Y-m-d H:i:s');
            }
            
            return \App\Contract\Provider\Dto\PositionDto::fromArray([
                'symbol' => $symbol,
                'side' => $side,
                'size' => (string)$totalSize,
                'entry_price' => (string)$entryPrice,
                'mark_price' => (string)$exitPrice, // Prix de sortie comme mark price
                'unrealized_pnl' => '0',
                'realized_pnl' => (string)$totalPnl,
                'margin' => (string)$totalMargin,
                'leverage' => (string)$leverage,
                'open_timestamp' => $firstOrderData['create_time'] ?? $firstOrderData['timestamp'] ?? $firstOrderData['create_time_ms'] ?? time() * 1000,
                'closed_at' => $closedAtString,
                'metadata' => [
                    'exit_price' => (string)$exitPrice,
                    'entry_price_calculated' => (string)$entryPrice,
                ],
            ]);
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Erreur lors de la reconstruction de la position depuis les ordres', [
                    'symbol' => $symbol,
                    'side' => $side,
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }
    }
}

