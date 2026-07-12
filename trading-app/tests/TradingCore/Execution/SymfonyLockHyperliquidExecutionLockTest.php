<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Provider\Hyperliquid\HyperliquidReconciliationStatusInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionLockInterface;
use App\TradingCore\Execution\Hyperliquid\SymfonyLockHyperliquidExecutionLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
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

    public function testFailedReleaseRetainsOwnershipUntilSuccessfulRetry(): void
    {
        $backend = new TestSharedLock();
        $backend->releaseFailure = true;
        $owner = new SymfonyLockHyperliquidExecutionLock(new TestLockFactory($backend));
        $lease = $owner->acquire();
        self::assertNotNull($lease);

        try {
            $lease->release();
            self::fail('release failure must propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('lock release failed', $exception->getMessage());
        }

        self::assertNull($owner->acquire(), 'owner must remain quarantined after failed release');
        $backend->releaseFailure = false;
        $lease->release();

        self::assertNotNull($owner->acquire(), 'successful retry must clear ownership');
    }

    public function testDestructorSuppressesReleaseFailureAndRetainsOwnership(): void
    {
        $backend = new TestSharedLock();
        $backend->releaseFailure = true;
        $owner = new SymfonyLockHyperliquidExecutionLock(new TestLockFactory($backend));
        $lease = $owner->acquire();
        self::assertNotNull($lease);

        unset($lease);
        gc_collect_cycles();

        self::assertNull($owner->acquire());
    }

    public function testAcquisitionBackendExceptionPropagates(): void
    {
        $owner = new SymfonyLockHyperliquidExecutionLock(new ThrowingTestLockFactory());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lock backend unavailable');

        $owner->acquire();
    }
}

final class TestLockFactory extends LockFactory
{
    public function __construct(private readonly SharedLockInterface $lock)
    {
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->lock;
    }
}

final class ThrowingTestLockFactory extends LockFactory
{
    public function __construct()
    {
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        throw new \RuntimeException('lock backend unavailable');
    }
}

final class TestSharedLock implements SharedLockInterface
{
    public bool $releaseFailure = false;

    public function acquire(bool $blocking = false): bool
    {
        return true;
    }

    public function acquireRead(bool $blocking = false): bool
    {
        return $this->acquire($blocking);
    }

    public function refresh(?float $ttl = null): void
    {
    }

    public function isAcquired(): bool
    {
        return true;
    }

    public function release(): void
    {
        if ($this->releaseFailure) {
            throw new \RuntimeException('lock release failed');
        }
    }

    public function isExpired(): bool
    {
        return false;
    }

    public function getRemainingLifetime(): ?float
    {
        return 300.0;
    }
}
