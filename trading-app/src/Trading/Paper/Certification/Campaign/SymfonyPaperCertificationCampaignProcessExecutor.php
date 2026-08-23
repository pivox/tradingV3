<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class SymfonyPaperCertificationCampaignProcessExecutor implements PaperCertificationCampaignProcessExecutorInterface
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDirectory)
    {
    }

    public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
    {
        $process = new Process($argv, $this->projectDirectory, ['PAPER_EXECUTION_ENABLED' => '1']);
        $process->setTimeout($timeoutSeconds);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new PaperCertificationCampaignProcessResult(124, '', true);
        } catch (\Throwable $failure) {
            return new PaperCertificationCampaignProcessResult(127, '', false, $failure->getMessage());
        }

        return new PaperCertificationCampaignProcessResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            false,
            $process->getErrorOutput(),
        );
    }
}
