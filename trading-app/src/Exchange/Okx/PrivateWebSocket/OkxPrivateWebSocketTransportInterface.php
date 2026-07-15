<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

interface OkxPrivateWebSocketTransportInterface
{
    public function connect(
        string $uri,
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void;

    /** @param array<string, mixed> $message */
    public function send(array $message): void;

    public function close(): void;
}
