<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use React\EventLoop\LoopInterface;

interface HyperliquidPaperPublicWebSocketTransportFactoryInterface
{
    public function create(
        LoopInterface $loop,
        HyperliquidPaperPublicConfig $config,
    ): HyperliquidPaperPublicWebSocketTransportInterface;
}
