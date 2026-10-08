<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

final readonly class FilesystemOkxDemoTrip implements OkxDemoTripInterface
{
    private const MARKER_HEADER = "OKX_DEMO_EXECUTION_QUARANTINED\n";

    public function __construct(private string $markerPath)
    {
    }

    public function isTripped(): bool
    {
        clearstatcache(true, $this->markerPath);

        return @lstat($this->markerPath) !== false;
    }

    public function reason(): ?string
    {
        $content = @file_get_contents($this->markerPath);
        if (!\is_string($content) || !str_starts_with($content, self::MARKER_HEADER)) {
            return null;
        }
        $reason = trim(substr($content, \strlen(self::MARKER_HEADER)));

        return $reason === '' ? null : $reason;
    }

    public function trip(string $reason): void
    {
        $content = self::MARKER_HEADER . preg_replace('/[^a-z0-9_]/i', '_', $reason) . "\n";
        if (@file_put_contents($this->markerPath, $content, LOCK_EX) === false) {
            throw new \RuntimeException('okx_demo_trip_persistence_failed');
        }
    }
}
