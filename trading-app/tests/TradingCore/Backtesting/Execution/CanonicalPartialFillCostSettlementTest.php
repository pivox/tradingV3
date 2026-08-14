<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Execution;

use App\TradingCore\Backtesting\Execution\CanonicalPartialFillCostSettlement;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalPartialFillCostSettlement::class)]
final class CanonicalPartialFillCostSettlementTest extends TestCase
{
    public function testItSettlesOnePartialTargetFromTheValidatedPlan(): void
    {
        $plan = $this->plan();
        $result = (new CanonicalPartialFillCostSettlement())->settle(
            $this->request($plan, '1', 'target_filled', $plan->targets[0]->id),
        );

        self::assertSame('canonical-partial-fill-cost-result.v1', $result['schema_version']);
        self::assertSame('canonical-plan-partial-quantity.v1', $result['cost_policy_version']);
        self::assertSame($plan->planHash, $result['plan_hash']);
        self::assertSame('1', $result['filled_quantity_base']);
        self::assertSame($this->decimal($plan->quantity * $plan->contractSize), $result['planned_quantity_base']);
        self::assertSame('target_filled', $result['terminal_kind']);
        self::assertSame($plan->targets[0]->id, $result['target_id']);
        self::assertSame('2.5', $result['gross_pnl_quote']);
        self::assertFalse($result['costs_are_certified']);
        self::assertFalse($result['result_is_live_proof']);
        self::assertTrue($result['is_partial_fill']);
        self::assertSame(
            BigDecimal::of($result['gross_pnl_quote'])
                ->minus($result['total_planned_cost_quote'])
                ->stripTrailingZeros()
                ->__toString(),
            $result['net_pnl_quote'],
        );
        self::assertSame($this->hashWithout($result, 'result_hash'), $result['result_hash']);
        self::assertSame(
            $this->hashWithout(
                $this->request($plan, '1', 'target_filled', $plan->targets[0]->id),
                '__absent__',
            ),
            $result['request_hash'],
        );
    }

    public function testItSettlesAStopAndFullQuantityDeterministically(): void
    {
        $plan = $this->plan();
        $plannedBase = $this->decimal($plan->quantity * $plan->contractSize);
        $request = $this->request($plan, $plannedBase, 'stop_filled', null);
        $authority = new CanonicalPartialFillCostSettlement();

        $first = $authority->settle($request);
        $second = $authority->settle($request);

        self::assertSame($first, $second);
        self::assertFalse($first['is_partial_fill']);
        self::assertSame('0', $first['remaining_quantity_base']);
        self::assertSame('-1', $first['net_r']);
        self::assertSame(
            BigDecimal::of($plan->grossStopLoss)->negated()->stripTrailingZeros()->__toString(),
            $first['gross_pnl_quote'],
        );
        self::assertSame(
            BigDecimal::of($plan->totalStopLoss)->negated()->stripTrailingZeros()->__toString(),
            $first['net_pnl_quote'],
        );

        $target = $authority->settle(
            $this->request($plan, $plannedBase, 'target_filled', $plan->targets[0]->id),
        );
        self::assertSame($this->decimal($plan->targets[0]->grossReward), $target['gross_pnl_quote']);
        self::assertSame($this->decimal($plan->targets[0]->entryFee), $target['entry_fee_quote']);
        self::assertSame($this->decimal($plan->targets[0]->targetFee), $target['exit_fee_quote']);
        self::assertSame($this->decimal($plan->targets[0]->netReward), $target['net_pnl_quote']);
        self::assertSame($this->decimal($plan->targets[0]->netR), $target['net_r']);
    }

    public function testShortSideDoesNotChargePositiveFundingAsAdverse(): void
    {
        $plan = $this->plan('short');
        $result = (new CanonicalPartialFillCostSettlement())->settle(
            $this->request($plan, '1', 'target_filled', $plan->targets[0]->id),
        );

        self::assertSame('short', $result['side']);
        self::assertSame('0', $result['planned_adverse_funding_cost_quote']);
    }

