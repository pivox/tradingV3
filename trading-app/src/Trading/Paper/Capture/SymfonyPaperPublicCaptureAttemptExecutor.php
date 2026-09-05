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
            $process->run();

            return new PaperPublicCaptureAttemptResult($process->getExitCode() ?? 1);
        } catch (\Throwable) {
            return new PaperPublicCaptureAttemptResult(127);
        }
    }
}
