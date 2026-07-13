<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

interface OkxPrivateWebSocketStatusStoreInterface
{
    public function save(OkxPrivateWebSocketObservabilityStatus $status): void;

    public function load(): ?OkxPrivateWebSocketObservabilityStatus;

    public function clear(): void;
}
