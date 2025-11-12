<?php

declare(strict_types=1);

namespace App\MtfValidator\Command;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use Brick\Math\RoundingMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mtf:list-open-positions-orders',
    description: 'Liste les positions ouvertes et les ordres ouverts (similaire à MtfRunOrchestrator)'
)]
class ListOpenPositionsOrdersCommand extends Command
{
    public function __construct(
        private readonly ?AccountProviderInterface $accountProvider = null,
        private readonly ?OrderProviderInterface $orderProvider = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MainProviderInterface $mainProvider = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Filtrer par symbole (optionnel)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (table|json)', 'table')
            ->addOption('exchange', null, InputOption::VALUE_OPTIONAL, 'Identifiant de l\'exchange (ex: bitmart)')
            ->addOption('market-type', null, InputOption::VALUE_OPTIONAL, 'Type de marché (perpetual|spot)')
            ->setHelp('
Cette commande liste les positions ouvertes et les ordres ouverts en utilisant les mêmes méthodes
que MtfRunOrchestrator (accountProvider->getOpenPositions() et orderProvider->getOpenOrders()).

Exemples:
  php bin/console mtf:list-open-positions-orders
  php bin/console mtf:list-open-positions-orders --symbol=BTCUSDT
  php bin/console mtf:list-open-positions-orders --format=json
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $format = $input->getOption('format');

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error("Format invalide. Utilisez 'table' ou 'json'.");
            return Command::FAILURE;
        }

        $data = [
            'positions' => [],
            'orders' => [],
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        try {
            // 1. Récupération des positions ouvertes
            if ($this->accountProvider) {
                $io->section('Récupération des positions ouvertes...');
                try {
                    $openPositions = $this->accountProvider->getOpenPositions($symbol);

                    if (empty($openPositions)) {
                        $io->note('Aucune position ouverte' . ($symbol ? " pour $symbol" : ''));
                        $data['positions'] = [];
                    } else {
                        $data['positions'] = array_map(function ($position) {
                            return [
                                'symbol' => $position->symbol,
                                'side' => $position->side->value,
                                'size' => $position->size->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'entry_price' => $position->entryPrice->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'mark_price' => $position->markPrice->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'unrealized_pnl' => $position->unrealizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'realized_pnl' => $position->realizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'margin' => $position->margin->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'leverage' => $position->leverage->toScale(2, RoundingMode::DOWN)->toFloat(),
                                'opened_at' => $position->openedAt->format('Y-m-d H:i:s'),
                                'closed_at' => $position->closedAt?->format('Y-m-d H:i:s'),
                                'metadata' => $position->metadata,
                            ];
                        }, $openPositions);

                        if ($format === 'table') {
                            $io->success('✅ ' . count($openPositions) . ' position(s) trouvée(s)');
                            $rows = [];
                            foreach ($openPositions as $position) {
                                $rows[] = [
                                    $position->symbol,
                                    $position->side->value,
                                    number_format($position->size->toScale(8, RoundingMode::DOWN)->toFloat(), 4),
                                    number_format($position->entryPrice->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                    number_format($position->markPrice->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                    number_format($position->unrealizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                    number_format($position->realizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                    number_format($position->margin->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                    number_format($position->leverage->toScale(2, RoundingMode::DOWN)->toFloat(), 2) . 'x',
                                    $position->openedAt->format('Y-m-d H:i:s'),
                                    $position->closedAt?->format('Y-m-d H:i:s') ?? 'N/A',
                                    json_encode($position->metadata, JSON_UNESCAPED_UNICODE),
                                ];
                            }
                            $io->table(
                                [
                                    'Symbole',
                                    'Side',
                                    'Size',
                                    'Prix entrée',
                                    'Prix mark',
                                    'PnL non réalisé',
                                    'PnL réalisé',
                                    'Margin',
                                    'Levier',
                                    'Ouvert le',
                                    'Fermé le',
                                    'Metadata',
                                ],
                                $rows
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $errorMsg = 'Erreur lors de la récupération des positions ouvertes: ' . $e->getMessage();
                    $io->warning($errorMsg);
                    if ($this->logger) {
                        $this->logger->error('[ListOpenPositionsOrdersCommand] Failed to fetch open positions', [
                            'symbol' => $symbol,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                    $data['positions_error'] = $e->getMessage();
                }
            } else {
                $io->note('AccountProvider non disponible');
                $data['positions_error'] = 'AccountProvider not available';
            }

            // 2. Récupération des ordres ouverts (ordres normaux)
            $orderProvider = $this->orderProvider;
            if ($this->mainProvider) {
                $orderProvider = $this->mainProvider->forContext($context)->getOrderProvider();
            }
            if ($orderProvider) {
                $io->section('Récupération des ordres ouverts...');
                try {
                    $openOrders = $orderProvider->getOpenOrders($symbol);

                    if (empty($openOrders)) {
                        $io->note('Aucun ordre ouvert' . ($symbol ? " pour $symbol" : ''));
                        $data['orders'] = [];
                    } else {
                        $data['orders'] = array_map(function ($order) {
                            return [
                                'order_id' => $order->orderId,
                                'symbol' => $order->symbol,
                                'side' => $order->side->value,
                                'type' => $order->type->value,
                                'status' => $order->status->value,
                                'quantity' => $order->quantity->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'price' => $order->price?->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'stop_price' => $order->stopPrice?->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'filled_quantity' => $order->filledQuantity->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'remaining_quantity' => $order->remainingQuantity->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'average_price' => $order->averagePrice?->toScale(8, RoundingMode::DOWN)->toFloat(),
                                'created_at' => $order->createdAt->format('Y-m-d H:i:s'),
                                'updated_at' => $order->updatedAt?->format('Y-m-d H:i:s'),
                                'filled_at' => $order->filledAt?->format('Y-m-d H:i:s'),
                                'metadata' => $order->metadata,
                            ];
                        }, $openOrders);

                        if ($format === 'table') {
                            $io->success('✅ ' . count($openOrders) . ' ordre(s) trouvé(s)');
                            $rows = [];
                            foreach ($openOrders as $order) {
                                $rows[] = [
                                    $order->orderId,
                                    $order->symbol,
                                    $order->side->value,
                                    $order->type->value,
                                    $order->status->value,
                                    number_format($order->quantity->toScale(8, RoundingMode::DOWN)->toFloat(), 4),
                                    $order->price ? number_format($order->price->toScale(8, RoundingMode::DOWN)->toFloat(), 2) : 'N/A',
                                    $order->stopPrice ? number_format($order->stopPrice->toScale(8, RoundingMode::DOWN)->toFloat(), 2) : 'N/A',
                                    number_format($order->filledQuantity->toScale(8, RoundingMode::DOWN)->toFloat(), 4),
                                    number_format($order->remainingQuantity->toScale(8, RoundingMode::DOWN)->toFloat(), 4),
                                    $order->averagePrice ? number_format($order->averagePrice->toScale(8, RoundingMode::DOWN)->toFloat(), 2) : 'N/A',
                                    $order->createdAt->format('Y-m-d H:i:s'),
                                    $order->updatedAt?->format('Y-m-d H:i:s') ?? 'N/A',
                                    $order->filledAt?->format('Y-m-d H:i:s') ?? 'N/A',
                                    json_encode($order->metadata, JSON_UNESCAPED_UNICODE),
                                ];
                            }
                            $io->table(
                                [
                                    'Order ID',
                                    'Symbole',
                                    'Side',
                                    'Type',
                                    'Status',
                                    'Quantité',
                                    'Prix',
                                    'Stop Price',
                                    'Quantité remplie',
                                    'Quantité restante',
                                    'Prix moyen',
                                    'Créé le',
                                    'Mis à jour le',
                                    'Rempli le',
                                    'Metadata',
                                ],
                                $rows
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $errorMsg = 'Erreur lors de la récupération des ordres ouverts: ' . $e->getMessage();
                    $io->warning($errorMsg);
                    if ($this->logger) {
                        $this->logger->error('[ListOpenPositionsOrdersCommand] Failed to fetch open orders', [
                            'symbol' => $symbol,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                    $data['orders_error'] = $e->getMessage();
                }
                
                // 3. Récupération des ordres planifiés (TP/SL)
                // Note: getPlanOrders() est spécifique à BitmartOrderProvider
                $io->section('Récupération des ordres planifiés (TP/SL)...');
                try {
                    $planOrders = [];
                    // Vérifier si le provider supporte getPlanOrders (méthode spécifique BitMart)
                    if ($orderProvider instanceof \App\Provider\Bitmart\BitmartOrderProvider) {
                        /** @var \App\Provider\Bitmart\BitmartOrderProvider $orderProvider */
                        $orderProvider = $orderProvider;
                        $planOrders = $orderProvider->getPlanOrders($symbol);
                    } else {
                        $io->note('Le provider ne supporte pas la récupération des ordres planifiés (TP/SL)');
                        $data['plan_orders'] = [];
                    }
                    
                    if (empty($planOrders)) {
                        $io->note('Aucun ordre planifié (TP/SL)' . ($symbol ? " pour $symbol" : ''));
                        $data['plan_orders'] = [];
                    } else {
                        $data['plan_orders'] = $planOrders;
                        
                        if ($format === 'table') {
                            $io->success('✅ ' . count($planOrders) . ' ordre(s) planifié(s) trouvé(s)');
                            $rows = [];
                            foreach ($planOrders as $order) {
                                $rows[] = [
                                    $order['plan_order_id'] ?? $order['order_id'] ?? 'N/A',
                                    $order['symbol'] ?? 'N/A',
                                    $order['side'] ?? 'N/A',
                                    $order['type'] ?? $order['order_type'] ?? 'N/A',
                                    $order['trigger_price'] ?? 'N/A',
                                    $order['exec_price'] ?? $order['price'] ?? 'N/A',
                                    $order['size'] ?? $order['quantity'] ?? 'N/A',
                                    $order['status'] ?? 'N/A',
                                    isset($order['create_time']) ? date('Y-m-d H:i:s', (int)($order['create_time'] / 1000)) : 'N/A',
                                ];
                            }
                            $io->table(
                                [
                                    'Plan Order ID',
                                    'Symbole',
                                    'Side',
                                    'Type',
                                    'Prix déclencheur',
                                    'Prix exécution',
                                    'Taille',
                                    'Status',
                                    'Créé le',
                                ],
                                $rows
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $errorMsg = 'Erreur lors de la récupération des ordres planifiés: ' . $e->getMessage();
                    $io->warning($errorMsg);
                    if ($this->logger) {
                        $this->logger->error('[ListOpenPositionsOrdersCommand] Failed to fetch plan orders', [
                            'symbol' => $symbol,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                    $data['plan_orders_error'] = $e->getMessage();
                }
            } else {
                $io->note('OrderProvider non disponible');
                $data['orders_error'] = 'OrderProvider not available';
            }

            // Affichage JSON si demandé
            if ($format === 'json') {
                $io->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            // Résumé
            if ($format === 'table') {
                $io->newLine();
                $io->section('Résumé');
                $summaryRows = [
                    ['Positions ouvertes', count($data['positions'])],
                    ['Ordres ouverts', count($data['orders'])],
                    ['Ordres planifiés (TP/SL)', count($data['plan_orders'] ?? [])],
                ];
                if (isset($data['positions_error'])) {
                    $summaryRows[] = ['Erreur positions', $data['positions_error']];
                }
                if (isset($data['orders_error'])) {
                    $summaryRows[] = ['Erreur ordres', $data['orders_error']];
                }
                if (isset($data['plan_orders_error'])) {
                    $summaryRows[] = ['Erreur ordres planifiés', $data['plan_orders_error']];
                }
                $io->table(['Type', 'Nombre'], $summaryRows);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur fatale: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln('Trace: ' . $e->getTraceAsString());
            }
            if ($this->logger) {
                $this->logger->error('[ListOpenPositionsOrdersCommand] Fatal error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            if ($format === 'json') {
                $io->writeln(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
            }

            return Command::FAILURE;
        }
    }
}
