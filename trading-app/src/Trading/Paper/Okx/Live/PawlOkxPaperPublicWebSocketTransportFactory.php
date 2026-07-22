<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use React\EventLoop\LoopInterface;

final readonly class PawlOkxPaperPublicWebSocketTransportFactory implements OkxPaperPublicWebSocketTransportFactoryInterface
{
    public function create(LoopInterface $loop): OkxPaperPublicWebSocketTransportInterface
    {
        return new PawlOkxPaperPublicWebSocketTransport($loop);
    }
}
