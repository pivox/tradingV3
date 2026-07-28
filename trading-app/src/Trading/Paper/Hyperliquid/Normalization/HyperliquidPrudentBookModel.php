<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class HyperliquidPrudentBookModel
{
    public const NAME = 'hl_candle_atr_top_v1';
    public const VERSION = '1.0.0';
    private const ATR_PERIOD = 14;
    private const MIN_SPREAD_BPS = '2';
    private const MAX_SPREAD_BPS = '50';
    private const VOLATILITY_MULTIPLIER = '0.15';
    private const CALCULATION_SCALE = 18;

    private ?string $coin = null;
    private ?string $interval = null;
    private ?HyperliquidCandle $previous = null;

    /** @var list<BigDecimal> */
    private array $trueRanges = [];

    /**
     * @return array{
     *     bid: string,
     *     ask: string,
     *     size: string,
     *     spread_bps: string,
     *     atr: string,
     *     model_name: string,
     *     model_version: string
     * }|null
     */
    public function push(HyperliquidCandle $candle): ?array
    {
        $this->assertCanAppend($candle);

        $trueRange = $candle->trueRange($this->previous);
        $trueRanges = $this->trueRanges;
        $trueRanges[] = $trueRange;
        if (\count($trueRanges) > self::ATR_PERIOD) {
            array_shift($trueRanges);
        }

        /** @var non-empty-list<BigDecimal> $trueRanges */
        $atr = $this->average($trueRanges);
        $result = $this->book($candle, $atr);

        $this->coin = $candle->coin;
        $this->interval = $candle->interval;
        $this->previous = $candle;
        $this->trueRanges = $trueRanges;

        return $result;
    }

    private function assertCanAppend(HyperliquidCandle $candle): void
    {
        if ($this->previous === null) {
            return;
        }
        if ($candle->coin !== $this->coin || $candle->interval !== $this->interval) {
            throw new \InvalidArgumentException('hyperliquid_prudent_book_stream_mismatch');
        }

        $intervalMilliseconds = (new HyperliquidPaperInstrumentMap())
            ->intervalMilliseconds($candle->interval);
        if ($this->previous->startTime > \PHP_INT_MAX - $intervalMilliseconds
            || $candle->startTime !== $this->previous->startTime + $intervalMilliseconds
        ) {
            throw new \InvalidArgumentException('hyperliquid_prudent_book_sequence_invalid');
        }
    }

    /** @param non-empty-list<BigDecimal> $ranges */
    private function average(array $ranges): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($ranges as $range) {
            $sum = $sum->plus($range);
        }

        return $sum->dividedBy(\count($ranges), self::CALCULATION_SCALE, RoundingMode::HALF_EVEN);
    }

    /**
     * @return array{
     *     bid: string,
     *     ask: string,
     *     size: string,
     *     spread_bps: string,
     *     atr: string,
     *     model_name: string,
     *     model_version: string
     * }|null
     */
    private function book(HyperliquidCandle $candle, BigDecimal $atr): ?array
    {
        if (!$candle->volume->isGreaterThan(0) || $candle->tradeCount === 0) {
            return null;
        }

        $volatilityRange = $candle->range()->isGreaterThanOrEqualTo($atr)
            ? $candle->range()
            : $atr;
        $volatilityBps = $volatilityRange
            ->multipliedBy(10_000)
            ->dividedBy($candle->close, self::CALCULATION_SCALE, RoundingMode::HALF_EVEN);
        $spreadBps = $volatilityBps
            ->multipliedBy(self::VOLATILITY_MULTIPLIER)
            ->toScale(self::CALCULATION_SCALE, RoundingMode::HALF_EVEN);
        if ($spreadBps->isLessThan(self::MIN_SPREAD_BPS)) {
            $spreadBps = BigDecimal::of(self::MIN_SPREAD_BPS);
        } elseif ($spreadBps->isGreaterThan(self::MAX_SPREAD_BPS)) {
            $spreadBps = BigDecimal::of(self::MAX_SPREAD_BPS);
        }

        $halfSpreadRatio = $spreadBps
            ->dividedBy(20_000, self::CALCULATION_SCALE, RoundingMode::HALF_EVEN);
        $bid = $candle->close->multipliedBy(BigDecimal::one()->minus($halfSpreadRatio));
        $ask = $candle->close->multipliedBy(BigDecimal::one()->plus($halfSpreadRatio));
        $size = $candle->volume
            ->dividedBy($candle->tradeCount, self::CALCULATION_SCALE, RoundingMode::HALF_EVEN);

        return [
            'bid' => self::canonical($bid),
            'ask' => self::canonical($ask),
            'size' => self::canonical($size),
            'spread_bps' => self::canonical($spreadBps),
            'atr' => self::canonical($atr),
            'model_name' => self::NAME,
            'model_version' => self::VERSION,
        ];
    }

    private static function canonical(BigDecimal $value): string
    {
        return (string) $value->stripTrailingZeros();
    }
}
