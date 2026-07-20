<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

enum PaperDatasetState: string
{
    case RECORDING = 'recording';
    case COMPLETE = 'complete';
    case INCOMPLETE = 'incomplete';
}
