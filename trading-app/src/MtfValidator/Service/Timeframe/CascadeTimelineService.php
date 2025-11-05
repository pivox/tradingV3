<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Timeframe;

use App\Config\MtfValidationConfig;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\ValidationContextDto;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Orchestration explicite de la cascade timeframe.
 */
final class CascadeTimelineService
{
    /** @var array<string, TimeframeProcessorInterface> */
    private array $processors = [];

    /**
     * @param iterable<TimeframeProcessorInterface> $processors
     */
    public function __construct(
        #[TaggedIterator('app.mtf.timeframe.processor')]
        iterable $processors,
        private readonly MtfValidationConfig $validationConfig,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $orderJourneyLogger,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($processors as $processor) {
            $this->processors[strtolower($processor->getTimeframeValue())] = $processor;
        }
    }

    public function execute(
        string $symbol,
        UuidInterface $runId,
        MtfRunDto $mtfRunDto,
        \DateTimeImmutable $now,
        ?string $timeframeOverride = null,
        array $symbolContext = []
    ): SymbolResultDto {
        $sequence = $this->determineSequence($mtfRunDto, $timeframeOverride);
        if ($sequence === []) {
            $this->logger->warning('[CascadeTimeline] No timeframe processors available', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
            ]);

            return new SymbolResultDto(
                symbol: $symbol,
                status: 'SKIPPED',
                error: ['message' => 'No timeframe processor available for cascade'],
                context: [
                    'timeframes' => [],
                    'positions_snapshot' => $this->summarizeContext($symbolContext),
                    'timeframe_override' => $timeframeOverride,
                ]
            );
        }

        $this->logger->debug('[CascadeTimeline] Starting cascade', [
            'symbol' => $symbol,
            'run_id' => $runId->toString(),
            'sequence' => $sequence,
            'override_tf' => $timeframeOverride,
        ] + $this->summarizeContext($symbolContext));

        $collector = [];
        $results = [];
        $lastValidTf = null;
        $lastSignalSide = null;
        $lastPrice = null;
        $lastAtr = null;
        $failure = null;

        foreach ($sequence as $index => $timeframe) {
            $processor = $this->processors[$timeframe];
            $this->orderJourneyLogger->debug('order_journey.cascade.timeframe_start', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'timeframe' => $timeframe,
            ]);

            $contextDto = new ValidationContextDto(
                runId: $runId->toString(),
                now: $now,
                collector: $collector,
                forceTimeframeCheck: $mtfRunDto->forceTimeframeCheck,
                forceRun: $mtfRunDto->forceRun,
                skipContextValidation: $mtfRunDto->skipContextValidation,
            );

            $resultDto = $processor->processTimeframe($symbol, $contextDto);
            $result = $resultDto->toArray();
            $results[$timeframe] = $result;

            $collector[] = [
                'tf' => $timeframe,
                'status' => $result['status'] ?? 'UNKNOWN',
                'signal_side' => $result['signal_side'] ?? 'NONE',
                'kline_time' => $result['kline_time'] ?? null,
            ];

