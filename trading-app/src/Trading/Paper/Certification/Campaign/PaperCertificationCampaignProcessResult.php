<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

final readonly class PaperCertificationCampaignProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public bool $timedOut,
        #[\SensitiveParameter]
        public string $stderr = '',
    ) {
    }
}
