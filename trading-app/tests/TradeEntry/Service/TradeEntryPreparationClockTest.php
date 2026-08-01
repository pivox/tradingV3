<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Service;

use App\Config\TradeEntryConfigResolver;
use App\TradeEntry\Service\TradeEntryPreparationService;
use App\TradeEntry\Workflow\BuildOrderPlan;
use App\TradeEntry\Workflow\BuildPreOrder;
use App\Trading\Paper\Replay\PaperReplayClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TradeEntryPreparationService::class)]
final class TradeEntryPreparationClockTest extends TestCase
{
    public function testZoneTtlUsesInjectedReplayTimeInsteadOfHostTime(): void
    {
        $service = new TradeEntryPreparationService(
            (new \ReflectionClass(BuildPreOrder::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(BuildOrderPlan::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(TradeEntryConfigResolver::class))->newInstanceWithoutConstructor(),
            clock: new PaperReplayClock(new \DateTimeImmutable('2026-08-01T10:00:00Z')),
        );
        $method = new \ReflectionMethod($service, 'remainingZoneTtl');

        self::assertSame(90, $method->invoke($service, new \DateTimeImmutable('2026-08-01T10:01:30Z')));
        self::assertSame(0, $method->invoke($service, new \DateTimeImmutable('2026-08-01T09:59:59Z')));
        self::assertSame(PHP_INT_MAX, $method->invoke($service, null));
    }
}
