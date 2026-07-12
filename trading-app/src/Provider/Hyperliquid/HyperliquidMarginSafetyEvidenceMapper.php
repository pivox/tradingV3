<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class HyperliquidMarginSafetyEvidenceMapper
{
    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $activeAssetData
     */
    public function map(
        array $meta,
        array $activeAssetData,
        string $symbol,
        string $notional,
        int $requestedLeverage,
        string $accountAddress,
        \DateTimeImmutable $observedAt,
    ): HyperliquidMarginSafetyEvidence {
        $asset = $this->asset($meta, $symbol);
        $tableId = $this->positiveInt($asset['marginTableId'] ?? null);
        $assetMaxLeverage = $this->positiveInt($asset['maxLeverage'] ?? null);
        $notionalDecimal = $this->positiveDecimal($notional);
        $tiers = $tableId < 50
            ? [['lowerBound' => BigDecimal::zero(), 'maxLeverage' => $assetMaxLeverage]]
            : $this->tiers($meta, $tableId);
        if ($tableId < 50 && $assetMaxLeverage !== $tableId) {
            throw new \InvalidArgumentException('hyperliquid_single_tier_identity_invalid');
        }

        $deduction = BigDecimal::zero();
        $selected = null;
        $previousRate = null;
        foreach ($tiers as $tier) {
            $rate = BigDecimal::one()->dividedBy(2 * $tier['maxLeverage'], 18, RoundingMode::HALF_UP);
            if ($previousRate instanceof BigDecimal) {
                $deduction = $deduction->plus($tier['lowerBound']->multipliedBy($rate->minus($previousRate)));
            }
            if ($notionalDecimal->isGreaterThanOrEqualTo($tier['lowerBound'])) {
                $selected = [
                    'lowerBound' => $tier['lowerBound'],
                    'maxLeverage' => $tier['maxLeverage'],
                    'rate' => $rate,
                    'deduction' => $deduction,
                ];
            }
            $previousRate = $rate;
        }
        if (!is_array($selected) || $requestedLeverage < 1 || $requestedLeverage > $selected['maxLeverage']) {
            throw new \InvalidArgumentException('hyperliquid_margin_tier_leverage_invalid');
        }

        $leverage = $activeAssetData['leverage'] ?? null;
        if (!is_array($leverage)
            || ($leverage['type'] ?? null) !== 'isolated'
            || $this->positiveInt($leverage['value'] ?? null) !== $requestedLeverage
            || preg_match('/^0x[a-f0-9]{40}$/D', $accountAddress) !== 1
        ) {
            throw new \InvalidArgumentException('hyperliquid_isolated_account_evidence_invalid');
        }

        return new HyperliquidMarginSafetyEvidence(
            symbol: strtoupper($symbol),
            notional: $this->decimalString($notionalDecimal),
            marginTableId: $tableId,
            tierLowerBound: $this->decimalString($selected['lowerBound']),
            tierMaxLeverage: $selected['maxLeverage'],
            maintenanceMarginRate: $this->decimalString($selected['rate']),
            maintenanceMarginDeduction: $this->decimalString($selected['deduction']),
            accountAddress: $accountAddress,
            accountMarginMode: 'isolated',
            accountLeverage: $requestedLeverage,
            observedAt: $observedAt,
        );
    }

    /**
     * @param array<string,mixed> $meta
     *
     * @return array<string,mixed>
     */
    private function asset(array $meta, string $symbol): array
    {
        $universe = $meta['universe'] ?? null;
        if (!is_array($universe)) {
            throw new \InvalidArgumentException('hyperliquid_margin_universe_missing');
        }
        foreach ($universe as $candidate) {
            if (is_array($candidate)
                && strtoupper((string) ($candidate['name'] ?? '')) . 'USDT' === strtoupper($symbol)
            ) {
                return $candidate;
            }
        }

        throw new \InvalidArgumentException('hyperliquid_margin_asset_missing');
    }

    /**
     * @param array<string,mixed> $meta
     *
     * @return list<array{lowerBound:BigDecimal,maxLeverage:int}>
     */
    private function tiers(array $meta, int $tableId): array
    {
        $tables = $meta['marginTables'] ?? null;
        if (!is_array($tables)) {
            throw new \InvalidArgumentException('hyperliquid_margin_tables_missing');
        }
        $rows = null;
        foreach ($tables as $table) {
            if (is_array($table) && count($table) === 2 && $this->positiveInt($table[0] ?? null) === $tableId) {
                $definition = $table[1] ?? null;
                $rows = is_array($definition) ? ($definition['marginTiers'] ?? null) : null;
                break;
            }
        }
        if (!is_array($rows) || $rows === []) {
            throw new \InvalidArgumentException('hyperliquid_margin_table_missing');
        }

        $tiers = [];
        $previousBound = null;
        $previousLeverage = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('hyperliquid_margin_tier_invalid');
            }
            $bound = $this->nonNegativeDecimal($row['lowerBound'] ?? null);
            $maxLeverage = $this->positiveInt($row['maxLeverage'] ?? null);
            if (($previousBound === null && !$bound->isZero())
                || ($previousBound instanceof BigDecimal && $bound->isLessThanOrEqualTo($previousBound))
                || ($previousLeverage !== null && $maxLeverage > $previousLeverage)
            ) {
                throw new \InvalidArgumentException('hyperliquid_margin_tier_order_invalid');
            }
            $tiers[] = ['lowerBound' => $bound, 'maxLeverage' => $maxLeverage];
            $previousBound = $bound;
            $previousLeverage = $maxLeverage;
        }

        return $tiers;
    }

    private function positiveInt(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000) {
            throw new \InvalidArgumentException('hyperliquid_margin_integer_invalid');
        }

        return $value;
    }

    private function positiveDecimal(string $value): BigDecimal
    {
        $decimal = $this->nonNegativeDecimal($value);
        if ($decimal->isZero()) {
            throw new \InvalidArgumentException('hyperliquid_margin_notional_invalid');
        }

        return $decimal;
    }

    private function nonNegativeDecimal(mixed $value): BigDecimal
    {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9]|\.0)?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_margin_decimal_invalid');
        }
        try {
            $decimal = BigDecimal::of($value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('hyperliquid_margin_decimal_invalid');
        }
        if ($decimal->isLessThan(BigDecimal::zero())) {
            throw new \InvalidArgumentException('hyperliquid_margin_decimal_invalid');
        }

        return $decimal;
    }

    private function decimalString(BigDecimal $value): string
    {
        $normalized = (string) $value;
        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }

        return $normalized === '' ? '0' : $normalized;
    }
}
