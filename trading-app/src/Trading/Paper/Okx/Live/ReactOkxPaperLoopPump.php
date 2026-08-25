<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use React\EventLoop\LoopInterface;

final readonly class ReactOkxPaperLoopPump implements OkxPaperLoopPumpInterface
{
    public function __construct(private LoopInterface $loop)
    {
    }

    public function pump(): void
    {
        $this->loop->futureTick(fn () => $this->loop->stop());
        $this->loop->run();
    }
}
