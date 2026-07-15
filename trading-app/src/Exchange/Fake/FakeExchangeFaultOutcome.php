<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

enum FakeExchangeFaultOutcome: string
{
    case NotApplied = 'not_applied';
    case AppliedResponseLost = 'applied_response_lost';
}
