<?php
declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\Common\Enum\OrderStatus;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\Message\LimitFillWatchMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler(fromTransport: 'order_timeout')]
final class LimitFillWatchMessageHandler
{
    private const POLL_DELAY_MS = 5000; // 5s
    private const GRACE_SECONDS = 10;   // marge au-delà du cancel-after

    public function __construct(
        private readonly MainProviderInterface $provider,
        #[Autowire(service: 'monolog.logger.positions')]
        private readonly LoggerInterface $positionsLogger,
        private readonly MessageBusInterface $bus,
    ) {}

    public function __invoke(LimitFillWatchMessage $message): void
    {
        $orderProvider = $this->provider->getOrderProvider();

        try {
            $order = $orderProvider->getOrder($message->exchangeOrderId);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('limit_watch.order_fetch_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'tries' => $message->tries,
                'error' => $e->getMessage(),
            ]);
            $order = null;
        }

        if ($order !== null) {
            $status = $order->status;

            if ($status === OrderStatus::FILLED || $status === OrderStatus::PARTIALLY_FILLED) {
                // Position ouverte: désarmer le dead-man switch pour ce symbole
                if ($orderProvider instanceof \App\Provider\Bitmart\BitmartOrderProvider) {
                    try {
                        $orderProvider->cancelAllAfter($message->symbol, 0);
                        $this->positionsLogger->info('limit_watch.deadman_disarmed', [
                            'symbol' => $message->symbol,
                            'exchange_order_id' => $message->exchangeOrderId,
                            'client_order_id' => $message->clientOrderId,
                            'decision_key' => $message->decisionKey,
                            'tries' => $message->tries,
                        ]);
                    } catch (\Throwable $e) {
                        $this->positionsLogger->warning('limit_watch.deadman_disarm_failed', [
                            'symbol' => $message->symbol,
                            'error' => $e->getMessage(),
                            'decision_key' => $message->decisionKey,
                        ]);
                    }
                }
                return;
            }

            if (
                $status === OrderStatus::CANCELLED ||
                $status === OrderStatus::REJECTED ||
                $status === OrderStatus::EXPIRED
            ) {
                // Ordre clôturé sans fill → ne pas désarmer (inutile)
                $this->positionsLogger->info('limit_watch.closed_no_disarm', [
                    'symbol' => $message->symbol,
                    'order_status' => $status->value,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'client_order_id' => $message->clientOrderId,
                    'decision_key' => $message->decisionKey,
                ]);
                return;
            }
        }

        // Toujours en attente → reprogammer si dans la fenêtre autorisée
        $maxTries = (int) ceil((max(0, $message->cancelAfterSec) + self::GRACE_SECONDS) * 1000 / self::POLL_DELAY_MS);
        if ($message->tries + 1 > $maxTries) {
            $this->positionsLogger->info('limit_watch.window_exceeded', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'tries' => $message->tries,
                'max_tries' => $maxTries,
            ]);
            return;
        }

        // Re-dispatch avec un délai
        $this->bus->dispatch(
            new LimitFillWatchMessage(
                symbol: $message->symbol,
                exchangeOrderId: $message->exchangeOrderId,
                clientOrderId: $message->clientOrderId,
                cancelAfterSec: $message->cancelAfterSec,
                tries: $message->tries + 1,
                decisionKey: $message->decisionKey,
            ),
            [new DelayStamp(self::POLL_DELAY_MS)]
        );

        $this->positionsLogger->debug('limit_watch.rescheduled', [
            'symbol' => $message->symbol,
            'exchange_order_id' => $message->exchangeOrderId,
            'client_order_id' => $message->clientOrderId,
            'decision_key' => $message->decisionKey,
            'tries' => $message->tries + 1,
            'max_tries' => $maxTries,
        ]);
    }
}

