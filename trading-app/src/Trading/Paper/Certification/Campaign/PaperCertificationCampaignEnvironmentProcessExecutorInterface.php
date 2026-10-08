<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

/** Executors able to give a campaign child its own database (one PostgreSQL instance per cell). */
interface PaperCertificationCampaignEnvironmentProcessExecutorInterface extends PaperCertificationCampaignAsyncProcessExecutorInterface
{
    /**
     * @param list<string> $argv
     * @param array{DATABASE_URL?: string} $environment
     */
    public function startWithEnvironment(
        array $argv,
        int $timeoutSeconds,
        #[\SensitiveParameter] array $environment,
    ): PaperCertificationCampaignRunningProcessInterface;
}
