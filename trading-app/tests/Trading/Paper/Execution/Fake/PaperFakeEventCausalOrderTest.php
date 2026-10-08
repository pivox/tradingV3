<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Trading\Lineage\Persistence\CanonicalPositionEvidence;
use App\Trading\Paper\Execution\Fake\PaperFakeEventCausalOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** #132: the Fake engine records a position change before the fill that caused it. */
#[CoversClass(PaperFakeEventCausalOrder::class)]
final class PaperFakeEventCausalOrderTest extends TestCase
{
    public function testAPositionChangeIsProjectedRightAfterTheFillItNames(): void
    {
        $opened = $this->position(ExchangePositionUpdated::class, 'fake-fill-entry');
        $entry = $this->fill('fake-fill-entry');
        $closed = $this->position(ExchangePositionClosed::class, 'fake-fill-exit');
        $exit = $this->fill('fake-fill-exit');

        self::assertSame(
            [$entry, $opened, $exit, $closed],
            PaperFakeEventCausalOrder::order([$opened, $entry, $closed, $exit]),
        );
    }

    public function testEveryOtherEventKeepsItsRecordedOrder(): void
    {
        $earlierFill = $this->fill('fake-fill-1');
        $afterEarlierFill = $this->position(ExchangePositionUpdated::class, 'fake-fill-1');
        $withoutFill = $this->position(ExchangePositionUpdated::class, null);
        $unknownFill = $this->position(ExchangePositionUpdated::class, 'fake-fill-missing');
        $laterFill = $this->fill('fake-fill-2');
        $events = [$earlierFill, $afterEarlierFill, $withoutFill, $unknownFill, $laterFill];

        self::assertSame($events, PaperFakeEventCausalOrder::order($events));
        self::assertSame([], PaperFakeEventCausalOrder::order([]));
    }

    public function testSeveralChangesNamingTheSameFillKeepTheirRelativeOrder(): void
    {
        $first = $this->position(ExchangePositionUpdated::class, 'fake-fill-1');
        $second = $this->position(ExchangePositionClosed::class, 'fake-fill-1');
        $fill = $this->fill('fake-fill-1');

        self::assertSame([$fill, $first, $second], PaperFakeEventCausalOrder::order([$first, $second, $fill]));
    }

    private function fill(string $fillId): ExchangeFillReceived
    {
        return new ExchangeFillReceived(new ExchangeFillDto(
            Exchange::FAKE,
            MarketType::PERPETUAL,
            'BTCUSDT',
            'fake-order-1',
            'CID1',
            $fillId,
            ExchangeOrderSide::BUY,
            ExchangePositionSide::LONG,
            0.001,
            30000.0,
            0.01,
            'USDT',
            new \DateTimeImmutable('2026-08-20T12:00:05Z'),
        ));
    }

    /** @param class-string<ExchangePositionUpdated|ExchangePositionClosed> $class */
    private function position(string $class, ?string $fillId): ExchangeEventInterface
    {
        return new $class(
            Exchange::FAKE,
            MarketType::PERPETUAL,
            'BTCUSDT',
            ExchangePositionSide::LONG,
            0.001,
            null,
            new \DateTimeImmutable('2026-08-20T12:00:05Z'),
            [],
            new CanonicalPositionEvidence(exchangeFillId: $fillId),
        );
    }
}