            $this->orderJourneyLogger->debug('order_journey.cascade.timeframe_done', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'timeframe' => $timeframe,
                'status' => $result['status'] ?? 'UNKNOWN',
                'signal_side' => $result['signal_side'] ?? 'NONE',
            ]);

            $status = strtoupper((string)($result['status'] ?? 'UNKNOWN'));

            if ($status !== 'VALID') {
                $failure = [
                    'status' => $this->mapFailureStatus($status),
                    'timeframe' => $timeframe,
                    'error' => $result['error'] ?? null,
                    'reason' => $result['reason'] ?? null,
                ];
                break;
            }

            if ($index > 0) {
                $parentTf = $sequence[$index - 1];
                $parentResult = $results[$parentTf] ?? null;
                if ($parentResult !== null && method_exists($processor, 'checkAlignment')) {
                    $alignment = $processor->checkAlignment($result, $parentResult, strtoupper($parentTf));
                    if (($alignment['status'] ?? null) !== 'ALIGNED') {
                        $failure = [
                            'status' => 'INVALID',
                            'timeframe' => $timeframe,
                            'error' => $alignment,
                            'reason' => $alignment['reason'] ?? 'ALIGNMENT_FAILED',
                        ];
                        break;
                    }
                }
            }

            $lastValidTf = $timeframe;
            $lastSignalSide = $result['signal_side'] ?? $lastSignalSide;
            $lastPrice = $result['current_price'] ?? $lastPrice;
            $lastAtr = $result['atr'] ?? $lastAtr;
        }

        $contextPayload = [
            'timeframes' => $this->simplifyResults($results),
            'collector' => $collector,
            'positions_snapshot' => $this->summarizeContext($symbolContext),
        ];
        if ($timeframeOverride !== null) {
            $contextPayload['timeframe_override'] = $timeframeOverride;
        }

        if ($failure !== null) {
            $this->logger->info('[CascadeTimeline] Cascade stopped', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'failed_timeframe' => $failure['timeframe'],
                'status' => $failure['status'],
                'reason' => $failure['reason'] ?? null,
            ]);

            $this->orderJourneyLogger->debug('order_journey.cascade.completed', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'status' => $failure['status'],
                'execution_tf' => $lastValidTf,
                'override_tf' => $timeframeOverride,
            ]);

            $errorPayload = $failure['error'];
            if ($errorPayload === null && ($failure['reason'] ?? null) !== null) {
                $errorPayload = ['message' => $failure['reason']];
            }

            return new SymbolResultDto(
                symbol: $symbol,
                status: $failure['status'],
                executionTf: $lastValidTf,
                failedTimeframe: $failure['timeframe'],
                signalSide: $lastSignalSide,
                error: $errorPayload,
                context: $contextPayload,
                currentPrice: $lastPrice,
                atr: $lastAtr,
            );
        }

        if ($lastValidTf === null) {
            $this->logger->warning('[CascadeTimeline] Cascade produced no valid timeframe', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
            ]);

            $this->orderJourneyLogger->debug('order_journey.cascade.completed', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'status' => 'INVALID',
                'execution_tf' => null,
                'override_tf' => $timeframeOverride,
            ]);

            return new SymbolResultDto(
                symbol: $symbol,
                status: 'INVALID',
                failedTimeframe: $sequence[0],
                context: $contextPayload,
            );
        }

        $finalSide = strtoupper((string)($lastSignalSide ?? 'NONE'));
        if (!in_array($finalSide, ['LONG', 'SHORT'], true)) {
            $this->logger->info('[CascadeTimeline] Final signal side is NONE', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'execution_tf' => $lastValidTf,
            ]);

            return new SymbolResultDto(
                symbol: $symbol,
                status: 'INVALID',
                executionTf: $lastValidTf,
                failedTimeframe: $lastValidTf,
                signalSide: $lastSignalSide,
                context: $contextPayload,
                currentPrice: $lastPrice,
                atr: $lastAtr,
            );
        }

        $this->orderJourneyLogger->debug('order_journey.cascade.completed', [
            'symbol' => $symbol,
            'run_id' => $runId->toString(),
            'status' => 'READY',
            'execution_tf' => $lastValidTf,
            'override_tf' => $timeframeOverride,
        ]);

        return new SymbolResultDto(
            symbol: $symbol,
            status: 'READY',
            executionTf: $lastValidTf,
            signalSide: $lastSignalSide,
            context: $contextPayload,
            currentPrice: $lastPrice,
            atr: $lastAtr,
        );
    }

    /**
     * @return list<string>
     */
    private function determineSequence(MtfRunDto $runDto, ?string $override): array
    {
        $order = ['4h', '1h', '15m', '5m', '1m'];
        $config = $this->validationConfig->getConfig();
        $start = strtolower((string)($config['validation']['start_from_timeframe'] ?? '4h'));

        if ($override !== null) {
            $start = strtolower($override);
        } elseif ($runDto->currentTf !== null) {
            $start = strtolower($runDto->currentTf);
        }

        $startIndex = array_search($start, $order, true);
        if ($startIndex === false) {
            $startIndex = array_search(strtolower((string)($config['validation']['start_from_timeframe'] ?? '4h')), $order, true);
            if ($startIndex === false) {
                $startIndex = 0;
            }
        }

        $sequence = array_slice($order, $startIndex);

        return array_values(array_filter($sequence, fn (string $tf): bool => isset($this->processors[$tf])));
    }

    private function mapFailureStatus(string $status): string
    {
        return match ($status) {
            'GRACE_WINDOW', 'TOO_RECENT' => 'GRACE_WINDOW',
            'SKIPPED' => 'SKIPPED',
            'ERROR' => 'ERROR',
            default => 'INVALID',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $results
     *
     * @return array<string, array<string, mixed>>
     */
    private function simplifyResults(array $results): array
    {
        $simplified = [];
        foreach ($results as $tf => $payload) {
            $simplified[$tf] = [
                'status' => $payload['status'] ?? null,
                'signal_side' => $payload['signal_side'] ?? null,
                'kline_time' => $payload['kline_time'] ?? null,
                'current_price' => $payload['current_price'] ?? null,
                'atr' => $payload['atr'] ?? null,
            ];
        }

        return $simplified;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeContext(array $context): array
    {
        $summary = [];
        if (isset($context['position']) && $context['position'] instanceof \App\Contract\Provider\Dto\PositionDto) {
            $summary['position'] = [
                'side' => $context['position']->side->value,
                'size' => $context['position']->size->toFloat(),
                'entry' => $context['position']->entryPrice->toFloat(),
            ];
        }
        if (isset($context['adjustment_requested'])) {
            $summary['adjustment_requested'] = (bool) $context['adjustment_requested'];
        }
        if (isset($context['orders']) && is_iterable($context['orders'])) {
            if (is_array($context['orders']) || $context['orders'] instanceof \Countable) {
                $summary['orders_count'] = count($context['orders']);
            } else {
                $count = 0;
                foreach ($context['orders'] as $_) {
                    $count++;
                }
                $summary['orders_count'] = $count;
            }
        }

        return $summary;
    }
}
