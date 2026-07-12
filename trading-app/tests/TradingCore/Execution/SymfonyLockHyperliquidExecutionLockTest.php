<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Provider\Hyperliquid\HyperliquidReconciliationStatusInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionLockInterface;
use App\TradingCore\Execution\Hyperliquid\SymfonyLockHyperliquidExecutionLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(SymfonyLockHyperliquidExecutionLock::class)]
final class SymfonyLockHyperliquidExecutionLockTest extends TestCase
{
    public function testSharedOwnerBlocksConcurrentProcessWithoutSelfBlockingReadiness(): void
    {
        $path = sys_get_temp_dir() . '/hl-execution-lock-' . bin2hex(random_bytes(6));
        $factory = new LockFactory(new FlockStore($path));
        $owner = new SymfonyLockHyperliquidExecutionLock($factory);
        $concurrent = new SymfonyLockHyperliquidExecutionLock($factory);

        self::assertInstanceOf(HyperliquidExecutionLockInterface::class, $owner);
        self::assertInstanceOf(HyperliquidReconciliationStatusInterface::class, $owner);
        self::assertFalse($owner->isInFlight());
        $lease = $owner->acquire();
        self::assertNotNull($lease);
        self::assertFalse($owner->isInFlight());
        self::assertTrue($concurrent->isInFlight());
        self::assertNull($concurrent->acquire());

        $lease->release();
        self::assertFalse($concurrent->isInFlight());
        @rmdir($path);
    }
}