    public function testItRejectsZeroOverfillUnknownTargetAndMalformedShape(): void
    {
        $plan = $this->plan();
        $cases = [
            [$this->request($plan, '0', 'stop_filled', null), 'canonical_partial_fill_cost_decimal_invalid'],
            [$this->request($plan, '999', 'stop_filled', null), 'canonical_partial_fill_cost_quantity_invalid'],
            [$this->request($plan, '1', 'target_filled', 'missing'), 'canonical_partial_fill_cost_target_invalid'],
            [[...$this->request($plan, '1', 'stop_filled', null), 'extra' => true], 'canonical_partial_fill_cost_request_invalid'],
        ];

        foreach ($cases as [$request, $reason]) {
            try {
                (new CanonicalPartialFillCostSettlement())->settle($request);
                self::fail('Invalid settlement request must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame($reason, $exception->getMessage());
            }
        }
    }

    public function testItRejectsTamperedPlanBeforeUsingItsCosts(): void
    {
        $plan = $this->plan();
        $request = $this->request($plan, '1', 'target_filled', $plan->targets[0]->id);
        $request['plan']['entryFee'] += 100;

        $this->expectExceptionMessage('canonical_order_plan_hash_mismatch');
        (new CanonicalPartialFillCostSettlement())->settle($request);
    }

    public function testItRejectsAValidSelfHashedNonFakePlan(): void
    {
        $plan = $this->plan();
        $request = $this->request($plan, '1', 'target_filled', $plan->targets[0]->id);
        $request['plan'] = $this->mutatedPlanWire($plan, ['exchange' => 'okx']);

        $this->expectExceptionMessage('canonical_partial_fill_cost_plan_invalid');
        (new CanonicalPartialFillCostSettlement())->settle($request);
    }

    private function plan(string $side = 'long'): CanonicalOrderPlan
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...CanonicalOrderPlanPipelineFixture::accepted($side)));
    }

    /** @return array<string, mixed> */
    private function request(
        CanonicalOrderPlan $plan,
        string $filledBase,
        string $terminalKind,
        ?string $targetId,
    ): array {
        return [
            'schema_version' => 'canonical-partial-fill-cost-request.v1',
            'dataset_id' => 'backtest-dataset-' . str_repeat('a', 64),
            'dataset_checksum' => 'sha256:' . str_repeat('a', 64),
            'plan' => $plan->toArray(),
            'maker_fill_result_hash' => 'sha256:' . str_repeat('b', 64),
            'maker_fill_trace_hash' => 'sha256:' . str_repeat('c', 64),
            'filled_quantity_base' => $filledBase,
            'terminal_kind' => $terminalKind,
            'target_id' => $targetId,
        ];
    }

    /** @param array<string, mixed> $value */
    private function hashWithout(array $value, string $field): string
    {
        unset($value[$field]);

        return 'sha256:' . hash('sha256', CanonicalOrderPlanDecimal::encodeCanonicalJson(
            $value,
            'test_encoding_failed',
        ));
    }

    private function decimal(float $value): string
    {
        return CanonicalOrderPlanDecimal::fromFloat($value, 'test_decimal_invalid')
            ->stripTrailingZeros()
            ->__toString();
    }

    /** @param array<string, mixed> $mutations
     *  @return array<string, mixed>
     */
    private function mutatedPlanWire(CanonicalOrderPlan $plan, array $mutations): array
    {
        $wire = [...$plan->toArray(), ...$mutations];
        $values = [...get_object_vars($plan), ...$mutations];
        unset($values['planHash']);
        if ($values['orderBookInputHash'] === null) {
            unset($values['orderBookInputHash']);
        }
        $values['targets'] = array_map(
            static fn ($target): array => $target->toArray(),
            $values['targets'],
        );
        foreach ([
            'inputObservedAt', 'observedAt', 'costObservedAt', 'zoneComputedAt',
            'createdAt', 'expiresAt', 'cancelAfterAt', 'holdingExpiresAt',
        ] as $field) {
            if ($values[$field] instanceof \DateTimeImmutable) {
                $values[$field] = $values[$field]->format('Y-m-d\TH:i:s.uP');
            }
        }
        $wire['planHash'] = 'sha256:' . hash(
            'sha256',
            CanonicalOrderPlanDecimal::encodeCanonicalJson($values, 'test_hash_failed'),
        );

        return $wire;
    }
}
