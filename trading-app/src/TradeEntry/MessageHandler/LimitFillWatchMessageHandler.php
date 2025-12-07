<?php
declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\Common\Enum\OrderStatus;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\MainProviderInterface;
use App\Logging\TradeLifecycleLogger;
use App\Logging\TradeLifecycleReason;
use App\TradeEntry\Message\LimitFillWatchMessage;
use Brick\Math\RoundingMode;
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
    private const CONFIRMATION_POLLS = 3; // nombre de polls supplémentaires après cancel() pour confirmer l'état réel

    public function __construct(
        private readonly MainProviderInterface $provider,
        #[Autowire(service: 'monolog.logger.positions')]
        private readonly LoggerInterface $positionsLogger,
        private readonly MessageBusInterface $bus,
        private readonly TradeLifecycleLogger $tradeLifecycleLogger,
    ) {}

    public function __invoke(LimitFillWatchMessage $message): void
    {
        $orderProvider = $this->provider->getOrderProvider();

        try {
            $order = $orderProvider->getOrder($message->symbol, $message->exchangeOrderId);
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
                $this->logPositionOpenedLifecycle($message, $order);
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

                $this->logOrderExpiredLifecycle(
                    $message,
                    $status->value,
                    strtoupper($order->side->value)
                );
                return;
            }
        }

        // Toujours en attente → reprogammer si dans la fenêtre autorisée
        $maxTriesBeforeCancel = (int) ceil((max(0, $message->cancelAfterSec) + self::GRACE_SECONDS) * 1000 / self::POLL_DELAY_MS);
        $maxAllowedTries = $maxTriesBeforeCancel + ($message->cancelIssued ? self::CONFIRMATION_POLLS : 0);

        if ($message->tries + 1 > $maxTriesBeforeCancel && !$message->cancelIssued) {
            // Fenêtre dépassée pour la première fois: tenter un cancel mais attendre la confirmation réelle avant de logguer un lifecycle
            $this->positionsLogger->info('limit_watch.window_exceeded', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'tries' => $message->tries,
                'max_tries_before_cancel' => $maxTriesBeforeCancel,
            ]);

            try {
                $ok = $orderProvider->cancelOrder($message->symbol, $message->exchangeOrderId);
                $this->positionsLogger->info('limit_watch.cancel_issued', [
                    'symbol' => $message->symbol,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'client_order_id' => $message->clientOrderId,
                    'decision_key' => $message->decisionKey,
                    'result' => $ok ? 'success' : 'failed',
                ]);
            } catch (\Throwable $e) {
                $this->positionsLogger->warning('limit_watch.cancel_failed', [
                    'symbol' => $message->symbol,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'client_order_id' => $message->clientOrderId,
                    'decision_key' => $message->decisionKey,
                    'error' => $e->getMessage(),
                ]);
            }

            $maxAllowedTriesAfterCancel = $maxTriesBeforeCancel + self::CONFIRMATION_POLLS;
            $this->rescheduleWatch($message, true, $maxTriesBeforeCancel, $maxAllowedTriesAfterCancel);
            return;
        }

        if ($message->tries + 1 > $maxAllowedTries) {
            // Même après la fenêtre de confirmation post-cancel on n'a pas d'état fiable → arrêter proprement sans faux positif
            $this->positionsLogger->warning('limit_watch.confirmation_timeout', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'tries' => $message->tries,
                'max_tries_before_cancel' => $maxTriesBeforeCancel,
                'max_tries_total' => $maxAllowedTries,
                'cancel_issued' => $message->cancelIssued,
            ]);
            return;
        }

        $this->rescheduleWatch($message, $message->cancelIssued, $maxTriesBeforeCancel, $maxAllowedTries);
    }

    private function logOrderExpiredLifecycle(
        LimitFillWatchMessage $message,
        string $status,
        ?string $detectedSide = null
    ): void {
        try {
            $extra = $this->withLifecycleContext($message, [
                'source' => 'limit_watch',
                'decision_key' => $message->decisionKey,
                'order_status' => $status,
                'tries' => $message->tries,
                'cancel_after_sec' => $message->cancelAfterSec,
                'grace_seconds' => self::GRACE_SECONDS,
            ]);

            $side = $detectedSide ?? $message->side;
            $normalizedSide = $side !== null ? strtoupper($side) : null;

            $this->tradeLifecycleLogger->logOrderExpired(
                symbol: $message->symbol,
                orderId: $message->exchangeOrderId,
                clientOrderId: $message->clientOrderId,
                side: $normalizedSide,
                reasonCode: TradeLifecycleReason::CANCEL_AFTER_TIMEOUT,
                extra: $extra,
            );
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('limit_watch.lifecycle_log_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logPositionOpenedLifecycle(LimitFillWatchMessage $message, OrderDto $order): void
    {
        try {
            $filledQty = $order->filledQuantity->isZero() ? $order->quantity : $order->filledQuantity;
            $avgPrice = $order->averagePrice ?? $order->price;
            $extra = $this->withLifecycleContext($message, [
                'client_order_id' => $message->clientOrderId,
                'exchange_order_id' => $order->orderId,
                'decision_key' => $message->decisionKey,
                'source' => 'limit_watch',
            ]);

            $this->tradeLifecycleLogger->logPositionOpened(
                symbol: $order->symbol,
                positionId: $order->metadata['position_id'] ?? null,
                side: $order->side->value,
                qty: $filledQty->toScale(8, RoundingMode::DOWN)->__toString(),
                entryPrice: $avgPrice?->toScale(8, RoundingMode::DOWN)->__toString(),
                extra: $extra,
            );
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('limit_watch.lifecycle_position_log_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function withLifecycleContext(LimitFillWatchMessage $message, array $extra): array
    {
        if ($message->lifecycleContext === null) {
            return $extra;
        }

        return array_merge($message->lifecycleContext, $extra);
    }

    private function rescheduleWatch(
        LimitFillWatchMessage $message,
        bool $cancelIssued,
        int $maxTriesBeforeCancel,
        int $maxAllowedTries
    ): void {
        $this->bus->dispatch(
            new LimitFillWatchMessage(
                symbol: $message->symbol,
                exchangeOrderId: $message->exchangeOrderId,
                clientOrderId: $message->clientOrderId,
                side: $message->side,
                cancelAfterSec: $message->cancelAfterSec,
                tries: $message->tries + 1,
                decisionKey: $message->decisionKey,
                lifecycleContext: $message->lifecycleContext,
                cancelIssued: $cancelIssued,
            ),
            [new DelayStamp(self::POLL_DELAY_MS)]
        );

        $this->positionsLogger->debug('limit_watch.rescheduled', [
            'symbol' => $message->symbol,
            'exchange_order_id' => $message->exchangeOrderId,
            'client_order_id' => $message->clientOrderId,
            'decision_key' => $message->decisionKey,
            'tries' => $message->tries + 1,
            'max_tries_before_cancel' => $maxTriesBeforeCancel,
            'max_tries_total' => $maxAllowedTries,
            'cancel_issued' => $cancelIssued,
        ]);
    }
}
