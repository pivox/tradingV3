<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution;

enum PaperCrashPoint: string
{
    case BEFORE_PHASE_1_COMMIT = 'before_phase_1_commit';
    case AFTER_PHASE_1_COMMIT = 'after_phase_1_commit';
    case AFTER_FAKE_EFFECT = 'after_fake_effect';
    case BEFORE_PHASE_3_COMMIT = 'before_phase_3_commit';
    case AFTER_PHASE_3_COMMIT = 'after_phase_3_commit';
}
