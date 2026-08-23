<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

interface PaperCertificationCampaignProcessExecutorInterface
{
    /** @param list<string> $argv */
    public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult;
}
