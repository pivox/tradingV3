<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Provider\Bitmart\BitmartAccountProvider;
use Brick\Math\RoundingMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-account',
    description: 'Récupère les informations du compte BitMart (solde, positions, trades, fees)'
)]
class GetAccountCommand extends Command
{
    public function __construct(
        private readonly BitmartAccountProvider $accountProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('currency', 'c', InputOption::VALUE_REQUIRED, 'Devise du compte', 'USDT')
            ->addOption('positions', 'p', InputOption::VALUE_NONE, 'Afficher les positions ouvertes')
            ->addOption('trades', 't', InputOption::VALUE_NONE, 'Afficher l\'historique des trades du mois courant')
            ->addOption('fees', 'f', InputOption::VALUE_NONE, 'Afficher les frais de trading')
            ->addOption('symbol', 's', InputOption::VALUE_REQUIRED, 'Symbole pour filtrer positions/trades/fees (optionnel pour trades)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format de sortie (table|json)', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limite de trades à afficher par symbole', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $currency = $input->getOption('currency');
        $showPositions = $input->getOption('positions');
        $showTrades = $input->getOption('trades');
        $showFees = $input->getOption('fees');
        $symbol = $input->getOption('symbol');
        $format = $input->getOption('format');
        $limit = (int) $input->getOption('limit');

        if (!in_array($format, ['table', 'json'])) {
            $io->error("Format invalide. Utilisez 'table' ou 'json'.");
            return Command::FAILURE;
        }

        try {
            $data = [];

            // 1. Récupération des informations du compte
            $io->section('Récupération des informations du compte...');
            $accountInfo = $this->accountProvider->getAccountInfo($currency);

            if ($accountInfo === null) {
                $io->warning("Aucune information de compte trouvée pour la devise $currency");
                if ($format === 'json') {
                    $io->writeln(json_encode(['error' => 'No account info found'], JSON_PRETTY_PRINT));
                }
                return Command::FAILURE;
            }

            $data['account'] = [
                'currency' => $accountInfo->currency,
                'available_balance' => $accountInfo->availableBalance->toScale(8, RoundingMode::DOWN)->toFloat(),
                'frozen_balance' => $accountInfo->frozenBalance->toScale(8, RoundingMode::DOWN)->toFloat(),
                'unrealized' => $accountInfo->unrealized->toScale(8, RoundingMode::DOWN)->toFloat(),
                'equity' => $accountInfo->equity->toScale(8, RoundingMode::DOWN)->toFloat(),
                'position_deposit' => $accountInfo->positionDeposit->toScale(8, RoundingMode::DOWN)->toFloat(),
            ];

            if ($format === 'table') {
                $io->success('✅ Informations du compte récupérées');
                $io->table(
                    ['Propriété', 'Valeur'],
                    [
                        ['Devise', $accountInfo->currency],
                        ['Solde disponible', number_format($accountInfo->availableBalance->toScale(2, RoundingMode::DOWN)->toFloat(), 2) . ' ' . $accountInfo->currency],
                        ['Solde gelé', number_format($accountInfo->frozenBalance->toScale(2, RoundingMode::DOWN)->toFloat(), 2) . ' ' . $accountInfo->currency],
                        ['PnL non réalisé', number_format($accountInfo->unrealized->toScale(2, RoundingMode::DOWN)->toFloat(), 2) . ' ' . $accountInfo->currency],
                        ['Equity', number_format($accountInfo->equity->toScale(2, RoundingMode::DOWN)->toFloat(), 2) . ' ' . $accountInfo->currency],
                        ['Dépôt de position', number_format($accountInfo->positionDeposit->toScale(2, RoundingMode::DOWN)->toFloat(), 2) . ' ' . $accountInfo->currency],
                    ]
                );
            }

            // 2. Positions ouvertes (si demandé)
            if ($showPositions) {
                $io->section('Récupération des positions ouvertes...');
                $positions = $this->accountProvider->getOpenPositions($symbol);

                if (empty($positions)) {
                    $io->note('Aucune position ouverte');
                    $data['positions'] = [];
                } else {
                    $data['positions'] = array_map(function ($position) {
                        return [
                            'symbol' => $position->symbol,
                            'side' => $position->side,
                            'leverage' => $position->leverage,
                            'volume' => $position->volume->toScale(8, RoundingMode::DOWN)->toFloat(),
                            'entry_price' => $position->entryPrice->toScale(8, RoundingMode::DOWN)->toFloat(),
                            'current_price' => $position->currentPrice->toScale(8, RoundingMode::DOWN)->toFloat(),
                            'unrealized_pnl' => $position->unrealizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(),
                            'realized_pnl' => $position->realizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(),
                        ];
                    }, $positions);

                    if ($format === 'table') {
                        $io->success('✅ ' . count($positions) . ' position(s) trouvée(s)');
                        $rows = [];
                        foreach ($positions as $position) {
                            $rows[] = [
                                $position->symbol,
                                $position->side,
                                $position->leverage . 'x',
                                number_format($position->volume->toScale(8, RoundingMode::DOWN)->toFloat(), 4),
                                number_format($position->entryPrice->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                number_format($position->currentPrice->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                number_format($position->unrealizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                                number_format($position->realizedPnl->toScale(8, RoundingMode::DOWN)->toFloat(), 2),
                            ];
                        }
                        $io->table(
                            ['Symbole', 'Side', 'Levier', 'Volume', 'Prix entrée', 'Prix actuel', 'PnL non réalisé', 'PnL réalisé'],
                            $rows
                        );
                    }
                }
            }

            // 3. Historique des trades (si demandé)
            if ($showTrades) {
                $io->section('Récupération de l\'historique des trades...');
                
                // Filtrer par mois courant
                $currentMonth = (int) date('n');
                $currentYear = (int) date('Y');
                
                if ($symbol === null) {
                    // Récupérer tous les trades de tous les symboles
                    $allTrades = $this->accountProvider->getAllTradeHistory($limit);
                    
                    if (empty($allTrades)) {
                        $io->note('Aucun trade trouvé');
                        $data['trades'] = [];
                    } else {
                        // Aplatir et filtrer par mois courant
                        $filteredTrades = [];
                        foreach ($allTrades as $symbolName => $trades) {
                            foreach ($trades as $trade) {
                                if (isset($trade['create_time'])) {
                                    $tradeTimestamp = $trade['create_time'] / 1000;
                                    $tradeMonth = (int) date('n', (int) $tradeTimestamp);
                                    $tradeYear = (int) date('Y', (int) $tradeTimestamp);
                                    
                                    if ($tradeMonth === $currentMonth && $tradeYear === $currentYear) {
                                        $trade['symbol'] = $symbolName;
                                        $filteredTrades[] = $trade;
                                    }
                                }
                            }
                        }
                        
                        // Trier par date décroissante
                        usort($filteredTrades, function($a, $b) {
                            return ($b['create_time'] ?? 0) <=> ($a['create_time'] ?? 0);
                        });
                        
                        $data['trades'] = $filteredTrades;
                        
                        if ($format === 'table') {
                            $io->success('✅ ' . count($filteredTrades) . ' trade(s) du mois courant trouvé(s)');
                            $rows = [];
                            foreach ($filteredTrades as $trade) {
                                $rows[] = [
                                    $trade['symbol'] ?? 'N/A',
                                    $trade['order_id'] ?? 'N/A',
                                    $trade['trade_id'] ?? 'N/A',
                                    $trade['side'] ?? 'N/A',
                                    $trade['price'] ?? 'N/A',
                                    $trade['vol'] ?? 'N/A',
                                    $trade['exec_type'] ?? 'N/A',
                                    isset($trade['create_time']) ? date('Y-m-d H:i:s', (int) ($trade['create_time'] / 1000)) : 'N/A',
                                ];
                            }
                            $io->table(
                                ['Symbole', 'Order ID', 'Trade ID', 'Side', 'Prix', 'Volume', 'Type', 'Date'],
                                $rows
                            );
                        }
                    }
                } else {
                    // Récupérer les trades pour un symbole spécifique
                    $trades = $this->accountProvider->getTradeHistory($symbol, $limit);
                    
                    // Filtrer par mois courant
                    $filteredTrades = array_filter($trades, function($trade) use ($currentMonth, $currentYear) {
                        if (isset($trade['create_time'])) {
                            $tradeTimestamp = $trade['create_time'] / 1000;
                            $tradeMonth = (int) date('n', (int) $tradeTimestamp);
                            $tradeYear = (int) date('Y', (int) $tradeTimestamp);
                            return $tradeMonth === $currentMonth && $tradeYear === $currentYear;
                        }
                        return false;
                    });
                    
                    $filteredTrades = array_values($filteredTrades);

                    if (empty($filteredTrades)) {
                        $io->note('Aucun trade du mois courant trouvé pour ' . $symbol);
                        $data['trades'] = [];
                    } else {
                        $data['trades'] = $filteredTrades;

                        if ($format === 'table') {
                            $io->success('✅ ' . count($filteredTrades) . ' trade(s) du mois courant trouvé(s) pour ' . $symbol);
                            $rows = [];
                            foreach ($filteredTrades as $trade) {
                                $rows[] = [
                                    $trade['order_id'] ?? 'N/A',
                                    $trade['trade_id'] ?? 'N/A',
                                    $trade['side'] ?? 'N/A',
                                    $trade['price'] ?? 'N/A',
                                    $trade['vol'] ?? 'N/A',
                                    $trade['exec_type'] ?? 'N/A',
                                    isset($trade['create_time']) ? date('Y-m-d H:i:s', (int) ($trade['create_time'] / 1000)) : 'N/A',
                                ];
                            }
                            $io->table(
                                ['Order ID', 'Trade ID', 'Side', 'Prix', 'Volume', 'Type', 'Date'],
                                $rows
                            );
                        }
                    }
                }
            }

            // 4. Frais de trading (si demandé)
            if ($showFees) {
                if ($symbol === null) {
                    $io->warning('Option --fees requiert --symbol');
                } else {
                    $io->section('Récupération des frais de trading...');
                    $fees = $this->accountProvider->getTradingFees($symbol);

                    if (empty($fees)) {
                        $io->note('Aucune information de frais trouvée');
                        $data['fees'] = [];
                    } else {
                        $data['fees'] = $fees;

                        if ($format === 'table') {
                            $io->success('✅ Frais de trading récupérés');
                            $rows = [];
                            foreach ($fees as $key => $value) {
                                $rows[] = [$key, $value];
                            }
                            $io->table(
                                ['Propriété', 'Valeur'],
                                $rows
                            );
                        }
                    }
                }
            }

            // Affichage JSON si demandé
            if ($format === 'json') {
                $io->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de la récupération des données: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln('Trace: ' . $e->getTraceAsString());
            }
            
            if ($format === 'json') {
                $io->writeln(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
            }
            
            return Command::FAILURE;
        }
    }
}

