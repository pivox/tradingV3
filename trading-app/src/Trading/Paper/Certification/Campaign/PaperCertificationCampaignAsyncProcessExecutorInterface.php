<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

/** Executors able to run several campaign children at the same time. */
interface PaperCertificationCampaignAsyncProcessExecutorInterface extends PaperCertificationCampaignProcessExecutorInterface
{
    /** @param list<string> $argv */
    public function start(array $argv, int $timeoutSeconds): PaperCertificationCampaignRunningProcessInterface;
}
