<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeFillCostModel;
use App\Exchange\Fake\FakeFundingModelConfig;
use App\Exchange\Fake\FakeMonetaryLedgerException;
use App\Exchange\Fake\FakeMonetaryLedgerProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeMonetaryLedgerProjector::class)]
final class FakeMonetaryLedgerProjectorTest extends TestCase
{
    public function testProjectsExactNetForWindowAndStableIdentity(): void
    {
        $projector = new FakeMonetaryLedgerProjector();
        $events = [
            $this->fill(1, '2026-08-09T23:59:00Z', '4', '0.5', true),
            $this->fill(2, '2026-08-10T10:00:00Z', '10', '1.25', true),
            $this->funding(3, '2026-08-10T11:00:00Z', '-2.5', 'funding-3'),
            new FakeExchangeEvent('order.accepted', 'BTCUSDT', new \DateTimeImmutable('2026-08-10T11:30:00Z')),
        ];

        $projection = $projector->project(
            $events,
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            new \DateTimeImmutable('2026-08-10T00:00:00Z'),
            new \DateTimeImmutable('2026-08-11T00:00:00Z'),
        );
        $same = $projector->project(
            $events,
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            new \DateTimeImmutable('2026-08-10T00:00:00Z'),
            new \DateTimeImmutable('2026-08-11T00:00:00Z'),
        );

        self::assertSame('6.250000000000', $projection->netUsdt);
        self::assertSame(2, $projection->monetaryEventCount);
        self::assertSame(0, $projection->duplicateEventCount);
        self::assertSame(3, $projection->lastEventSequence);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $projection->inputHash);
        self::assertSame($projection->inputHash, $same->inputHash);
    }

    public function testExactDuplicateSequenceAndFundingIdentityAreCountedOnce(): void
    {
        $event = $this->funding(7, '2026-08-10T11:00:00Z', '-2.5', 'funding-shared');

        $projection = (new FakeMonetaryLedgerProjector())->project(
            [$event, $event],
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
        );

        self::assertSame('-2.500000000000', $projection->netUsdt);
        self::assertSame(1, $projection->monetaryEventCount);
        self::assertSame(1, $projection->duplicateEventCount);
        self::assertSame(7, $projection->lastEventSequence);
    }

    public function testConflictingSequenceFailsWithStableCounts(): void
    {
        $projector = new FakeMonetaryLedgerProjector();

        try {
            $projector->project([
                $this->fill(4, '2026-08-10T10:00:00Z', '1', '0', true),
                $this->fill(4, '2026-08-10T10:00:00Z', '2', '0', true),
            ], new \DateTimeImmutable('2026-08-10T12:00:00Z'));
            self::fail('A conflicting monetary sequence must fail closed.');
        } catch (FakeMonetaryLedgerException $exception) {
            self::assertSame('conflicting_event_sequence', $exception->detailReason);
            self::assertSame(1, $exception->monetaryEventCount);
            self::assertSame(0, $exception->duplicateEventCount);
            self::assertSame(1, $exception->invalidEventCount);
        }
    }

    public function testConflictingFundingIdentityFailsClosed(): void
    {
        $projector = new FakeMonetaryLedgerProjector();

        try {
            $projector->project([
                $this->funding(8, '2026-08-10T10:00:00Z', '-1', 'funding-shared'),
                $this->funding(9, '2026-08-10T11:00:00Z', '-2', 'funding-shared'),
            ], new \DateTimeImmutable('2026-08-10T12:00:00Z'));
            self::fail('A conflicting funding identity must fail closed.');
        } catch (FakeMonetaryLedgerException $exception) {
            self::assertSame('funding_idempotency_conflict', $exception->detailReason);
            self::assertSame(1, $exception->monetaryEventCount);
            self::assertSame(1, $exception->invalidEventCount);
        }
    }

    public function testFutureMonetaryEventFailsEvenOutsideRequestedWindow(): void
    {
        $projector = new FakeMonetaryLedgerProjector();

        try {
            $projector->project(
                [$this->fill(10, '2026-08-12T10:00:00Z', '1', '0', true)],
                new \DateTimeImmutable('2026-08-10T12:00:00Z'),
                new \DateTimeImmutable('2026-08-10T00:00:00Z'),
                new \DateTimeImmutable('2026-08-11T00:00:00Z'),
            );
            self::fail('A future monetary event must fail closed.');
        } catch (FakeMonetaryLedgerException $exception) {
            self::assertSame('future_monetary_event', $exception->detailReason);
            self::assertSame(0, $exception->monetaryEventCount);
            self::assertSame(1, $exception->invalidEventCount);
        }
    }

    private function fill(
        int $sequence,
        string $occurredAt,
        string $gross,
        string $fee,
        bool $reduceOnly,
    ): FakeExchangeEvent {
        return new FakeExchangeEvent('order.filled', 'BTCUSDT', new \DateTimeImmutable($occurredAt), [
            'event_sequence' => $sequence,
            'fill_quantity' => '1.000000000000',
            'fill_price' => '100.000000000000',
            'fill_fee' => $fee,
            'fee_currency' => 'USDT',
            'liquidity_role' => 'taker',
            'spread_cost_usdt' => '0',
            'slippage_cost_usdt' => '0',
            'cost_model_version' => FakeFillCostModel::MODEL_VERSION,
            'spread_model_version' => FakeFillCostModel::SPREAD_MODEL_VERSION,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
            'cost_completeness' => 'complete',
            'realized_gross_pnl_usdt' => $gross,
            'order_snapshot' => ['reduce_only' => $reduceOnly],
        ]);
    }

    private function funding(
        int $sequence,
        string $occurredAt,
        string $amount,
        string $identity,
    ): FakeExchangeEvent {
        return new FakeExchangeEvent('funding.accrued', 'BTCUSDT', new \DateTimeImmutable($occurredAt), [
            'event_sequence' => $sequence,
            'amount' => $amount,
            'currency' => 'USDT',
            'amount_usdt' => $amount,
            'due_at' => (new \DateTimeImmutable($occurredAt))->format(\DateTimeInterface::ATOM),
            'model_version' => FakeFundingModelConfig::MODEL_VERSION,
            'funding_idempotency_key' => $identity,
            'funding_payload_hash' => 'sha256:' . hash('sha256', $identity . ':' . $amount),
        ]);
    }
}
