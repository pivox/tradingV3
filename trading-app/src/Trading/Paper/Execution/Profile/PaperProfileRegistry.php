<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Profile;

final class PaperProfileRegistry
{
    /** @var array<string, PaperProfileEligibility> */
    private const PROFILES = [
        'regular' => PaperProfileEligibility::REFERENCE_ONLY,
        'scalper' => PaperProfileEligibility::REFERENCE_ONLY,
        'scalper_micro' => PaperProfileEligibility::REFERENCE_ONLY,
    ];

    public function require(string $profile): PaperProfileEligibility
    {
        return self::PROFILES[$profile]
            ?? throw new \InvalidArgumentException('paper_strategy_profile_unknown');
    }
}
