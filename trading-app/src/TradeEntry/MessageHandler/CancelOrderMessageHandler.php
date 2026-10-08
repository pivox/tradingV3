<?php

declare(strict_types=1);

namespace App\TradeEntry\MessageHandler;

use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Execution\RestingEntryState;
use App\TradeEntry\Execution\RestingEntryWatcher;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\TradeEntry\Message\CancelOrderMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'order_timeout')]
final class CancelOrderMessageHandler
{
    public function __construct(
        private readonly ExchangeAdapterRegistryInterface $adapters,
        private readonly RestingEntryWatcher $watcher,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        #[Autowire(service: 'monolog.logger.positions')]
        private readonly LoggerInterface $positionsLogger,
        #[Autowire(env: 'ORDER_TIMEOUT_SWITCH_DURATION')]
        private readonly string $switchDuration = '15m',
    ) {}

    public function __invoke(CancelOrderMessage $message): void
    {
        $context = ExchangeContext::resolve(null);
        $adapter = $this->adapters->get($context->exchange, $context->marketType);

        try {
            $state = $this->watcher->inspect($adapter, $message->symbol, $message->exchangeOrderId, $message->clientOrderId);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('trade_entry.timeout.order_fetch_failed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'error' => $e->getMessage(),
            ]);
            $state = null;
        }

        if ($state?->status === RestingEntryState::FILLED) {
            $this->positionsLogger->info('trade_entry.timeout.skip_cancel', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'order_status' => $state->order?->status->value ?? 'filled',
            ]);

            return;
        }

        if ($state?->status === RestingEntryState::CLOSED) {
            $this->positionsLogger->info('trade_entry.timeout.already_closed', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'order_status' => $state->order?->status->value ?? 'closed',
            ]);

            return;
        }

        try {
            $cancelled = $this->watcher->cancel($adapter, $message->symbol, $message->exchangeOrderId, $message->clientOrderId, $message->decisionKey)->cancelled;
            $this->positionsLogger->info('trade_entry.timeout.cancel_attempt', [
                'symbol' => $message->symbol,
                'exchange_order_id' => $message->exchangeOrderId,
                'client_order_id' => $message->clientOrderId,
                'decision_key' => $message->decisionKey,
                'cancelled' => $cancelled,
                'order_found' => $state !== null,
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
        } catch (\Throwable $e) {
            $this->positionsLogger->error('trade_entry.timeout.switch_release_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
