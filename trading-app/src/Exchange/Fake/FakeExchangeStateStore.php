<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
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
    private const PRIVATE_WS_AUDIT_LIMIT = 100;
    private const PRIVATE_WS_CONNECTED = 'connected';
    private const PRIVATE_WS_RESYNC_REQUIRED = 'resync_required';

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

    /** @var array<string,mixed> */
    private array $privateWs = [];

    private bool $deferPersistence = false;

    private bool $privateWsConsumptionActive = false;

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
        $this->privateWs = self::defaultPrivateWsState();
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
        $this->appendEventToActivePrivateWsScenario($event);
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

    public function configurePrivateWsScenario(FakePrivateWsScenario $scenario): void
    {
        $lease = $this->acquirePrivateWsConsumptionLease();

        try {
            $this->transactional(function () use ($scenario): void {
                if ($this->privateWs['connection_state'] === self::PRIVATE_WS_RESYNC_REQUIRED) {
                    throw new \LogicException('fake_private_ws_snapshot_resync_required');
                }

                $this->privateWs = self::defaultPrivateWsState();
                $this->privateWs['scenario'] = $scenario->toArray();
            });
        } finally {
            $lease->release();
        }
    }

    public function acquirePrivateWsConsumptionLease(): FakePrivateWsConsumptionLease
    {
        if ($this->privateWsConsumptionActive) {
            throw FakePrivateWsException::consumerBusy($this->privateWsLastAcknowledgedSequence());
        }

        $lockHandle = null;
        if ($this->stateFile !== null) {
            $directory = \dirname($this->stateFile);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('fake_exchange_state_directory_unavailable');
            }

            $lockHandle = fopen($this->stateFile . '.private-ws-consumer.lock', 'c+');
            if ($lockHandle === false) {
                throw new \RuntimeException('fake_private_ws_consumer_lock_unavailable');
            }
            if (!flock($lockHandle, \LOCK_EX | \LOCK_NB)) {
                fclose($lockHandle);

                throw FakePrivateWsException::consumerBusy($this->privateWsLastAcknowledgedSequence());
            }
        }

        $this->privateWsConsumptionActive = true;
        $lease = new FakePrivateWsConsumptionLease(
            $lockHandle,
            function (): void {
                $this->privateWsConsumptionActive = false;
            },
        );

        try {
            if ($this->stateFile !== null) {
                $this->transactional(static function (): void {
                });
            }
        } catch (\Throwable $exception) {
            $lease->release();

            throw $exception;
        }

        return $lease;
    }

    public function hasPrivateWsScenario(): bool
    {
        return $this->privateWs['scenario'] !== null;
    }

    public function privateWsCurrentDelivery(): ?FakePrivateWsDelivery
    {
        $scenario = $this->privateWsScenario();
        if (!$scenario instanceof FakePrivateWsScenario) {
            return null;
        }

        return $scenario->deliveries[$this->privateWs['next_delivery_index']] ?? null;
    }

    public function privateWsAcknowledgedFingerprint(string $sequence): ?string
    {
        $fingerprint = $this->privateWs['acknowledged_fingerprints'][$sequence] ?? null;

        return \is_string($fingerprint) ? $fingerprint : null;
    }

    public function privateWsLastAcknowledgedSequence(): ?string
    {
        return $this->privateWs['last_acknowledged_sequence'];
    }

    public function privateWsExpectedNumericSequence(): int
    {
        return $this->privateWs['last_observed_numeric_sequence'] + 1;
    }

    public function acknowledgePrivateWsDelivery(FakePrivateWsDelivery $delivery): void
    {
        $this->transactional(function () use ($delivery): void {
            $current = $this->assertCurrentPrivateWsDelivery($delivery);
            $known = $this->privateWsAcknowledgedFingerprint($current->sequence);
            if ($known !== null && !hash_equals($known, $current->fingerprint)) {
                throw new \LogicException('fake_private_ws_delivery_acknowledgement_conflict');
            }

            $this->privateWs['acknowledged_fingerprints'][$current->sequence] = $current->fingerprint;
            $this->privateWs['last_acknowledged_sequence'] = $current->sequence;
            $this->observePrivateWsNumericSequence($current->sequence);
            ++$this->privateWs['next_delivery_index'];
            ++$this->privateWs['counters']['acknowledged_total'];
        });
    }

    public function skipExactPrivateWsDuplicate(FakePrivateWsDelivery $delivery): void
    {
        $this->transactional(function () use ($delivery): void {
            $current = $this->assertCurrentPrivateWsDelivery($delivery);
            $known = $this->privateWsAcknowledgedFingerprint($current->sequence);
            if ($known === null || !hash_equals($known, $current->fingerprint)) {
                throw new \LogicException('fake_private_ws_duplicate_identity_invalid');
            }

            ++$this->privateWs['next_delivery_index'];
            $this->observePrivateWsNumericSequence($current->sequence);
            ++$this->privateWs['counters']['duplicate_total'];
            $this->appendPrivateWsRecord([
                'kind' => 'duplicate',
                'sequence' => $current->sequence,
                'fixture_entry_id' => $current->fixtureEntryId,
                'fingerprint_prefix' => substr($current->fingerprint, 0, 12),
            ]);
        });
    }

    public function markPrivateWsGap(
        string $expectedSequence,
        string $actualSequence,
        FakePrivateWsDelivery $delivery,
    ): void {
        $this->transactional(function () use ($expectedSequence, $actualSequence, $delivery): void {
            $current = $this->assertCurrentPrivateWsDelivery($delivery);
            if (
                trim($expectedSequence) === ''
                || trim($actualSequence) === ''
                || $actualSequence !== $current->sequence
            ) {
                throw new \LogicException('fake_private_ws_gap_identity_invalid');
            }

            $this->privateWs['connection_state'] = self::PRIVATE_WS_RESYNC_REQUIRED;
            $this->privateWs['resync_reason'] = 'fake_private_ws_sequence_gap';
            ++$this->privateWs['counters']['gap_total'];
            $this->appendPrivateWsRecord([
                'kind' => 'gap',
                'sequence' => $current->sequence,
                'expected_sequence' => $expectedSequence,
                'actual_sequence' => $actualSequence,
                'fixture_entry_id' => $current->fixtureEntryId,
                'fingerprint_prefix' => substr($current->fingerprint, 0, 12),
            ]);
        });
    }

    public function markPrivateWsConflict(FakePrivateWsDelivery $delivery): void
    {
        $this->transactional(function () use ($delivery): void {
            $current = $this->assertCurrentPrivateWsDelivery($delivery);
            $known = $this->privateWsAcknowledgedFingerprint($current->sequence);
            if ($known === null || hash_equals($known, $current->fingerprint)) {
                throw new \LogicException('fake_private_ws_conflict_identity_invalid');
            }

            $this->privateWs['connection_state'] = self::PRIVATE_WS_RESYNC_REQUIRED;
            $this->privateWs['resync_reason'] = 'fake_private_ws_sequence_conflict';
            ++$this->privateWs['counters']['conflict_total'];
            $this->appendPrivateWsRecord([
                'kind' => 'conflict',
                'sequence' => $current->sequence,
                'fixture_entry_id' => $current->fixtureEntryId,
                'fingerprint_prefix' => substr($current->fingerprint, 0, 12),
            ]);
        });
    }

    public function completePrivateWsSnapshotResync(ExchangeReconciliationResult $reconciliation): void
    {
        $lease = $this->acquirePrivateWsConsumptionLease();

        try {
            if (
                $reconciliation->exchange !== Exchange::FAKE
                || $reconciliation->marketType !== MarketType::PERPETUAL
                || $reconciliation->symbol !== null
            ) {
                throw new \LogicException('fake_private_ws_global_reconciliation_required');
            }
            if ($reconciliation->errors !== []) {
                throw new \LogicException('fake_private_ws_reconciliation_failed');
            }

            $this->transactional(function (): void {
                if ($this->privateWs['connection_state'] !== self::PRIVATE_WS_RESYNC_REQUIRED) {
                    throw new \LogicException('fake_private_ws_snapshot_resync_not_required');
                }

                $watermark = $this->maximumCanonicalNumericEventSequence();
                $scenario = $this->privateWsScenario();
                if (!$scenario instanceof FakePrivateWsScenario) {
                    throw new \LogicException('fake_private_ws_scenario_not_configured');
                }

                while (isset($scenario->deliveries[$this->privateWs['next_delivery_index']])) {
                    $delivery = $scenario->deliveries[$this->privateWs['next_delivery_index']];
                    if (!ctype_digit($delivery->sequence) || (int) $delivery->sequence > $watermark) {
                        break;
                    }

                    $this->privateWs['acknowledged_fingerprints'][$delivery->sequence] = $delivery->fingerprint;
                    ++$this->privateWs['next_delivery_index'];
                }

                $this->privateWs['last_observed_numeric_sequence'] = max(
                    $this->privateWs['last_observed_numeric_sequence'],
                    $watermark,
                );
                $lastAcknowledged = $this->privateWs['last_acknowledged_sequence'];
                $lastAcknowledgedNumeric = \is_string($lastAcknowledged) && ctype_digit($lastAcknowledged)
                    ? (int) $lastAcknowledged
                    : 0;
                if ($watermark > $lastAcknowledgedNumeric) {
                    $this->privateWs['last_acknowledged_sequence'] = (string) $watermark;
                }
                $this->privateWs['connection_state'] = self::PRIVATE_WS_CONNECTED;
                $this->privateWs['resync_reason'] = null;
                ++$this->privateWs['counters']['resync_total'];
                $this->appendPrivateWsRecord([
                    'kind' => 'resync_completed',
                    'sequence' => (string) $watermark,
                ]);
            });
        } finally {
            $lease->release();
        }
    }

    /**
     * @return array{
     *     scenario_id:?string,
     *     next_delivery_index:int,
     *     acknowledged_total:int,
     *     duplicate_total:int,
     *     gap_total:int,
     *     conflict_total:int,
     *     resync_total:int,
     *     connection_state:string,
     *     resync_reason:?string,
     *     last_acknowledged_sequence:?string,
     *     last_observed_numeric_sequence:int,
     *     records:list<array<string,string>>
     * }
     */
    public function privateWsAudit(): array
    {
        $scenario = $this->privateWsScenario();
        $counters = $this->privateWs['counters'];

        return [
            'scenario_id' => $scenario?->scenarioId,
            'next_delivery_index' => $this->privateWs['next_delivery_index'],
            'acknowledged_total' => $counters['acknowledged_total'],
            'duplicate_total' => $counters['duplicate_total'],
            'gap_total' => $counters['gap_total'],
            'conflict_total' => $counters['conflict_total'],
            'resync_total' => $counters['resync_total'],
            'connection_state' => $this->privateWs['connection_state'],
            'resync_reason' => $this->privateWs['resync_reason'],
            'last_acknowledged_sequence' => $this->privateWs['last_acknowledged_sequence'],
            'last_observed_numeric_sequence' => $this->privateWs['last_observed_numeric_sequence'],
            'records' => $this->privateWs['records'],
        ];
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

    /**
     * @template TResult
     * @param callable(): TResult $operationCallback
     * @return TResult
     */
    public function runAtomically(callable $operationCallback): mixed
    {
        return $this->transactional($operationCallback);
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
     * @return array{
     *     format_version:int,
     *     engine_version:string,
     *     scenario_config_hash:string,
     *     restored:bool,
     *     legacy:bool,
     *     next_event_sequence:int,
     *     pending_fault_count:int,
     *     private_ws_scenario_active:bool,
     *     private_ws_connection_state:string
     * }
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
            'private_ws_scenario_active' => $this->hasPrivateWsScenario(),
            'private_ws_connection_state' => $this->privateWs['connection_state'],
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
            'privateWs' => $this->privateWs,
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
        $privateWs = array_key_exists('privateWs', $state)
            ? $state['privateWs']
            : self::defaultPrivateWsState();
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
            || !$this->isPrivateWsState($privateWs)
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
        $this->privateWs = $privateWs;

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

    /** @return array<string,mixed> */
    private static function defaultPrivateWsState(): array
    {
        return [
            'scenario' => null,
            'next_delivery_index' => 0,
            'acknowledged_fingerprints' => [],
            'last_acknowledged_sequence' => null,
            'last_observed_numeric_sequence' => 0,
            'connection_state' => self::PRIVATE_WS_CONNECTED,
            'resync_reason' => null,
            'counters' => [
                'acknowledged_total' => 0,
                'duplicate_total' => 0,
                'gap_total' => 0,
                'conflict_total' => 0,
                'resync_total' => 0,
            ],
            'records' => [],
        ];
    }

    private function isPrivateWsState(mixed $value): bool
    {
        if (!\is_array($value) || !$this->hasExactKeys($value, array_keys(self::defaultPrivateWsState()))) {
            return false;
        }

        $scenarioPayload = $value['scenario'];
        if ($scenarioPayload !== null && !\is_array($scenarioPayload)) {
            return false;
        }
        try {
            $scenario = \is_array($scenarioPayload) ? FakePrivateWsScenario::fromArray($scenarioPayload) : null;
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (
            !\is_int($value['next_delivery_index'])
            || $value['next_delivery_index'] < 0
            || ($scenario instanceof FakePrivateWsScenario
                && $value['next_delivery_index'] > \count($scenario->deliveries))
            || ($scenario === null && $value['next_delivery_index'] !== 0)
            || !$this->isPrivateWsFingerprintMap($value['acknowledged_fingerprints'])
            || ($value['last_acknowledged_sequence'] !== null
                && (!\is_string($value['last_acknowledged_sequence'])
                    || trim($value['last_acknowledged_sequence']) === ''))
            || !\is_int($value['last_observed_numeric_sequence'])
            || $value['last_observed_numeric_sequence'] < 0
            || !\in_array($value['connection_state'], [
                self::PRIVATE_WS_CONNECTED,
                self::PRIVATE_WS_RESYNC_REQUIRED,
            ], true)
            || !$this->isPrivateWsResyncReason($value['connection_state'], $value['resync_reason'])
            || !$this->isPrivateWsCounters($value['counters'])
            || !$this->isPrivateWsRecords($value['records'])
        ) {
            return false;
        }

        return true;
    }

    private function isPrivateWsFingerprintMap(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $sequence => $fingerprint) {
            if (
                (string) $sequence === ''
                || !\is_string($fingerprint)
                || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateWsResyncReason(mixed $connectionState, mixed $reason): bool
    {
        if ($connectionState === self::PRIVATE_WS_CONNECTED) {
            return $reason === null;
        }

        return \is_string($reason) && \in_array($reason, [
            'fake_private_ws_sequence_gap',
            'fake_private_ws_sequence_conflict',
        ], true);
    }

    private function isPrivateWsCounters(mixed $value): bool
    {
        $keys = [
            'acknowledged_total',
            'duplicate_total',
            'gap_total',
            'conflict_total',
            'resync_total',
        ];
        if (!\is_array($value) || !$this->hasExactKeys($value, $keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if (!\is_int($value[$key]) || $value[$key] < 0) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateWsRecords(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value) || \count($value) > self::PRIVATE_WS_AUDIT_LIMIT) {
            return false;
        }

        foreach ($value as $record) {
            if (!\is_array($record) || !\is_string($record['kind'] ?? null)) {
                return false;
            }

            $keys = match ($record['kind']) {
                'duplicate', 'conflict' => [
                    'kind',
                    'sequence',
                    'fixture_entry_id',
                    'fingerprint_prefix',
                ],
                'gap' => [
                    'kind',
                    'sequence',
                    'expected_sequence',
                    'actual_sequence',
                    'fixture_entry_id',
                    'fingerprint_prefix',
                ],
                'resync_completed' => ['kind', 'sequence'],
                default => null,
            };
            if ($keys === null || !$this->hasExactKeys($record, $keys)) {
                return false;
            }
            foreach ($keys as $key) {
                if (!\is_string($record[$key]) || $record[$key] === '') {
                    return false;
                }
            }
            if (
                isset($record['fingerprint_prefix'])
                && !preg_match('/^[a-f0-9]{12}$/D', $record['fingerprint_prefix'])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $expectedKeys
     */
    private function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    private function privateWsScenario(): ?FakePrivateWsScenario
    {
        $payload = $this->privateWs['scenario'];

        return \is_array($payload) ? FakePrivateWsScenario::fromArray($payload) : null;
    }

    private function appendEventToActivePrivateWsScenario(FakeExchangeEvent $event): void
    {
        $scenario = $this->privateWsScenario();
        if (!$scenario instanceof FakePrivateWsScenario) {
            return;
        }

        $delivery = FakePrivateWsDelivery::fromEvent(
            sprintf(
                '%s-appended-%04d',
                $scenario->scenarioId,
                \count($scenario->deliveries) + 1,
            ),
            $event,
        );
        $payload = $scenario->toArray();
        $payload['deliveries'][] = $delivery->toArray();
        $this->privateWs['scenario'] = $payload;
    }

    private function assertCurrentPrivateWsDelivery(FakePrivateWsDelivery $expected): FakePrivateWsDelivery
    {
        $current = $this->privateWsCurrentDelivery();
        if (
            !$current instanceof FakePrivateWsDelivery
            || $current->fixtureEntryId !== $expected->fixtureEntryId
            || $current->sequence !== $expected->sequence
            || !hash_equals($current->fingerprint, $expected->fingerprint)
        ) {
            throw new \LogicException('fake_private_ws_delivery_cursor_mismatch');
        }

        return $current;
    }

    private function observePrivateWsNumericSequence(string $sequence): void
    {
        if (ctype_digit($sequence)) {
            $this->privateWs['last_observed_numeric_sequence'] = max(
                $this->privateWs['last_observed_numeric_sequence'],
                (int) $sequence,
            );
        }
    }

    /** @param array<string,string> $record */
    private function appendPrivateWsRecord(array $record): void
    {
        $this->privateWs['records'][] = $record;
        if (\count($this->privateWs['records']) > self::PRIVATE_WS_AUDIT_LIMIT) {
            $this->privateWs['records'] = array_slice(
                $this->privateWs['records'],
                -self::PRIVATE_WS_AUDIT_LIMIT,
            );
        }
    }

    private function maximumCanonicalNumericEventSequence(): int
    {
        $watermark = 0;
        foreach ($this->events as $event) {
            $sequence = $event->payload['event_sequence'] ?? null;
            if (\is_int($sequence) && $sequence >= 0) {
                $watermark = max($watermark, $sequence);
            } elseif (\is_string($sequence) && ctype_digit($sequence)) {
                $watermark = max($watermark, (int) $sequence);
            }
        }

        return $watermark;
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
                $this->initializeDefaults();
            }

            $snapshot = $this->runtimeState();
            $this->deferPersistence = true;

            try {
                $result = $callback();
                $this->deferPersistence = false;
                if ($this->runtimeState() !== $snapshot) {
                    $this->persist();
                }

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
            'privateWs' => $this->privateWs,
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
        $this->privateWs = $snapshot['privateWs'];
    }
}
