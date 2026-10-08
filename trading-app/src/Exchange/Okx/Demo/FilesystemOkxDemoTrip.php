<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

final readonly class FilesystemOkxDemoTrip implements OkxDemoTripInterface
{
    private const MARKER_CONTENT = "OKX_DEMO_EXECUTION_QUARANTINED\n";

    public function __construct(private string $markerPath)
    {
    }

    public function isTripped(): bool
    {
        clearstatcache(true, $this->markerPath);

        return @lstat($this->markerPath) !== false;
    }

    public function trip(): void
    {
        if (@file_put_contents($this->markerPath, self::MARKER_CONTENT, LOCK_EX) === false) {
            throw new \RuntimeException('okx_demo_trip_persistence_failed');
        }
    }
}
