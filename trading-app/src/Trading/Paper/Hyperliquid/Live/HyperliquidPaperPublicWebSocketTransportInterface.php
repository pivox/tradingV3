<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

interface HyperliquidPaperPublicWebSocketTransportInterface
{
    public function connect(
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void;

    /** @param array<string, mixed> $message */
    public function send(array $message): void;

    public function pauseReading(): void;

    public function resumeReading(): void;

    public function close(): void;
}
