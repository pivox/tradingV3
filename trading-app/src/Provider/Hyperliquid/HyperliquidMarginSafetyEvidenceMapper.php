<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;
use App\Provider\Hyperliquid\Dto\HyperliquidMarginTierEvidence;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class HyperliquidMarginSafetyEvidenceMapper
{
    private const MAX_SUPPORTED_TIERS = 3;
    private const RATE_SCALE = 36;

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $activeAssetData
     */
    public function map(
        array $meta,
        array $activeAssetData,
        string $symbol,
        string $accountAddress,
        \DateTimeImmutable $observedAt,
    ): HyperliquidMarginSafetyEvidence {
        $asset = $this->asset($meta, $symbol);
        $coin = strtoupper((string) ($asset['name'] ?? ''));
        $universeMaxLeverage = $this->leverage($asset['maxLeverage'] ?? null);
        $tableId = array_key_exists('marginTableId', $asset)
            ? $this->tableId($asset['marginTableId'])
            : $universeMaxLeverage;
        if ($tableId < 50 && $tableId !== $universeMaxLeverage) {
            throw new \InvalidArgumentException('hyperliquid_single_tier_identity_invalid');
        }
        $rows = $tableId < 50
            ? [['lowerBound' => '0', 'maxLeverage' => $tableId]]
            : $this->tableRows($meta, $tableId);
        $tiers = $this->tiers($rows, $universeMaxLeverage);

        $observedUser = strtolower($this->identity($activeAssetData['user'] ?? null, '/^0x[a-f0-9]{40}$/D'));
        $observedCoin = strtoupper($this->identity($activeAssetData['coin'] ?? null, '/^[A-Z0-9][A-Z0-9_-]{0,31}$/D'));
        $expectedAccount = strtolower($accountAddress);
        $leverage = $activeAssetData['leverage'] ?? null;
        $mode = is_array($leverage) ? ($leverage['type'] ?? null) : null;
        $observedLeverage = is_array($leverage) ? $this->leverage($leverage['value'] ?? null) : 0;
        if ($observedUser !== $expectedAccount || $observedCoin !== $coin
            || !is_string($mode) || !in_array($mode, ['isolated', 'cross'], true)
            || preg_match('/^0x[a-f0-9]{40}$/D', $expectedAccount) !== 1
        ) {
            throw new \InvalidArgumentException('hyperliquid_active_asset_identity_invalid');
        }

        return new HyperliquidMarginSafetyEvidence(
            strtoupper($symbol),
            $coin,
            $tableId,
            $universeMaxLeverage,
            $tiers,
            $expectedAccount,
            $observedUser,
            $observedCoin,
            $mode,
            $observedLeverage,
            $observedAt,
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
        $matches = array_values(array_filter($universe, static fn (mixed $asset): bool => is_array($asset)
            && strtoupper((string) ($asset['name'] ?? '')) . 'USDT' === strtoupper($symbol)));
        if (count($matches) !== 1 || !is_array($matches[0])) {
            throw new \InvalidArgumentException('hyperliquid_margin_asset_invalid');
        }

        return $matches[0];
    }

    /**
     * @param array<string,mixed> $meta
     *
     * @return array<mixed>
     */
    private function tableRows(array $meta, int $tableId): array
    {
        $tables = $meta['marginTables'] ?? null;
        if (!is_array($tables)) {
            throw new \InvalidArgumentException('hyperliquid_margin_tables_missing');
        }
        $matches = [];
        foreach ($tables as $table) {
            if (is_array($table) && count($table) === 2 && ($table[0] ?? null) === $tableId) {
                $matches[] = $table;
            }
        }
        if (count($matches) !== 1 || !is_array($matches[0][1] ?? null)
            || !is_array($matches[0][1]['marginTiers'] ?? null)
        ) {
            throw new \InvalidArgumentException('hyperliquid_margin_table_invalid');
        }

        return $matches[0][1]['marginTiers'];
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<HyperliquidMarginTierEvidence>
     */
    private function tiers(array $rows, int $universeMaxLeverage): array
    {
        if ($rows === [] || !array_is_list($rows) || count($rows) > self::MAX_SUPPORTED_TIERS) {
            throw new \InvalidArgumentException('hyperliquid_margin_tier_count_invalid');
        }
        $tiers = [];
        $previousBound = null;
        $previousLeverage = null;
        $previousRate = null;
        $deduction = BigDecimal::zero();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('hyperliquid_margin_tier_invalid');
            }
            $bound = $this->nonNegativeDecimal($row['lowerBound'] ?? null);
            $maxLeverage = $this->leverage($row['maxLeverage'] ?? null);
            if (($index === 0 && (!$bound->isZero() || $maxLeverage !== $universeMaxLeverage))
                || ($previousBound instanceof BigDecimal && $bound->isLessThanOrEqualTo($previousBound))
                || ($previousLeverage !== null && $maxLeverage >= $previousLeverage)
            ) {
                throw new \InvalidArgumentException('hyperliquid_margin_tier_order_invalid');
            }
            // Upward rate rounding is conservative for liquidation distance; deductions remain canonical decimals.
            $rate = BigDecimal::one()->dividedBy(2 * $maxLeverage, self::RATE_SCALE, RoundingMode::UP);
            if ($previousRate instanceof BigDecimal) {
                $deduction = $deduction->plus($bound->multipliedBy($rate->minus($previousRate)));
            }
            $tiers[] = new HyperliquidMarginTierEvidence(
                $this->decimalString($bound),
                $maxLeverage,
                $this->decimalString($rate),
                $this->decimalString($deduction),
            );
            $previousBound = $bound;
            $previousLeverage = $maxLeverage;
            $previousRate = $rate;
        }

        return $tiers;
    }

    private function tableId(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000) {
            throw new \InvalidArgumentException('hyperliquid_margin_integer_invalid');
        }

        return $value;
    }

    private function leverage(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 50) {
            throw new \InvalidArgumentException('hyperliquid_margin_leverage_invalid');
        }

        return $value;
    }

    private function nonNegativeDecimal(mixed $value): BigDecimal
    {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_margin_decimal_invalid');
        }
        $decimal = BigDecimal::of($value);
        if ($decimal->isNegative()) {
            throw new \InvalidArgumentException('hyperliquid_margin_decimal_invalid');
        }

        return $decimal;
    }

    private function identity(mixed $value, string $pattern): string
    {
        if (!is_string($value) || preg_match($pattern, strtolower($value)) !== 1 && preg_match($pattern, strtoupper($value)) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_active_asset_identity_invalid');
        }

        return $value;
    }

    private function decimalString(BigDecimal $value): string
    {
        $normalized = (string) $value;
        return str_contains($normalized, '.') ? rtrim(rtrim($normalized, '0'), '.') : $normalized;
    }
}
