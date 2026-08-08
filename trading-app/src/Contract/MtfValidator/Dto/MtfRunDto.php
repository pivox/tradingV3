<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

use App\Trading\Lineage\LineageContext;

final class MtfRunDto
{
    /** @param array<string,mixed> $options */
    public function __construct(
        public readonly string $symbol,
        public readonly string $profile,               // 'regular', 'scalper', ...
        public readonly ?string $mode = null,          // 'pragmatic', 'strict', ... (optionnel, peut venir de la config)
        public readonly ?\DateTimeImmutable $now = null,
        public readonly ?string $requestId = null,     // trace_id / correlation_id
        public readonly bool $dryRun = false,
        public readonly array $options = [],           // dry_run, debug, overrides éventuels
        public readonly ?LineageContext $lineageContext = null,
    ) {
    }
}
