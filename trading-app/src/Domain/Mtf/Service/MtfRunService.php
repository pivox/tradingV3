<?php

declare(strict_types=1);

namespace App\Domain\Mtf\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Trading\Service\TradingDecisionService;
use App\Domain\Trading\Service\TradeContextService;
use App\Repository\MtfLockRepository;
use App\Repository\MtfSwitchRepository;
use App\Repository\ContractRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

class MtfRunService
{
    public function __construct(
        private readonly MtfService $mtfService,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfLockRepository $mtfLockRepository,
        private readonly ContractRepository $contractRepository,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly TradingDecisionService $tradingDecisionService,
        private readonly TradeContextService $tradeContext,
    ) {
    }

    /**
     * Lance un cycle MTF en déléguant le traitement de chaque symbole à MtfService.
     * @param string[] $symbols
     * @return \Generator<int, array{symbol: string, result: array, progress: array}, array{summary: array, results: array}>
     */
    public function run(array $symbols = [], bool $dryRun = true, bool $forceRun = false, ?string $currentTf = null, bool $forceTimeframeCheck = false): \Generator
    {
        $startTime = microtime(true);
        $runId = Uuid::uuid4();
        $lockKey = 'mtf_execution';
        $processId = $runId->toString();
        $lockAcquired = false;

        try {
            $this->logger->info('[MTF Run] Start', [
                'run_id' => $runId->toString(),
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'force_timeframe_check' => $forceTimeframeCheck,
            ]);

            if (!$forceRun && !$this->mtfSwitchRepository->isGlobalSwitchOn()) {
                return [
                    'summary' => [
                        'run_id' => $runId->toString(),
                        'dry_run' => $dryRun,
                        'force_run' => $forceRun,
                        'current_tf' => $currentTf,
                        'status' => 'blocked_global_switch_off',
                    ],
                    'results' => [],
                ];
            }

            $lockTimeout = 600; // 10 minutes
            $lockMetadata = json_encode([
                'run_id' => $runId->toString(),
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'started_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);

            // Activer le verrou si nécessaire (désactivé pour le moment)
             if (!$this->mtfLockRepository->acquireLock($lockKey, $processId, $lockTimeout, $lockMetadata)) {
                 $existingLockInfo = $this->mtfLockRepository->getLockInfo($lockKey);
                 $summary = [
                     'run_id' => $runId->toString(),
                     'status' => 'already_in_progress',
                     'existing_lock' => $existingLockInfo,
                     'current_tf' => $currentTf,
                 ];
                 return yield from $this->yieldFinalResult($summary, [], $startTime, $runId);
             }
            $lockAcquired = true;
            $this->logger->info('[MTF Run] Lock acquired', [
                'run_id' => $runId->toString(),
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
                } catch (\Throwable $e) {
                    $this->logger->warning('[MTF Run] Failed to load active symbols, using fallback list', [
                        'error' => $e->getMessage(),
                    ]);
                    $symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
                }
            }

            if (empty($symbols)) {
                $endTime = microtime(true);
                $executionTime = $endTime - $startTime;
                $summary = [
                    'run_id' => $runId->toString(),
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
                } catch (Throwable $e) {
                    $this->logger->warning('[MTF Run] Unable to resolve trading context', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            foreach ($symbols as $index => $symbol) {
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
                            $symbolResult['trading_decision'] = [
                                'status' => 'skipped',
                                'reason' => 'dry_run',
                            ];
                        } elseif ($accountBalance > 0.0 && $riskPercentage > 0.0) {
                            $symbolResult = $this->maybeExecuteTradingDecision(
                                $symbol,
                                $symbolResult,
                                $accountBalance,
                                $riskPercentage
                            );
                        } else {
                            $this->logger->warning('[MTF Run] Skipping trading decision (missing trading context)', [
                                'symbol' => $symbol,
                                'account_balance' => $accountBalance,
                                'risk_percentage' => $riskPercentage,
                            ]);
                            $symbolResult['trading_decision'] = [
                                'status' => 'skipped',
                                'reason' => 'missing_trading_context',
                            ];
                        }
                    }

                    $results[$symbol] = $symbolResult;
                } elseif ($finalResult !== null) {
                    $results[$symbol] = $finalResult;
                } elseif ($result !== null) {
                    $results[$symbol] = $result;
                }
            }

            $summary = [
                'run_id' => $runId->toString(),
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

            $this->logger->info('[MTF Run] Completed', $summary);
            return yield from $this->yieldFinalResult($summary, $results, $startTime, $runId);
        } finally {
            if ($lockAcquired) {
                $released = $this->mtfLockRepository->releaseLock($lockKey, $processId);
                $this->logger->info('[MTF Run] Lock released', [
                    'run_id' => $runId->toString(),
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
        if ($executionTf !== '1m') {
            $this->logger->info('[MTF Run] Skipping trading decision (execution_tf not 1m)', [
                'symbol' => $symbol,
                'execution_tf' => $result['execution_tf'] ?? null,
            ]);
            $result['trading_decision'] = [
                'status' => 'skipped',
                'reason' => 'execution_tf_not_1m',
                'execution_tf' => $result['execution_tf'] ?? null,
            ];
            return $result;
        }

        if (!isset($result['current_price'], $result['atr'])) {
            $this->logger->debug('[MTF Run] Missing price or ATR, skipping trading decision', [
                'symbol' => $symbol,
                'has_price' => isset($result['current_price']),
                'has_atr' => isset($result['atr']),
            ]);
            return $result;
        }

        $currentPrice = (float) $result['current_price'];
        $atr = (float) $result['atr'];
        if ($currentPrice <= 0.0 || $atr <= 0.0) {
            $this->logger->debug('[MTF Run] Invalid price/ATR values, skipping trading decision', [
                'symbol' => $symbol,
                'price' => $currentPrice,
                'atr' => $atr,
            ]);
            return $result;
        }

        try {
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
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] Trading decision failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
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
