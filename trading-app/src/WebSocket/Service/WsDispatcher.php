<?php

declare(strict_types=1);

namespace App\WebSocket\Service;

use Psr\Log\LoggerInterface;

/**
 * Dispatcher WebSocket
 * Implémentation temporaire pour permettre au système de fonctionner
 */
class WsDispatcher
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function dispatch(string $message): void
    {
        $this->logger->info('[WsDispatcher] Dispatching message', [
            'message' => $message,
        ]);
    }

    public function subscribe(string $symbol, array $timeframes): void
    {
        $this->logger->info('[WsDispatcher] Subscribing to symbol', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
        ]);
    }

    public function unsubscribe(string $symbol, array $timeframes): void
    {
        $this->logger->info('[WsDispatcher] Unsubscribing from symbol', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
        ]);
    }
}
