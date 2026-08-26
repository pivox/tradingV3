<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;

final readonly class HyperliquidPaperLiveCandleWarmup
{
    private const PAGE_SIZE = 500;
    private const MAXIMUM_CATCHUP_PAGES = 24;
    private const FAILURE = 'hyperliquid_paper_public_candle_warmup_invalid';

    public function __construct(
        private HyperliquidPaperPublicRestClientInterface $restClient,
    ) {
    }

    /**
     * @param array<string, string|null> $windowEnds
     * @return list<HyperliquidCandle>
     */
    public function candles(array $windowEnds, ?int $currentUpperBound = null): array
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
                $catchupUpperBound = $currentUpperBound ?? $upperBound;
                if ($catchupUpperBound < $upperBound) {
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
                    array_push($candles, ...$this->fetchRange(
                        $coin, $interval, $start, $end, $step, 2,
                    ));
                    $currentEnd = intdiv($catchupUpperBound, $step) * $step - $step;
                    if ($currentEnd > $end) {
                        array_push($candles, ...$this->fetchRange(
                            $coin,
                            $interval,
                            $end + $step,
                            $currentEnd,
                            $step,
                            self::MAXIMUM_CATCHUP_PAGES,
                        ));
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

    /**
     * @param array<string, int> $frontiers
     * @return list<HyperliquidCandle>
     */
    public function catchupCandles(array $frontiers, int $currentUpperBound): array
    {
        try {
            if ($currentUpperBound < 0) {
                throw new \InvalidArgumentException();
            }
            $expectedStreams = [];
            foreach (['BTC', 'ETH'] as $coin) {
                foreach (['1m', '5m', '15m', '1h'] as $interval) {
                    $expectedStreams[] = $coin . '/' . $interval;
                }
            }
            $actualStreams = array_keys($frontiers);
            sort($actualStreams, \SORT_STRING);
            sort($expectedStreams, \SORT_STRING);
            if ($actualStreams !== $expectedStreams) {
                throw new \InvalidArgumentException();
            }

            $instruments = new HyperliquidPaperInstrumentMap();
            $candles = [];
            foreach (['BTC', 'ETH'] as $coin) {
                foreach (['1m', '5m', '15m', '1h'] as $interval) {
                    $step = $instruments->intervalMilliseconds($interval);
                    $frontier = $frontiers[$coin . '/' . $interval] ?? null;
                    if (!\is_int($frontier) || $frontier < 0 || $frontier % $step !== 0) {
                        throw new \InvalidArgumentException();
                    }
                    $end = intdiv($currentUpperBound, $step) * $step - $step;
                    if ($frontier > $end) {
                        throw new \InvalidArgumentException();
                    }
                    if ($frontier === $end) {
                        continue;
                    }
                    array_push($candles, ...$this->fetchRange(
                        $coin,
                        $interval,
                        $frontier + $step,
                        $end,
                        $step,
                        self::MAXIMUM_CATCHUP_PAGES,
                    ));
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

    /** @return list<HyperliquidCandle> */
    private function fetchRange(
        string $coin,
        string $interval,
        int $start,
        int $end,
        int $step,
        int $maximumPages,
    ): array {
        $candles = [];
        $pages = 0;
        for ($pageStart = $start; $pageStart <= $end;) {
            if (++$pages > $maximumPages) {
                throw new \InvalidArgumentException();
            }
            $pageEnd = min($end, $pageStart + ((self::PAGE_SIZE - 1) * $step));
            $rows = $this->restClient->candleSnapshot(
                $coin, $interval, $pageStart, $pageEnd,
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

        return $candles;
    }
}
