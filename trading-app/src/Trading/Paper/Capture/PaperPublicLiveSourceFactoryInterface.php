<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use React\EventLoop\LoopInterface;

interface PaperPublicLiveSourceFactoryInterface
{
    public function create(
        #[\SensitiveParameter] string $datasetDirectory,
        ?LoopInterface $loop = null,
    ): PaperLiveMarketDataSourceInterface;
}
