<?php

declare(strict_types=1);

namespace App\Command;

interface HyperliquidTestnetOrderPlanFileReaderInterface
{
    public function read(string $path): string;
}
