<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

/**
 * DTO pour les requêtes d'exécution MTF
 */
final class MtfRunRequestDto
{
    public function __construct(
        public readonly array $symbols,
        public readonly bool $dryRun = false,
        public readonly bool $forceRun = false,
        public readonly ?string $currentTf = null,
        public readonly bool $forceTimeframeCheck = false,
        public readonly bool $lockPerSymbol = false,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            symbols: $data['symbols'] ?? [],
            dryRun: (bool) ($data['dry_run'] ?? false),
            forceRun: (bool) ($data['force_run'] ?? false),
            currentTf: $data['current_tf'] ?? null,
            forceTimeframeCheck: (bool) ($data['force_timeframe_check'] ?? false),
            lockPerSymbol: (bool) ($data['lock_per_symbol'] ?? false),
            userId: $data['user_id'] ?? null,
            ipAddress: $data['ip_address'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'symbols' => $this->symbols,
            'dry_run' => $this->dryRun,
            'force_run' => $this->forceRun,
            'current_tf' => $this->currentTf,
            'force_timeframe_check' => $this->forceTimeframeCheck,
            'lock_per_symbol' => $this->lockPerSymbol,
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress
        ];
    }
}
