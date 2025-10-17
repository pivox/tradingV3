<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Common\Enum\Timeframe;
use App\Domain\Indicator\Service\IndicatorEngine;
use App\Domain\Kline\Service\KlineFetcher;
use App\Domain\Mtf\Service\MtfService;
use App\Domain\PostValidation\Service\MarketDataProvider;
use App\Repository\SignalRepository;
use App\Repository\KlineRepository;
use App\Service\Indicator\HybridIndicatorService;
use App\Service\Indicator\SqlIndicatorService;
use App\Service\Indicator\PhpIndicatorService;
use App\Service\TradingConfigService;
use App\Signal\SignalValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:diagnose-mtf-signals',
    description: 'Diagnostique les signaux MTF - analyse les 10 derniers signaux 5m et recalcule les indicateurs'
)]
class DiagnoseMtfSignalsCommand extends Command
{
    public function __construct(
        private readonly SignalRepository $signalRepository,
        private readonly KlineRepository $klineRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HybridIndicatorService $indicatorService,
        private readonly SqlIndicatorService $sqlIndicatorService,
        private readonly PhpIndicatorService $phpIndicatorService,
        private readonly SignalValidationService $signalValidationService,
        private readonly MtfService $mtfService,
        private readonly TradingConfigService $tradingConfigService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_REQUIRED, 'Symbole à analyser', 'BTCUSDT')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de signaux à analyser', 10)
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe principal à analyser', '5m')
            ->addOption('output-format', 'f', InputOption::VALUE_OPTIONAL, 'Format de sortie (json|table)', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $limit = (int) $input->getOption('limit');
        $timeframe = $input->getOption('timeframe');
        $outputFormat = $input->getOption('output-format');

        $io->title("🔍 Diagnostic MTF - Analyse des signaux pour $symbol");

        try {
            // 1. Récupérer les 10 derniers signaux 5m
            $io->section('📊 Récupération des signaux 5m');
            $signals = $this->getRecentSignals($symbol, $timeframe, $limit);
            
            if (empty($signals)) {
                $io->error("Aucun signal trouvé pour $symbol sur le timeframe $timeframe");
                return Command::FAILURE;
            }

            $io->success(sprintf('Trouvé %d signaux récents', count($signals)));

            // 2. Analyser chaque signal
            $analysisResults = [];
            foreach ($signals as $index => $signal) {
                $io->section(sprintf('🔬 Analyse du signal #%d', $index + 1));
                
                $analysis = $this->analyzeSignal($signal, $io);
                $analysisResults[] = $analysis;
            }

            // 3. Afficher les résultats
            $this->displayResults($analysisResults, $io, $outputFormat);

            // 4. Résumé et recommandations
            $this->displaySummary($analysisResults, $io);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors du diagnostic: ' . $e->getMessage());
            $this->logger->error('Erreur dans DiagnoseMtfSignalsCommand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function getRecentSignals(string $symbol, string $timeframe, int $limit): array
    {
        $timeframeEnum = match($timeframe) {
            '1m' => Timeframe::TF_1M,
            '5m' => Timeframe::TF_5M,
            '15m' => Timeframe::TF_15M,
            '1h' => Timeframe::TF_1H,
            '4h' => Timeframe::TF_4H,
            default => throw new \InvalidArgumentException("Timeframe invalide: $timeframe")
        };

        return $this->signalRepository->findRecentSignals($symbol, $timeframeEnum, $limit);
    }

    private function analyzeSignal($signal, SymfonyStyle $io): array
    {
        $symbol = $signal->getSymbol();
        $timeframe = $signal->getTimeframe()->value;
        $klineTime = $signal->getKlineTime();
        $side = $signal->getSide();
        $score = $signal->getScore();

        $io->text(sprintf(
            'Signal: %s | Timeframe: %s | Side: %s | Score: %.2f | Date: %s',
            $symbol,
            $timeframe,
            $side->value,
            $score,
            $klineTime->format('Y-m-d H:i:s')
        ));

        $analysis = [
            'signal_id' => $signal->getId(),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'side' => $side,
            'side_value' => $side->value,
            'score' => $score,
            'kline_time' => $klineTime,
            'indicators' => [],
            'parent_analysis' => [],
            'rule_validation' => [],
            'issues' => []
        ];

        // 1. Récupérer et analyser les klines
        $klines = $this->getKlinesForSignal($symbol, $timeframe, $klineTime);
        if (empty($klines)) {
            $analysis['issues'][] = 'Aucune kline trouvée pour cette période';
            return $analysis;
        }

        // 2. Calculer les indicateurs techniques
        $analysis['indicators'] = $this->calculateIndicatorsForSignal($symbol, $timeframe, $klines);

        // 3. Appliquer les règles de validation
        $analysis['rule_validation'] = $this->validateRulesForSignal($symbol, $timeframe, $klines);

        // 4. Analyser les timeframes parents
        $analysis['parent_analysis'] = $this->analyzeParentTimeframes($symbol, $klineTime);

        return $analysis;
    }

    private function getKlinesForSignal(string $symbol, string $timeframe, \DateTimeImmutable $klineTime): array
    {
        $timeframeEnum = match($timeframe) {
            '1m' => Timeframe::TF_1M,
            '5m' => Timeframe::TF_5M,
            '15m' => Timeframe::TF_15M,
            '1h' => Timeframe::TF_1H,
            '4h' => Timeframe::TF_4H,
            default => throw new \InvalidArgumentException("Timeframe invalide: $timeframe")
        };

        // Récupérer les klines nécessaires pour le calcul des indicateurs
        $config = $this->tradingConfigService->getConfig();
        $minBars = $config['timeframes'][$timeframe]['guards']['min_bars'] ?? 200;
        
        return $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframeEnum, $minBars);
    }

    private function calculateIndicatorsForSignal(string $symbol, string $timeframe, array $klines): array
    {
        try {
            $results = [
                'sql' => null,
                'php' => null,
                'comparison' => []
            ];

            // 1. Essayer SQL d'abord
            if ($this->sqlIndicatorService->hasData($symbol, $timeframe)) {
                $results['sql'] = $this->getIndicatorsFromSql($symbol, $timeframe, $klines);
            } else {
                $results['sql'] = ['error' => 'Aucune donnée dans les vues matérialisées SQL'];
            }

            // 2. Toujours calculer en PHP pour comparaison
            $results['php'] = $this->getIndicatorsFromPhp($symbol, $timeframe, $klines);

            // 3. Comparer les résultats si les deux sont disponibles
            if (!isset($results['sql']['error']) && !isset($results['php']['error']) && 
                $results['sql'] !== null && $results['php'] !== null &&
                is_array($results['sql']) && is_array($results['php'])) {
                /** @var array $sqlResults */
                $sqlResults = $results['sql'];
                /** @var array $phpResults */
                $phpResults = $results['php'];
                $results['comparison'] = $this->compareIndicators($sqlResults, $phpResults);
            }

            return $results;

        } catch (\Exception $e) {
            $this->logger->error('Erreur calcul indicateurs', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    private function getIndicatorsFromSql(string $symbol, string $timeframe, array $klines): array
    {
        try {
            // Récupérer tous les indicateurs depuis les vues matérialisées
            $allIndicators = $this->sqlIndicatorService->getAllIndicators($symbol, $timeframe, 1);
            
            if (empty($allIndicators)) {
                throw new \Exception('Aucun indicateur trouvé dans les vues matérialisées');
            }

            // Extraire les valeurs des indicateurs
            $ema = $allIndicators['ema'][0] ?? [];
            $rsi = $allIndicators['rsi'][0] ?? [];
            $macd = $allIndicators['macd'][0] ?? [];
            $vwap = $allIndicators['vwap'][0] ?? [];
            $bollinger = $allIndicators['bollinger'][0] ?? [];

            // Prix actuel depuis la dernière kline
            $currentPrice = !empty($klines) ? end($klines)->getClosePriceFloat() : null;

            return [
                'source' => 'SQL_MATERIALIZED_VIEWS',
                'ema_9' => $ema['ema9'] ?? null,
                'ema_20' => $ema['ema21'] ?? null, // Note: la vue utilise ema21 au lieu de ema20
                'ema_50' => $ema['ema50'] ?? null,
                'ema_200' => $ema['ema200'] ?? null,
                'rsi' => $rsi['rsi'] ?? null,
                'macd' => $macd['macd'] ?? null,
                'macd_signal' => $macd['signal'] ?? null,
                'macd_histogram' => $macd['histogram'] ?? null,
                'vwap' => $vwap['vwap'] ?? null,
                'bollinger_upper' => $bollinger['upper_band'] ?? null,
                'bollinger_middle' => $bollinger['middle_band'] ?? null,
                'bollinger_lower' => $bollinger['lower_band'] ?? null,
                'current_price' => $currentPrice,
                'bucket_time' => $ema['bucket'] ?? null
            ];

        } catch (\Exception $e) {
            $this->logger->warning('Erreur récupération indicateurs SQL, fallback vers PHP', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage()
            ]);
            return $this->getIndicatorsFromPhp($symbol, $timeframe, $klines);
        }
    }

    private function getIndicatorsFromPhp(string $symbol, string $timeframe, array $klines): array
    {
        $timeframeEnum = match($timeframe) {
            '1m' => Timeframe::TF_1M,
            '5m' => Timeframe::TF_5M,
            '15m' => Timeframe::TF_15M,
            '1h' => Timeframe::TF_1H,
            '4h' => Timeframe::TF_4H,
            default => throw new \InvalidArgumentException("Timeframe invalide: $timeframe")
        };

        // Convertir les entités Kline en DTOs
        $klineDtos = array_map(function($kline) {
            return new \App\Domain\Common\Dto\KlineDto(
                symbol: $kline->getSymbol(),
                timeframe: $kline->getTimeframe(),
                openTime: $kline->getOpenTime(),
                open: \Brick\Math\BigDecimal::of($kline->getOpenPriceFloat()),
                high: \Brick\Math\BigDecimal::of($kline->getHighPriceFloat()),
                low: \Brick\Math\BigDecimal::of($kline->getLowPriceFloat()),
                close: \Brick\Math\BigDecimal::of($kline->getClosePriceFloat()),
                volume: \Brick\Math\BigDecimal::of($kline->getVolume()->toFloat())
            );
        }, $klines);

        // Calculer les indicateurs via le service PHP
        $snapshot = $this->phpIndicatorService->calculateIndicators($symbol, $timeframeEnum, $klineDtos);

        return [
            'source' => 'PHP_CALCULATION',
            'ema_20' => $snapshot->ema20?->toScale(12)->__toString(),
            'ema_50' => $snapshot->ema50?->toScale(12)->__toString(),
            'ema_200' => null, // Pas disponible dans le DTO actuel
            'rsi' => $snapshot->rsi,
            'macd' => $snapshot->macd?->toScale(12)->__toString(),
            'macd_signal' => $snapshot->macdSignal?->toScale(12)->__toString(),
            'macd_histogram' => $snapshot->macdHistogram?->toScale(12)->__toString(),
            'vwap' => $snapshot->vwap?->toScale(12)->__toString(),
            'atr' => $snapshot->atr?->toScale(12)->__toString(),
            'bollinger_upper' => $snapshot->bbUpper?->toScale(12)->__toString(),
            'bollinger_middle' => $snapshot->bbMiddle?->toScale(12)->__toString(),
            'bollinger_lower' => $snapshot->bbLower?->toScale(12)->__toString(),
            'current_price' => end($klineDtos)->close ?? null
        ];
    }

    private function compareIndicators(array $sqlResults, array $phpResults): array
    {
        $comparison = [];
        $tolerance = 0.0001; // Tolérance pour la comparaison des valeurs numériques

        // Liste des indicateurs à comparer
        $indicatorsToCompare = [
            'ema_20', 'ema_50', 'rsi', 'macd', 'macd_signal', 'macd_histogram', 
            'vwap', 'bollinger_upper', 'bollinger_middle', 'bollinger_lower'
        ];

        foreach ($indicatorsToCompare as $indicator) {
            $sqlValue = $sqlResults[$indicator] ?? null;
            $phpValue = $phpResults[$indicator] ?? null;

            if ($sqlValue === null && $phpValue === null) {
                $comparison[$indicator] = [
                    'status' => 'both_null',
                    'sql' => null,
                    'php' => null,
                    'difference' => 0,
                    'match' => true
                ];
            } elseif ($sqlValue === null || $phpValue === null) {
                $comparison[$indicator] = [
                    'status' => 'one_null',
                    'sql' => $sqlValue,
                    'php' => $phpValue,
                    'difference' => null,
                    'match' => false
                ];
            } else {
                // Conversion en float pour comparaison
                $sqlFloat = is_numeric($sqlValue) ? (float)$sqlValue : null;
                $phpFloat = is_numeric($phpValue) ? (float)$phpValue : null;

                if ($sqlFloat !== null && $phpFloat !== null) {
                    $difference = abs($sqlFloat - $phpFloat);
                    $match = $difference <= $tolerance;
                    
                    $comparison[$indicator] = [
                        'status' => 'both_values',
                        'sql' => $sqlValue,
                        'php' => $phpValue,
                        'difference' => $difference,
                        'match' => $match
                    ];
                } else {
                    $comparison[$indicator] = [
                        'status' => 'non_numeric',
                        'sql' => $sqlValue,
                        'php' => $phpValue,
                        'difference' => null,
                        'match' => false
                    ];
                }
            }
        }

        // Statistiques globales
        $totalIndicators = count($indicatorsToCompare);
        $matchingIndicators = count(array_filter($comparison, fn($c) => $c['match']));
        $mismatchingIndicators = $totalIndicators - $matchingIndicators;

        $comparison['_summary'] = [
            'total_indicators' => $totalIndicators,
            'matching' => $matchingIndicators,
            'mismatching' => $mismatchingIndicators,
            'match_percentage' => $totalIndicators > 0 ? round(($matchingIndicators / $totalIndicators) * 100, 2) : 0
        ];

        return $comparison;
    }

    private function displayIndicatorTable(SymfonyStyle $io, array $indicators): void
    {
        $io->table(
            ['Indicateur', 'Valeur'],
            [
                ['EMA 9', $indicators['ema_9'] ?? 'N/A'],
                ['EMA 20', $indicators['ema_20'] ?? 'N/A'],
                ['EMA 50', $indicators['ema_50'] ?? 'N/A'],
                ['EMA 200', $indicators['ema_200'] ?? 'N/A'],
                ['RSI', $indicators['rsi'] ?? 'N/A'],
                ['MACD', $indicators['macd'] ?? 'N/A'],
                ['MACD Signal', $indicators['macd_signal'] ?? 'N/A'],
                ['MACD Histogram', $indicators['macd_histogram'] ?? 'N/A'],
                ['VWAP', $indicators['vwap'] ?? 'N/A'],
                ['ATR', $indicators['atr'] ?? 'N/A'],
                ['BB Upper', $indicators['bollinger_upper'] ?? 'N/A'],
                ['BB Middle', $indicators['bollinger_middle'] ?? 'N/A'],
                ['BB Lower', $indicators['bollinger_lower'] ?? 'N/A'],
                ['Prix actuel', $indicators['current_price'] ?? 'N/A']
            ]
        );
    }

    private function displayComparison(SymfonyStyle $io, array $comparison): void
    {
        $summary = $comparison['_summary'] ?? [];
        $matchPercentage = $summary['match_percentage'] ?? 0;
        
        $io->text("🔍 Comparaison SQL vs PHP:");
        
        // Résumé global
        $io->definitionList(
            ['Indicateurs comparés' => $summary['total_indicators'] ?? 0],
            ['Correspondances' => $summary['matching'] ?? 0],
            ['Différences' => $summary['mismatching'] ?? 0],
            ['Taux de correspondance' => $matchPercentage . '%']
        );

        // Tableau de comparaison détaillé
        $comparisonRows = [];
        foreach ($comparison as $indicator => $data) {
            if ($indicator === '_summary') continue;
            
            $status = match($data['status']) {
                'both_null' => '✅ N/A',
                'one_null' => '⚠️ Partiel',
                'both_values' => $data['match'] ? '✅ OK' : '❌ Diff',
                'non_numeric' => '⚠️ Non-num',
                default => '❓ Inconnu'
            };
            
            $difference = $data['difference'] !== null ? 
                sprintf('%.6f', $data['difference']) : 'N/A';
            
            $comparisonRows[] = [
                ucfirst(str_replace('_', ' ', $indicator)),
                $data['sql'] ?? 'N/A',
                $data['php'] ?? 'N/A',
                $difference,
                $status
            ];
        }

        $io->table(
            ['Indicateur', 'SQL', 'PHP', 'Différence', 'Statut'],
            $comparisonRows
        );

        // Recommandations
        if ($matchPercentage >= 95) {
            $io->success("✅ Excellente cohérence entre SQL et PHP ($matchPercentage%)");
        } elseif ($matchPercentage >= 80) {
            $io->warning("⚠️ Bonne cohérence mais quelques différences ($matchPercentage%)");
        } else {
            $io->error("❌ Incohérences importantes détectées ($matchPercentage%)");
        }
    }

    private function validateRulesForSignal(string $symbol, string $timeframe, array $klines): array
    {
        try {
            $config = $this->tradingConfigService->getConfig();
            $rules = $config['validation']['timeframe'][$timeframe] ?? [];
            
            if (empty($rules)) {
                return ['error' => 'Aucune règle de validation trouvée pour ce timeframe'];
            }

            // Utiliser directement les entités Kline pour la validation
            $klineEntities = $klines;

            // Créer un contrat fictif pour la validation
            $contract = new \App\Entity\Contract();
            $contract->setSymbol($symbol);

            // Appliquer la validation
            $result = $this->signalValidationService->validate($timeframe, $klineEntities, [], $contract);

            return [
                'long_rules' => $rules['long'] ?? [],
                'short_rules' => $rules['short'] ?? [],
                'validation_result' => $result,
                'signal' => $result['signals'][$timeframe]['signal'] ?? 'NONE',
                'conditions_long' => $result['signals'][$timeframe]['conditions_long'] ?? [],
                'conditions_short' => $result['signals'][$timeframe]['conditions_short'] ?? []
            ];

        } catch (\Exception $e) {
            $this->logger->error('Erreur validation règles', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    private function analyzeParentTimeframes(string $symbol, \DateTimeImmutable $klineTime): array
    {
        $parentAnalysis = [];
        $parentTimeframes = ['15m', '1h', '4h'];

        foreach ($parentTimeframes as $parentTf) {
            try {
                $timeframeEnum = match($parentTf) {
                    '15m' => Timeframe::TF_15M,
                    '1h' => Timeframe::TF_1H,
                    '4h' => Timeframe::TF_4H,
                    default => null
                };

                if (!$timeframeEnum) continue;

                // Récupérer les klines du timeframe parent
                $parentKlines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframeEnum, 200);
                
                if (empty($parentKlines)) {
                    $parentAnalysis[$parentTf] = ['error' => 'Aucune kline trouvée'];
                    continue;
                }

                // Calculer les indicateurs pour le timeframe parent (comparaison SQL vs PHP)
                $indicators = $this->calculateIndicatorsForSignal($symbol, $parentTf, $parentKlines);

                // Appliquer les règles de validation du timeframe parent
                $klineEntities = $parentKlines;

                $contract = new \App\Entity\Contract();
                $contract->setSymbol($symbol);

                $validationResult = $this->signalValidationService->validate($parentTf, $klineEntities, [], $contract);

                $parentAnalysis[$parentTf] = [
                    'indicators' => $indicators,
                    'validation' => [
                        'signal' => $validationResult['signals'][$parentTf]['signal'] ?? 'NONE',
                        'conditions_long' => $validationResult['signals'][$parentTf]['conditions_long'] ?? [],
                        'conditions_short' => $validationResult['signals'][$parentTf]['conditions_short'] ?? []
                    ]
                ];

            } catch (\Exception $e) {
                $parentAnalysis[$parentTf] = ['error' => $e->getMessage()];
            }
        }

        return $parentAnalysis;
    }

    private function displayResults(array $analysisResults, SymfonyStyle $io, string $format): void
    {
        if ($format === 'json') {
            $io->writeln(json_encode($analysisResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }

        // Format table
        foreach ($analysisResults as $index => $analysis) {
            $io->section(sprintf('📋 Résultat #%d - Signal %s', $index + 1, $analysis['signal_id']));
            
            // Informations de base
            $io->definitionList(
                ['Symbole' => $analysis['symbol']],
                ['Timeframe' => $analysis['timeframe']],
                ['Side' => $analysis['side_value']],
                ['Score' => $analysis['score']],
                ['Date' => $analysis['kline_time']->format('Y-m-d H:i:s')]
            );

            // Indicateurs techniques - Comparaison SQL vs PHP
            if (!empty($analysis['indicators']) && !isset($analysis['indicators']['error'])) {
                $indicators = $analysis['indicators'];
                
                // Affichage des résultats SQL
                if (isset($indicators['sql']) && !isset($indicators['sql']['error'])) {
                    $io->text("🗄️ Indicateurs SQL (Vues matérialisées):");
                    $this->displayIndicatorTable($io, $indicators['sql']);
                    
                    if (isset($indicators['sql']['bucket_time'])) {
                        $io->text("🕐 Bucket time SQL: " . $indicators['sql']['bucket_time']);
                    }
                } elseif (isset($indicators['sql']['error'])) {
                    $io->text("❌ Erreur SQL: " . $indicators['sql']['error']);
                }

                // Affichage des résultats PHP
                if (isset($indicators['php']) && !isset($indicators['php']['error'])) {
                    $io->text("⚙️ Indicateurs PHP (Calcul en temps réel):");
                    $this->displayIndicatorTable($io, $indicators['php']);
                } elseif (isset($indicators['php']['error'])) {
                    $io->text("❌ Erreur PHP: " . $indicators['php']['error']);
                }

                // Comparaison des résultats
                if (isset($indicators['comparison']) && !empty($indicators['comparison'])) {
                    $this->displayComparison($io, $indicators['comparison']);
                }
            }

            // Validation des règles
            if (!empty($analysis['rule_validation']) && !isset($analysis['rule_validation']['error'])) {
                $io->text('✅ Validation des règles:');
                $validation = $analysis['rule_validation'];
                $io->table(
                    ['Règle', 'Statut'],
                    [
                        ['Signal généré', $validation['signal'] ?? 'NONE'],
                        ['Règles LONG', implode(', ', $validation['long_rules'] ?? [])],
                        ['Règles SHORT', implode(', ', $validation['short_rules'] ?? [])]
                    ]
                );
            }

            // Analyse des timeframes parents
            if (!empty($analysis['parent_analysis'])) {
                $io->text('🔗 Analyse des timeframes parents:');
                foreach ($analysis['parent_analysis'] as $parentTf => $parentData) {
                    if (isset($parentData['error'])) {
                        $io->text(sprintf('  %s: ❌ %s', $parentTf, $parentData['error']));
                    } else {
                        $signal = $parentData['validation']['signal'] ?? 'NONE';
                        $io->text(sprintf('  %s: Signal %s', $parentTf, $signal));
                    }
                }
            }

            // Problèmes identifiés
            if (!empty($analysis['issues'])) {
                $io->text('⚠️ Problèmes identifiés:');
                foreach ($analysis['issues'] as $issue) {
                    $io->text("  - $issue");
                }
            }

            $io->newLine();
        }
    }

    private function displaySummary(array $analysisResults, SymfonyStyle $io): void
    {
        $io->section('📈 Résumé et recommandations');

        $totalSignals = count($analysisResults);
        $signalsWithIssues = count(array_filter($analysisResults, fn($a) => !empty($a['issues'])));
        $signalsWithIndicators = count(array_filter($analysisResults, fn($a) => !empty($a['indicators']) && !isset($a['indicators']['error'])));

        $io->definitionList(
            ['Total signaux analysés' => $totalSignals],
            ['Signaux avec indicateurs valides' => $signalsWithIndicators],
            ['Signaux avec problèmes' => $signalsWithIssues]
        );

        // Recommandations
        if ($signalsWithIssues > 0) {
            $io->warning('Des problèmes ont été détectés dans les signaux. Vérifiez:');
            $io->listing([
                'La disponibilité des données de klines',
                'Le calcul des indicateurs techniques',
                'La configuration des règles de validation',
                'Les timeframes parents'
            ]);
        } else {
            $io->success('Aucun problème majeur détecté dans les signaux analysés.');
        }

        // Statistiques des timeframes parents
        $parentStats = [];
        foreach ($analysisResults as $analysis) {
            foreach ($analysis['parent_analysis'] as $parentTf => $parentData) {
                if (!isset($parentData['error'])) {
                    $signal = $parentData['validation']['signal'] ?? 'NONE';
                    if (!isset($parentStats[$parentTf])) {
                        $parentStats[$parentTf] = [];
                    }
                    $parentStats[$parentTf][$signal] = ($parentStats[$parentTf][$signal] ?? 0) + 1;
                }
            }
        }

        if (!empty($parentStats)) {
            $io->text('📊 Statistiques des timeframes parents:');
            foreach ($parentStats as $parentTf => $stats) {
                $io->text("  $parentTf: " . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($stats), $stats)));
            }
        }
    }
}
