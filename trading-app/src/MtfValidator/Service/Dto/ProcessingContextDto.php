<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto;

/**
 * DTO interne pour le contexte de traitement
 */
final class ProcessingContextDto
{
    public function __construct(
        public readonly string $runId,
        public readonly string $symbol,
        public readonly \DateTimeImmutable $now,
        public readonly array $collector = [],
        public readonly bool $forceTimeframeCheck = false,
        public readonly bool $forceRun = false,
        public readonly bool $skipContextValidation = false,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?array $metadata = null
    ) {}

    public static function fromContractContext(
        string $symbol,
        \App\Contract\MtfValidator\Dto\ValidationContextDto $context
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
            ipAddress: $context->ipAddress
        );
    }

    public function toContractContext(): \App\Contract\MtfValidator\Dto\ValidationContextDto
    {
        return new \App\Contract\MtfValidator\Dto\ValidationContextDto(
            runId: $this->runId,
            now: $this->now,
            collector: $this->collector,
            forceTimeframeCheck: $this->forceTimeframeCheck,
            forceRun: $this->forceRun,
            skipContextValidation: $this->skipContextValidation,
            userId: $this->userId,
            ipAddress: $this->ipAddress
        );
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
            'metadata' => $this->metadata
        ];
    }
}
