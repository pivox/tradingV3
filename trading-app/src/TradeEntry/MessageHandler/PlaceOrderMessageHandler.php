<?php

declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\TradeEntry\Message\PlaceOrderMessage;
use App\TradeEntry\Service\WsWorkerClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler pour placer un ordre via ws-worker
 */
#[AsMessageHandler]
final class PlaceOrderMessageHandler
{
    public function __construct(
        private readonly WsWorkerClient $wsWorkerClient,
        #[Autowire(service: 'monolog.logger.orders')]
        private readonly LoggerInterface $ordersLogger,
        #[Autowire(env: 'APP_BASE_URL')]
        private readonly string $appBaseUrl,
    ) {}

    public function __invoke(PlaceOrderMessage $message): void
    {
        $this->ordersLogger->info('[PlaceOrderHandler] Processing order placement', [
            'order_id' => $message->orderId,
            'symbol' => $message->symbol,
            'side' => $message->side,
            'entry_zone' => [$message->entryZoneMin, $message->entryZoneMax],
        ]);

        $orderData = [
            'id' => $message->orderId,
            'symbol' => $message->symbol,
            'side' => $message->side,
            'entry_zone_min' => $message->entryZoneMin,
            'entry_zone_max' => $message->entryZoneMax,
            'quantity' => $message->quantity,
            'leverage' => $message->leverage,
            'stop_loss' => $message->stopLoss,
            'take_profit' => $message->takeProfit,
            'timeout_seconds' => $message->timeoutSeconds,
            'callback_url' => $this->appBaseUrl . '/api/orders/callback',
            'metadata' => $message->metadata,
        ];

        $result = $this->wsWorkerClient->placeOrder($orderData);

        if (!$result['ok']) {
            $this->ordersLogger->error('[PlaceOrderHandler] Failed to place order', [
                'order_id' => $message->orderId,
                'error' => $result['message'],
            ]);
        }
    }
}
