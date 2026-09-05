<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final readonly class SymfonyPaperPublicCaptureAttemptExecutor implements PaperPublicCaptureAttemptExecutorInterface
{
    private const OUTPUT_TAIL_BYTES = 8192;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDirectory,
        private ?PaperPublicCaptureOrphanFinalizer $orphanFinalizer = null,
    ) {
    }

    public function execute(
        string $venue,
        string $datasetId,
        int $durationSeconds,
    ): PaperPublicCaptureAttemptResult {
        $startedAt = self::now();
        $stdoutTail = '';
        $stderrTail = '';
        $pid = null;
        $operatorSignal = null;
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
            $signalState = $this->forwardSignalsTo($process, $operatorSignal);
            try {
                $captureOutput = static function (string $type, string $chunk) use (&$stdoutTail, &$stderrTail): void {
                    $tail = $type === Process::OUT ? $stdoutTail : $stderrTail;
                    $tail = substr($tail . $chunk, -self::OUTPUT_TAIL_BYTES);
                    if ($type === Process::OUT) {
                        $stdoutTail = $tail;
                    } else {
                        $stderrTail = $tail;
                    }
                };
                $process->start($captureOutput);
                $pid = $process->getPid();
                $process->wait();
            } finally {
                $this->restoreSignals($signalState);
            }

            $exitCode = $process->getExitCode() ?? 1;

            return new PaperPublicCaptureAttemptResult(
                exitCode: $exitCode,
                termSignal: $operatorSignal
                    ?? ($process->hasBeenSignaled() ? $process->getTermSignal() : null),
                pid: $pid,
                startedAt: $startedAt,
                endedAt: self::now(),
                stdoutTail: $this->redact($stdoutTail),
                stderrTail: $this->redact($stderrTail),
                orphanFinalized: $exitCode === 0
                    ? null
                    : $this->orphanFinalizer?->finalize($datasetId),
            );
        } catch (\Throwable $failure) {
            return new PaperPublicCaptureAttemptResult(
                exitCode: 127,
                pid: $pid,
                startedAt: $startedAt,
                endedAt: self::now(),
                stderrTail: $this->redact($failure::class . ': ' . $failure->getMessage()),
                orphanFinalized: $this->orphanFinalizer?->finalize($datasetId),
            );
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function redact(string $output): string
    {
        $output = str_replace($this->projectDirectory, '[project]', $output);
        $output = preg_replace(
            '#(?<![A-Za-z0-9])/(?:[^\s:/]+/)*[^\s:]*#',
            '[path]',
            $output,
        ) ?? '';
        $output = preg_replace(
            '/\S*(?:secret|password|token|api[_-]?key|wallet)\S*/i',
            '[redacted]',
            $output,
        ) ?? '';

        return substr($output, -self::OUTPUT_TAIL_BYTES);
    }

    /** @return array{async: bool, handlers: array<int, callable|int>}|null */
    private function forwardSignalsTo(Process $process, ?int &$operatorSignal): ?array
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
            pcntl_signal($signal, static function (int $received) use ($process, &$operatorSignal): void {
                $operatorSignal ??= $received;
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
