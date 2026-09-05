<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final readonly class SymfonyPaperPublicCaptureAttemptExecutor implements PaperPublicCaptureAttemptExecutorInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDirectory,
    ) {
    }

    public function execute(
        string $venue,
        string $datasetId,
        int $durationSeconds,
    ): PaperPublicCaptureAttemptResult {
        try {
            $process = new Process([
                \PHP_BINARY,
                $this->projectDirectory . '/bin/console',
                'app:paper-market:public-capture',
                '--venue=' . $venue,
                '--dataset-id=' . $datasetId,
                '--duration-sec=' . $durationSeconds,
                '--no-interaction',
            ], $this->projectDirectory, [
                'PAPER_EXECUTION_ENABLED' => '0',
            ]);
            $process->setTimeout(null);
            $process->disableOutput();
            $signalState = $this->forwardSignalsTo($process);
            try {
                $process->run();
            } finally {
                $this->restoreSignals($signalState);
            }

            return new PaperPublicCaptureAttemptResult($process->getExitCode() ?? 1);
        } catch (\Throwable) {
            return new PaperPublicCaptureAttemptResult(127);
        }
    }

    /** @return array{async: bool, handlers: array<int, callable|int>}|null */
    private function forwardSignalsTo(Process $process): ?array
    {
        if (!function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            return null;
        }

        $state = [
            'async' => pcntl_async_signals(true),
            'handlers' => [],
        ];
        foreach ([\SIGINT, \SIGTERM] as $signal) {
            $state['handlers'][$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $received) use ($process): void {
                if (!$process->isRunning()) {
                    return;
                }
                try {
                    $process->signal($received);
                } catch (\Throwable) {
                    // The child may already have received the process-group signal.
                }
            });
        }

        return $state;
    }

    /** @param array{async: bool, handlers: array<int, callable|int>}|null $state */
    private function restoreSignals(?array $state): void
    {
        if ($state === null) {
            return;
        }
        foreach ($state['handlers'] as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($state['async']);
    }
}
