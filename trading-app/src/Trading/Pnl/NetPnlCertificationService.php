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
        $normalizedSide = strtoupper($side);
        if (!\in_array($normalizedSide, ['LONG', 'SHORT'], true)) {
            $flags[] = 'invalid_side';
        }

        $entryQty = $this->quantity($entryFills);
        $exitQty = $this->quantity($exitFills);
        if ($entryFills !== [] && $entryQty <= 0.0) {
            $flags[] = 'quantity_mismatch';
        }
        if ($exitFills !== [] && $exitQty <= 0.0) {
            $flags[] = 'quantity_mismatch';
        }
        if ($entryQty > 0.0 && abs($entryQty - $exitQty) > 0.00000001) {
            $flags[] = 'quantity_mismatch';
        }

        $entryFee = $this->fee($entryFills, $flags);
        $exitFee = $this->fee($exitFills, $flags);
        foreach ($costs->components() as $name => $value) {
            if ($value === null) {
                $flags[] = 'missing_' . str_replace('_usdt', '', $name);
                continue;
            }
            if ($name !== 'funding_usdt' && $value < 0.0) {
                $flags[] = 'negative_cost_component';
            }
        }
        if (!$this->fillSidesMatchPosition($entryFills, $exitFills, $normalizedSide)) {
            $flags[] = 'invalid_fill_side';
        }

        $gross = $this->grossRealizedPnl($entryFills, $exitFills, $normalizedSide);
        if ($gross === null) {
            $flags[] = 'missing_gross_pnl';
        }

        $certified = $flags === [];
        if (!$certified || $gross === null || $entryFee === null || $exitFee === null) {
            return new NetPnlCertificationResult(
                certified: false,
                costCompleteness: $this->costCompleteness($entryFills, $exitFills, $costs, $flags),
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
     * @param list<TradeFill> $entryFills
     * @param list<TradeFill> $exitFills
     */
    public function certifyWithQuantityAggregation(
        array $entryFills,
        array $exitFills,
        TradeCosts $costs,
        string $side,
        FillQuantityAggregationResult $quantityAggregation,
        bool $lineageSufficient,
        bool $identifierConflict,
        ?float $riskUsdtAtEntry = null,
    ): NetPnlCertificationResult {
        $quantityBlocksCertification = !$quantityAggregation->netPnlCertificationAllowed();
        $result = $this->certify(
            entryFills: $entryFills,
            exitFills: $exitFills,
            costs: $costs,
            side: $side,
            positionFullyClosed: $quantityAggregation->positionFullyClosed && !$quantityBlocksCertification,
            lineageSufficient: $lineageSufficient,
            identifierConflict: $identifierConflict || \in_array('fill_conflict', $quantityAggregation->quantityQualityFlags, true),
            riskUsdtAtEntry: $riskUsdtAtEntry,
        );
        if (!$quantityBlocksCertification) {
            return $result;
        }

        return new NetPnlCertificationResult(
            certified: false,
            costCompleteness: $result->costCompleteness,
            grossRealizedPnlUsdt: $result->grossRealizedPnlUsdt,
            entryFeeUsdt: $result->entryFeeUsdt,
            exitFeeUsdt: $result->exitFeeUsdt,
            totalKnownCostUsdt: null,
            netPnlUsdt: null,
            realizedNetPnlR: null,
            qualityFlags: array_values(array_unique([...$result->qualityFlags, ...$quantityAggregation->quantityQualityFlags])),
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

        return $side === 'SHORT'
            ? $entryNotional - $exitNotional
            : ($side === 'LONG' ? $exitNotional - $entryNotional : null);
    }

    /**
     * @param list<TradeFill> $entryFills
     * @param list<TradeFill> $exitFills
     */
    private function fillSidesMatchPosition(array $entryFills, array $exitFills, string $side): bool
    {
        $expected = match ($side) {
            'LONG' => ['entry' => 'BUY', 'exit' => 'SELL'],
            'SHORT' => ['entry' => 'SELL', 'exit' => 'BUY'],
            default => null,
        };
        if ($expected === null) {
            return true;
        }

        foreach ($entryFills as $fill) {
            if (strtoupper($fill->side) !== $expected['entry']) {
                return false;
            }
        }
        foreach ($exitFills as $fill) {
            if (strtoupper($fill->side) !== $expected['exit']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<TradeFill> $entryFills
     * @param list<TradeFill> $exitFills
     * @param list<string> $flags
     */
    private function costCompleteness(array $entryFills, array $exitFills, TradeCosts $costs, array $flags): string
    {
        if ($entryFills === [] && $exitFills === [] && !$this->hasCostEvidence($costs)) {
            return 'unknown';
        }

        return $flags === [] ? 'complete' : 'partial';
    }

    private function hasCostEvidence(TradeCosts $costs): bool
    {
        foreach ($costs->components() as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }
}
