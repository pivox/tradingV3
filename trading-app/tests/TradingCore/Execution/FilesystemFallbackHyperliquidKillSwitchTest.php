<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Hyperliquid\FilesystemFallbackHyperliquidKillSwitch;
use App\TradingCore\Execution\Hyperliquid\HyperliquidDurableTripPersistenceException;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidQuarantineFilesystemInterface;
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

    public function testInjectedDurabilityFailureRaisesDistinctPersistenceException(): void
    {
        $switch = new FilesystemFallbackHyperliquidKillSwitch(
            new FailingPrimaryKillSwitch(),
            $this->directory . '/execution.quarantine',
            new FailingQuarantineFilesystem(),
        );

        $this->expectException(HyperliquidDurableTripPersistenceException::class);

        $switch->trip('reason', []);
    }

    public function testRecoveryTransfersQuarantineToAlreadyTrippedRepositoryWithoutReenabling(): void
    {
        $repository = new ControlledPrimaryKillSwitch(tripped: false, tripFailure: true);
        $marker = $this->directory . '/execution.quarantine';
        $switch = new FilesystemFallbackHyperliquidKillSwitch($repository, $marker);
        $switch->trip('fallback', []);
        self::assertFileExists($marker);
        $repository->tripFailure = false;
        $repository->tripped = true;

        self::assertTrue($switch->recoverFallbackMarker());

        self::assertFileDoesNotExist($marker);
        self::assertTrue($switch->isTripped(), 'repository quarantine must remain active');
    }

    public function testRecoveryRefusesWhenRepositoryIsNotTripped(): void
    {
        $marker = $this->directory . '/execution.quarantine';
        (new \App\TradingCore\Execution\Hyperliquid\NativeHyperliquidQuarantineFilesystem())->persistMarker(
            $marker,
            "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n",
        );
        $switch = new FilesystemFallbackHyperliquidKillSwitch(
            new ControlledPrimaryKillSwitch(tripped: false),
            $marker,
        );

        self::assertFalse($switch->recoverFallbackMarker());
        self::assertFileExists($marker);
    }

    public function testRecoveryRefusesUnreadableRepositoryAndPreservesMarker(): void
    {
        $marker = $this->directory . '/execution.quarantine';
        (new \App\TradingCore\Execution\Hyperliquid\NativeHyperliquidQuarantineFilesystem())->persistMarker(
            $marker,
            "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n",
        );
        $switch = new FilesystemFallbackHyperliquidKillSwitch(
            new ControlledPrimaryKillSwitch(tripped: false, readFailure: true),
            $marker,
        );

        try {
            $switch->recoverFallbackMarker();
            self::fail('unreadable repository must refuse recovery');
        } catch (HyperliquidDurableTripPersistenceException) {
            self::assertFileExists($marker);
        }
    }

    public function testRecoverySurfacesDurableMarkerRemovalFailure(): void
    {
        $switch = new FilesystemFallbackHyperliquidKillSwitch(
            new ControlledPrimaryKillSwitch(tripped: true),
            $this->directory . '/execution.quarantine',
            new MarkerPresentFailingRemovalFilesystem(),
        );

        $this->expectException(HyperliquidDurableTripPersistenceException::class);

        $switch->recoverFallbackMarker();
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

final class FailingQuarantineFilesystem implements HyperliquidQuarantineFilesystemInterface
{
    public function markerExists(string $path): bool
    {
        return false;
    }

    public function persistMarker(string $path, string $content): void
    {
        throw new \RuntimeException('fsync failed');
    }

    public function removeMarker(string $path): void
    {
        throw new \RuntimeException('fsync failed');
    }
}

final class ControlledPrimaryKillSwitch implements HyperliquidKillSwitchTripInterface
{
    public function __construct(
        public bool $tripped,
        public bool $tripFailure = false,
        public bool $readFailure = false,
    ) {
    }

    public function isTripped(): bool
    {
        if ($this->readFailure) {
            throw new \RuntimeException('database unavailable');
        }

        return $this->tripped;
    }

    public function trip(string $reason, array $auditContext): void
    {
        if ($this->tripFailure) {
            throw new \RuntimeException('database unavailable');
        }
        $this->tripped = true;
    }
}

final class MarkerPresentFailingRemovalFilesystem implements HyperliquidQuarantineFilesystemInterface
{
    public function markerExists(string $path): bool
    {
        return true;
    }

    public function persistMarker(string $path, string $content): void
    {
    }

    public function removeMarker(string $path): void
    {
        throw new \RuntimeException('directory fsync failed');
    }
}
