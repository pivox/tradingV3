<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

/** A private, opt-in side channel; never part of the Paper replay journal. */
final class PaperPlanRejectionDiagnostics
{
    /** @var resource|null */
    private $handle = null;

    public function start(string $path): void
    {
        $this->close();
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('paper_plan_diagnostics_path_must_be_absolute');
        }
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new \RuntimeException('paper_plan_diagnostics_open_failed');
        }
        $this->handle = $handle;
    }

    /** @param array<string, mixed> $record Already safelisted by the Paper plan source. */
    public function record(array $record): void
    {
        if ($this->handle === null) {
            return;
        }
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (@fwrite($this->handle, $line) !== strlen($line) || !@fflush($this->handle)) {
            throw new \RuntimeException('paper_plan_diagnostics_write_failed');
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            $handle = $this->handle;
            $this->handle = null;
            if (!@fclose($handle)) {
                throw new \RuntimeException('paper_plan_diagnostics_close_failed');
            }
        }
    }
}
