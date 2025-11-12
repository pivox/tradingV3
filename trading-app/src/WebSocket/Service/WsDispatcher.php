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

    public function subscribe(string $symbol, array $timeframes, ?\App\Provider\Context\ExchangeContext $context = null): void
    {
        $this->logger->info('[WsDispatcher] Subscribing to symbol', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
            'exchange' => $context?->exchange->value ?? 'bitmart',
            'market_type' => $context?->marketType->value ?? 'perpetual',
        ]);

        $this->wsPublicKlinesService->subscribe($symbol, $timeframes, $context);
    }

    public function unsubscribe(string $symbol, array $timeframes, ?\App\Provider\Context\ExchangeContext $context = null): void
    {
        $this->logger->info('[WsDispatcher] Unsubscribing from symbol', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
            'exchange' => $context?->exchange->value ?? 'bitmart',
            'market_type' => $context?->marketType->value ?? 'perpetual',
        ]);

        $this->wsPublicKlinesService->unsubscribe($symbol, $timeframes, $context);
    }

    public function disconnect(): void
    {
        $this->wsPublicKlinesService->disconnect();
    }
}
