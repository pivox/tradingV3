<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

/**
 * DTO pour le contexte de validation
 */
final class ValidationContextDto
{
    public function __construct(
        public readonly string $runId,
        public readonly \DateTimeImmutable $now,
        public readonly array $collector = [],
        public readonly bool $forceTimeframeCheck = false,
        public readonly bool $forceRun = false,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null
    ) {}

    public static function create(
        string $runId,
        \DateTimeImmutable $now,
        array $collector = [],
        bool $forceTimeframeCheck = false,
        bool $forceRun = false,
        ?string $userId = null,
        ?string $ipAddress = null
    ): self {
        return new self(
            runId: $runId,
            now: $now,
            collector: $collector,
            forceTimeframeCheck: $forceTimeframeCheck,
            forceRun: $forceRun,
            userId: $userId,
            ipAddress: $ipAddress
        );
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'now' => $this->now->format('Y-m-d H:i:s'),
            'collector' => $this->collector,
            'force_timeframe_check' => $this->forceTimeframeCheck,
            'force_run' => $this->forceRun,
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress
        ];
    }
}
