<?php

declare(strict_types=1);

namespace App\Domain\Mtf\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Trading\Service\TradingDecisionService;
use App\Domain\Trading\Service\TradeContextService;
use App\Event\MtfRunCompletedEvent;
use App\Repository\MtfLockRepository;
use App\Repository\MtfSwitchRepository;
use App\Repository\ContractRepository;
use App\Logging\AsyncBusLogHandler;
use App\Logging\MessengerLogHandler;
use App\Logging\Message\LogMessage;
use App\Message\LogRecord as LogRecordMessage;
use App\Service\Indicator\SqlIndicatorService;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

class MtfRunService
{
    private bool $asyncDebugLoggingAvailable;
    private string $resolvedLogAppName;
    private string $logChannel;

    public function __construct(
        private readonly MtfService $mtfService,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfLockRepository $mtfLockRepository,
        private readonly ContractRepository $contractRepository,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsFlowLogger,
        private readonly ClockInterface $clock,
        private readonly TradingDecisionService $tradingDecisionService,
        private readonly TradeContextService $tradeContext,
        private readonly SqlIndicatorService $sqlIndicatorService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?MessageBusInterface $logMessageBus = null,
        private readonly ?string $logAppName = null,
    ) {
        $this->asyncDebugLoggingAvailable = $this->detectAsyncDebugLogging($this->logger);
        $this->resolvedLogAppName = $this->logAppName ?? 'trading-app';
        $this->logChannel = $this->resolveLoggerChannel($this->logger);
    }

