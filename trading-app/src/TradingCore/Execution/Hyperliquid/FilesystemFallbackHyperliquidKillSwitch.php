<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final readonly class FilesystemFallbackHyperliquidKillSwitch implements HyperliquidKillSwitchTripInterface
{
    private const MARKER_CONTENT = "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n";

    public function __construct(
        private HyperliquidKillSwitchTripInterface $repository,
        private string $markerPath,
    ) {
    }

    public function isTripped(): bool
    {
        if (@is_file($this->markerPath)) {
            return true;
        }

        return $this->repository->isTripped();
    }

    public function trip(string $reason, array $auditContext): void
    {
        try {
            $this->repository->trip($reason, $auditContext);
            return;
        } catch (\Throwable) {
        }

        try {
            $this->writeMarker();
        } catch (\Throwable) {
            throw new HyperliquidDurableTripPersistenceException();
        }
    }

    private function writeMarker(): void
    {
        if (@is_file($this->markerPath)) {
            return;
        }

        $directory = dirname($this->markerPath);
        $temporary = @tempnam($directory, '.hl-quarantine-');
        if (!is_string($temporary)) {
            throw new \RuntimeException('hyperliquid_quarantine_marker_temp_failed');
        }

        try {
            $written = @file_put_contents($temporary, self::MARKER_CONTENT, LOCK_EX);
            if ($written !== strlen(self::MARKER_CONTENT) || !@chmod($temporary, 0600) || !@rename($temporary, $this->markerPath)) {
                throw new \RuntimeException('hyperliquid_quarantine_marker_write_failed');
            }
        } finally {
            if (@is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
