<?php

declare(strict_types=1);

namespace App\Domain\Signal\Strategy;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;

class BollingerBandsStrategy implements StrategyInterface
{
    private array $parameters = [
        'period' => 20,
        'std_dev' => 2.0,
        'min_score' => 0.6
    ];

    private bool $enabled = true;

    public function getName(): string
    {
        return 'Bollinger Bands Strategy';
    }

    public function getDescription(): string
    {
        return 'Stratégie basée sur les Bandes de Bollinger';
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

        $bbUpper = $indicators->getValue('bb_upper');
        $bbMiddle = $indicators->getValue('bb_middle');
        $bbLower = $indicators->getValue('bb_lower');

        if ($bbUpper === null || $bbMiddle === null || $bbLower === null) {
            return null;
        }

        $upperValue = $bbUpper->toFloat();
        $middleValue = $bbMiddle->toFloat();
        $lowerValue = $bbLower->toFloat();
        $closePrice = $kline->closePrice->toFloat();

        $side = null;
        $score = 0.0;
        $trigger = null;

        // Signal d'achat : prix touche la bande inférieure
        if ($closePrice <= $lowerValue) {
            $side = SignalSide::LONG;
            $score = $this->calculateScore($closePrice, $lowerValue, $middleValue, true);
            $trigger = 'BB_LOWER_TOUCH';
        }
        // Signal de vente : prix touche la bande supérieure
        elseif ($closePrice >= $upperValue) {
            $side = SignalSide::SHORT;
            $score = $this->calculateScore($closePrice, $upperValue, $middleValue, false);
            $trigger = 'BB_UPPER_TOUCH';
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
                'bb_upper' => $upperValue,
                'bb_middle' => $middleValue,
                'bb_lower' => $lowerValue,
                'close_price' => $closePrice,
                'parameters' => $this->parameters
            ]
        );
    }

    private function calculateScore(float $price, float $bandValue, float $middleValue, bool $isLower): float
    {
        $bandWidth = abs($middleValue - $bandValue);
        $distanceFromBand = abs($price - $bandValue);
        
        // Plus le prix est proche de la bande, plus le score est élevé
        return min(1.0, max(0.0, 1.0 - ($distanceFromBand / $bandWidth)));
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


