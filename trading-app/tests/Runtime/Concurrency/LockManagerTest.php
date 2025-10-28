<?php

declare(strict_types=1);

namespace App\Tests\Runtime\Concurrency;

use App\Runtime\Concurrency\LockManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Redis;

class LockManagerTest extends TestCase
{
    private LockManager $lockManager;
    private Redis $redis;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(Redis::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->lockManager = new LockManager($this->redis, $this->logger);
    }

    public function testAcquireLockSuccess(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with('lock:test_key', $this->isType('string'), ['NX', 'EX' => 30])
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Verrou acquis', $this->isType('array'));

        $result = $this->lockManager->acquireLock('test_key', 30);
        $this->assertTrue($result);
    }

    public function testAcquireLockFailure(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->willReturn(false);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Impossible d\'acquérir le verrou', $this->isType('array'));

        $result = $this->lockManager->acquireLock('test_key', 30);
        $this->assertFalse($result);
    }

    public function testAcquireLockWithRetrySuccess(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->willReturn(true);

        $result = $this->lockManager->acquireLockWithRetry('test_key', 30, 3, 100);
        $this->assertTrue($result);
    }

    public function testAcquireLockWithRetryFailure(): void
    {
        $this->redis->expects($this->exactly(3))
            ->method('set')
            ->willReturn(false);

        $result = $this->lockManager->acquireLockWithRetry('test_key', 30, 3, 100);
        $this->assertFalse($result);
    }

    public function testReleaseLockSuccess(): void
    {
        $this->redis->expects($this->once())
            ->method('get')
            ->with('lock:test_key')
            ->willReturn('identifier123');

        $this->redis->expects($this->once())
            ->method('eval')
            ->willReturn(1);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Verrou libéré', ['key' => 'test_key']);

        $result = $this->lockManager->releaseLock('test_key');
        $this->assertTrue($result);
    }

    public function testReleaseLockFailure(): void
    {
        $this->redis->expects($this->once())
            ->method('get')
            ->willReturn('identifier123');

        $this->redis->expects($this->once())
            ->method('eval')
            ->willReturn(0);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Impossible de libérer le verrou', ['key' => 'test_key']);

        $result = $this->lockManager->releaseLock('test_key');
        $this->assertFalse($result);
    }

    public function testIsLocked(): void
    {
        $this->redis->expects($this->once())
            ->method('exists')
            ->with('lock:test_key')
            ->willReturn(1);

        $result = $this->lockManager->isLocked('test_key');
        $this->assertTrue($result);
    }

    public function testIsNotLocked(): void
    {
        $this->redis->expects($this->once())
            ->method('exists')
            ->with('lock:test_key')
            ->willReturn(0);

        $result = $this->lockManager->isLocked('test_key');
        $this->assertFalse($result);
    }

    public function testGetLockInfo(): void
    {
        $this->redis->expects($this->once())
            ->method('exists')
            ->with('lock:test_key')
            ->willReturn(1);

        $this->redis->expects($this->once())
            ->method('ttl')
            ->with('lock:test_key')
            ->willReturn(300);

        $this->redis->expects($this->once())
            ->method('get')
            ->with('lock:test_key')
            ->willReturn('identifier123');

        $result = $this->lockManager->getLockInfo('test_key');
        
        $this->assertNotNull($result);
        $this->assertEquals('test_key', $result->key);
        $this->assertEquals('identifier123', $result->identifier);
        $this->assertEquals(300, $result->ttl);
    }

    public function testGetLockInfoNotFound(): void
    {
        $this->redis->expects($this->once())
            ->method('exists')
            ->with('lock:test_key')
            ->willReturn(0);

        $result = $this->lockManager->getLockInfo('test_key');
        $this->assertNull($result);
    }

    public function testForceReleaseLock(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with('lock:test_key')
            ->willReturn(1);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Verrou forcé libéré', ['key' => 'test_key']);

        $result = $this->lockManager->forceReleaseLock('test_key');
        $this->assertTrue($result);
    }

    public function testGetAllLocks(): void
    {
        $this->redis->expects($this->once())
            ->method('keys')
            ->with('lock:*')
            ->willReturn(['lock:key1', 'lock:key2']);

        $this->redis->expects($this->exactly(2))
            ->method('exists')
            ->willReturn(1);

        $this->redis->expects($this->exactly(2))
            ->method('ttl')
            ->willReturn(300);

        $this->redis->expects($this->exactly(2))
            ->method('get')
            ->willReturn('identifier123');

        $result = $this->lockManager->getAllLocks();
        $this->assertCount(2, $result);
    }

    public function testCleanupExpiredLocks(): void
    {
        $this->redis->expects($this->once())
            ->method('keys')
            ->with('lock:*')
            ->willReturn(['lock:key1']);

        $this->redis->expects($this->once())
            ->method('exists')
            ->willReturn(1);

        $this->redis->expects($this->once())
            ->method('ttl')
            ->willReturn(0); // Expired

        $this->redis->expects($this->once())
            ->method('get')
            ->willReturn('identifier123');

        $this->redis->expects($this->once())
            ->method('del')
            ->with('lock:key1')
            ->willReturn(1);

        $result = $this->lockManager->cleanupExpiredLocks();
        $this->assertEquals(1, $result);
    }
}
