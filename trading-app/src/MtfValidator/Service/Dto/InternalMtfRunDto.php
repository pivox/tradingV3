<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto;

/**
 * DTO interne pour l'exécution MTF
 */
final class InternalMtfRunDto
{
    public function __construct(
        public readonly string $runId,
        public readonly array $symbols,
        public readonly bool $dryRun,
        public readonly bool $forceRun,
        public readonly ?string $currentTf,
        public readonly bool $forceTimeframeCheck,
        public readonly bool $lockPerSymbol,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null
    ) {}

    public static function fromContractRequest(
        string $runId,
        \App\Contract\MtfValidator\Dto\MtfRunRequestDto $request
    ): self {
        return new self(
            runId: $runId,
            symbols: $request->symbols,
            dryRun: $request->dryRun,
            forceRun: $request->forceRun,
            currentTf: $request->currentTf,
            forceTimeframeCheck: $request->forceTimeframeCheck,
            lockPerSymbol: $request->lockPerSymbol,
            startedAt: new \DateTimeImmutable(),
            userId: $request->userId,
            ipAddress: $request->ipAddress
        );
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'symbols' => $this->symbols,
            'dry_run' => $this->dryRun,
            'force_run' => $this->forceRun,
            'current_tf' => $this->currentTf,
            'force_timeframe_check' => $this->forceTimeframeCheck,
            'lock_per_symbol' => $this->lockPerSymbol,
            'started_at' => $this->startedAt->format('Y-m-d H:i:s'),
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress
        ];
    }
}
