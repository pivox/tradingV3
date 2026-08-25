<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;

final readonly class HyperliquidPaperLiveCandleWarmup
{
    private const PAGE_SIZE = 500;
    private const FAILURE = 'hyperliquid_paper_public_candle_warmup_invalid';

    public function __construct(
        private HyperliquidPaperPublicRestClientInterface $restClient,
    ) {
    }

    /**
     * @param array{BTC: string|null, ETH: string|null} $windowEnds
     * @return list<HyperliquidCandle>
     */
    public function candles(array $windowEnds): array
    {
        try {
            if (array_keys($windowEnds) !== ['BTC', 'ETH']) {
                throw new \InvalidArgumentException();
            }
            $instruments = new HyperliquidPaperInstrumentMap();
            $candles = [];
            foreach ($windowEnds as $coin => $encodedEnd) {
                if (!\is_string($encodedEnd)
                    || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $encodedEnd) !== 1
                ) {
                    throw new \InvalidArgumentException();
                }
                $upperBound = filter_var($encodedEnd, \FILTER_VALIDATE_INT);
                if (!\is_int($upperBound) || $upperBound < 0) {
                    throw new \InvalidArgumentException();
                }
                foreach (['1m' => 250, '5m' => 250, '15m' => 250, '1h' => 1_000] as $interval => $count) {
                    $step = $instruments->intervalMilliseconds($interval);
                    $end = intdiv($upperBound, $step) * $step - $step;
                    if ($interval === '1h') {
                        $end -= (($end % 14_400_000) + 14_400_000 - 10_800_000)
                            % 14_400_000;
                    }
                    $start = $end - (($count - 1) * $step);
                    if ($start < 0 || ($interval === '1h' && $start % 14_400_000 !== 0)) {
                        throw new \InvalidArgumentException();
                    }
                    for ($pageStart = $start; $pageStart <= $end;) {
                        $pageEnd = min($end, $pageStart + ((self::PAGE_SIZE - 1) * $step));
                        $rows = $this->restClient->candleSnapshot(
                            $coin,
                            $interval,
                            $pageStart,
                            $pageEnd,
                        );
                        $expected = $pageStart;
                        foreach ($rows as $row) {
                            $candle = HyperliquidCandle::fromApiRow($row, $coin, $interval);
                            if ($candle->startTime !== $expected || $candle->startTime > $pageEnd) {
                                throw new \InvalidArgumentException();
                            }
                            $candles[] = $candle;
                            $expected += $step;
                        }
                        if ($rows === [] || $expected !== $pageEnd + $step) {
                            throw new \InvalidArgumentException();
                        }
                        $pageStart = $expected;
                    }
                }
            }
            usort($candles, static function (HyperliquidCandle $left, HyperliquidCandle $right) use ($instruments): int {
                return [$left->closeTime, $instruments->normalizedSymbol($left->coin), $instruments->intervalMilliseconds($left->interval)]
                    <=> [$right->closeTime, $instruments->normalizedSymbol($right->coin), $instruments->intervalMilliseconds($right->interval)];
            });

            return $candles;
        } catch (\Throwable $exception) {
            if ($exception instanceof HyperliquidPaperLiveIntegrityException
                && $exception->getMessage() === self::FAILURE
            ) {
                throw $exception;
            }
            throw new HyperliquidPaperLiveIntegrityException(self::FAILURE, 0, $exception);
        }
    }
}
