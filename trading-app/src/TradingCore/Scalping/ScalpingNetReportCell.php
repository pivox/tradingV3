<?php

declare(strict_types=1);

namespace App\TradingCore\Scalping;

final readonly class ScalpingNetReportCell
{
    /** @var array<string, string> */
    private const SIDES_BY_SETUP = [
        'scalping.trend_continuation.long' => 'long',
        'scalping.pullback.long' => 'long',
        'scalping.trend_momentum.short' => 'short',
    ];

    public function __construct(
        public string $setupId,
        public string $setupVersion,
        public string $side,
        public int $sampleCount,
        public float $grossR,
        public float $netR,
        public float $costQuote,
        public bool $certified = false,
    ) {
        if (
            (self::SIDES_BY_SETUP[$setupId] ?? null) !== $side
            || $setupVersion !== '1.1.0'
            || $sampleCount < 1
            || !\is_finite($grossR)
            || !\is_finite($netR)
            || !\is_finite($costQuote)
            || $grossR <= 0.0
            || $netR <= 0.0
            || $costQuote < 0.0
            || $certified
        ) {
            throw new \InvalidArgumentException('scalping_net_report_cell_invalid');
        }
    }

    /** @return array{setup_id:string,setup_version:string,side:string,sample_count:int,gross_r:float,net_r:float,cost_quote:float,certified:false} */
    public function toArray(): array
    {
        return [
            'setup_id' => $this->setupId,
            'setup_version' => $this->setupVersion,
            'side' => $this->side,
            'sample_count' => $this->sampleCount,
            'gross_r' => $this->grossR,
            'net_r' => $this->netR,
            'cost_quote' => $this->costQuote,
            'certified' => false,
        ];
    }
}
