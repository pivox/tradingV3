<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;

/** Frozen hypothetical OHLCV costs; neither venue quotes nor historical tariff evidence. */
final readonly class ResearchCostAssumptions
{
    private const RATES = ['entry_spread_rate', 'stop_spread_rate', 'target_spread_rate', 'entry_slippage_rate', 'stop_slippage_rate', 'target_slippage_rate', 'funding_provision_rate'];

    /** @param array<string, array<string, float>> $profiles */
    private function __construct(public array $profiles, public string $hash, public string $frozenAt)
    {
    }

    /** @param array<string, mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        $keys = array_keys($manifest);
        sort($keys);
        $expected = ['schema_version', 'frozen_at', 'fee_basis', 'assumption_status', 'units', 'profiles', 'assumption_hash'];
        sort($expected);
        if ($keys !== $expected
            || ($manifest['schema_version'] ?? null) !== 'research-cost-assumptions.v1'
            || ($manifest['fee_basis'] ?? null) !== 'fake_local_policy_not_historical_binance'
            || ($manifest['assumption_status'] ?? null) !== 'hypothetical_ohlcv_costs'
            || ($manifest['units'] ?? null) !== 'fraction_of_notional'
            || !is_string($manifest['frozen_at'])
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $manifest['frozen_at']) !== 1
            || !self::validInstant($manifest['frozen_at'])
            || !is_array($manifest['profiles']) || count($manifest['profiles']) !== 2
            || array_diff(array_keys($manifest['profiles']), ['baseline', 'adverse']) !== []
        ) {
            throw new \InvalidArgumentException('research_cost_assumptions_invalid');
        }
        $profiles = [];
        foreach ($manifest['profiles'] as $name => $profile) {
            if (!is_array($profile)) {
                throw new \InvalidArgumentException('research_cost_assumptions_invalid');
            }
            $profileKeys = array_keys($profile);
            $rates = self::RATES;
            sort($profileKeys);
            sort($rates);
            if ($profileKeys !== $rates) {
                throw new \InvalidArgumentException('research_cost_assumptions_invalid');
            }
            foreach (self::RATES as $field) {
                $value = $profile[$field];
                if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < 0.0 || $value >= 1.0) {
                    throw new \InvalidArgumentException('research_cost_assumptions_invalid');
                }
                $profiles[$name][$field] = (float) $value;
            }
        }
        foreach (self::RATES as $field) {
            if ($profiles['adverse'][$field] < $profiles['baseline'][$field]) {
                throw new \InvalidArgumentException('research_cost_assumptions_invalid');
            }
        }
        $payload = $manifest;
        unset($payload['assumption_hash']);
        if (!is_string($manifest['assumption_hash']) || !hash_equals(CanonicalBacktestRuleEvaluator::canonicalHash($payload), $manifest['assumption_hash'])) {
            throw new \InvalidArgumentException('research_cost_hash_invalid');
        }

        return new self($profiles, $manifest['assumption_hash'], $manifest['frozen_at']);
    }

    /** @return array<string, float> */
    public function profile(string $name): array
    {
        return $this->profiles[$name] ?? throw new \InvalidArgumentException('research_cost_profile_invalid');
    }

    private static function validInstant(string $value): bool
    {
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $instant instanceof \DateTimeImmutable
            && $instant->format('Y-m-d\TH:i:s\Z') === $value
            && $instant <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
