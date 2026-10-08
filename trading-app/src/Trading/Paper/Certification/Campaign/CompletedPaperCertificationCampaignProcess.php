<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

/** A child that already ran to its end (synchronous executors). */
final readonly class CompletedPaperCertificationCampaignProcess implements PaperCertificationCampaignRunningProcessInterface
{
    public function __construct(private PaperCertificationCampaignProcessResult $result)
    {
    }

    public function poll(): PaperCertificationCampaignProcessResult
    {
        return $this->result;
    }

    public function stop(): PaperCertificationCampaignProcessResult
    {
        return $this->result;
    }
}
