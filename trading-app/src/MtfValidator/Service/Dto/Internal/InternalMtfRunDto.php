<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto\Internal;

/**
 * Représente la demande d'exécution MTF sous forme interne.
 * Ce DTO ne connaît que les besoins métiers.
 */
final class InternalMtfRunDto
{
    /**
     * @param string[] $symbols
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $symbols,
        public readonly bool $dryRun,
        public readonly bool $forceRun,
        public readonly ?string $currentTf,
        public readonly bool $forceTimeframeCheck,
        public readonly bool $skipContextValidation,
        public readonly bool $lockPerSymbol,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null
    ) {
    }
}
