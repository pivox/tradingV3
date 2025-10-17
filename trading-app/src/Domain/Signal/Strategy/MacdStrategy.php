<?php

declare(strict_types=1);

namespace App\Domain\Signal\Strategy;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;

class MacdStrategy implements StrategyInterface
{
    private array $parameters = [
        'fast_period' => 12,
        'slow_period' => 26,
        'signal_period' => 9,
        'min_score' => 0.6
    ];

    private bool $enabled = true;

    public function getName(): string
    {
        return 'MACD Strategy';
    }

    public function getDescription(): string
    {
        return 'Stratégie basée sur l\'indicateur MACD (Moving Average Convergence Divergence)';
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

        $macd = $indicators->getValue('macd');
        $macdSignal = $indicators->getValue('macd_signal');
        $macdHistogram = $indicators->getValue('macd_histogram');

        if ($macd === null || $macdSignal === null || $macdHistogram === null) {
            return null;
        }

        $macdValue = $macd->toFloat();
        $signalValue = $macdSignal->toFloat();
        $histogramValue = $macdHistogram->toFloat();

        $side = null;
        $score = 0.0;
        $trigger = null;

        // Signal d'achat : MACD croise au-dessus du signal
        if ($macdValue > $signalValue && $histogramValue > 0) {
            $side = SignalSide::LONG;
            $score = min(1.0, abs($histogramValue) * 10); // Normalisation approximative
            $trigger = 'MACD_BULLISH_CROSSOVER';
        }
        // Signal de vente : MACD croise en-dessous du signal
        elseif ($macdValue < $signalValue && $histogramValue < 0) {
            $side = SignalSide::SHORT;
            $score = min(1.0, abs($histogramValue) * 10); // Normalisation approximative
            $trigger = 'MACD_BEARISH_CROSSOVER';
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
                'macd_value' => $macdValue,
                'macd_signal' => $signalValue,
                'macd_histogram' => $histogramValue,
                'parameters' => $this->parameters
            ]
        );
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


