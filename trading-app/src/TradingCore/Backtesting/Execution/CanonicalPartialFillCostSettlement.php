<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Execution;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanTarget;
use Brick\Math\BigDecimal;

final class CanonicalPartialFillCostSettlement
{
    private const HASH = '/\Asha256:[0-9a-f]{64}\z/D';
    private const DECIMAL = '/\A(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D';

    /** @param array<string, mixed> $request
     *  @return array<string, mixed>
     */
    public function settle(array $request): array
    {
        $this->assertRequest($request);
        $plan = CanonicalOrderPlan::fromArray($request['plan']);
        if ($plan->exchange !== 'fake' || !\in_array($plan->environment, ['local', 'test'], true)) {
            throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_plan_invalid');
        }
        $filledBase = $this->positiveDecimal($request['filled_quantity_base']);
        $plannedBase = $this->decimal($plan->quantity)->multipliedBy($this->decimal($plan->contractSize));
        if ($filledBase->isGreaterThan($plannedBase)) {
            throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_quantity_invalid');
        }
        $target = $this->target($plan, $request['terminal_kind'], $request['target_id']);
        $entry = $this->decimal($plan->entryPrice);
        $stop = $this->decimal($plan->stopPrice);
        $entryNotional = $entry->multipliedBy($filledBase);
        $entryFee = $entryNotional->multipliedBy($this->feeRate($plan->entryLiquidityRole, $plan));
        $entrySpread = $entryNotional->multipliedBy($this->decimal($plan->entrySpreadRate));
        $entrySlippage = $entryNotional->multipliedBy($this->decimal($plan->entrySlippageRate));
        $funding = $entryNotional
            ->multipliedBy($this->adverseFundingRate($plan))
            ->multipliedBy((string) $plan->fundingIntervals);
        $grossStop = $entry->minus($stop)->abs()->multipliedBy($filledBase);
        $stopNotional = $stop->multipliedBy($filledBase);
        $stopFee = $stopNotional->multipliedBy($this->feeRate($plan->stopLiquidityRole, $plan));
        $stopSpread = $stopNotional->multipliedBy($this->decimal($plan->stopSpreadRate));
        $stopSlippage = $stopNotional->multipliedBy($this->decimal($plan->stopSlippageRate));
        $totalStopRisk = $grossStop
            ->plus($entryFee)->plus($stopFee)
            ->plus($entrySpread)->plus($stopSpread)
            ->plus($entrySlippage)->plus($stopSlippage)
            ->plus($funding);

        if ($target === null) {
            $grossPnl = $grossStop->negated();
            $exitFee = $stopFee;
            $exitSpread = $stopSpread;
            $exitSlippage = $stopSlippage;
            $totalCost = $entryFee->plus($exitFee)
                ->plus($entrySpread)->plus($exitSpread)
                ->plus($entrySlippage)->plus($exitSlippage)
                ->plus($funding);
            $netPnl = $totalStopRisk->negated();
            $netR = BigDecimal::of('-1');
        } else {
            $targetPrice = $this->decimal($target->price);
            $grossPnl = $targetPrice->minus($entry)->abs()->multipliedBy($filledBase);
            $targetNotional = $targetPrice->multipliedBy($filledBase);
            $exitFee = $targetNotional->multipliedBy($this->feeRate($target->liquidityRole, $plan));
            $exitSpread = $targetNotional->multipliedBy($this->decimal($target->spreadRate));
            $exitSlippage = $targetNotional->multipliedBy($this->decimal($target->slippageRate));
            $totalCost = $entryFee->plus($exitFee)
                ->plus($entrySpread)->plus($exitSpread)
                ->plus($entrySlippage)->plus($exitSlippage)
                ->plus($funding);
            $netPnl = $grossPnl->minus($totalCost);
            // Every component is linear in filled base quantity, so the
            // validated plan-bound target R remains invariant at any fill size.
            $netR = $this->decimal($target->netR);
        }

        $result = [
            'schema_version' => 'canonical-partial-fill-cost-result.v1',
            'cost_policy_version' => 'canonical-plan-partial-quantity.v1',
            'cost_evidence' => 'canonical_plan_partial_quantity',
            'costs_are_certified' => false,
            'dataset_id' => $request['dataset_id'],
            'dataset_checksum' => $request['dataset_checksum'],
            'plan_hash' => $plan->planHash,
            'config_hash' => $plan->configHash,
            'cost_input_hash' => $plan->costInputHash,
            'maker_fill_result_hash' => $request['maker_fill_result_hash'],
            'maker_fill_trace_hash' => $request['maker_fill_trace_hash'],
            'mode_id' => $plan->modeId,
            'mode_version' => $plan->modeVersion,
            'setup_id' => $plan->setupId,
            'setup_version' => $plan->setupVersion,
            'symbol' => $plan->symbol,
            'market_type' => $plan->marketType,
            'side' => $plan->side,
            'planned_quantity_base' => $this->canonical($plannedBase),
            'filled_quantity_base' => $this->canonical($filledBase),
            'remaining_quantity_base' => $this->canonical($plannedBase->minus($filledBase)),
            'is_partial_fill' => $filledBase->isLessThan($plannedBase),
            'terminal_kind' => $request['terminal_kind'],
            'target_id' => $request['target_id'],
            'gross_pnl_quote' => $this->canonical($grossPnl),
            'entry_fee_quote' => $this->canonical($entryFee),
            'exit_fee_quote' => $this->canonical($exitFee),
            'entry_spread_cost_quote' => $this->canonical($entrySpread),
            'exit_spread_cost_quote' => $this->canonical($exitSpread),
            'entry_slippage_cost_quote' => $this->canonical($entrySlippage),
            'exit_slippage_cost_quote' => $this->canonical($exitSlippage),
            'planned_adverse_funding_cost_quote' => $this->canonical($funding),
            'total_planned_cost_quote' => $this->canonical($totalCost),
            'gross_stop_risk_quote' => $this->canonical($grossStop),
            'total_stop_risk_quote' => $this->canonical($totalStopRisk),
            'net_pnl_quote' => $this->canonical($netPnl),
            'net_r' => $this->canonical($netR),
            'result_is_live_proof' => false,
            'request_hash' => 'sha256:' . hash('sha256', $this->json($request)),
        ];
        $result['result_hash'] = 'sha256:' . hash('sha256', $this->json($result));

        return $result;
    }

