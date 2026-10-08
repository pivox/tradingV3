<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

interface OkxPaperPublicWebSocketTransportInterface
{
    /**
     * $onClose receives the WebSocket close code and reason when the peer or the
     * socket provides them.
     *
     * @param callable(): void $onOpen
     * @param callable(string): void $onMessage
     * @param callable(?int, ?string): void $onClose
     * @param callable(\Throwable): void $onError
     */
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
