<?php
declare(strict_types=1);

namespace App\Contract\Indicator\Dto;

final class ListIndicatorDto
{
    /**
     * @param array<string,mixed> $indicators
     * @param array<string,string> $descriptions
     */
    public function __construct(
        public array $indicators,
        public array $descriptions = []
    ) {}

    public function toArray(): array
    {
        return $this->indicators;
    }

    public function getDescriptions(): array
    {
        return $this->descriptions;
    }
}
