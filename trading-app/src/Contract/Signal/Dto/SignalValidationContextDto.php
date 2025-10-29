<?php

declare(strict_types=1);

namespace App\Contract\Signal\Dto;

/**
 * DTO représentant le contexte multi-timeframe associé à une validation.
 */
final readonly class SignalValidationContextDto
{
    /**
     * @param array<string, string> $signals
     * @param string[]              $timeframes
     */
    public function __construct(
        public array $signals,
        public bool $aligned,
        public string $direction,
        public array $timeframes,
        public bool $fullyPopulated,
        public bool $fullyAligned,
    ) {
    }

    /**
     * @return array{
     *     signals: array<string,string>,
     *     aligned: bool,
     *     dir: string,
     *     fully_populated: bool,
     *     fully_aligned: bool,
     *     timeframes: string[]
     * }
     */
    public function toArray(): array
    {
        return [
            'signals' => $this->signals,
            'aligned' => $this->aligned,
            'dir' => $this->direction,
            'fully_populated' => $this->fullyPopulated,
            'fully_aligned' => $this->fullyAligned,
            'timeframes' => $this->timeframes,
        ];
    }
}

