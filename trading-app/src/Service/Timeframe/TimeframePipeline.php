<?php

declare(strict_types=1);

namespace App\Service\Timeframe;

use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\MtfValidator\Service\Dto\InternalTimeframeResultDto;
use App\Service\Dto\Internal\ProcessingContextDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class TimeframePipeline
{
    /** @var array<string, TimeframeProcessorInterface> */
    private array $processors = [];

    /**
     * @param iterable<TimeframeProcessorInterface> $processors
     */
    public function __construct(
        #[TaggedIterator('app.mtf.timeframe.processor')]
        iterable $processors,
        private readonly TimeframeSelectionStrategyInterface $selectionStrategy,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.order_journey')]
        private readonly ?LoggerInterface $orderJourneyLogger = null,
    ) {
        foreach ($processors as $processor) {
            $this->processors[strtolower($processor->getTimeframeValue())] = $processor;
        }
    }

    public function getProcessor(string $timeframe): ?TimeframeProcessorInterface
    {
        return $this->processors[strtolower($timeframe)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function determineSequence(ProcessingContextDto $context): array
    {
        $selected = $this->selectionStrategy->selectProcessors($context, $this->processors);

        return array_values(array_map(
            static fn (TimeframeProcessorInterface $processor): string => strtolower($processor->getTimeframeValue()),
            $selected
        ));
    }

    /**
     * @return array{
     *     results: array<string, InternalTimeframeResultDto>,
     *     hard_stop: array{timeframe: string, reason: string, context: array}|null
     * }
     */
    public function run(ProcessingContextDto $context): array
    {
        $selected = $this->selectionStrategy->selectProcessors($context, $this->processors);
        $results = [];

        foreach ($selected as $processor) {
            $timeframe = strtolower($processor->getTimeframeValue());

            $this->logger->debug('[TimeframePipeline] Processing timeframe', [
                'run_id' => $context->runId,
                'symbol' => $context->symbol,
                'timeframe' => $timeframe,
            ]);
            $this->orderJourneyLogger?->info('order_journey.pipeline.timeframe_start', [
                'run_id' => $context->runId,
                'symbol' => $context->symbol,
                'timeframe' => $timeframe,
            ]);

            $internal = $context->getResult($timeframe);
            if ($internal instanceof InternalTimeframeResultDto) {
                $this->logger->debug('[TimeframePipeline] Using pre-populated result', [
                    'run_id' => $context->runId,
                    'symbol' => $context->symbol,
                    'timeframe' => $timeframe,
                ]);
            } else {
                $resultDto = $processor->processTimeframe($context->symbol, $context->toContractContext());
                $internal = $context->getResult($timeframe);
                if (!$internal instanceof InternalTimeframeResultDto) {
                    $internal = InternalTimeframeResultDto::fromContractDto($resultDto);
                    $context->addResult($internal);
                    $context->pushCollectorEntry($internal);
                }
            }

            $results[$timeframe] = $internal;

            $this->orderJourneyLogger?->info('order_journey.pipeline.timeframe_done', [
                'run_id' => $context->runId,
                'symbol' => $context->symbol,
                'timeframe' => $timeframe,
                'status' => $internal->status,
                'signal_side' => $internal->signalSide,
                'hard_stop' => $context->getHardStop(),
            ]);

            $hardStop = $context->getHardStop();
            if ($hardStop !== null && $hardStop['timeframe'] === $timeframe) {
                $this->logger->debug('[TimeframePipeline] Hard stop triggered', [
                    'run_id' => $context->runId,
                    'symbol' => $context->symbol,
                    'timeframe' => $timeframe,
                    'reason' => $hardStop['reason'],
                ]);
                break;
            }

            if ($internal->status !== 'VALID') {
                $this->logger->debug('[TimeframePipeline] Non valid status, stopping pipeline', [
                    'run_id' => $context->runId,
                    'symbol' => $context->symbol,
                    'timeframe' => $timeframe,
                    'status' => $internal->status,
                ]);
                break;
            }
        }

        return [
            'results' => $results,
            'hard_stop' => $context->getHardStop(),
        ];
    }
}
