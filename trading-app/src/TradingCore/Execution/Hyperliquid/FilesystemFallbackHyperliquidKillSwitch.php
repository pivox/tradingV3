<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final readonly class FilesystemFallbackHyperliquidKillSwitch implements HyperliquidKillSwitchTripInterface, HyperliquidQuarantineRecoveryInterface
{
    private const MARKER_CONTENT = "HYPERLIQUID_TESTNET_EXECUTION_QUARANTINED\n";

    public function __construct(
        private HyperliquidKillSwitchTripInterface $repository,
        private string $markerPath,
        private ?HyperliquidQuarantineFilesystemInterface $filesystem = null,
    ) {
    }

    public function isTripped(): bool
    {
        if ($this->markerFilesystem()->markerExists($this->markerPath)) {
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
            $this->markerFilesystem()->persistMarker($this->markerPath, self::MARKER_CONTENT);
        } catch (HyperliquidDurableTripPersistenceException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new HyperliquidDurableTripPersistenceException();
        }
    }

    /**
     * Transfers quarantine ownership to an already-tripped repository.
     * This never resets the kill switch or releases process-retained execution locks.
     */
    public function recoverFallbackMarker(): HyperliquidQuarantineRecoveryStatus
    {
        $filesystem = $this->markerFilesystem();
        if (!$filesystem->markerExists($this->markerPath)) {
            return HyperliquidQuarantineRecoveryStatus::NoMarker;
        }

        try {
            $repositoryTripped = $this->repository->isTripped();
        } catch (\Throwable) {
            throw new HyperliquidDurableTripPersistenceException();
        }
        if (!$repositoryTripped) {
            return HyperliquidQuarantineRecoveryStatus::RepositoryNotTripped;
        }

        try {
            $filesystem->removeMarker($this->markerPath);
        } catch (HyperliquidDurableTripPersistenceException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new HyperliquidDurableTripPersistenceException();
        }

        return HyperliquidQuarantineRecoveryStatus::Transferred;
    }

    private function markerFilesystem(): HyperliquidQuarantineFilesystemInterface
    {
        return $this->filesystem ?? new NativeHyperliquidQuarantineFilesystem();
    }
}
