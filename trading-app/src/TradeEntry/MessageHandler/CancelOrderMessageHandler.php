<?php

declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\Common\Enum\OrderStatus;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\Message\CancelOrderMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler(fromTransport: 'order_timeout')]
final class CancelOrderMessageHandler
{
    public function __construct(
        private readonly MainProviderInterface $provider,
        #[Autowire(service: 'monolog.logger.positions')]
        private readonly LoggerInterface $positionsLogger,
    ) {}

    public function __invoke(CancelOrderMessage $message): void
    {
        $orderProvider = $this->provider->getOrderProvider();

        try {
            $order = $orderProvider->getOrder($message->exchangeOrderId);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('trade_entry.timeout.order_fetch_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'error' => $e->getMessage(),
            ]);
            $order = null;
        }

        if ($order !== null) {
            $status = $order->status->value;

            if (in_array($status, [OrderStatus::FILLED->value, OrderStatus::PARTIALLY_FILLED->value], true)) {
                $this->positionsLogger->info('trade_entry.timeout.skip_cancel', [
                    'symbol' => $message->symbol,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'client_order_id' => $message->clientOrderId,
                    'decision_key' => $message->decisionKey,
                    'order_status' => $status,
                ]);
                return;
            }

            if (in_array($status, [OrderStatus::CANCELLED->value, OrderStatus::EXPIRED->value, OrderStatus::REJECTED->value], true)) {
                $this->positionsLogger->info('trade_entry.timeout.already_closed', [
                    'symbol' => $message->symbol,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'client_order_id' => $message->clientOrderId,
                    'decision_key' => $message->decisionKey,
                    'order_status' => $status,
                ]);
                return;
            }
        }

        try {
            $cancelled = $orderProvider->cancelOrder($message->exchangeOrderId);
            $this->positionsLogger->info('trade_entry.timeout.cancel_attempt', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'cancelled' => $cancelled,
                'order_found' => $order !== null,
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('trade_entry.timeout.cancel_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
