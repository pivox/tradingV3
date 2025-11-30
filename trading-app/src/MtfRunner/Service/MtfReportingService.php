<?php

declare(strict_types=1);

namespace App\MtfRunner\Service;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service d'accès aux rapports MTF (scripts bash / PHP).
 */
final class MtfReportingService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{output: string, error: string, exitCode: int}
     */
    public function getMtfReport(string $date): array
    {
        $script = $this->projectDir . '/../mtf_report.sh';
        if (!is_file($script)) {
            return $this->buildMissingScriptResult($script);
        }

        return $this->runProcess(['bash', $script, $date]);
    }

    /**
     * @return array{output: string, error: string, exitCode: int}
     */
    public function getMtfSymbolsReport(string $date): array
    {
        $script = $this->projectDir . '/../mtf_symbols_report.sh';
        if (!is_file($script)) {
            return $this->buildMissingScriptResult($script);
        }

        return $this->runProcess(['bash', $script, $date]);
    }

    /**
     * @return array{output: string, error: string, exitCode: int}
     */
    public function getMtfBlockersReport(string $date, ?string $timeFilter = null): array
    {
        $script = $this->projectDir . '/scripts/analyze_mtf_blockers.php';
        if (!is_file($script)) {
            return $this->buildMissingScriptResult($script);
        }

        $command = ['php', $script, $date];
        if ($timeFilter !== null && $timeFilter !== '') {
            $command[] = $timeFilter;
        }

        return $this->runProcess($command, $this->projectDir);
    }

    /**
     * @param list<string> $command
     * @return array{output: string, error: string, exitCode: int}
     */
    private function runProcess(array $command, ?string $cwd = null): array
    {
        $workingDir = $cwd ?? $this->projectDir;
        $process = new Process($command, $workingDir);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (ProcessFailedException $e) {
            return [
                'output' => $process->getOutput(),
                'error' => $e->getMessage(),
                'exitCode' => $process->getExitCode() ?? 1,
            ];
        }

        return [
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? 0,
        ];
    }

    /**
     * @return array{output: string, error: string, exitCode: int}
     */
    private function buildMissingScriptResult(string $scriptPath): array
    {
        return [
            'output' => '',
            'error' => sprintf('Script introuvable: %s', $scriptPath),
            'exitCode' => 1,
        ];
    }
}
