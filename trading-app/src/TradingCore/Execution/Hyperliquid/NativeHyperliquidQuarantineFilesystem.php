<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final class NativeHyperliquidQuarantineFilesystem implements HyperliquidQuarantineFilesystemInterface
{
    public function markerExists(string $path): bool
    {
        return @is_file($path);
    }

    public function persistMarker(string $path, string $content): void
    {
        $temporary = null;
        $handle = null;
        try {
            $directory = dirname($path);
            $temporary = @tempnam($directory, '.hl-quarantine-');
            if (!is_string($temporary) || !@chmod($temporary, 0600)) {
                throw new \RuntimeException('quarantine_temp_failed');
            }
            $handle = @fopen($temporary, 'wb');
            if (!is_resource($handle)) {
                throw new \RuntimeException('quarantine_open_failed');
            }

            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($content, $offset));
                if (!is_int($written) || $written <= 0) {
                    throw new \RuntimeException('quarantine_write_failed');
                }
                $offset += $written;
            }
            if (!@fflush($handle) || !@fsync($handle) || !@fclose($handle)) {
                throw new \RuntimeException('quarantine_file_sync_failed');
            }
            $handle = null;
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException('quarantine_rename_failed');
            }
            $temporary = null;
            $this->syncDirectory($directory);
        } catch (\Throwable $exception) {
            if ($exception instanceof HyperliquidDurableTripPersistenceException) {
                throw $exception;
            }
            throw new HyperliquidDurableTripPersistenceException();
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if (is_string($temporary) && @is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function removeMarker(string $path): void
    {
        if (!$this->markerExists($path)) {
            return;
        }

        try {
            if (!@unlink($path)) {
                throw new \RuntimeException('quarantine_unlink_failed');
            }
            $this->syncDirectory(dirname($path));
        } catch (\Throwable $exception) {
            if ($exception instanceof HyperliquidDurableTripPersistenceException) {
                throw $exception;
            }
            throw new HyperliquidDurableTripPersistenceException();
        }
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if (!is_resource($handle)) {
            throw new HyperliquidDurableTripPersistenceException();
        }

        try {
            if (!@fsync($handle)) {
                throw new HyperliquidDurableTripPersistenceException();
            }
        } finally {
            @fclose($handle);
        }
    }
}
