<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

final class NetPnlCertificationService
{
    /**
     * @param list<TradeFill> $entryFills
     * @param list<TradeFill> $exitFills
     */
    public function certify(
        array $entryFills,
        array $exitFills,
        TradeCosts $costs,
        string $side,
        bool $positionFullyClosed,
        bool $lineageSufficient,
        bool $identifierConflict,
        ?float $riskUsdtAtEntry = null,
    ): NetPnlCertificationResult {
        $flags = [];
        if ($entryFills === []) {
            $flags[] = 'missing_entry_fill';
        }
        if ($exitFills === []) {
            $flags[] = 'missing_exit_fill';
        }
        if (!$positionFullyClosed) {
            $flags[] = 'position_not_fully_closed';
        }
        if (!$lineageSufficient) {
            $flags[] = 'lineage_insufficient';
        }
        if ($identifierConflict) {
            $flags[] = 'identifier_conflict';
        }

        $entryQty = $this->quantity($entryFills);
        $exitQty = $this->quantity($exitFills);
        if ($entryQty > 0.0 && abs($entryQty - $exitQty) > 0.00000001) {
            $flags[] = 'quantity_mismatch';
        }

        $entryFee = $this->fee($entryFills, $flags);
        $exitFee = $this->fee($exitFills, $flags);
        foreach ($costs->components() as $name => $value) {
            if ($value === null) {
                $flags[] = 'missing_' . str_replace('_usdt', '', $name);
            }
        }

        $gross = $this->grossRealizedPnl($entryFills, $exitFills, $side);
        if ($gross === null) {
            $flags[] = 'missing_gross_pnl';
        }

        $certified = $flags === [];
        if (!$certified || $gross === null || $entryFee === null || $exitFee === null) {
            return new NetPnlCertificationResult(
                certified: false,
                costCompleteness: $this->costCompleteness($entryFills, $exitFills, $flags),
                grossRealizedPnlUsdt: $gross,
                entryFeeUsdt: $entryFee,
                exitFeeUsdt: $exitFee,
                totalKnownCostUsdt: null,
                netPnlUsdt: null,
                realizedNetPnlR: null,
                qualityFlags: array_values(array_unique($flags)),
            );
        }

        $totalKnownCost = $entryFee
            + $exitFee
            + (float) $costs->otherTradingFeesUsdt
            - (float) $costs->fundingUsdt
            + (float) $costs->spreadCostUsdt
            + (float) $costs->slippageCostUsdt
            + (float) $costs->borrowCostUsdt
            + (float) $costs->liquidationFeeUsdt;
        $net = $gross - $totalKnownCost;

        return new NetPnlCertificationResult(
            certified: true,
            costCompleteness: 'complete',
            grossRealizedPnlUsdt: $gross,
            entryFeeUsdt: $entryFee,
            exitFeeUsdt: $exitFee,
            totalKnownCostUsdt: $totalKnownCost,
            netPnlUsdt: $net,
            realizedNetPnlR: $riskUsdtAtEntry !== null && $riskUsdtAtEntry > 0.0 ? $net / $riskUsdtAtEntry : null,
            qualityFlags: [],
        );
    }

    /**
     * @param list<TradeFill> $fills
     * @param list<string> $flags
     */
    private function fee(array $fills, array &$flags): ?float
    {
        $sum = 0.0;
        foreach ($fills as $fill) {
            if ($fill->feeUsdt === null) {
                $flags[] = 'missing_fee';
                return null;
            }
            if (strtoupper((string) $fill->feeCurrency) !== 'USDT') {
                $flags[] = 'fee_currency_not_normalized';
                return null;
            }
            $sum += $fill->feeUsdt;
        }

        return $fills === [] ? null : $sum;
    }

    /**
     * @param list<TradeFill> $fills
     */
    private function quantity(array $fills): float
    {
        return array_reduce($fills, static fn (float $sum, TradeFill $fill): float => $sum + $fill->quantity, 0.0);
    }

    /**
     * @param list<TradeFill> $entryFills
     * @param list<TradeFill> $exitFills
     */
    private function grossRealizedPnl(array $entryFills, array $exitFills, string $side): ?float
    {
        if ($entryFills === [] || $exitFills === []) {
            return null;
        }

        $entryNotional = array_reduce($entryFills, static fn (float $sum, TradeFill $fill): float => $sum + $fill->notionalUsdt(), 0.0);
        $exitNotional = array_reduce($exitFills, static fn (float $sum, TradeFill $fill): float => $sum + $fill->notionalUsdt(), 0.0);

        return strtoupper($side) === 'SHORT'
            ? $entryNotional - $exitNotional
            : $exitNotional - $entryNotional;
    }

    /**
     * @param list<TradeFill> $entryFills
     * @param list<TradeFill> $exitFills
     * @param list<string> $flags
     */
    private function costCompleteness(array $entryFills, array $exitFills, array $flags): string
    {
        if ($entryFills === [] && $exitFills === []) {
            return 'unknown';
        }

        return $flags === [] ? 'complete' : 'partial';
    }
}
