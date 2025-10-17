<?php

declare(strict_types=1);

namespace App\Domain\Signal\Strategy;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;

class MovingAverageStrategy implements StrategyInterface
{
    private array $parameters = [
        'fast_ma_period' => 10,
        'slow_ma_period' => 20,
        'min_score' => 0.6
    ];

    private bool $enabled = true;

    public function getName(): string
    {
        return 'Moving Average Strategy';
    }

    public function getDescription(): string
    {
        return 'Stratégie basée sur le croisement de moyennes mobiles';
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

        $fastMA = $indicators->getValue('ma_' . $this->parameters['fast_ma_period']);
        $slowMA = $indicators->getValue('ma_' . $this->parameters['slow_ma_period']);

        if ($fastMA === null || $slowMA === null) {
            return null;
        }

        $fastValue = $fastMA->toFloat();
        $slowValue = $slowMA->toFloat();
        $closePrice = $kline->closePrice->toFloat();

        $side = null;
        $score = 0.0;
        $trigger = null;

        // Signal d'achat : MA rapide croise au-dessus de la MA lente
        if ($fastValue > $slowValue && $closePrice > $fastValue) {
            $side = SignalSide::LONG;
            $score = $this->calculateScore($fastValue, $slowValue, $closePrice, true);
            $trigger = 'MA_BULLISH_CROSSOVER';
        }
        // Signal de vente : MA rapide croise en-dessous de la MA lente
        elseif ($fastValue < $slowValue && $closePrice < $fastValue) {
            $side = SignalSide::SHORT;
            $score = $this->calculateScore($fastValue, $slowValue, $closePrice, false);
            $trigger = 'MA_BEARISH_CROSSOVER';
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
                'fast_ma' => $fastValue,
                'slow_ma' => $slowValue,
                'close_price' => $closePrice,
                'parameters' => $this->parameters
            ]
        );
    }

    private function calculateScore(float $fastMA, float $slowMA, float $closePrice, bool $isBullish): float
    {
        $maDifference = abs($fastMA - $slowMA);
        $priceDistance = abs($closePrice - $fastMA);
        
        // Score basé sur la différence entre les MA et la distance du prix
        $baseScore = min(1.0, $maDifference / $slowMA * 100); // Normalisation
        $priceScore = min(1.0, 1.0 - ($priceDistance / $closePrice));
        
        return ($baseScore + $priceScore) / 2;
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


