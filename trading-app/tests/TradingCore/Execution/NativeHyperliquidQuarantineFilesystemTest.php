<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Hyperliquid\HyperliquidDurableTripPersistenceException;
use App\TradingCore\Execution\Hyperliquid\NativeHyperliquidQuarantineFilesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativeHyperliquidQuarantineFilesystem::class)]
final class NativeHyperliquidQuarantineFilesystemTest extends TestCase
{
    public function testPersistMarkerWritesExactContentWithOwnerOnlyPermissions(): void
    {
        $directory = sys_get_temp_dir() . '/hl-native-quarantine-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $marker = $directory . '/execution.quarantine';

        try {
            (new NativeHyperliquidQuarantineFilesystem())->persistMarker(
                $marker,
                "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n",
            );

            self::assertSame("HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n", file_get_contents($marker));
            self::assertSame(0600, fileperms($marker) & 0777);
        } finally {
            @unlink($marker);
            @rmdir($directory);
        }
    }

    public function testUnavailableDurabilityOperationRaisesDistinctException(): void
    {
        $this->expectException(HyperliquidDurableTripPersistenceException::class);
        $this->expectExceptionMessage('hyperliquid_durable_trip_persistence_failed');

        (new NativeHyperliquidQuarantineFilesystem())->persistMarker(
            '/dev/null/execution.quarantine',
            "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n",
        );
    }

    public function testPersistMarkerReplacesExistingContentAndPermissions(): void
    {
        $directory = sys_get_temp_dir() . '/hl-native-replace-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $marker = $directory . '/execution.quarantine';
        file_put_contents($marker, 'secret-bearing-old-marker');
        chmod($marker, 0644);

        try {
            (new NativeHyperliquidQuarantineFilesystem())->persistMarker(
                $marker,
                "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n",
            );

            self::assertSame("HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n", file_get_contents($marker));
            self::assertSame(0600, fileperms($marker) & 0777);
        } finally {
            @unlink($marker);
            @rmdir($directory);
        }
    }

    public function testRemoveMarkerDurablyUnlinksMarker(): void
    {
        $directory = sys_get_temp_dir() . '/hl-native-recovery-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $marker = $directory . '/execution.quarantine';
        $filesystem = new NativeHyperliquidQuarantineFilesystem();

        try {
            $filesystem->persistMarker($marker, "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n");
            $filesystem->removeMarker($marker);

            self::assertFileDoesNotExist($marker);
        } finally {
            @unlink($marker);
            @rmdir($directory);
        }
    }

    public function testMarkerExistsTreatsDirectoryEntryAsQuarantine(): void
    {
        $directory = sys_get_temp_dir() . '/hl-marker-directory-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        try {
            self::assertTrue((new NativeHyperliquidQuarantineFilesystem())->markerExists($directory));
        } finally {
            @rmdir($directory);
        }
    }

    public function testMarkerExistsTreatsDanglingSymlinkAsQuarantine(): void
    {
        $directory = sys_get_temp_dir() . '/hl-marker-symlink-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $marker = $directory . '/execution.quarantine';
        symlink($directory . '/missing-target', $marker);

        try {
            self::assertTrue((new NativeHyperliquidQuarantineFilesystem())->markerExists($marker));
        } finally {
            @unlink($marker);
            @rmdir($directory);
        }
    }

    public function testMarkerExistsReturnsFalseOnlyForConfirmedAccessibleAbsence(): void
    {
        $directory = sys_get_temp_dir() . '/hl-marker-absent-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        try {
            self::assertFalse((new NativeHyperliquidQuarantineFilesystem())->markerExists(
                $directory . '/execution.quarantine',
            ));
        } finally {
            @rmdir($directory);
        }
    }

    public function testMarkerExistsThrowsWhenParentCannotBeInspected(): void
    {
        $this->expectException(HyperliquidDurableTripPersistenceException::class);

        (new NativeHyperliquidQuarantineFilesystem())->markerExists('/dev/null/execution.quarantine');
    }
}