    /** @param array<string, mixed> $request */
    private function assertRequest(array $request): void
    {
        if (array_keys($request) !== [
            'schema_version', 'dataset_id', 'dataset_checksum', 'plan',
            'maker_fill_result_hash', 'maker_fill_trace_hash',
            'filled_quantity_base', 'terminal_kind', 'target_id',
        ] || $request['schema_version'] !== 'canonical-partial-fill-cost-request.v1'
            || !\is_string($request['dataset_id'])
            || !\is_string($request['dataset_checksum'])
            || $request['dataset_id'] !== 'backtest-dataset-' . substr($request['dataset_checksum'], 7)
            || !\is_array($request['plan'])
            || !\in_array($request['terminal_kind'], ['stop_filled', 'target_filled'], true)
            || ($request['target_id'] !== null && !\is_string($request['target_id']))
        ) {
            throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_request_invalid');
        }
        foreach (['dataset_checksum', 'maker_fill_result_hash', 'maker_fill_trace_hash'] as $field) {
            if (!\is_string($request[$field]) || preg_match(self::HASH, $request[$field]) !== 1) {
                throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_request_invalid');
            }
        }
    }

    private function target(CanonicalOrderPlan $plan, string $kind, mixed $targetId): ?CanonicalOrderPlanTarget
    {
        if ($kind === 'stop_filled') {
            if ($targetId !== null) {
                throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_target_invalid');
            }
            return null;
        }
        if (!\is_string($targetId) || $targetId === '') {
            throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_target_invalid');
        }
        foreach ($plan->targets as $target) {
            if ($target->id === $targetId) {
                return $target;
            }
        }
        throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_target_invalid');
    }

    private function feeRate(string $role, CanonicalOrderPlan $plan): BigDecimal
    {
        return match ($role) {
            'maker' => $this->decimal($plan->makerFeeRate),
            'taker' => $this->decimal($plan->takerFeeRate),
            default => throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_role_invalid'),
        };
    }

    private function adverseFundingRate(CanonicalOrderPlan $plan): BigDecimal
    {
        $rate = $this->decimal($plan->fundingRate);
        if (($plan->side === 'long' && $rate->isPositive()) || ($plan->side === 'short' && $rate->isNegative())) {
            return $rate->abs();
        }
        return BigDecimal::zero();
    }

    private function positiveDecimal(mixed $value): BigDecimal
    {
        if (!\is_string($value) || strlen($value) > 128 || preg_match(self::DECIMAL, $value) !== 1
            || !BigDecimal::of($value)->isPositive()
        ) {
            throw new CanonicalPartialFillCostSettlementException('canonical_partial_fill_cost_decimal_invalid');
        }
        return BigDecimal::of($value);
    }

    private function decimal(float $value): BigDecimal
    {
        return CanonicalOrderPlanDecimal::fromFloat($value, 'canonical_partial_fill_cost_decimal_invalid');
    }

    private function canonical(BigDecimal $value): string
    {
        $rendered = (string) $value->stripTrailingZeros();
        return $rendered === '-0' ? '0' : $rendered;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return CanonicalOrderPlanDecimal::encodeCanonicalJson(
            $value,
            'canonical_partial_fill_cost_encoding_invalid',
        );
    }
}
