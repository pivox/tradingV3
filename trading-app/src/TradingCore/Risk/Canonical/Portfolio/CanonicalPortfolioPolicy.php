<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class CanonicalPortfolioPolicy
{
    private function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
        public string $configHash,
        public float $dailyLossRate,
        public float $dailyLossAbsoluteQuote,
        public string $quoteCurrency,
        public string $dayTimezone,
        public string $dayBoundaryLocal,
        public bool $includeUnrealizedLoss,
        public int $maxConcurrentPositions,
        public bool $includePendingEntries,
        public float $modeExposureRate,
    ) {
    }

    public static function fromSnapshot(EffectiveTradingConfigSnapshot $snapshot): self
    {
        if (!$snapshot->executable || $snapshot->blockers !== []) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_snapshot_not_executable');
        }
        if (!\is_string($snapshot->conditionCatalogHash)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->conditionCatalogHash) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->configHash) !== 1
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_hash_invalid');
        }

        $payload = $snapshot->payload();
        $mode = self::mapping($payload, 'mode', 'canonical_portfolio_policy_shape_invalid');
        $setup = self::mapping($payload, 'setup', 'canonical_portfolio_policy_shape_invalid');
        $exchange = self::mapping($payload, 'exchange', 'canonical_portfolio_policy_shape_invalid');
        $environment = self::mapping($payload, 'environment', 'canonical_portfolio_policy_shape_invalid');
        $safety = self::mapping($payload, 'safety', 'canonical_portfolio_policy_shape_invalid');
        $request = $snapshot->request;
        if (
            ($mode['mode_id'] ?? null) !== $request->modeId
            || ($mode['mode_version'] ?? null) !== $request->modeVersion
            || ($setup['setup_id'] ?? null) !== $request->setupId
            || ($setup['setup_version'] ?? null) !== $request->setupVersion
            || ($setup['side'] ?? null) !== $request->side
            || ($exchange['id'] ?? null) !== $request->exchange
            || ($environment['id'] ?? null) !== $request->environment
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_identity_mismatch');
        }
        if (
            ($safety['mainnet_write_enabled'] ?? null) !== false
            || ($environment['write_enabled'] ?? null) !== false
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_safety_invalid');
        }

        $risk = self::mapping($mode, 'risk', 'canonical_portfolio_policy_shape_invalid');
        $daily = self::definedDecision(
            $risk,
            'daily_loss_cap',
            'compound_percent_equity_and_quote_per_day',
            'canonical_portfolio_daily_policy_invalid',
        );
        $dailyValue = self::mapping($daily, 'value', 'canonical_portfolio_daily_policy_invalid');
        self::exactKeys($dailyValue, [
            'percent_equity',
            'absolute_quote',
            'quote_currency',
            'day_timezone',
            'day_boundary_local',
            'include_unrealized_loss',
        ], 'canonical_portfolio_daily_policy_invalid');
        $dailyPercent = self::positiveNumber($dailyValue['percent_equity'], 'canonical_portfolio_daily_policy_invalid');
        if ($dailyPercent > 100.0) {
            throw new CanonicalPortfolioException('canonical_portfolio_daily_policy_invalid');
        }
        $dailyAbsolute = self::positiveNumber($dailyValue['absolute_quote'], 'canonical_portfolio_daily_policy_invalid');
        $currency = $dailyValue['quote_currency'];
        $timezone = $dailyValue['day_timezone'];
        $boundary = $dailyValue['day_boundary_local'];
        $includeUnrealized = $dailyValue['include_unrealized_loss'];
        if (
            !\is_string($currency)
            || preg_match('/\A[A-Z][A-Z0-9]{2,11}\z/D', $currency) !== 1
            || !\is_string($timezone)
            || !self::validTimezone($timezone)
            || !\is_string($boundary)
            || !self::validBoundary($boundary)
            || !\is_bool($includeUnrealized)
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_daily_policy_invalid');
        }

        $concurrency = self::definedDecision(
            $risk,
            'max_concurrent_positions',
            'positions',
            'canonical_portfolio_concurrency_policy_invalid',
        );
        $concurrencyValue = self::mapping($concurrency, 'value', 'canonical_portfolio_concurrency_policy_invalid');
        self::exactKeys($concurrencyValue, ['limit', 'include_pending_entries'], 'canonical_portfolio_concurrency_policy_invalid');
        if (!\is_int($concurrencyValue['limit']) || $concurrencyValue['limit'] < 1 || !\is_bool($concurrencyValue['include_pending_entries'])) {
            throw new CanonicalPortfolioException('canonical_portfolio_concurrency_policy_invalid');
        }

        $exposure = self::decision($risk, 'mode_exposure_cap', 'canonical_portfolio_exposure_policy_unresolved');
        if (($exposure['state'] ?? null) !== 'defined' || !array_key_exists('value', $exposure) || $exposure['value'] === null) {
            throw new CanonicalPortfolioException('canonical_portfolio_exposure_policy_unresolved');
        }
        if (($exposure['unit'] ?? null) !== 'percent_equity_notional') {
            throw new CanonicalPortfolioException('canonical_portfolio_exposure_policy_invalid');
        }
        $exposurePercent = self::positiveNumber($exposure['value'], 'canonical_portfolio_exposure_policy_invalid');

        $expectedHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $snapshot->conditionCatalogHash);
        if (!hash_equals($expectedHash, $snapshot->configHash)) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_hash_mismatch');
        }

        return new self(
            $request->modeId,
            $request->modeVersion,
            $request->setupId,
            $request->setupVersion,
            $request->exchange,
            $request->environment,
            $request->side,
            $snapshot->configHash,
            self::percentageRate($dailyPercent, 'canonical_portfolio_daily_policy_invalid'),
            $dailyAbsolute,
            $currency,
            $timezone,
            $boundary,
            $includeUnrealized,
            $concurrencyValue['limit'],
            $concurrencyValue['include_pending_entries'],
            self::percentageRate($exposurePercent, 'canonical_portfolio_exposure_policy_invalid'),
        );
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function mapping(array $owner, string $key, string $reasonCode): array
    {
        $value = $owner[$key] ?? null;
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function decision(array $owner, string $key, string $reasonCode): array
    {
        return self::mapping($owner, $key, $reasonCode);
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function definedDecision(array $owner, string $key, string $unit, string $reasonCode): array
    {
        $decision = self::decision($owner, $key, $reasonCode);
        if (($decision['state'] ?? null) !== 'defined' || ($decision['unit'] ?? null) !== $unit || !array_key_exists('value', $decision)) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return $decision;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $keys
     */
    private static function exactKeys(array $value, array $keys, string $reasonCode): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new CanonicalPortfolioException($reasonCode);
        }
    }

    private static function positiveNumber(mixed $value, string $reasonCode): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !\is_finite((float) $value) || (float) $value <= 0.0) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return (float) $value;
    }

    private static function percentageRate(float $percentage, string $reasonCode): float
    {
        return CanonicalPortfolioDecimal::fromFloat($percentage, $reasonCode)
            ->dividedBy(BigDecimal::of('100'), 16, RoundingMode::UNNECESSARY)
            ->stripTrailingZeros()
            ->toFloat();
    }

    private static function validTimezone(string $timezone): bool
    {
        try {
            return (new \DateTimeZone($timezone))->getName() === $timezone;
        } catch (\Exception) {
            return false;
        }
    }

    private static function validBoundary(string $boundary): bool
    {
        if (preg_match('/\A(\d{2}):(\d{2}):(\d{2})\z/D', $boundary, $parts) !== 1) {
            return false;
        }

        return (int) $parts[1] < 24 && (int) $parts[2] < 60 && (int) $parts[3] < 60;
    }
}
