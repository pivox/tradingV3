<?php

declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\TradeEntry\Dto\MarketStructureSnapshot;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MarketStructureSampler
{
    private const ORDERBOOK_LEVELS = 10;
    private const LIQUIDITY_TARGET_USD = 150000.0;
    private const VOLUME_WINDOW = 20;

    public function __construct(
        private readonly BitmartHttpClientPublic $publicHttpClient,
        private readonly LoggerInterface $positionsLogger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function sample(string $symbol, float $contractSize, float $midPrice): MarketStructureSnapshot
    {
        $depthUsd = null;
        $liquidityScore = null;
        $latencyRestMs = null;
        try {
            $startedAt = microtime(true);
            $orderBook = $this->publicHttpClient->getOrderBook($symbol, self::ORDERBOOK_LEVELS);
            $latencyRestMs = (microtime(true) - $startedAt) * 1000;
            $depthUsd = $this->computeDepthUsd($orderBook, $contractSize);
            if ($depthUsd !== null) {
                $liquidityScore = min(1.0, $depthUsd / self::LIQUIDITY_TARGET_USD);
            }
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('market_sampler.depth_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }

        $volatilityPct = null;
        $volumeRatio = null;

        try {
            $nowUtc = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $endTime = $nowUtc->getTimestamp();
            $startTime = max(0, $endTime - (self::VOLUME_WINDOW * 60));

            $klines = $this->publicHttpClient->getMarkPriceKline(
                $symbol,
                step: 1,
                limit: self::VOLUME_WINDOW,
                startTime: $startTime,
                endTime: $endTime,
            );
            if ($klines !== []) {
                $last = end($klines);
                $volatilityPct = $this->computeVolatilityPct($last);
                $volumeRatio = $this->computeVolumeRatio($klines);
            }
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('market_sampler.kline_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }

        return new MarketStructureSnapshot(
            depthTopUsd: $depthUsd,
            bookLiquidityScore: $liquidityScore,
            volatilityPct1m: $volatilityPct,
            volumeRatio: $volumeRatio,
            latencyRestMs: $latencyRestMs !== null ? round($latencyRestMs, 2) : null,
            latencyWsMs: null,
        );
    }

    /**
     * @param array{bids: array<int, array{0:string,1:string}>, asks: array<int, array{0:string,1:string}>} $orderBook
     */
    private function computeDepthUsd(array $orderBook, float $contractSize): ?float
    {
        if (!isset($orderBook['bids'], $orderBook['asks'])) {
            return null;
        }

        $accumulator = 0.0;
        $levels = array_merge(
            array_slice($orderBook['bids'], 0, 5),
            array_slice($orderBook['asks'], 0, 5)
        );
        foreach ($levels as $row) {
            if (!isset($row[0], $row[1])) {
                continue;
            }
            $price = (float)$row[0];
            $contracts = (float)$row[1];
            if ($price <= 0.0 || $contracts <= 0.0) {
                continue;
            }
            $accumulator += $price * $contracts * max($contractSize, 1.0);
        }

        return $accumulator > 0.0 ? $accumulator : null;
    }

    /**
     * @param array<string,mixed> $kline
     */
    private function computeVolatilityPct(array $kline): ?float
    {
        $high = isset($kline['high_price']) ? (float)$kline['high_price'] : (float)($kline['high'] ?? 0.0);
        $low = isset($kline['low_price']) ? (float)$kline['low_price'] : (float)($kline['low'] ?? 0.0);
        $close = isset($kline['close_price']) ? (float)$kline['close_price'] : (float)($kline['close'] ?? 0.0);

        if ($close <= 0.0 || $high <= 0.0 || $low <= 0.0) {
            return null;
        }

        $range = max(0.0, $high - $low);

        return $range > 0.0 ? round($range / $close, 6) : null;
    }

    /**
     * @param array<int,array<string,mixed>> $klines
     */
    private function computeVolumeRatio(array $klines): ?float
    {
        if (count($klines) < 2) {
            return null;
        }

        $volumes = [];
        foreach ($klines as $row) {
            $volume = isset($row['volume']) ? (float)$row['volume'] : (float)($row['base_volume'] ?? 0.0);
            if ($volume <= 0.0) {
                continue;
            }
            $volumes[] = $volume;
        }

        if (count($volumes) < 2) {
            return null;
        }

        $latest = (float)array_pop($volumes);
        $average = array_sum($volumes) / max(count($volumes), 1);
        if ($average <= 0.0) {
            return null;
        }

        return round($latest / $average, 4);
    }
}
