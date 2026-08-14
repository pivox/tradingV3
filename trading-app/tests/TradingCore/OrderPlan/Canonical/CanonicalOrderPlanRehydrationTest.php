<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalOrderPlan::class)]
final class CanonicalOrderPlanRehydrationTest extends TestCase
{
    public function testItRehydratesTheExactCanonicalWirePayload(): void
    {
        $plan = $this->plan();
        $wire = json_decode(
            CanonicalOrderPlanDecimal::encodeCanonicalJson($plan->toArray(), 'test_encoding_failed'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $rehydrated = CanonicalOrderPlan::fromArray($wire);

        self::assertSame($wire, $rehydrated->toArray());
        self::assertSame($plan->planHash, $rehydrated->planHash);
        self::assertSame($plan->expectedPlanHash(), $rehydrated->expectedPlanHash());
    }

    public function testItRejectsReorderedExtraAndMissingWireFields(): void
    {
        $wire = $this->plan()->toArray();
        $reordered = ['modeVersion' => $wire['modeVersion'], 'modeId' => $wire['modeId']]
            + array_diff_key($wire, ['modeVersion' => true, 'modeId' => true]);

        foreach ([
            $reordered,
            [...$wire, 'unexpected' => true],
            array_diff_key($wire, ['modeId' => true]),
            $this->withOptionalNull($wire),
        ] as $invalid) {
            try {
                CanonicalOrderPlan::fromArray($invalid);
                self::fail('Invalid wire shape must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('canonical_order_plan_wire_shape_invalid', $exception->getMessage());
            }
        }
    }

    public function testItRejectsScalarTargetAndTimestampCoercion(): void
    {
        $wire = $this->plan()->toArray();
        $cases = [];
        $cases['scalar'] = [...$wire, 'quantity' => (string) $wire['quantity']];
        $cases['scalar_empty'] = [...$wire, 'modeId' => ''];
        $cases['timestamp'] = [...$wire, 'createdAt' => '2026-08-10T12:00:00.000000Z'];
        $target = $wire['targets'][0];
        $target['unexpected'] = true;
        $cases['target'] = [...$wire, 'targets' => [$target, ...array_slice($wire['targets'], 1)]];

        foreach ($cases as $kind => $invalid) {
            try {
                CanonicalOrderPlan::fromArray($invalid);
                self::fail('Invalid ' . $kind . ' must fail closed.');
            } catch (\RuntimeException $exception) {
                $reasonKind = str_starts_with($kind, 'scalar') ? 'scalar' : $kind;
                self::assertSame('canonical_order_plan_wire_' . $reasonKind . '_invalid', $exception->getMessage());
            }
        }
    }

    public function testItRejectsHashTampering(): void
    {
        $wire = $this->plan()->toArray();
        $wire['entryFee'] += 0.01;

        $this->expectExceptionMessage('canonical_order_plan_hash_mismatch');
        CanonicalOrderPlan::fromArray($wire);
    }

    public function testItRejectsSelfHashedInvalidCostArithmetic(): void
    {
        $plan = $this->plan();
        $wire = $plan->toArray();
        $wire['entryFee'] += 0.01;
        $wire['planHash'] = $this->mutatedHash($plan, ['entryFee' => $wire['entryFee']]);

        $this->expectExceptionMessage('canonical_order_plan_risk_components_mismatch');
        CanonicalOrderPlan::fromArray($wire);
    }

    private function plan(): CanonicalOrderPlan
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...CanonicalOrderPlanPipelineFixture::accepted()));
    }

    /** @param array<string, mixed> $wire
     *  @return array<string, mixed>
     */
    private function withOptionalNull(array $wire): array
    {
        $result = [];
        foreach ($wire as $field => $value) {
            $result[$field] = $value;
            if ($field === 'costInputHash') {
                $result['orderBookInputHash'] = null;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $mutations */
    private function mutatedHash(CanonicalOrderPlan $plan, array $mutations): string
    {
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

        return 'sha256:' . hash('sha256', CanonicalOrderPlanDecimal::encodeCanonicalJson(
            $values,
            'test_hash_encoding_failed',
        ));
    }
}
