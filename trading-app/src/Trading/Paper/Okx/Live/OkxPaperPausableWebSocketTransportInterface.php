<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

interface OkxPaperPausableWebSocketTransportInterface extends OkxPaperPublicWebSocketTransportInterface
{
    public function pause(): void;

    public function resume(): void;
}
