<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Hyperliquid\FilesystemFallbackHyperliquidKillSwitch;
use App\TradingCore\Execution\Hyperliquid\HyperliquidDurableTripPersistenceException;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemFallbackHyperliquidKillSwitch::class)]
final class FilesystemFallbackHyperliquidKillSwitchTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/hl-quarantine-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testRepositoryFailureAtomicallyCreatesFixedMarkerAndNextReadBlocksLocally(): void
    {
        $repository = new FailingPrimaryKillSwitch();
        $marker = $this->directory . '/execution.quarantine';
        $switch = new FilesystemFallbackHyperliquidKillSwitch($repository, $marker);

        $switch->trip('reason-containing-secret=never-write', ['token' => 'never-write']);

        self::assertSame("HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n", file_get_contents($marker));
        self::assertTrue($switch->isTripped());
        self::assertSame(0, $repository->readAttempts);
    }

    public function testRepositoryAndMarkerFailureRaiseDistinctPersistenceException(): void
    {
        $switch = new FilesystemFallbackHyperliquidKillSwitch(
            new FailingPrimaryKillSwitch(),
            '/dev/null/execution.quarantine',
        );

        $this->expectException(HyperliquidDurableTripPersistenceException::class);
        $this->expectExceptionMessage('hyperliquid_durable_trip_persistence_failed');

        $switch->trip('reason', []);
    }
}

final class FailingPrimaryKillSwitch implements HyperliquidKillSwitchTripInterface
{
    public int $readAttempts = 0;

    public function isTripped(): bool
    {
        ++$this->readAttempts;
        throw new \RuntimeException('database unavailable');
    }

    public function trip(string $reason, array $auditContext): void
    {
        throw new \RuntimeException('database unavailable');
    }
}
