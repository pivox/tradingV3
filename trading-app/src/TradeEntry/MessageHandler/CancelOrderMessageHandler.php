<?php

declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\Common\Enum\OrderStatus;
use App\Contract\Provider\MainProviderInterface;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\TradeEntry\Message\CancelOrderMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'order_timeout')]
final class CancelOrderMessageHandler
{
    public function __construct(
        private readonly MainProviderInterface $provider,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        #[Autowire(service: 'monolog.logger.positions')]
        private readonly LoggerInterface $positionsLogger,
        #[Autowire(service: 'monolog.logger.order_journey')]
        private readonly LoggerInterface $journeyLogger,
        #[Autowire(env: 'ORDER_TIMEOUT_SWITCH_DURATION')]
        private readonly string $switchDuration = '15m',
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
            $this->journeyLogger->warning('order_journey.timeout.order_fetch_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'reason' => 'timeout_handler_get_order_failed',
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
                $this->journeyLogger->info('order_journey.timeout.skip_cancel', [
                    'symbol' => $message->symbol,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'client_order_id' => $message->clientOrderId,
                    'decision_key' => $message->decisionKey,
                    'order_status' => $status,
                    'reason' => 'order_already_filled',
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
                $this->journeyLogger->info('order_journey.timeout.already_closed', [
                    'symbol' => $message->symbol,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'client_order_id' => $message->clientOrderId,
                    'decision_key' => $message->decisionKey,
                    'order_status' => $status,
                    'reason' => 'order_already_closed',
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
            $this->journeyLogger->info('order_journey.timeout.cancel_attempt', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'cancelled' => $cancelled,
                'order_found' => $order !== null,
                'reason' => 'timeout_triggered_cancel',
            ]);

            // Si l'ordre a été annulé avec succès, réactiver le MtfSwitch avec un délai réduit
            if ($cancelled) {
                $this->releaseMtfSwitch($message->symbol);
            }
        } catch (\Throwable $e) {
            $this->positionsLogger->error('trade_entry.timeout.cancel_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'error' => $e->getMessage(),
            ]);
            $this->journeyLogger->error('order_journey.timeout.cancel_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'reason' => 'cancel_request_failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Réactive le MtfSwitch du symbole avec un délai réduit après annulation d'ordre
     */
    private function releaseMtfSwitch(string $symbol): void
    {
        try {
            $this->mtfSwitchRepository->turnOffSymbolForDuration($symbol, $this->switchDuration);
            $this->positionsLogger->info('trade_entry.timeout.switch_released', [
                'symbol' => $symbol,
                'duration' => $this->switchDuration,
                'reason' => 'order_cancelled_reduced_cooldown',
            ]);
            $this->journeyLogger->info('order_journey.timeout.switch_released', [
                'symbol' => $symbol,
                'duration' => $this->switchDuration,
                'reason' => 'order_cancelled_reduced_cooldown',
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('trade_entry.timeout.switch_release_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $this->journeyLogger->error('order_journey.timeout.switch_release_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
