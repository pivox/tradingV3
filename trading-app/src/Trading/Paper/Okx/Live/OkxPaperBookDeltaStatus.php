<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

enum OkxPaperBookDeltaStatus: string
{
    case APPLIED = 'applied';
    case REPLAYED = 'replayed';
}
