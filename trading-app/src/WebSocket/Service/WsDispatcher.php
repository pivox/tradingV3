<?php

declare(strict_types=1);

namespace App\WebSocket\Service;

use Psr\Log\LoggerInterface;

/**
 * Dispatcher WebSocket
 * Délègue les opérations de souscription au service WebSocket public
 */
class WsDispatcher
{
    public function __construct(
        private readonly WsPublicKlinesService $wsPublicKlinesService,
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

        $this->wsPublicKlinesService->subscribe($symbol, $timeframes);
    }

    public function unsubscribe(string $symbol, array $timeframes): void
    {
        $this->logger->info('[WsDispatcher] Unsubscribing from symbol', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
        ]);

        $this->wsPublicKlinesService->unsubscribe($symbol, $timeframes);
    }

    public function disconnect(): void
    {
        $this->wsPublicKlinesService->disconnect();
    }
}
