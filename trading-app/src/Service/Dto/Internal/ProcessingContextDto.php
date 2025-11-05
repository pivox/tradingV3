<?php

declare(strict_types=1);

namespace App\Service\Dto\Internal;

use App\Contract\MtfValidator\Dto\ValidationContextDto;
use App\MtfValidator\Service\Dto\InternalTimeframeResultDto;

/**
 * Contexte interne pour l'exécution du pipeline timeframe.
 */
final class ProcessingContextDto
{
    /** @var array<string, InternalTimeframeResultDto> */
    private array $results = [];

    /** @var array<string, bool> */
    private array $cacheHits = [];

    /**
     * @var array{timeframe: string, reason: string, context: array}|null
     */
    private ?array $hardStop = null;

    /**
     * @param array<int, array<string, mixed>> $collector
     * @param array<string, mixed>             $options
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $symbol,
        public readonly \DateTimeImmutable $now,
        public array $collector = [],
        public readonly bool $forceTimeframeCheck = false,
        public readonly bool $forceRun = false,
        public readonly bool $skipContextValidation = false,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?array $metadata = null,
        public readonly ?string $currentTimeframe = null,
        private array $options = []
    ) {
        $this->options = array_merge([
            'force_timeframe_check' => $forceTimeframeCheck,
            'force_run' => $forceRun,
            'skip_context_validation' => $skipContextValidation,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'metadata' => $metadata,
            'current_timeframe' => $currentTimeframe,
        ], $this->options);
    }

    public static function fromContractContext(
        string $symbol,
        ValidationContextDto $context,
        ?string $currentTimeframe = null
    ): self {
        return new self(
            runId: $context->runId,
            symbol: $symbol,
            now: $context->now,
            collector: $context->collector,
            forceTimeframeCheck: $context->forceTimeframeCheck,
            forceRun: $context->forceRun,
            skipContextValidation: $context->skipContextValidation ?? false,
            userId: $context->userId,
            ipAddress: $context->ipAddress,
            metadata: $context->metadata ?? null,
            currentTimeframe: $currentTimeframe
        );
    }

    public function toContractContext(): ValidationContextDto
    {
        return new ValidationContextDto(
            runId: $this->runId,
            now: $this->now,
            collector: $this->collector,
            forceTimeframeCheck: $this->forceTimeframeCheck,
            forceRun: $this->forceRun,
            skipContextValidation: $this->skipContextValidation,
            userId: $this->userId,
            ipAddress: $this->ipAddress,
            metadata: $this->metadata
        );
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function withOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'symbol' => $this->symbol,
            'now' => $this->now->format('Y-m-d H:i:s'),
            'collector' => $this->collector,
            'force_timeframe_check' => $this->forceTimeframeCheck,
            'force_run' => $this->forceRun,
            'skip_context' => $this->skipContextValidation,
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress,
            'metadata' => $this->metadata,
            'options' => $this->options,
            'results' => array_map(
                static fn (InternalTimeframeResultDto $result): array => $result->toArray(),
                $this->results
            ),
            'cache_hits' => array_keys(array_filter($this->cacheHits)),
            'hard_stop' => $this->hardStop,
        ];
    }

    public function addResult(InternalTimeframeResultDto $result): void
    {
        $this->results[strtolower($result->timeframe)] = $result;
    }

    public function pushCollectorEntry(InternalTimeframeResultDto $result): void
    {
        $this->collector[] = [
            'tf' => $result->timeframe,
            'status' => $result->status,
            'signal_side' => $result->signalSide,
            'kline_time' => $result->klineTime,
        ];
    }

    public function getResult(string $timeframe): ?InternalTimeframeResultDto
    {
        return $this->results[strtolower($timeframe)] ?? null;
    }

    /**
     * @return array<string, InternalTimeframeResultDto>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function markCacheHit(string $timeframe): void
    {
        $this->cacheHits[strtolower($timeframe)] = true;
    }

    public function isCacheHit(string $timeframe): bool
    {
        return (bool) ($this->cacheHits[strtolower($timeframe)] ?? false);
    }

    public function markHardStop(string $timeframe, string $reason, array $context = []): void
    {
        $this->hardStop = [
            'timeframe' => strtolower($timeframe),
            'reason' => $reason,
            'context' => $context,
        ];
    }

    public function getHardStop(): ?array
    {
        return $this->hardStop;
    }
}
