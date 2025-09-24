<?php

declare(strict_types=1);


namespace App\Service\Trading;


use App\Dto\ExchangeFilters;

use Psr\Log\LoggerInterface;


final class Quantizer
{
    public function __construct(private readonly ExchangeFilters $filters)
    {
    }


    public function qPriceFloor(float $p): float
    {
        return $this->q($p, $this->filters->tickSize, 'floor');
    }

    public function qPriceCeil(float $p): float
    {
        return $this->q($p, $this->filters->tickSize, 'ceil');
    }

    public function qQtyFloor(float $q): float
    {
        return $this->q($q, $this->filters->stepSize, 'floor');
    }

    public function qQtyCeil(float $q): float
    {
        return $this->q($q, $this->filters->stepSize, 'ceil');
    }


    private function q(float $value, float $step, string $mode): float
    {
        if ($step <= 0.0) return $value;
        $ticks = $value / $step;
        $rounded = match ($mode) {
                'ceil' => ceil($ticks),
                'floor' => floor($ticks),
                default => round($ticks, 8),
            } * $step;
// Clamps optionnels
        if ($step === $this->filters->tickSize) {
            if ($this->filters->minPrice !== null) $rounded = max($this->filters->minPrice, $rounded);
            if ($this->filters->maxPrice !== null) $rounded = min($this->filters->maxPrice, $rounded);
        } else {
            if ($this->filters->minQty !== null) $rounded = max($this->filters->minQty, $rounded);
            if ($this->filters->maxQty !== null) $rounded = min($this->filters->maxQty, $rounded);
        }
        return $rounded;
    }
}