    /**
     * Lance un cycle MTF en déléguant le traitement de chaque symbole à MtfService.
     * @param string[] $symbols
     * @return \Generator<int, array{symbol: string, result: array, progress: array}, array{summary: array, results: array}>
     */
    public function run(array $symbols = [], bool $dryRun = false, bool $forceRun = false, ?string $currentTf = null, bool $forceTimeframeCheck = false, bool $lockPerSymbol = false): \Generator
    {
        if ($dryRun) {
            $this->logger->info('[MTF Run] Dry run mode enabled', [
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'force_timeframe_check' => $forceTimeframeCheck,
            ]);
        }
        $startTime = microtime(true);
        $runId = Uuid::uuid4();
        $runIdString = $runId->toString();
        $this->logDebug('[MTF Run] Run invoked', [
            'run_id' => $runIdString,
            'dry_run' => $dryRun,
            'force_run' => $forceRun,
            'current_tf' => $currentTf,
            'force_timeframe_check' => $forceTimeframeCheck,
            'lock_per_symbol' => $lockPerSymbol,
            'symbols_requested_count' => count($symbols),
            'symbols_preview' => array_slice($symbols, 0, 10),
        ]);
        $lockKey = 'mtf_execution';
        $processId = $runIdString;
        $lockAcquired = false;

        try {
            $this->logger->info('[MTF Run] Start', [
                'run_id' => $runIdString,
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'force_timeframe_check' => $forceTimeframeCheck,
            ]);

            if (!$forceRun && !$this->mtfSwitchRepository->isGlobalSwitchOn()) {
                $this->logDebug('[MTF Run] Global switch OFF, aborting run', [
                    'run_id' => $runIdString,
                    'force_run' => $forceRun,
                ]);
                return [
                    'summary' => [
                        'run_id' => $runIdString,
                        'dry_run' => $dryRun,
                        'force_run' => $forceRun,
                        'current_tf' => $currentTf,
                        'status' => 'blocked_global_switch_off',
                    ],
                    'results' => [],
                ];
            }

            $lockTimeout = 600; // 10 minutes
            // Utiliser un verrou par symbole en mode worker (évite le blocage global en exécution parallèle)
            if ($lockPerSymbol && count($symbols) === 1) {
                $lockKey = 'mtf_execution:' . strtoupper((string) $symbols[0]);
            }
            $lockMetadata = json_encode([
                'run_id' => $runIdString,
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'started_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);

            $this->logDebug('[MTF Run] Attempting lock acquisition', [
                'run_id' => $runIdString,
                'lock_key' => $lockKey,
                'timeout_seconds' => $lockTimeout,
                'process_id' => $processId,
            ]);

            // Activer le verrou si nécessaire (désactivé pour le moment)
             if (!$this->mtfLockRepository->acquireLock($lockKey, $processId, $lockTimeout, $lockMetadata)) {
                 $existingLockInfo = $this->mtfLockRepository->getLockInfo($lockKey);
                 $this->logDebug('[MTF Run] Lock already held by another process', [
                     'run_id' => $runIdString,
                     'lock_key' => $lockKey,
                     'existing_lock' => $existingLockInfo,
                 ]);
                 $summary = [
                     'run_id' => $runIdString,
                     'status' => 'already_in_progress',
                     'existing_lock' => $existingLockInfo,
                     'current_tf' => $currentTf,
                     'dry_run' => $dryRun,
                     'force_run' => $forceRun,
                 ];
                 return yield from $this->yieldFinalResult($summary, [], $startTime, $runId);
             }
            $lockAcquired = true;
            $this->logger->info('[MTF Run] Lock acquired', [
                'run_id' => $runIdString,
                'lock_key' => $lockKey,
                'timeout_seconds' => $lockTimeout,
            ]);

            // Charger tous les symboles actifs si aucun n'est fourni
            if (empty($symbols)) {
                try {
                    $symbols = $this->contractRepository->allActiveSymbolNames();
                    $this->logger->info('[MTF Run] Loaded active symbols from repository', [
                        'count' => count($symbols),
                    ]);
                    $this->logDebug('[MTF Run] Active symbols loaded', [
                        'run_id' => $runIdString,
                        'symbols_count' => count($symbols),
                        'symbols_preview' => array_slice($symbols, 0, 20),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('[MTF Run] Failed to load active symbols, using fallback list', [
                        'error' => $e->getMessage(),
                    ]);
                    $this->logDebug('[MTF Run] Falling back to default symbols list', [
                        'run_id' => $runIdString,
                        'error' => $e->getMessage(),
                    ]);
                    $symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
                }
            }

            if (empty($symbols)) {
                $endTime = microtime(true);
                $executionTime = $endTime - $startTime;
                $summary = [
                    'run_id' => $runIdString,
                    'execution_time_seconds' => round($executionTime, 3),
                    'symbols_requested' => 0,
                    'symbols_processed' => 0,
                    'symbols_successful' => 0,
                    'symbols_failed' => 0,
                    'symbols_skipped' => 0,
                    'success_rate' => 0,
                    'dry_run' => $dryRun,
                    'force_run' => $forceRun,
                    'current_tf' => $currentTf,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'status' => 'no_active_symbols',
                ];
                $this->logger->info('[MTF Run] Completed - no active symbols');
                $this->logDebug('[MTF Run] Completion without symbols', [
                    'run_id' => $runIdString,
                    'execution_time_seconds' => round($executionTime, 3),
                ]);
                return yield from $this->yieldFinalResult($summary, [], $startTime, $runId);
            }

            $results = [];
            $now = $this->clock->now();
            $totalSymbols = count($symbols);

            $accountBalance = 0.0;
            $riskPercentage = 0.0;
            if (!$dryRun) {
                try {
                    $accountBalance = max(0.0, $this->tradeContext->getAccountBalance());
                    $riskPercentage = max(0.0, $this->tradeContext->getRiskPercentage());
                    $this->logDebug('[MTF Run] Trading context resolved', [
                        'run_id' => $runIdString,
                        'account_balance' => $accountBalance,
                        'risk_percentage' => $riskPercentage,
                    ]);
                } catch (Throwable $e) {
                    $this->logger->warning('[MTF Run] Unable to resolve trading context', [
                        'error' => $e->getMessage(),
                    ]);
                    $this->logDebug('[MTF Run] Trading context resolution failed', [
                        'run_id' => $runIdString,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logDebug('[MTF Run] Starting symbol iteration', [
                'run_id' => $runIdString,
                'total_symbols' => $totalSymbols,
                'dry_run' => $dryRun,
                'now' => $now->format('Y-m-d H:i:s'),
            ]);
            foreach ($symbols as $index => $symbol) {
                $this->logDebug('[MTF Run] Processing symbol', [
                    'run_id' => $runIdString,
                    'symbol' => $symbol,
                    'position' => $index + 1,
                    'total' => $totalSymbols,
                    'force_timeframe_check' => $forceTimeframeCheck,
                    'force_run' => $forceRun,
                ]);
                $mtfGenerator = $this->mtfService->runForSymbol($runId, $symbol, $now, $currentTf, $forceTimeframeCheck, $forceRun);

                // Consommer le generator de MtfService et récupérer le résultat
                $result = null;
                foreach ($mtfGenerator as $mtfYieldedData) {
                    $result = $mtfYieldedData['result'];

                    // Yield progress information avec les données de MtfService
                    $progress = [
                        'current' => $index + 1,
                        'total' => $totalSymbols,
                        'percentage' => round((($index + 1) / $totalSymbols) * 100, 2),
                        'symbol' => $symbol,
                        'status' => $result['status'] ?? 'unknown',
                        'mtf_progress' => $mtfYieldedData['progress'] ?? null,
                    ];

                    yield [
                        'symbol' => $symbol,
                        'result' => $result,
                        'progress' => $progress,
                    ];
                }

                // Récupérer le résultat final du generator
                $finalResult = $mtfGenerator->getReturn();
                $symbolResult = $finalResult ?? $result;

                if ($symbolResult !== null) {
                    $status = strtoupper((string)($symbolResult['status'] ?? ''));
                    if ($status === 'READY') {
                        if ($dryRun) {
                            try {
                                $this->positionsFlowLogger->info('[PositionsFlow] Skipped trading decision (dry_run)', [
                                    'symbol' => $symbol,
                                ]);
                            } catch (\Throwable) {}
                            $symbolResult['trading_decision'] = [
                                'status' => 'skipped',
                                'reason' => 'dry_run',
                            ];
                            $this->logDebug('[MTF Run] Trading decision skipped during dry run', [
                                'run_id' => $runIdString,
                                'symbol' => $symbol,
                            ]);
                        } elseif ($accountBalance > 0.0 && $riskPercentage > 0.0) {
                            $symbolResult = $this->maybeExecuteTradingDecision(
                                $symbol,
                                $symbolResult,
                                $accountBalance,
                                $riskPercentage
                            );
                            // Log le résultat de décision si présent
                            try {
                                if (isset($symbolResult['trading_decision']) && is_array($symbolResult['trading_decision'])) {
                                    $td = $symbolResult['trading_decision'];
                                    $statusTd = strtolower((string)($td['status'] ?? 'unknown'));
                                    $msg = match ($statusTd) {
                                        'success' => '[PositionsFlow] Trading decision executed',
                                        'skipped' => '[PositionsFlow] Trading decision skipped',
                                        'error' => '[PositionsFlow] Trading decision error',
                                        default => '[PositionsFlow] Trading decision result',
                                    };
                                    $this->positionsFlowLogger->info($msg, [
                                        'symbol' => $symbol,
                                        'status' => $td['status'] ?? null,
                                        'reason' => $td['reason'] ?? null,
                                        'error' => $td['error'] ?? null,
                                    ]);
                                }
                            } catch (\Throwable) {}
                        } else {
                            $this->logger->warning('[MTF Run] Skipping trading decision (missing trading context)', [
                                'symbol' => $symbol,
                                'account_balance' => $accountBalance,
                                'risk_percentage' => $riskPercentage,
                            ]);
                            $this->logDebug('[MTF Run] Trading decision skipped (missing trading context)', [
                                'run_id' => $runIdString,
                                'symbol' => $symbol,
                                'account_balance' => $accountBalance,
                                'risk_percentage' => $riskPercentage,
                            ]);
                            try {
                                $this->positionsFlowLogger->info('[PositionsFlow] Skipped trading decision (missing_trading_context)', [
                                    'symbol' => $symbol,
                                    'account_balance' => $accountBalance,
                                    'risk_percentage' => $riskPercentage,
                                ]);
                            } catch (\Throwable) {}
                            $symbolResult['trading_decision'] = [
                                'status' => 'skipped',
                                'reason' => 'missing_trading_context',
                            ];
                        }
                    }

                    $this->logDebug('[MTF Run] Symbol processed', [
                        'run_id' => $runIdString,
                        'symbol' => $symbol,
                        'status' => $symbolResult['status'] ?? null,
                        'execution_tf' => $symbolResult['execution_tf'] ?? null,
                        'signal_side' => $symbolResult['signal_side'] ?? null,
                        'trading_decision_status' => $symbolResult['trading_decision']['status'] ?? null,
                        'has_error' => isset($symbolResult['error']),
                    ]);
                    $results[$symbol] = $symbolResult;
                } elseif ($finalResult !== null) {
                    $this->logDebug('[MTF Run] Symbol processed using generator return', [
                        'run_id' => $runIdString,
                        'symbol' => $symbol,
                        'status' => $finalResult['status'] ?? null,
                    ]);
                    $results[$symbol] = $finalResult;
                } elseif ($result !== null) {
                    $this->logDebug('[MTF Run] Symbol processed using last yielded result', [
                        'run_id' => $runIdString,
                        'symbol' => $symbol,
                        'status' => $result['status'] ?? null,
                    ]);
                    $results[$symbol] = $result;
                }
            }

            $summary = [
                'run_id' => $runIdString,
                'execution_time_seconds' => round(microtime(true) - $startTime, 3),
                'symbols_requested' => count($symbols),
                'symbols_processed' => count($results),
                'symbols_successful' => count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'SUCCESS')),
                'symbols_failed' => count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'ERROR')),
                'symbols_skipped' => count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'SKIPPED')),
                'success_rate' => count($results) > 0 ? round((count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'SUCCESS')) / count($results)) * 100, 2) : 0.0,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s'),
                'status' => 'completed',
            ];

            // Rafraîchir les vues matérialisées après le traitement de tous les symboles
            $this->refreshMaterializedViewsAfterRun($runId);

            // Déclencher l'événement de completion
            $this->dispatchMtfRunCompletedEvent($runId, $symbols, $summary, $results, $startTime);

            $this->logger->info('[MTF Run] Completed', $summary);
            $this->logDebug('[MTF Run] Run summary prepared', array_merge($summary, [
                'symbol_keys' => array_keys($results),
            ]));
            return yield from $this->yieldFinalResult($summary, $results, $startTime, $runId);
        } catch (\Throwable $e) {
            // Force release du verrou en cas d'erreur inattendue
            try {
                $this->logger->warning('[MTF Run] Error encountered, forcing lock release', [
                    'run_id' => $runIdString,
                    'lock_key' => $lockKey,
                    'error' => $e->getMessage(),
                ]);
                $this->logDebug('[MTF Run] Exception caught during run', [
                    'run_id' => $runIdString,
                    'lock_key' => $lockKey,
                    'error' => $e->getMessage(),
                ]);
                $this->mtfLockRepository->forceReleaseLock($lockKey);
            } catch (\Throwable) {}
            throw $e;
        } finally {
            if ($lockAcquired) {
                $released = $this->mtfLockRepository->releaseLock($lockKey, $processId);
                $this->logger->info('[MTF Run] Lock released', [
                    'run_id' => $runIdString,
                    'lock_key' => $lockKey,
                    'released' => $released,
                ]);
                $this->logDebug('[MTF Run] Lock release processed', [
                    'run_id' => $runIdString,
                    'lock_key' => $lockKey,
                    'released' => $released,
                ]);
            }
        }
    }

