<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

interface PaperCertificationCampaignRunningProcessInterface
{
    /** Null while the process runs; its final result once it exited or timed out. */
    public function poll(): ?PaperCertificationCampaignProcessResult;

    /** Stops the process (SIGTERM, then SIGKILL after a grace period) and returns its result. */
    public function stop(): PaperCertificationCampaignProcessResult;
}
