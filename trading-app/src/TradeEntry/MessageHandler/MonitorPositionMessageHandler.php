<?php

declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\TradeEntry\Message\MonitorPositionMessage;
use App\TradeEntry\Service\WsWorkerClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler pour monitorer une position (SL/TP) via ws-worker
 */
#[AsMessageHandler]
final class MonitorPositionMessageHandler
{
    public function __construct(
        private readonly WsWorkerClient $wsWorkerClient,
        #[Autowire(service: 'monolog.logger.orders')]
        private readonly LoggerInterface $ordersLogger,
        #[Autowire(env: 'APP_BASE_URL')]
        private readonly string $appBaseUrl,
    ) {}

    public function __invoke(MonitorPositionMessage $message): void
    {
        $this->ordersLogger->info('[MonitorPositionHandler] Processing position monitoring', [
            'position_id' => $message->positionId,
            'symbol' => $message->symbol,
            'order_id' => $message->orderId,
            'stop_loss' => $message->stopLoss,
            'take_profit' => $message->takeProfit,
        ]);

        $positionData = [
            'id' => $message->positionId,
            'symbol' => $message->symbol,
            'order_id' => $message->orderId,
            'stop_loss' => $message->stopLoss,
            'take_profit' => $message->takeProfit,
            'callback_url' => $this->appBaseUrl . '/api/orders/callback',
        ];

        $result = $this->wsWorkerClient->monitorPosition($positionData);

        if (!$result['ok']) {
            $this->ordersLogger->error('[MonitorPositionHandler] Failed to monitor position', [
                'position_id' => $message->positionId,
                'error' => $result['message'],
            ]);
        }
    }
}