    private function maybeExecuteTradingDecision(string $symbol, array $result, float $accountBalance, float $riskPercentage): array
    {
        $signalSideRaw = strtoupper((string)($result['signal_side'] ?? 'NONE'));
        if ($signalSideRaw === 'NONE') {
            return $result;
        }

        $executionTf = strtolower((string)($result['execution_tf'] ?? ''));
        $this->logDebug('[MTF Run] Evaluating trading decision', [
            'symbol' => $symbol,
            'signal_side' => $signalSideRaw,
            'execution_tf' => $executionTf,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
        ]);
        if ($executionTf !== '1m') {
            $this->logger->info('[MTF Run] Skipping trading decision (execution_tf not 1m)', [
                'symbol' => $symbol,
                'execution_tf' => $result['execution_tf'] ?? null,
            ]);
            try {
                $this->positionsFlowLogger->info('[PositionsFlow] Skipped trading decision (execution_tf_not_1m)', [
                    'symbol' => $symbol,
                    'execution_tf' => $result['execution_tf'] ?? null,
                ]);
            } catch (\Throwable) {}
            $result['trading_decision'] = [
                'status' => 'skipped',
                'reason' => 'execution_tf_not_1m',
                'execution_tf' => $result['execution_tf'] ?? null,
            ];
            $this->logDebug('[MTF Run] Trading decision skipped (execution timeframe mismatch)', [
                'symbol' => $symbol,
                'execution_tf' => $result['execution_tf'] ?? null,
            ]);
            return $result;
        }

        if (!isset($result['current_price'], $result['atr'])) {
            $this->logDebug('[MTF Run] Missing price or ATR, skipping trading decision', [
                'symbol' => $symbol,
                'has_price' => isset($result['current_price']),
                'has_atr' => isset($result['atr']),
            ]);
            try {
                $this->positionsFlowLogger->info('[PositionsFlow] Skipped trading decision (missing_price_or_atr)', [
                    'symbol' => $symbol,
                    'has_price' => isset($result['current_price']),
                    'has_atr' => isset($result['atr']),
                ]);
            } catch (\Throwable) {}
            return $result;
        }

        $currentPrice = (float) $result['current_price'];
        $atr = (float) $result['atr'];
        if ($currentPrice <= 0.0 || $atr <= 0.0) {
            $this->logDebug('[MTF Run] Invalid price/ATR values, skipping trading decision', [
                'symbol' => $symbol,
                'price' => $currentPrice,
                'atr' => $atr,
            ]);
            try {
                $this->positionsFlowLogger->info('[PositionsFlow] Skipped trading decision (invalid_price_or_atr)', [
                    'symbol' => $symbol,
                    'price' => $currentPrice,
                    'atr' => $atr,
                ]);
            } catch (\Throwable) {}
            return $result;
        }

        try {
            try {
                $this->positionsFlowLogger->info('[PositionsFlow] Executing trading decision', [
                    'symbol' => $symbol,
                    'execution_tf' => $executionTf,
                ]);
            } catch (\Throwable) {}
            $decision = $this->tradingDecisionService->makeTradingDecision(
                $symbol,
                SignalSide::from($signalSideRaw),
                $currentPrice,
                $atr,
                $accountBalance,
                $riskPercentage,
                $this->isHighConviction($result),
                $this->tradeContext->getTimeframeMultiplier($result['execution_tf'] ?? null)
            );

            $result['trading_decision'] = $decision;
            try {
                // Extraire infos clés pour le traçage
                $levCalc = $decision['leverage_calculation'] ?? null;
                $posOpen = $decision['position_opening'] ?? null;
                $execRes = $decision['execution_result'] ?? [];
                $mainOrder = is_array($execRes) ? ($execRes['main_order'] ?? []) : [];
                $slOrder = is_array($execRes) ? ($execRes['stop_loss_order'] ?? []) : [];
                $tpOrder = is_array($execRes) ? ($execRes['take_profit_order'] ?? []) : [];
                $tfMultiplier = $this->tradeContext->getTimeframeMultiplier($result['execution_tf'] ?? null);

                $this->positionsFlowLogger->info('[PositionsFlow] Trading decision returned', [
                    'symbol' => $symbol,
                    'status' => $decision['status'] ?? null,
                    'execution_tf' => $executionTf,
                    'atr_input' => $atr,
                    'leverage' => \is_object($levCalc) && property_exists($levCalc, 'finalLeverage') ? $levCalc->finalLeverage : null,
                    'timeframe_multiplier' => $tfMultiplier,
                    'position_size' => \is_object($posOpen) && property_exists($posOpen, 'positionSize') ? $posOpen->positionSize : null,
                    'entry_price' => \is_object($posOpen) && property_exists($posOpen, 'entryPrice') ? $posOpen->entryPrice : null,
                    'stop_loss_price' => \is_object($posOpen) && property_exists($posOpen, 'stopLossPrice') ? $posOpen->stopLossPrice : null,
                    'take_profit_price' => \is_object($posOpen) && property_exists($posOpen, 'takeProfitPrice') ? $posOpen->takeProfitPrice : null,
                    'main_order_id' => is_array($mainOrder) ? ($mainOrder['order_id'] ?? null) : null,
                    'tp_order_id' => is_array($tpOrder) ? ($tpOrder['order_id'] ?? null) : null,
                    'sl_order_id' => is_array($slOrder) ? ($slOrder['order_id'] ?? null) : null,
                    'main_order_code' => is_array($mainOrder) ? ($mainOrder['raw_response']['code'] ?? null) : null,
                ]);
                $this->logDebug('[MTF Run] Trading decision evaluated', [
                    'symbol' => $symbol,
                    'status' => $decision['status'] ?? null,
                    'execution_tf' => $executionTf,
                    'leverage' => \is_object($levCalc) && property_exists($levCalc, 'finalLeverage') ? $levCalc->finalLeverage : null,
                    'position_size' => \is_object($posOpen) && property_exists($posOpen, 'positionSize') ? $posOpen->positionSize : null,
                    'main_order_id' => is_array($mainOrder) ? ($mainOrder['order_id'] ?? null) : null,
                    'tp_order_id' => is_array($tpOrder) ? ($tpOrder['order_id'] ?? null) : null,
                    'sl_order_id' => is_array($slOrder) ? ($slOrder['order_id'] ?? null) : null,
                ]);

                // Avertir si l'ordre principal n'a pas été obtenu
                if (!is_array($mainOrder) || empty($mainOrder['order_id'])) {
                    $this->positionsFlowLogger->warning('[PositionsFlow] Main order missing or not placed', [
                        'symbol' => $symbol,
                        'execution_tf' => $executionTf,
                        'raw_response' => is_array($mainOrder) ? ($mainOrder['raw_response'] ?? null) : null,
                    ]);
                }
            } catch (\Throwable) {}
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] Trading decision failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $this->logDebug('[MTF Run] Trading decision execution failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            try {
                $this->positionsFlowLogger->error('[PositionsFlow] Trading decision failed', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {}
            $result['trading_decision'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }

        return $result;
    }

    private function isHighConviction(array $result): bool
    {
        $indicatorContext = $result['indicator_context'] ?? [];
        if (is_array($indicatorContext)) {
            if (isset($indicatorContext['high_conviction'])) {
                return (bool) $indicatorContext['high_conviction'];
            }
            if (isset($indicatorContext['meta']['high_conviction'])) {
                return (bool) $indicatorContext['meta']['high_conviction'];
            }
        }

        $context = $result['context'] ?? [];
        $aligned = ($context['context_fully_aligned'] ?? false) === true;
        $contextDir = strtoupper((string)($context['context_dir'] ?? 'NONE'));
        $signalSide = strtoupper((string)($result['signal_side'] ?? 'NONE'));

        return $aligned && $contextDir !== 'NONE' && $contextDir === $signalSide;
    }

    /**
     * Rafraîchit les vues matérialisées après le traitement de tous les symboles
     */
    private function refreshMaterializedViewsAfterRun(\Ramsey\Uuid\UuidInterface $runId): void
    {
        try {
            $this->logger->info('[MTF Run] Refreshing materialized views', [
                'run_id' => $runId->toString(),
            ]);

            $this->sqlIndicatorService->refreshMaterializedViews();

            $this->logger->info('[MTF Run] Materialized views refreshed successfully', [
                'run_id' => $runId->toString(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] Failed to refresh materialized views', [
                'run_id' => $runId->toString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Déclenche l'événement de completion du cycle MTF
     */
    private function dispatchMtfRunCompletedEvent(
        \Ramsey\Uuid\UuidInterface $runId,
        array $symbols,
        array $summary,
        array $results,
        float $startTime
    ): void {
        try {
            $event = new MtfRunCompletedEvent(
                $runId->toString(),
                $symbols,
                count($symbols),
                microtime(true) - $startTime,
                $summary,
                $results
            );

            $this->eventDispatcher->dispatch($event, MtfRunCompletedEvent::NAME);

            $this->logger->info('[MTF Run] Event dispatched', [
                'run_id' => $runId->toString(),
                'event_name' => MtfRunCompletedEvent::NAME,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] Failed to dispatch completion event', [
                'run_id' => $runId->toString(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function detectAsyncDebugLogging(LoggerInterface $logger): bool
    {
        if (!$logger instanceof MonologLogger) {
            return false;
        }

        foreach ($logger->getHandlers() as $handler) {
            if (($handler instanceof AsyncBusLogHandler || $handler instanceof MessengerLogHandler) && $this->handlerHandlesDebugLevel($handler)) {
                return true;
            }
        }

        return false;
    }

    private function handlerHandlesDebugLevel(object $handler): bool
    {
        if (!method_exists($handler, 'getLevel')) {
            return false;
        }

        $level = $handler->getLevel();

        if ($level instanceof Level) {
            return $level->value <= Level::Debug->value;
        }

        if (is_int($level)) {
            return $level <= MonologLogger::DEBUG;
        }

        if (is_string($level)) {
            return strtolower($level) === 'debug';
        }

        return false;
    }

    private function resolveLoggerChannel(LoggerInterface $logger): string
    {
        if ($logger instanceof MonologLogger) {
            return $logger->getName();
        }

        return 'mtf';
    }

    private function logDebug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);

        if (null === $this->logMessageBus) {
            return;
        }

        $contextForRecord = $context;
        if (!isset($contextForRecord['run_id']) && isset($contextForRecord['runId'])) {
            $contextForRecord['run_id'] = (string) $contextForRecord['runId'];
        }

        $correlationId = null;
        if (isset($contextForRecord['correlation_id'])) {
            $correlationId = (string) $contextForRecord['correlation_id'];
        } elseif (isset($contextForRecord['run_id'])) {
            $correlationId = (string) $contextForRecord['run_id'];
        }

        $timestamp = new \DateTimeImmutable();
        $record = null;
        if (!$this->asyncDebugLoggingAvailable) {
            $record = new LogRecordMessage(
                app: $this->resolvedLogAppName,
                channel: $this->logChannel,
                level: 'DEBUG',
                message: $message,
                context: $contextForRecord,
                extra: [
                    'forwarded_by' => self::class,
                ],
                datetime: $timestamp->format(\DateTimeInterface::ATOM),
                correlationId: $correlationId,
            );
        }

        $logMessage = new LogMessage(
            channel: $this->logChannel,
            level: 'DEBUG',
            message: $message,
            context: $contextForRecord,
            createdAt: $timestamp,
        );

        try {
            if ($record !== null) {
                $this->logMessageBus->dispatch($record);
            }
            $this->logMessageBus->dispatch($logMessage);
        } catch (Throwable $exception) {
            $this->logger->warning('[MTF Run] Failed to forward debug log to messenger', [
                'error' => $exception->getMessage(),
                'message' => $message,
            ]);
        }
    }

    /**
     * Helper method to yield final result
     * @param array $summary
     * @param array $results
     * @param float $startTime
     * @param \Ramsey\Uuid\UuidInterface $runId
     * @return \Generator<int, array{symbol: string, result: array, progress: array}, array{summary: array, results: array}>
     */
    private function yieldFinalResult(array $summary, array $results, float $startTime, \Ramsey\Uuid\UuidInterface $runId): \Generator
    {
        // Yield final summary as progress
        yield [
            'symbol' => 'FINAL',
            'result' => $summary,
            'progress' => [
                'current' => count($results),
                'total' => count($results),
                'percentage' => 100.0,
                'symbol' => 'FINAL',
                'status' => 'completed',
                'execution_time' => round(microtime(true) - $startTime, 3),
            ],
        ];

        // Return final result
        return ['summary' => $summary, 'results' => $results];
    }
}
