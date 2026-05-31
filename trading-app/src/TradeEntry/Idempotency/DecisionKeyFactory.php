<?php

declare(strict_types=1);

namespace App\TradeEntry\Idempotency;

use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Types\Side;

final class DecisionKeyFactory
{
    /**
     * @return array{
     *     exchange: string,
     *     market_type: string,
     *     symbol: string,
     *     timeframe: string,
     *     candle_open_ts: int,
     *     side: string,
     *     strategy_profile: string,
     *     strategy_version: string
     * }
     */
    public function create(
        ?ExchangeContext $context,
        string $symbol,
        ?string $timeframe,
        mixed $candleOpenTs,
        Side|string|null $side,
        ?string $strategyProfile,
        ?string $strategyVersion,
        ?\DateTimeInterface $evaluatedAt = null,
    ): array {
        $resolved = ExchangeContext::resolve($context);
        $tf = $this->normalizeTimeframe($timeframe);

        return [
            'exchange' => $resolved->exchange->value,
            'market_type' => $resolved->marketType->value,
            'symbol' => strtoupper(trim($symbol)),
            'timeframe' => $tf,
            'candle_open_ts' => $this->normalizeCandleOpenTs($candleOpenTs, $tf, $evaluatedAt),
            'side' => $this->normalizeSide($side),
            'strategy_profile' => $this->normalizeToken($strategyProfile, 'default'),
            'strategy_version' => $this->normalizeToken($strategyVersion, 'unknown'),
        ];
    }

    public function key(
        ?ExchangeContext $context,
        string $symbol,
        ?string $timeframe,
        mixed $candleOpenTs,
        Side|string|null $side,
        ?string $strategyProfile,
        ?string $strategyVersion,
        ?\DateTimeInterface $evaluatedAt = null,
    ): string {
        $parts = $this->create(
            context: $context,
            symbol: $symbol,
            timeframe: $timeframe,
            candleOpenTs: $candleOpenTs,
            side: $side,
            strategyProfile: $strategyProfile,
            strategyVersion: $strategyVersion,
            evaluatedAt: $evaluatedAt,
        );

        return implode(':', [
            $parts['exchange'],
            $parts['market_type'],
            $parts['symbol'],
            $parts['timeframe'],
            (string) $parts['candle_open_ts'],
            $parts['side'],
            $parts['strategy_profile'],
            $parts['strategy_version'],
        ]);
    }

    /**
     * @return array{
     *     exchange?: string,
     *     market_type?: string,
     *     symbol?: string,
     *     timeframe?: string,
     *     candle_open_ts?: int,
     *     side?: string,
     *     strategy_profile?: string,
     *     strategy_version?: string
     * }
     */
    public function parse(string $decisionKey): array
    {
        $parts = explode(':', trim($decisionKey), 8);
        if (\count($parts) !== 8) {
            return [];
        }

        [$exchange, $marketType, $symbol, $timeframe, $candleOpenTs, $side, $profile, $version] = $parts;
        if ($exchange === '' || $marketType === '' || $symbol === '' || $timeframe === '' || !is_numeric($candleOpenTs)) {
            return [];
        }

        return [
            'exchange' => strtolower($exchange),
            'market_type' => strtolower($marketType),
            'symbol' => strtoupper($symbol),
            'timeframe' => strtolower($timeframe),
            'candle_open_ts' => (int) $candleOpenTs,
            'side' => strtolower($side),
            'strategy_profile' => strtolower($profile),
            'strategy_version' => $version,
        ];
    }

    public function normalizeCandleOpenTs(
        mixed $candleOpenTs,
        ?string $timeframe,
        ?\DateTimeInterface $evaluatedAt = null,
    ): int {
        $timestamp = $this->timestamp($candleOpenTs);
        if ($timestamp === null && $evaluatedAt !== null) {
            $timestamp = $evaluatedAt->getTimestamp();
        }
        if ($timestamp === null) {
            $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        }

        $seconds = $this->timeframeSeconds($timeframe);
        if ($seconds === null || $seconds <= 0) {
            return $timestamp;
        }

        return intdiv($timestamp, $seconds) * $seconds;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value > 9999999999 ? (int) round($value / 1000) : $value;
        }
        if (is_float($value)) {
            $int = (int) round($value);
            return $int > 9999999999 ? (int) round($int / 1000) : $int;
        }
        if (is_string($value) && trim($value) !== '') {
            $value = trim($value);
            if (is_numeric($value)) {
                $int = (int) $value;
                return $int > 9999999999 ? (int) round($int / 1000) : $int;
            }

            try {
                return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function timeframeSeconds(?string $timeframe): ?int
    {
        $timeframe = $this->normalizeTimeframe($timeframe);
        if (!preg_match('/^(\d+)([smhd])$/', $timeframe, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        return match ($matches[2]) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            default => null,
        };
    }

    private function normalizeTimeframe(?string $timeframe): string
    {
        $timeframe = strtolower(trim((string) $timeframe));

        return $timeframe !== '' ? $timeframe : 'unknown';
    }

    private function normalizeSide(Side|string|null $side): string
    {
        if ($side instanceof Side) {
            return $side->value;
        }

        $side = strtolower(trim((string) $side));
        if ($side === 'long' || $side === 'short') {
            return $side;
        }

        return 'unknown';
    }

    private function normalizeToken(?string $value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $fallback;
        }

        return str_replace(':', '_', $value);
    }
}
