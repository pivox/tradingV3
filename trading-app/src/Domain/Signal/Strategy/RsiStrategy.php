<?php

declare(strict_types=1);

namespace App\Domain\Signal\Strategy;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use Brick\Math\BigDecimal;

class RsiStrategy implements StrategyInterface
{
    private array $parameters = [
        'period' => 14,
        'oversold_threshold' => 30,
        'overbought_threshold' => 70,
        'min_score' => 0.6
    ];

    private bool $enabled = true;

    public function getName(): string
    {
        return 'RSI Strategy';
    }

    public function getDescription(): string
    {
        return 'Stratégie basée sur l\'indicateur RSI (Relative Strength Index)';
    }

    public function supports(Timeframe $timeframe): bool
    {
        return in_array($timeframe, [Timeframe::H1, Timeframe::H4]);
    }

    public function generateSignal(
        string $symbol,
        Timeframe $timeframe,
        KlineDto $kline,
        IndicatorSnapshotDto $indicators
    ): ?SignalDto {
        if (!$this->enabled) {
            return null;
        }

        $rsi = $indicators->getValue('rsi');
        if ($rsi === null) {
            return null;
        }

        $rsiValue = $rsi->toFloat();
        $side = null;
        $score = 0.0;
        $trigger = null;

        // Signal d'achat (oversold)
        if ($rsiValue <= $this->parameters['oversold_threshold']) {
            $side = SignalSide::LONG;
            $score = $this->calculateScore($rsiValue, $this->parameters['oversold_threshold'], true);
            $trigger = 'RSI_OVERSOLD';
        }
        // Signal de vente (overbought)
        elseif ($rsiValue >= $this->parameters['overbought_threshold']) {
            $side = SignalSide::SHORT;
            $score = $this->calculateScore($rsiValue, $this->parameters['overbought_threshold'], false);
            $trigger = 'RSI_OVERBOUGHT';
        }

        if ($side === null || $score < $this->parameters['min_score']) {
            return null;
        }

        return new SignalDto(
            symbol: $symbol,
            timeframe: $timeframe,
            klineTime: $kline->openTime,
            side: $side,
            score: $score,
            trigger: $trigger,
            meta: [
                'strategy' => $this->getName(),
                'rsi_value' => $rsiValue,
                'parameters' => $this->parameters
            ]
        );
    }

    private function calculateScore(float $rsiValue, float $threshold, bool $isOversold): float
    {
        if ($isOversold) {
            // Plus le RSI est bas, plus le score est élevé
            return min(1.0, ($threshold - $rsiValue) / $threshold);
        } else {
            // Plus le RSI est haut, plus le score est élevé
            return min(1.0, ($rsiValue - $threshold) / (100 - $threshold));
        }
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameters(array $parameters): void
    {
        $this->parameters = array_merge($this->parameters, $parameters);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}


