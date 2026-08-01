<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Profile;

enum PaperProfileEligibility: string
{
    case REFERENCE_ONLY = 'reference_only';
    case BASELINE_ELIGIBLE = 'baseline_eligible';
}
