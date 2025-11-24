<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

final class MtfResultDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $profile,
        public readonly ?string $mode,
        public readonly \DateTimeImmutable $evaluatedAt,

        public readonly bool $isTradable,
        public readonly ?string $side,
        public readonly ?string $executionTimeframe,

        public readonly ContextDecisionDto $context,
        public readonly ExecutionSelectionDto $execution,

        public readonly ?string $finalReason = null,
        public readonly array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol'      => $this->symbol,
            'profile'     => $this->profile,
            'mode'        => $this->mode,
            'evaluatedAt' => $this->evaluatedAt->format(\DateTimeInterface::ATOM),
            'isTradable'  => $this->isTradable,
            'side'        => $this->side,
            'executionTimeframe' => $this->executionTimeframe,
            'context'     => $this->context->toArray(),
            'execution'   => $this->execution->toArray(),
            'finalReason' => $this->finalReason,
            'extra'       => $this->extra,
        ];
    }

}

