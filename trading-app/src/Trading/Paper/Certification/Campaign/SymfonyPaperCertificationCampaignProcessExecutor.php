<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class SymfonyPaperCertificationCampaignProcessExecutor implements PaperCertificationCampaignEnvironmentProcessExecutorInterface
{
    /** Only these children execute Paper (Fake exchange); every other child runs with it disabled. */
    private const PAPER_EXECUTION_COMMANDS = ['app:paper-market:runtime-check', 'app:paper-market:replay'];

    /** The only variable a campaign may set for one child: that cell's own database. */
    private const CHILD_ENVIRONMENT_KEYS = ['DATABASE_URL'];

    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDirectory)
    {
    }

    public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
    {
        $process = $this->process($argv, $timeoutSeconds);
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

    public function start(array $argv, int $timeoutSeconds): PaperCertificationCampaignRunningProcessInterface
    {
        return $this->startWithEnvironment($argv, $timeoutSeconds, []);
    }

    public function startWithEnvironment(
        array $argv,
        int $timeoutSeconds,
        #[\SensitiveParameter] array $environment,
    ): PaperCertificationCampaignRunningProcessInterface {
        $process = $this->process($argv, $timeoutSeconds, $environment);
        try {
            $process->start();
        } catch (\Throwable $failure) {
            return new CompletedPaperCertificationCampaignProcess(
                new PaperCertificationCampaignProcessResult(127, '', false, $failure->getMessage()),
            );
        }

        return new SymfonyPaperCertificationCampaignRunningProcess($process);
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $environment
     */
    private function process(array $argv, int $timeoutSeconds, #[\SensitiveParameter] array $environment = []): Process
    {
        foreach ($environment as $name => $value) {
            if (!\in_array($name, self::CHILD_ENVIRONMENT_KEYS, true) || !\is_string($value) || $value === '') {
                throw new \InvalidArgumentException('paper_campaign_child_environment_invalid');
            }
        }
        $paperExecution = array_intersect($argv, self::PAPER_EXECUTION_COMMANDS) !== [];
        $process = new Process(
            $argv,
            $this->projectDirectory,
            [...$environment, 'PAPER_EXECUTION_ENABLED' => $paperExecution ? '1' : '0'],
        );
        $process->setTimeout($timeoutSeconds);

        return $process;
    }
}
