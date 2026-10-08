<?php
declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Registry\ExchangeAdapterNotFoundException;
use App\TradeEntry\Execution\RestingEntryState;
use App\TradeEntry\Execution\RestingEntryWatcher;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Service\TakeProfitPlacerInterface;
use App\TradeEntry\Types\Side;
use App\Logging\TradeLifecycleLogger;
use App\Logging\TradeLifecycleReason;
use App\Provider\Context\ExchangeContext;
use App\Provider\Context\UnsupportedExchangeException;
use App\TradeEntry\Message\LimitFillWatchMessage;
use App\Trading\Lineage\TradeLineageManager;
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
        private readonly ExchangeAdapterRegistryInterface $adapters,
        private readonly RestingEntryWatcher $watcher,
        #[Autowire(service: 'monolog.logger.positions')]
        private readonly LoggerInterface $positionsLogger,
        private readonly MessageBusInterface $bus,
        private readonly TradeLifecycleLogger $tradeLifecycleLogger,
        private readonly ?TradeLineageManager $tradeLineageManager = null,
        private readonly ?TakeProfitPlacerInterface $takeProfits = null,
    ) {}

    public function __invoke(LimitFillWatchMessage $message): void
    {
        try {
            $context = $message->lifecycleContext !== null
                ? ExchangeContext::fromArray($message->lifecycleContext)
                : null;
        } catch (UnsupportedExchangeException $e) {
            $this->positionsLogger->warning('limit_watch.unsupported_exchange_dropped', [
                'exchange' => $e->rawValue,
                'symbol' => $message->symbol,
                'order_id' => $message->exchangeOrderId,
            ]);

            return;
        }
        $context = ExchangeContext::resolve($context);
        try {
            $adapter = $this->adapters->get($context->exchange, $context->marketType);
        } catch (ExchangeAdapterNotFoundException $e) {
            $this->positionsLogger->warning('limit_watch.adapter_missing_dropped', [
                'symbol' => $message->symbol,
                'order_id' => $message->exchangeOrderId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $state = $this->watcher->inspect(
                $adapter,
                $message->symbol,
                $message->exchangeOrderId,
                $message->clientOrderId,
                $this->positionSide($message),
                $message->positionBaseline,
                isset($message->plan['size']) ? (float) $message->plan['size'] : null,
            );
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('limit_watch.order_fetch_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'tries' => $message->tries,
                'error' => $e->getMessage(),
            ]);
            $state = null;
        }

        if ($state?->status === RestingEntryState::FILLED) {
            $this->onFilled($message, $adapter, $state);

            return;
        }

        if ($state?->status === RestingEntryState::CLOSED) {
            $this->positionsLogger->info('limit_watch.closed_no_disarm', [
                'symbol' => $message->symbol,
                'order_status' => $state->order?->status->value ?? 'closed',
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
            ]);
            $this->logOrderExpiredLifecycle(
                $message,
                $state->order?->status->value ?? 'closed',
                $message->side,
            );

            return;
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
                $ok = $this->watcher->cancel($adapter, $message->symbol, $message->exchangeOrderId, $message->clientOrderId, $message->decisionKey)->cancelled;
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

    private function onFilled(LimitFillWatchMessage $message, \App\Exchange\Contract\ExchangeAdapterInterface $adapter, RestingEntryState $state): void
    {
        if ($state->remainderActive) {
            try {
                $this->watcher->cancel($adapter, $message->symbol, $message->exchangeOrderId, $message->clientOrderId, $message->decisionKey);
            } catch (\Throwable $e) {
                $this->positionsLogger->critical('limit_watch.remainder_cancel_failed', [
                    'symbol' => $message->symbol,
                    'exchange_order_id' => $message->exchangeOrderId,
                    'decision_key' => $message->decisionKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($message->plan === null) {
            $this->positionsLogger->critical('limit_watch.protection_plan_missing', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
            ]);
        } else {
            $this->protect($message, $adapter, $state);
        }

        $this->logPositionOpenedLifecycle($message, $this->filledOrder($message, $state));
    }

    private function protect(LimitFillWatchMessage $message, \App\Exchange\Contract\ExchangeAdapterInterface $adapter, RestingEntryState $state): void
    {
        try {
            $context = ExchangeContext::resolve($message->lifecycleContext !== null ? ExchangeContext::fromArray($message->lifecycleContext) : null);
            $protection = $this->watcher->protectFilledEntry(
                $adapter,
                OrderPlanModel::fromWatchSnapshot($message->plan ?? [], $context),
                $state,
                $message->exchangeOrderId,
                $message->clientOrderId,
                $message->decisionKey,
            );
            $this->placeTakeProfits($message, $state, $context, $protection->protected);
            $this->positionsLogger->info('limit_watch.protection_enforced', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'status' => $protection->status,
                'protected' => $protection->protected,
                'protection_order_id' => $protection->protectionOrderId,
                'emergency_order_id' => $protection->emergencyOrderId,
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->critical('limit_watch.protection_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function placeTakeProfits(LimitFillWatchMessage $message, RestingEntryState $state, ExchangeContext $context, bool $protected): void
    {
        if (!$protected || !$this->takeProfits instanceof TakeProfitPlacerInterface || $message->plan === null) {
            return;
        }

        try {
            $result = ($this->takeProfits)(
                new TpSlTwoTargetsRequest(
                    symbol: $message->symbol,
                    side: Side::from((string) $message->plan['side']),
                    entryPrice: $state->averagePrice,
                    size: (int) floor($state->filledQuantity),
                    cancelExistingStopLossIfDifferent: false,
                    cancelExistingTakeProfits: false,
                    slFullSize: false,
                    dryRun: false,
                    exchangeContext: $context,
                ),
                $message->decisionKey,
                $message->mode,
            );
            $this->positionsLogger->info('limit_watch.take_profit_placed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'decision_key' => $message->decisionKey,
                'submitted' => \count($result['submitted'] ?? []),
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->critical('limit_watch.take_profit_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
                'stop_loss_kept' => true,
            ]);
        }
    }

    private function filledOrder(LimitFillWatchMessage $message, RestingEntryState $state): ExchangeOrderDto
    {
        if ($state->order !== null) {
            return $state->order;
        }

        $context = ExchangeContext::resolve($message->lifecycleContext !== null ? ExchangeContext::fromArray($message->lifecycleContext) : null);
        $buy = strtoupper((string) $message->side) !== 'SELL';

        return new ExchangeOrderDto(
            exchange: $context->exchange,
            marketType: $context->marketType,
            symbol: $message->symbol,
            exchangeOrderId: $message->exchangeOrderId,
            clientOrderId: $message->clientOrderId,
            side: $buy ? \App\Exchange\Enum\ExchangeOrderSide::BUY : \App\Exchange\Enum\ExchangeOrderSide::SELL,
            positionSide: null,
            orderType: \App\Exchange\Enum\ExchangeOrderType::LIMIT,
            status: \App\Exchange\Enum\ExchangeOrderStatus::FILLED,
            quantity: $state->filledQuantity,
            filledQuantity: $state->filledQuantity,
            remainingQuantity: 0.0,
            price: $state->averagePrice,
            averagePrice: $state->averagePrice,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            timeInForce: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function positionSide(LimitFillWatchMessage $message): ?ExchangePositionSide
    {
        return match (strtoupper((string) $message->side)) {
            'BUY' => ExchangePositionSide::LONG,
            'SELL' => ExchangePositionSide::SHORT,
            default => null,
        };
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

    private function logPositionOpenedLifecycle(LimitFillWatchMessage $message, ExchangeOrderDto $order): void
    {
        try {
            $filledQty = $order->filledQuantity > 0.0 ? $order->filledQuantity : $order->quantity;
            $avgPrice = $order->averagePrice ?? $order->price;
            $extra = $this->withLifecycleContext($message, [
                'client_order_id' => $message->clientOrderId,
                'exchange_order_id' => $order->exchangeOrderId,
                'decision_key' => $message->decisionKey,
                'source' => 'limit_watch',
            ]);
            $context = $this->contextFromLifecycle($extra);
            $extra = $this->withBestEffortLineage($context, $message, $order, $extra);

            $this->tradeLifecycleLogger->logPositionOpened(
                symbol: $order->symbol,
                positionId: $order->metadata['position_id'] ?? null,
                side: $order->side->value,
                qty: $this->decimal($filledQty),
                entryPrice: $avgPrice !== null ? $this->decimal($avgPrice) : null,
                runId: $this->stringValue($extra['run_id'] ?? null),
                exchange: $context->exchange->value,
                accountId: null,
                extra: $extra,
                marketType: $context->marketType->value,
            );
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('limit_watch.lifecycle_position_log_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function decimal(float $value): string
    {
        return sprintf('%.8F', floor($value * 100000000) / 100000000);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function withBestEffortLineage(
        ExchangeContext $context,
        LimitFillWatchMessage $message,
        ExchangeOrderDto $order,
        array $extra,
    ): array {
        if ($this->tradeLineageManager === null) {
            return $extra;
        }

        try {
            $lineage = $this->tradeLineageManager->resolve(
                $context,
                internalTradeId: $this->stringValue($extra['internal_trade_id'] ?? null),
                clientOrderId: $message->clientOrderId,
                exchangeOrderId: $order->exchangeOrderId,
            );
            if ($lineage === null) {
                return $extra;
            }

            $positionId = $this->stringValue($order->metadata['position_id'] ?? null);
            $this->tradeLineageManager->attachPositionId($lineage, $positionId);

            return array_merge($this->tradeLineageManager->lifecycleExtra($lineage), $extra);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('limit_watch.lineage_sync_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $order->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'error' => $e->getMessage(),
            ]);

            return $extra;
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

    /**
     * @param array<string,mixed> $extra
     */
    private function contextFromLifecycle(array $extra): ExchangeContext
    {
        return ExchangeContext::fromValues(
            $this->stringValue($extra['exchange'] ?? null),
            $this->stringValue($extra['market_type'] ?? null),
        );
    }

    private function stringValue(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
                mode: $message->mode ?? null,
                plan: $message->plan,
                positionBaseline: $message->positionBaseline,
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
