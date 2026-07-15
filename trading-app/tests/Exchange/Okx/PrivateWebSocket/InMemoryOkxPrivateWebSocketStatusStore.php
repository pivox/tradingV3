<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityStatus;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketStatusStoreInterface;

final class InMemoryOkxPrivateWebSocketStatusStore implements OkxPrivateWebSocketStatusStoreInterface
{
    private ?OkxPrivateWebSocketObservabilityStatus $status = null;

    public function save(OkxPrivateWebSocketObservabilityStatus $status): void
    {
        $this->status = $status;
    }

    public function load(): ?OkxPrivateWebSocketObservabilityStatus
    {
        return $this->status;
    }

    public function clear(): void
    {
        $this->status = null;
    }
}
