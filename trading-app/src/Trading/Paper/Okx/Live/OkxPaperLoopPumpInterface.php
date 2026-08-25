<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

interface OkxPaperLoopPumpInterface
{
    public function pump(): void;
}
