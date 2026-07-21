<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Replay;

use App\Trading\Paper\Replay\PaperReplayClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(PaperReplayClock::class)]
final class PaperReplayClockTest extends TestCase
{
    public function testStartsAtTheFirstEventTimestampNormalizedToUtc(): void
    {
        $clock = new PaperReplayClock(new \DateTimeImmutable('2026-07-19T12:00:00.123456+02:00'));

        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
        self::assertSame('2026-07-19T10:00:00.123456Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testAdvancesMonotonicallyAndNormalizesEveryTimestampToUtc(): void
    {
        $clock = new PaperReplayClock(new \DateTimeImmutable('2026-07-19T10:00:00.000001Z'));

        $clock->advanceTo(new \DateTimeImmutable('2026-07-19T12:00:01.000002+02:00'));
        self::assertSame('2026-07-19T10:00:01.000002Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));

        $clock->advanceTo(new \DateTimeImmutable('2026-07-19T10:00:01.000002Z'));
        self::assertSame('2026-07-19T10:00:01.000002Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testRejectsClockRegressionWithoutChangingTheCurrentInstant(): void
    {
        $clock = new PaperReplayClock(new \DateTimeImmutable('2026-07-19T10:00:01.000002Z'));

        try {
            $clock->advanceTo(new \DateTimeImmutable('2026-07-19T10:00:01.000001Z'));
            self::fail('A replay clock must never move backwards.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_replay_clock_regression', $exception->getMessage());
        }

        self::assertSame('2026-07-19T10:00:01.000002Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));
    }
}
