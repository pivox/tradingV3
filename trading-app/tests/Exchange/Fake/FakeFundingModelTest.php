<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Fake\FakeFundingModel;
use App\Exchange\Fake\FakeFundingModelConfig;
use App\Exchange\Fake\FakeFundingSchedule;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeFundingModel::class)]
#[CoversClass(FakeFundingModelConfig::class)]
#[CoversClass(FakeFundingSchedule::class)]
final class FakeFundingModelTest extends TestCase
{
    /** @var list<string> */
    private array $stateFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->stateFiles as $stateFile) {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
            foreach (glob($stateFile . '.tmp.*') ?: [] as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string,array{ExchangePositionSide,string,string}>
     */
    public static function signedFundingProvider(): iterable
    {
        yield 'positive rate makes long pay' => [ExchangePositionSide::LONG, '0.0001', '-2.000000000000'];
        yield 'positive rate makes short receive' => [ExchangePositionSide::SHORT, '0.0001', '2.000000000000'];
        yield 'negative rate makes long receive' => [ExchangePositionSide::LONG, '-0.0001', '2.000000000000'];
        yield 'negative rate makes short pay' => [ExchangePositionSide::SHORT, '-0.0001', '-2.000000000000'];
    }

    #[DataProvider('signedFundingProvider')]
    public function testCalculatesSignedFundingForLongAndShort(
        ExchangePositionSide $side,
        string $rate,
        string $expectedAmount,
    ): void {
        $result = $this->model()->calculate(
            $this->schedule($side, $rate),
            $this->position($side, size: 2.0, markPrice: 10000.0),
        );

        self::assertSame('applied', $result->status);
        self::assertNotNull($result->funding);
        self::assertSame('20000.000000000000', $result->funding->notional);
        self::assertSame($expectedAmount, $result->funding->amount);
        self::assertSame($expectedAmount, $result->funding->amountUsdt);
        self::assertSame('USDT', $result->funding->currency);
        self::assertSame(FakeFundingModelConfig::MODEL_VERSION, $result->funding->modelVersion);
    }

    public function testUsesPartialPositionAndExplicitAppliedInterval(): void
    {
        $result = $this->model()->calculate(
            $this->schedule(
                ExchangePositionSide::LONG,
                '0.0001',
                rateIntervalSeconds: 28800,
                appliedIntervalSeconds: 14400,
            ),
            $this->position(ExchangePositionSide::LONG, size: 1.0, markPrice: 10000.0),
        );

        self::assertSame('applied', $result->status);
        self::assertNotNull($result->funding);
        self::assertSame('10000.000000000000', $result->funding->notional);
        self::assertSame('-0.500000000000', $result->funding->amountUsdt);
    }

    public function testMissingRateRemainsUnknownInsteadOfZero(): void
    {
        $result = $this->model()->calculate(
            $this->schedule(ExchangePositionSide::LONG, null),
            $this->position(ExchangePositionSide::LONG),
        );

        self::assertSame('unknown', $result->status);
        self::assertNull($result->funding);
    }

    public function testNoPositionProducesNoFundingEvent(): void
    {
        $result = $this->model()->calculate(
            $this->schedule(ExchangePositionSide::LONG, '0.0001'),
            null,
        );

        self::assertSame('no_position', $result->status);
        self::assertNull($result->funding);
    }

    public function testUnknownCurrencyPreservesNativeAmountWithoutInventingUsdt(): void
    {
        $result = $this->model()->calculate(
            $this->schedule(ExchangePositionSide::LONG, '0.0001', currency: 'EUR'),
            $this->position(ExchangePositionSide::LONG),
        );

        self::assertSame('applied', $result->status);
        self::assertNotNull($result->funding);
        self::assertSame('-1.000000000000', $result->funding->amount);
        self::assertSame('EUR', $result->funding->currency);
        self::assertNull($result->funding->amountUsdt);
    }

    public function testFutureExplicitDeadlineFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('fake_funding_deadline_not_reached');

        $this->model()->calculate(
            $this->schedule(
                ExchangePositionSide::LONG,
                '0.0001',
                dueAt: new \DateTimeImmutable('2026-01-01T08:00:01+00:00'),
            ),
            $this->position(ExchangePositionSide::LONG),
        );
    }

    public function testDuplicateSettlementAppendsOnePersistentEventAndOneSequence(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile());
        $model = $this->model();
        $schedule = $this->schedule(ExchangePositionSide::LONG, '0.0001');
        $position = $this->position(ExchangePositionSide::LONG);

        $first = $model->settle($schedule, $position, $state);
        $second = $model->settle($schedule, $position, $state);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertCount(1, $state->events('funding.accrued'));
        self::assertSame(1, $state->events('funding.accrued')[0]->payload['event_sequence'] ?? null);
    }

    public function testRestartReplayDoesNotAppendFundingTwice(): void
    {
        $stateFile = $this->stateFile();
        $schedule = $this->schedule(ExchangePositionSide::LONG, '0.0001');
        $position = $this->position(ExchangePositionSide::LONG);

        $this->model()->settle($schedule, $position, new FakeExchangeStateStore($stateFile));
        $restored = new FakeExchangeStateStore($stateFile);
        $replay = $this->model()->settle($schedule, $position, $restored);

        self::assertTrue($replay->replayed);
        self::assertCount(1, $restored->events('funding.accrued'));
    }

    public function testLateOutOfOrderDeadlineIsAcceptedOnce(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile());
        $position = $this->position(ExchangePositionSide::LONG);
        $newer = $this->schedule(ExchangePositionSide::LONG, '0.0001');
        $older = $this->schedule(
            ExchangePositionSide::LONG,
            '-0.0002',
            dueAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $this->model()->settle($newer, $position, $state);
        $late = $this->model()->settle($older, $position, $state);
        $lateReplay = $this->model()->settle($older, $position, $state);

        self::assertFalse($late->replayed);
        self::assertTrue($lateReplay->replayed);
        self::assertSame(
            ['2026-01-01T08:00:00+00:00', '2026-01-01T00:00:00+00:00'],
            array_map(
                static fn ($event): mixed => $event->payload['due_at'] ?? null,
                $state->events('funding.accrued'),
            ),
        );
    }

    public function testSamePositionDeadlineAndVersionWithDifferentPayloadIsConflict(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile());
        $position = $this->position(ExchangePositionSide::LONG);
        $this->model()->settle(
            $this->schedule(ExchangePositionSide::LONG, '0.0001'),
            $position,
            $state,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('fake_funding_idempotency_conflict');

        $this->model()->settle(
            $this->schedule(ExchangePositionSide::LONG, '0.0002'),
            $position,
            $state,
        );
    }

    public function testUnknownOrMissingPositionDoesNotPersistFundingEvent(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile());

        $unknown = $this->model()->settle(
            $this->schedule(ExchangePositionSide::LONG, null),
            $this->position(ExchangePositionSide::LONG),
            $state,
        );
        $missing = $this->model()->settle(
            $this->schedule(ExchangePositionSide::SHORT, '0.0001'),
            null,
            $state,
        );

        self::assertSame('unknown', $unknown->status);
        self::assertSame('no_position', $missing->status);
        self::assertSame([], $state->events('funding.accrued'));
    }

    private function model(): FakeFundingModel
    {
        return new FakeFundingModel(FakeFundingModelConfig::v1(), $this->clock());
    }

    private function schedule(
        ExchangePositionSide $side,
        ?string $rate,
        int $rateIntervalSeconds = 28800,
        int $appliedIntervalSeconds = 28800,
        string $currency = 'USDT',
        ?\DateTimeImmutable $dueAt = null,
    ): FakeFundingSchedule {
        return new FakeFundingSchedule(
            symbol: 'BTCUSDT',
            side: $side,
            fundingRate: $rate,
            rateIntervalSeconds: $rateIntervalSeconds,
            appliedIntervalSeconds: $appliedIntervalSeconds,
            currency: $currency,
            dueAt: $dueAt ?? new \DateTimeImmutable('2026-01-01T08:00:00+00:00'),
        );
    }

    /** @param array<string,mixed> $metadata */
    private function position(
        ExchangePositionSide $side,
        float $size = 1.0,
        float $markPrice = 10000.0,
        array $metadata = [],
    ): ExchangePositionDto {
        return new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            size: $size,
            entryPrice: 9900.0,
            markPrice: $markPrice,
            unrealizedPnl: null,
            realizedPnl: null,
            margin: 1000.0,
            leverage: 10.0,
            openedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T07:59:00+00:00'),
            metadata: ['position_id' => 'fake-position-' . $side->value, 'margin_contract_size' => '1'] + $metadata,
        );
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T08:00:00+00:00');
            }
        };
    }

    private function stateFile(): string
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_funding_state_');
        self::assertIsString($stateFile);
        @unlink($stateFile);
        $this->stateFiles[] = $stateFile;

        return $stateFile;
    }
}
