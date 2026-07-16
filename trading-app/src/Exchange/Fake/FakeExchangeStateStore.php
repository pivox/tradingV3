<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Autoconfigure(lazy: true)]
class FakeExchangeStateStore
{
    private const STATE_FORMAT_VERSION = 1;
    private const ENGINE_VERSION = 'fake-paper-state-v1';
    private const SCENARIO_CONFIG_ID = 'fake-paper-default-v1';

    private ?string $stateFile;

    private int $nextOrderSequence = 1;

    private int $nextEventSequence = 1;

    private bool $restored = false;

    private bool $restoredLegacyState = false;

    /** @var array<string, ExchangeOrderDto> */
    private array $orders = [];

    /** @var array<string, string> */
    private array $clientOrderIndex = [];

    /** @var array<string, ExchangePositionDto> */
    private array $positions = [];

    /** @var array<string, ExchangeBalanceDto> */
    private array $balances = [];

    /** @var array<string, array{bid: float, ask: float}> */
    private array $orderBooks = [];

    /** @var array<string, array{leverage:int,margin_mode:string}> */
    private array $leverageSettings = [];

    /** @var FakeExchangeEvent[] */
    private array $events = [];

    private bool $rejectNextProtectionOrder = false;

    /** @var list<array{operation:string,kind:string,outcome:string,retry_after_seconds:?int}> */
    private array $pendingFaults = [];

