<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

enum PaperDatasetAppendResult: string
{
    case APPENDED = 'appended';
    case REPLAYED = 'replayed';
}
