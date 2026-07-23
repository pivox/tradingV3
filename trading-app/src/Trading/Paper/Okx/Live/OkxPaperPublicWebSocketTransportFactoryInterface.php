<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use React\EventLoop\LoopInterface;

interface OkxPaperPublicWebSocketTransportFactoryInterface
{
    public function create(LoopInterface $loop): OkxPaperPublicWebSocketTransportInterface;
}