    private bool $deferPersistence = false;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/fake_exchange_state.dat')]
        ?string $stateFile = null,
    ) {
        $this->stateFile = $stateFile;
        if (!$this->restore()) {
            $this->initializeDefaults();
        }
    }

    public function reset(): void
    {
        $this->initializeDefaults();
        $this->persist();
    }

    private function initializeDefaults(): void
    {
        $this->nextOrderSequence = 1;
        $this->nextEventSequence = 1;
        $this->restored = false;
        $this->restoredLegacyState = false;
        $this->orders = [];
        $this->clientOrderIndex = [];
        $this->positions = [];
        $this->orderBooks = [];
        $this->leverageSettings = [];
        $this->events = [];
        $this->rejectNextProtectionOrder = false;
        $this->pendingFaults = [];
        $this->balances = [
            'USDT' => new ExchangeBalanceDto(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                currency: 'USDT',
                available: 100000.0,
                total: 100000.0,
                equity: 100000.0,
                unrealizedPnl: 0.0,
                metadata: ['source' => 'fake_exchange'],
            ),
        ];
    }

    /**
     * @return array{configured:bool,writable:bool,recovery_ready:bool}
     */
    public function persistenceHealth(): array
    {
        return self::persistenceHealthForPath($this->stateFile);
    }

    /**
     * @return array{configured:bool,writable:bool,recovery_ready:bool}
     */
    public static function persistenceHealthForPath(?string $stateFile): array
    {
        if ($stateFile === null) {
            return ['configured' => false, 'writable' => false, 'recovery_ready' => false];
        }

        $directory = \dirname($stateFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            return ['configured' => true, 'writable' => false, 'recovery_ready' => false];
        }

        $probeFile = tempnam($directory, '.fake-runtime-check-');
        if ($probeFile === false) {
            return ['configured' => true, 'writable' => false, 'recovery_ready' => false];
        }
        @unlink($probeFile);

        try {
            $probe = new self($probeFile);
            $probe->setOrderBookTop('BTCUSDT', 24999.0, 25001.0);
            $writable = is_file($probeFile) && is_readable($probeFile) && is_writable($probeFile);
            if (!$writable) {
                @unlink($probeFile);

                return ['configured' => true, 'writable' => false, 'recovery_ready' => false];
            }
        } catch (\Throwable) {
            @unlink($probeFile);

            return ['configured' => true, 'writable' => false, 'recovery_ready' => false];
        }

        try {
            $restored = new self($probeFile);
            $metadata = $restored->recoveryMetadata();

            return [
                'configured' => true,
                'writable' => true,
                'recovery_ready' => $metadata['restored']
                    && $metadata['format_version'] === self::STATE_FORMAT_VERSION
                    && $metadata['engine_version'] === self::ENGINE_VERSION,
            ];
        } catch (\Throwable) {
            return ['configured' => true, 'writable' => true, 'recovery_ready' => false];
        } finally {
            @unlink($probeFile);
        }
    }

    public function nextOrderId(): string
    {
        return sprintf('fake-%06d', $this->nextOrderSequence++);
    }

    public function saveOrder(ExchangeOrderDto $order): void
    {
        $this->orders[$order->exchangeOrderId] = $order;
        if ($order->clientOrderId !== null && trim($order->clientOrderId) !== '') {
            $this->clientOrderIndex[$this->clientOrderKey($order->symbol, $order->clientOrderId)] = $order->exchangeOrderId;
        }
        $this->persist();
    }

    public function getOrder(string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->orders[$exchangeOrderId] ?? null;
    }

    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrderDto
    {
        $orderId = $this->clientOrderIndex[$this->clientOrderKey($symbol, $clientOrderId)] ?? null;

        return $orderId !== null ? $this->getOrder($orderId) : null;
    }

    public function findActiveOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrderDto
    {
        $order = $this->getOrderByClientOrderId($symbol, $clientOrderId);
        if (!$order instanceof ExchangeOrderDto) {
            return null;
        }

        return $this->isActiveStatus($order->status) ? $order : null;
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->orders,
            fn (ExchangeOrderDto $order): bool => $this->isActiveStatus($order->status)
                && ($symbol === null || $order->symbol === $symbol),
        ));
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOrders(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->orders,
            fn (ExchangeOrderDto $order): bool => $symbol === null || $order->symbol === $symbol,
        ));
    }

    public function savePosition(ExchangePositionDto $position): void
    {
        $this->positions[$this->positionKey($position->symbol, $position->side)] = $position;
        $this->persist();
    }

    public function removePosition(string $symbol, ExchangePositionSide $side): void
    {
        unset($this->positions[$this->positionKey($symbol, $side)]);
        $this->persist();
    }

    public function getPosition(string $symbol, ExchangePositionSide $side): ?ExchangePositionDto
    {
        return $this->positions[$this->positionKey($symbol, $side)] ?? null;
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        $symbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->positions,
            fn (ExchangePositionDto $position): bool => $position->size > 0.0
                && ($symbol === null || $position->symbol === $symbol),
        ));
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return array_values($this->balances);
    }

    public function totalBalanceUsdt(): float
    {
        $balance = $this->balances['USDT'] ?? null;
        if (!$balance instanceof ExchangeBalanceDto) {
            throw new \LogicException('fake_usdt_balance_unavailable');
        }

        $total = $balance->total ?? $balance->equity ?? $balance->available;
        if (!\is_finite($total)) {
            throw new \LogicException('fake_usdt_balance_total_invalid');
        }

        return $total;
    }

    public function usedMarginUsdt(): float
    {
        $usedMargin = 0.0;
        foreach ($this->getOpenPositions() as $position) {
            if ($position->margin === null || !\is_finite($position->margin) || $position->margin < 0.0) {
                throw new \LogicException('fake_position_margin_unavailable');
            }

            $usedMargin += $position->margin;
        }

        foreach ($this->getOpenOrders() as $order) {
            if ($order->reduceOnly) {
                continue;
            }

            $price = $this->marginReferencePrice($order);
            $leverage = $order->metadata['leverage'] ?? 1;
            if (!\is_numeric($leverage) || !\is_finite((float) $leverage) || (float) $leverage <= 0.0) {
                throw new \LogicException('fake_order_margin_leverage_unavailable');
            }
            if (!\is_finite($order->remainingQuantity) || $order->remainingQuantity < 0.0) {
                throw new \LogicException('fake_order_remaining_quantity_invalid');
            }

            // Version-1 persisted orders predate contract-size metadata and use the original unit contract.
            $contractSize = $order->metadata['margin_contract_size'] ?? 1;
            if (!\is_numeric($contractSize) || !\is_finite((float) $contractSize) || (float) $contractSize <= 0.0) {
                throw new \LogicException('fake_order_margin_contract_size_unavailable');
            }

            $usedMargin += ($order->remainingQuantity * $price * (float) $contractSize) / (float) $leverage;
        }

        if (!\is_finite($usedMargin) || $usedMargin < 0.0) {
            throw new \LogicException('fake_used_margin_invalid');
        }

        return $usedMargin;
    }

    public function marginCollateralUsdt(): float
    {
        $balance = $this->balances['USDT'] ?? null;
        if (!$balance instanceof ExchangeBalanceDto) {
            throw new \LogicException('fake_usdt_balance_unavailable');
        }

        $total = $balance->total ?? $balance->equity ?? $balance->available;
        $equity = $balance->equity;
        if (
            !\is_finite($total)
            || $total < 0.0
            || ($equity !== null && (!\is_finite($equity) || $equity < 0.0))
        ) {
            throw new \LogicException('fake_usdt_margin_collateral_invalid');
        }

        return $equity !== null ? min($total, $equity) : $total;
    }

    public function availableMarginUsdt(): float
    {
        return max($this->marginCollateralUsdt() - $this->usedMarginUsdt(), 0.0);
    }

    /**
     * @return array{bid: float, ask: float}
     */
    public function getOrderBookTop(string $symbol): array
    {
        $symbol = strtoupper($symbol);

        return $this->orderBooks[$symbol] ?? match ($symbol) {
            'ETHUSDT' => ['bid' => 1799.5, 'ask' => 1800.5],
            default => ['bid' => 24999.5, 'ask' => 25000.5],
        };
    }

    public function hasOrderBookTop(string $symbol): bool
    {
        return isset($this->orderBooks[strtoupper($symbol)]);
    }

    public function setOrderBookTop(string $symbol, float $bid, float $ask): void
    {
        if ($bid <= 0.0 || $ask <= 0.0 || $bid >= $ask) {
            throw new \InvalidArgumentException('fake order book requires positive bid < ask');
        }

        $this->orderBooks[strtoupper($symbol)] = ['bid' => $bid, 'ask' => $ask];
        $this->persist();
    }

    public function setLeverageSetting(string $symbol, int $leverage, string $marginMode): void
    {
        $setting = ['leverage' => $leverage, 'margin_mode' => $marginMode];
        if (!$this->isLeverageSettingsMap([$symbol => $setting])) {
            throw new \InvalidArgumentException('fake_leverage_setting_invalid');
        }

        $this->leverageSettings[$symbol] = $setting;
        ksort($this->leverageSettings);
        $this->persist();
    }

    /** @return array{leverage:int,margin_mode:string}|null */
    public function getLeverageSetting(string $symbol): ?array
    {
        return $this->leverageSettings[$symbol] ?? null;
    }

    /** @return array<string, array{leverage:int,margin_mode:string}> */
    public function leverageSettings(): array
    {
        return $this->leverageSettings;
    }

    public function appendEvent(FakeExchangeEvent $event): void
    {
        $payload = $event->payload;
        if (!\array_key_exists('event_sequence', $event->payload)) {
            $payload = ['event_sequence' => $this->nextEventSequence++] + $payload;
        } else {
            $explicitSequence = $event->payload['event_sequence'];
            if (\is_int($explicitSequence) && $explicitSequence > 0) {
                $this->nextEventSequence = max($this->nextEventSequence, $explicitSequence + 1);
            } elseif (\is_string($explicitSequence) && ctype_digit($explicitSequence)) {
                $this->nextEventSequence = max($this->nextEventSequence, (int) $explicitSequence + 1);
        }
        }

        $orderId = $payload['order_id'] ?? null;
        if (!\array_key_exists('order_snapshot', $payload) && \is_scalar($orderId)) {
            $order = $this->getOrder((string)$orderId);
            if ($order instanceof ExchangeOrderDto) {
                $payload['order_snapshot'] = $this->orderSnapshot($order);
            }
        }
        $orderSnapshot = $payload['order_snapshot'] ?? null;
        if (
            !\array_key_exists('position_snapshot', $payload)
            && \is_array($orderSnapshot)
            && \in_array($event->type, ['position.opened', 'position.updated'], true)
            && \is_string($orderSnapshot['position_side'] ?? null)
        ) {
            try {
                $position = $this->getPosition($event->symbol, ExchangePositionSide::from($orderSnapshot['position_side']));
            } catch (\ValueError) {
                $position = null;
            }
            if ($position instanceof ExchangePositionDto) {
                $payload['position_snapshot'] = $this->positionSnapshot($position);
            }
        }

        $event = new FakeExchangeEvent($event->type, $event->symbol, $event->occurredAt, $payload);
        $this->events[] = $event;
        $this->persist();
    }

    /**
     * @return FakeExchangeEvent[]
     */
    public function events(?string $type = null): array
    {
        if ($type === null) {
            return $this->events;
        }

        return array_values(array_filter(
            $this->events,
            fn (FakeExchangeEvent $event): bool => $event->type === $type,
        ));
    }

    public function rejectNextProtectionOrder(): void
    {
        $this->rejectNextProtectionOrder = true;
        $this->persist();
    }

    public function consumeProtectionRejectionFlag(): bool
    {
        if (!$this->rejectNextProtectionOrder) {
            return false;
        }

        $this->rejectNextProtectionOrder = false;
        $this->persist();

        return true;
    }

    public function queueFault(FakeExchangeFault $fault): void
    {
        $this->pendingFaults[] = $fault->toArray();
        $this->persist();
    }

    /**
     * @return FakeExchangeFault[]
     */
    public function pendingFaults(): array
    {
        return array_map(
            static fn (array $fault): FakeExchangeFault => FakeExchangeFault::fromArray($fault),
            $this->pendingFaults,
        );
    }

    public function consumeFault(
        FakeExchangeOperation $operation,
        FakeExchangeFaultOutcome $outcome,
    ): ?FakeExchangeFault {
        return $this->transactional(
            fn (): ?FakeExchangeFault => $this->consumeFaultFromCurrentState($operation, $outcome),
        );
    }

    private function consumeFaultFromCurrentState(
        FakeExchangeOperation $operation,
        FakeExchangeFaultOutcome $outcome,
    ): ?FakeExchangeFault {
        $index = $this->firstFaultIndex($operation);
        if ($index === null) {
            return null;
        }

        $fault = FakeExchangeFault::fromArray($this->pendingFaults[$index]);
        if ($fault->outcome !== $outcome) {
            return null;
        }

        $this->removeFaultAt($index);

        return $fault;
    }

    /**
     * @template TResult
     * @param callable(): TResult $operationCallback
     * @return array{result:TResult,fault:?FakeExchangeFault}
     */
    public function runWithAppliedResponseLoss(
        FakeExchangeOperation $operation,
        callable $operationCallback,
    ): array {
        return $this->transactional(function () use ($operation, $operationCallback): array {
            $index = $this->firstFaultIndex($operation);
            if ($index === null) {
                return ['result' => $operationCallback(), 'fault' => null];
            }

            $fault = FakeExchangeFault::fromArray($this->pendingFaults[$index]);
            if ($fault->outcome !== FakeExchangeFaultOutcome::AppliedResponseLost) {
                return ['result' => $operationCallback(), 'fault' => null];
            }

            $eventsBefore = \count($this->events);
            $this->removeFaultAt($index);
            $this->persist();
            $result = $operationCallback();

            if (\count($this->events) === $eventsBefore) {
                array_splice($this->pendingFaults, $index, 0, [$fault->toArray()]);
                $this->persist();

                return ['result' => $result, 'fault' => null];
            }

            return ['result' => $result, 'fault' => $fault];
        });
    }

    private function firstFaultIndex(FakeExchangeOperation $operation): ?int
    {
        foreach ($this->pendingFaults as $index => $payload) {
            if (FakeExchangeFault::fromArray($payload)->operation === $operation) {
                return $index;
            }
        }

        return null;
    }

    private function removeFaultAt(int $index): void
    {
        unset($this->pendingFaults[$index]);
        $this->pendingFaults = array_values($this->pendingFaults);
    }

    public function openOrderCount(?string $symbol = null): int
    {
        return \count($this->getOpenOrders($symbol));
    }

    public function openPositionCount(?string $symbol = null): int
    {
        return \count($this->getOpenPositions($symbol));
    }

    /**
     * @return array{format_version:int,engine_version:string,scenario_config_hash:string,restored:bool,legacy:bool,next_event_sequence:int,pending_fault_count:int}
     */
    public function recoveryMetadata(): array
    {
        return [
            'format_version' => self::STATE_FORMAT_VERSION,
            'engine_version' => self::ENGINE_VERSION,
            'scenario_config_hash' => self::scenarioConfigHash(),
            'restored' => $this->restored,
            'legacy' => $this->restoredLegacyState,
            'next_event_sequence' => $this->nextEventSequence,
            'pending_fault_count' => \count($this->pendingFaults),
        ];
    }

    private function clientOrderKey(string $symbol, string $clientOrderId): string
    {
        return strtoupper($symbol) . '::' . $clientOrderId;
    }

    private function positionKey(string $symbol, ExchangePositionSide $side): string
    {
        return strtoupper($symbol) . '::' . $side->value;
    }

    private function marginReferencePrice(ExchangeOrderDto $order): float
    {
        $price = $order->price ?? $order->averagePrice;
        if (
            $price === null
            && ($order->metadata['margin_reference_source'] ?? null) === 'top_of_book'
            && \is_numeric($order->metadata['margin_reference_price'] ?? null)
        ) {
            $price = (float) $order->metadata['margin_reference_price'];
        }

        if ($price === null || !\is_finite($price) || $price <= 0.0) {
            throw new \LogicException('fake_order_margin_reference_price_unavailable');
        }

        return $price;
    }

    private function isActiveStatus(ExchangeOrderStatus $status): bool
    {
        return \in_array($status, [
            ExchangeOrderStatus::PENDING,
            ExchangeOrderStatus::OPEN,
            ExchangeOrderStatus::PARTIALLY_FILLED,
        ], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function orderSnapshot(ExchangeOrderDto $order): array
    {
        return [
            'exchange' => $order->exchange->value,
            'market_type' => $order->marketType->value,
            'symbol' => $order->symbol,
            'exchange_order_id' => $order->exchangeOrderId,
            'client_order_id' => $order->clientOrderId,
            'side' => $order->side->value,
            'position_side' => $order->positionSide?->value,
            'order_type' => $order->orderType->value,
            'status' => $order->status->value,
            'quantity' => $order->quantity,
            'filled_quantity' => $order->filledQuantity,
            'remaining_quantity' => $order->remainingQuantity,
            'price' => $order->price,
            'average_price' => $order->averagePrice,
            'stop_price' => $order->stopPrice,
            'reduce_only' => $order->reduceOnly,
            'post_only' => $order->postOnly,
            'time_in_force' => $order->timeInForce?->value,
            'created_at' => $order->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $order->updatedAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $order->metadata,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function positionSnapshot(ExchangePositionDto $position): array
    {
        return [
            'exchange' => $position->exchange->value,
            'market_type' => $position->marketType->value,
            'symbol' => $position->symbol,
            'side' => $position->side->value,
            'size' => $position->size,
            'entry_price' => $position->entryPrice,
            'mark_price' => $position->markPrice,
            'unrealized_pnl' => $position->unrealizedPnl,
            'realized_pnl' => $position->realizedPnl,
            'margin' => $position->margin,
            'leverage' => $position->leverage,
            'opened_at' => $position->openedAt?->format(\DateTimeInterface::ATOM),
            'updated_at' => $position->updatedAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $position->metadata,
        ];
    }

    private function restore(): bool
    {
        if ($this->stateFile === null || !is_file($this->stateFile)) {
            return false;
        }

        $raw = file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_unreadable');
        }

        try {
            $state = @unserialize($raw, ['allowed_classes' => self::allowedSerializedClasses()]);
        } catch (\Throwable $exception) {
            throw new FakeExchangeStateCorruptedException(
                'fake_exchange_state_deserialization_failed',
                previous: $exception,
            );
        }
        if (!\is_array($state)) {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_invalid_payload');
        }

        $legacy = !\array_key_exists('format_version', $state);
        if (!$legacy) {
            $state = $this->validatedEnvelopePayload($state);
        }

        $this->hydrate($state);
        $this->restored = true;
        $this->restoredLegacyState = $legacy;

        return true;
    }

    private function persist(): void
    {
        if ($this->deferPersistence) {
            return;
        }

        if ($this->stateFile === null) {
            return;
        }

        $directory = \dirname($this->stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('fake_exchange_state_directory_unavailable');
        }

        $payload = [
            'nextOrderSequence' => $this->nextOrderSequence,
            'nextEventSequence' => $this->nextEventSequence,
            'orders' => $this->orders,
            'clientOrderIndex' => $this->clientOrderIndex,
            'positions' => $this->positions,
            'balances' => $this->balances,
            'orderBooks' => $this->orderBooks,
            'leverageSettings' => $this->leverageSettings,
            'events' => $this->events,
            'rejectNextProtectionOrder' => $this->rejectNextProtectionOrder,
            'pendingFaults' => $this->pendingFaults,
        ];
        $serialized = serialize([
            'format_version' => self::STATE_FORMAT_VERSION,
            'engine_version' => self::ENGINE_VERSION,
            'scenario_config_hash' => self::scenarioConfigHash(),
            'payload_checksum' => hash('sha256', serialize($payload)),
            'payload' => $payload,
        ]);
        $temporaryFile = tempnam($directory, basename($this->stateFile) . '.tmp.');
        if ($temporaryFile === false) {
            throw new \RuntimeException('fake_exchange_state_temporary_file_unavailable');
        }

        try {
            $written = file_put_contents($temporaryFile, $serialized, \LOCK_EX);
            if ($written !== strlen($serialized)) {
                throw new \RuntimeException('fake_exchange_state_write_failed');
            }
            if (!rename($temporaryFile, $this->stateFile)) {
                throw new \RuntimeException('fake_exchange_state_replace_failed');
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    /**
     * @param array<string,mixed> $envelope
     * @return array<string,mixed>
     */
    private function validatedEnvelopePayload(array $envelope): array
    {
        if (($envelope['format_version'] ?? null) !== self::STATE_FORMAT_VERSION) {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_format_unsupported');
        }
        if (($envelope['engine_version'] ?? null) !== self::ENGINE_VERSION) {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_engine_version_mismatch');
        }
        if (($envelope['scenario_config_hash'] ?? null) !== self::scenarioConfigHash()) {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_config_mismatch');
        }

        $payload = $envelope['payload'] ?? null;
        $checksum = $envelope['payload_checksum'] ?? null;
        if (!\is_array($payload) || !\is_string($checksum)) {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_envelope_invalid');
        }
        if (!hash_equals(hash('sha256', serialize($payload)), $checksum)) {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_checksum_mismatch');
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function hydrate(array $state): void
    {
        $nextOrderSequence = $state['nextOrderSequence'] ?? null;
        $orders = $state['orders'] ?? null;
        $clientOrderIndex = $state['clientOrderIndex'] ?? null;
        $positions = $state['positions'] ?? null;
        $balances = $state['balances'] ?? null;
        $orderBooks = $state['orderBooks'] ?? null;
        $leverageSettings = $state['leverageSettings'] ?? [];
        $events = $state['events'] ?? null;
        $rejectNextProtectionOrder = $state['rejectNextProtectionOrder'] ?? null;
        $pendingFaults = $state['pendingFaults'] ?? [];
        if (
            !\is_int($nextOrderSequence) || $nextOrderSequence < 1
            || !$this->isTypedMap($orders, ExchangeOrderDto::class)
            || !$this->isStringMap($clientOrderIndex)
            || !$this->isTypedMap($positions, ExchangePositionDto::class)
            || !$this->isTypedMap($balances, ExchangeBalanceDto::class)
            || !$this->isOrderBookMap($orderBooks)
            || !$this->isLeverageSettingsMap($leverageSettings)
            || !$this->isTypedArray($events, FakeExchangeEvent::class)
            || !\is_bool($rejectNextProtectionOrder)
            || !$this->isFaultQueue($pendingFaults)
        ) {
            throw new FakeExchangeStateCorruptedException('fake_exchange_state_shape_invalid');
        }

        $this->nextOrderSequence = $nextOrderSequence;
        $this->orders = $orders;
        $this->clientOrderIndex = $clientOrderIndex;
        $this->positions = $positions;
        $this->balances = $balances;
        $this->orderBooks = $orderBooks;
        $this->leverageSettings = $leverageSettings;
        $this->events = array_values($events);
        $this->rejectNextProtectionOrder = $rejectNextProtectionOrder;
        $this->pendingFaults = array_values($pendingFaults);

        $nextEventSequence = $state['nextEventSequence'] ?? null;
        $this->nextEventSequence = \is_int($nextEventSequence) && $nextEventSequence > 0
            ? $nextEventSequence
            : $this->inferNextEventSequence();
    }

    private function inferNextEventSequence(): int
    {
        $maximum = 0;
        foreach ($this->events as $event) {
            $sequence = $event->payload['event_sequence'] ?? null;
            if (\is_int($sequence)) {
                $maximum = max($maximum, $sequence);
            } elseif (\is_string($sequence) && ctype_digit($sequence)) {
                $maximum = max($maximum, (int) $sequence);
            }
        }

        return $maximum + 1;
    }

    private static function scenarioConfigHash(): string
    {
        return hash('sha256', self::SCENARIO_CONFIG_ID);
    }

    /**
     * @return list<class-string>
     */
    private static function allowedSerializedClasses(): array
    {
        return [
            ExchangeBalanceDto::class,
            ExchangeOrderDto::class,
            ExchangePositionDto::class,
            FakeExchangeEvent::class,
            Exchange::class,
            MarketType::class,
            ExchangeOrderSide::class,
            ExchangeOrderStatus::class,
            ExchangeOrderType::class,
            ExchangePositionSide::class,
            ExchangeTimeInForce::class,
            \DateTimeImmutable::class,
            \DateTimeZone::class,
        ];
    }

    private function isTypedArray(mixed $value, string $class): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$item instanceof $class) {
                return false;
            }
        }

        return true;
    }

    private function isTypedMap(mixed $value, string $class): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (!\is_string($key) || !$item instanceof $class) {
                return false;
            }
        }

        return true;
    }

    private function isStringMap(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (!\is_string($key) || !\is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private function isOrderBookMap(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $symbol => $book) {
            if (
                !\is_string($symbol)
                || !\is_array($book)
                || !\is_float($book['bid'] ?? null)
                || !\is_float($book['ask'] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isLeverageSettingsMap(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $symbol => $setting) {
            if (
                !\is_string($symbol)
                || $symbol === ''
                || trim($symbol) !== $symbol
                || strtoupper($symbol) !== $symbol
                || !\is_array($setting)
                || \count($setting) !== 2
                || !\array_key_exists('leverage', $setting)
                || !\array_key_exists('margin_mode', $setting)
                || !\is_int($setting['leverage'])
                || $setting['leverage'] <= 0
                || !\is_string($setting['margin_mode'])
                || !\in_array($setting['margin_mode'], ['isolated', 'cross'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isFaultQueue(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $fault) {
            if (!\is_array($fault)) {
                return false;
            }

            try {
                FakeExchangeFault::fromArray($fault);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template TResult
     * @param callable(): TResult $callback
     * @return TResult
     */
    private function transactional(callable $callback): mixed
    {
        if ($this->deferPersistence) {
            throw new \LogicException('fake_exchange_state_nested_transaction_not_supported');
        }

        $lockHandle = $this->acquireTransactionLock();

        try {
            if ($this->stateFile !== null && !$this->restore()) {
                $this->reset();
            }

            $snapshot = $this->runtimeState();
            $this->deferPersistence = true;

            try {
                $result = $callback();
                $this->deferPersistence = false;
                $this->persist();

                return $result;
            } catch (\Throwable $exception) {
                $this->deferPersistence = false;
                $this->restoreRuntimeState($snapshot);

                throw $exception;
            }
        } finally {
            if (\is_resource($lockHandle)) {
                flock($lockHandle, \LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    /** @return resource|null */
    private function acquireTransactionLock(): mixed
    {
        if ($this->stateFile === null) {
            return null;
        }

        $directory = \dirname($this->stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('fake_exchange_state_directory_unavailable');
        }

        $handle = fopen($this->stateFile . '.lock', 'c+');
        if ($handle === false) {
            throw new \RuntimeException('fake_exchange_state_lock_unavailable');
        }
        if (!flock($handle, \LOCK_EX)) {
            fclose($handle);

            throw new \RuntimeException('fake_exchange_state_lock_failed');
        }

        return $handle;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeState(): array
    {
        return [
            'nextOrderSequence' => $this->nextOrderSequence,
            'nextEventSequence' => $this->nextEventSequence,
            'restored' => $this->restored,
            'restoredLegacyState' => $this->restoredLegacyState,
            'orders' => $this->orders,
            'clientOrderIndex' => $this->clientOrderIndex,
            'positions' => $this->positions,
            'balances' => $this->balances,
            'orderBooks' => $this->orderBooks,
            'leverageSettings' => $this->leverageSettings,
            'events' => $this->events,
            'rejectNextProtectionOrder' => $this->rejectNextProtectionOrder,
            'pendingFaults' => $this->pendingFaults,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function restoreRuntimeState(array $snapshot): void
    {
        $this->nextOrderSequence = $snapshot['nextOrderSequence'];
        $this->nextEventSequence = $snapshot['nextEventSequence'];
        $this->restored = $snapshot['restored'];
        $this->restoredLegacyState = $snapshot['restoredLegacyState'];
        $this->orders = $snapshot['orders'];
        $this->clientOrderIndex = $snapshot['clientOrderIndex'];
        $this->positions = $snapshot['positions'];
        $this->balances = $snapshot['balances'];
        $this->orderBooks = $snapshot['orderBooks'];
        $this->leverageSettings = $snapshot['leverageSettings'];
        $this->events = $snapshot['events'];
        $this->rejectNextProtectionOrder = $snapshot['rejectNextProtectionOrder'];
        $this->pendingFaults = $snapshot['pendingFaults'];
    }
}
