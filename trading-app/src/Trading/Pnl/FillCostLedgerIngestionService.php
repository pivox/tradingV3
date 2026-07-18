<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Entity\TradeLineage;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeFundingReceived;
use App\Provider\Context\ExchangeContext;
use App\Repository\FillCostLedgerEntryRepository;
use App\Trading\Lineage\TradeLineageManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class FillCostLedgerIngestionService
{
    private const SOURCE_VERSION = 'fill_cost_ledger_v1';
    private const SENSITIVE_KEY_MARKERS = ['apikey', 'secret', 'token', 'password', 'memo', 'credential'];
    private const FUNDING_DETAIL_CONFLICT_KEYS = [
        'funding_native_amount',
        'funding_currency',
        'funding_rate',
        'funding_rate_interval_seconds',
        'funding_applied_interval_seconds',
    ];

    public function __construct(
        private FillCostLedgerEntryRepository $ledger,
        private TradeLineageManager $lineageManager,
    ) {
    }

    public function ingestExchangeFill(ExchangeFillReceived $event): FillCostLedgerIngestionResult
    {
        $fill = $event->fill();
        $metadata = $fill->metadata;
        $payload = $event->payload();
        $exchangeFillId = $this->string($fill->fillId);
        $fillId = $exchangeFillId ?? $this->deterministicFillId($fill);
        $idempotencyKey = $exchangeFillId !== null
            ? sprintf('%s:%s:exchange_fill:%s', $fill->exchange->value, $fill->marketType->value, $exchangeFillId)
            : sprintf('%s:%s:internal:%s', $fill->exchange->value, $fill->marketType->value, $fillId);

        $lineage = $this->resolveLineage($fill, $metadata, $payload);
        $qualityFlags = [];
        $internalTradeId = $lineage?->getInternalTradeId() ?? $this->string($metadata['internal_trade_id'] ?? $payload['internal_trade_id'] ?? null);
        if (!$lineage instanceof TradeLineage) {
            $qualityFlags[] = 'missing_lineage';
        }

        $fee = $fill->fee !== null ? $this->decimal($fill->fee) : null;
        $feeCurrency = $this->string($fill->feeCurrency);
        $feeCurrency = $feeCurrency !== null ? strtoupper($feeCurrency) : null;
        $feeUsdt = $this->feeUsdt($fill, $metadata, $payload, $qualityFlags);
        $spreadCostUsdt = $this->nonNegativeCostUsdt(
            'spread_cost_usdt',
            'spread_cost_invalid',
            $metadata,
            $payload,
            $qualityFlags,
        );
        $slippageCostUsdt = $this->nonNegativeCostUsdt(
            'slippage_cost_usdt',
            'slippage_cost_invalid',
            $metadata,
            $payload,
            $qualityFlags,
        );
        $source = $this->source($metadata, $payload);
        $sourceVersion = $this->string($metadata['source_version'] ?? $payload['source_version'] ?? null)
            ?? $this->string($metadata['pnl_source'] ?? $payload['pnl_source'] ?? null)
            ?? self::SOURCE_VERSION;

        $snapshot = [
            'internal_trade_id' => $internalTradeId,
            'internal_position_id' => $lineage?->getInternalPositionId() ?? $this->string($metadata['internal_position_id'] ?? $payload['internal_position_id'] ?? null),
            'position_id' => $lineage?->getPositionId() ?? $this->string($metadata['position_id'] ?? $payload['position_id'] ?? $metadata['exchange_position_id'] ?? $payload['exchange_position_id'] ?? null),
            'exchange' => $fill->exchange->value,
            'market_type' => $fill->marketType->value,
            'symbol' => strtoupper($fill->symbol),
            'side' => strtoupper($fill->side->name),
            'fill_id' => $fillId,
            'exchange_fill_id' => $exchangeFillId,
            'exchange_order_id' => $fill->exchangeOrderId,
            'client_order_id' => $fill->clientOrderId,
            'order_intent_id' => $lineage?->getOrderIntent()?->getId(),
            'fill_role' => $this->fillRole($fill),
            'liquidity_role' => $this->liquidityRole($metadata, $payload),
            'price' => $this->decimal($fill->price),
            'quantity' => $this->decimal($fill->quantity),
            'notional' => $this->decimal($fill->price * $fill->quantity),
            'fee_amount' => $fee,
            'fee_currency' => $feeCurrency,
            'fee_usdt' => $feeUsdt,
            'funding_usdt' => null,
            'spread_cost_usdt' => $spreadCostUsdt,
            'slippage_cost_usdt' => $slippageCostUsdt,
            'borrow_cost_usdt' => null,
            'liquidation_fee_usdt' => null,
            'occurred_at' => $fill->filledAt->format(\DateTimeInterface::ATOM),
            'source' => $source,
            'source_version' => $sourceVersion,
            'quality_flags' => array_values(array_unique($qualityFlags)),
            'raw_reference' => $this->rawReference($event->eventType(), $source, $exchangeFillId, $fill->exchangeOrderId, $fill->clientOrderId),
        ];

        return $this->persistSnapshot($idempotencyKey, $snapshot);
    }

    public function ingestFunding(ExchangeFundingReceived $event): FillCostLedgerIngestionResult
    {
        $funding = $event->funding();
        $identityHash = hash('sha256', json_encode([
            'position_id' => $funding->positionId,
            'due_at' => $funding->dueAt->format(\DateTimeInterface::ATOM),
            'model_version' => $funding->modelVersion,
        ], JSON_THROW_ON_ERROR));
        $idempotencyKey = sprintf(
            '%s:%s:funding:%s',
            $funding->exchange->value,
            $funding->marketType->value,
            $identityHash,
        );
        $lineage = $this->lineageManager->resolve(
            new ExchangeContext($funding->exchange, $funding->marketType),
            internalTradeId: $funding->internalTradeId,
            positionId: $funding->positionId,
        );
        $qualityFlags = [];
        if (!$lineage instanceof TradeLineage) {
            $qualityFlags[] = 'missing_lineage';
        }
        if ($funding->amountUsdt === null) {
            $qualityFlags[] = 'funding_currency_not_normalized';
        }

        $snapshot = [
            'internal_trade_id' => $lineage?->getInternalTradeId() ?? $funding->internalTradeId,
            'internal_position_id' => $lineage?->getInternalPositionId() ?? $funding->internalPositionId,
            'position_id' => $lineage?->getPositionId() ?? $funding->positionId,
            'exchange' => $funding->exchange->value,
            'market_type' => $funding->marketType->value,
            'symbol' => strtoupper($funding->symbol),
            'side' => strtoupper($funding->positionSide->value),
            'fill_id' => 'funding-' . substr($identityHash, 0, 48),
            'exchange_fill_id' => null,
            'exchange_order_id' => null,
            'client_order_id' => null,
            'order_intent_id' => $lineage?->getOrderIntent()?->getId(),
            'fill_role' => 'funding',
            'liquidity_role' => 'unknown',
            'price' => null,
            'quantity' => null,
            'notional' => $funding->notional,
            'fee_amount' => null,
            'fee_currency' => null,
            'fee_usdt' => null,
            'funding_usdt' => $funding->amountUsdt,
            'funding_native_amount' => $funding->amount,
            'funding_currency' => strtoupper($funding->currency),
            'funding_rate' => $funding->fundingRate,
            'funding_rate_interval_seconds' => $funding->rateIntervalSeconds,
            'funding_applied_interval_seconds' => $funding->appliedIntervalSeconds,
            'spread_cost_usdt' => null,
            'slippage_cost_usdt' => null,
            'borrow_cost_usdt' => null,
            'liquidation_fee_usdt' => null,
            'occurred_at' => $funding->dueAt->format(\DateTimeInterface::ATOM),
            'source' => $funding->source,
            'source_version' => $funding->modelVersion,
            'quality_flags' => $qualityFlags,
            'raw_reference' => $this->redactReference([
                'event_type' => $event->eventType(),
                'position_id' => $funding->positionId,
                'due_at' => $funding->dueAt->format(\DateTimeInterface::ATOM),
                'model_version' => $funding->modelVersion,
                'native_amount' => $funding->amount,
                'currency' => strtoupper($funding->currency),
                'funding_rate' => $funding->fundingRate,
                'rate_interval_seconds' => $funding->rateIntervalSeconds,
                'applied_interval_seconds' => $funding->appliedIntervalSeconds,
                'funding_idempotency_key' => $event->payload()['funding_idempotency_key'] ?? null,
            ]),
        ];

        return $this->persistSnapshot($idempotencyKey, $snapshot);
    }

    /**
     * @param array<string,mixed> $rawReference
     */
    public function ingestFundingAdjustment(
        string $internalTradeId,
        Exchange $exchange,
        MarketType $marketType,
        string $symbol,
        float $fundingUsdt,
        \DateTimeImmutable $occurredAt,
        string $source,
        string $sourceVersion,
        array $rawReference,
    ): FillCostLedgerIngestionResult {
        $fundingEventId = $this->string($rawReference['funding_event_id'] ?? null);
        $idempotencyKey = $fundingEventId !== null
            ? sprintf('%s:%s:funding:%s', $exchange->value, $marketType->value, $fundingEventId)
            : sprintf('%s:%s:funding:%s', $exchange->value, $marketType->value, substr(hash('sha256', implode(':', [
                $internalTradeId,
                $symbol,
                $occurredAt->format('U.u'),
                $this->decimal($fundingUsdt),
            ])), 0, 48));
        $lineage = $this->lineageManager->resolve(
            new ExchangeContext($exchange, $marketType),
            internalTradeId: $internalTradeId,
        );
        $flags = $lineage instanceof TradeLineage ? [] : ['missing_lineage'];

        $snapshot = [
            'internal_trade_id' => $internalTradeId,
            'internal_position_id' => $lineage?->getInternalPositionId(),
            'position_id' => $lineage?->getPositionId(),
            'exchange' => $exchange->value,
            'market_type' => $marketType->value,
            'symbol' => strtoupper($symbol),
            'side' => $lineage?->getSide(),
            'fill_id' => $idempotencyKey,
            'exchange_fill_id' => null,
            'exchange_order_id' => null,
            'client_order_id' => $lineage?->getClientOrderId(),
            'order_intent_id' => $lineage?->getOrderIntent()?->getId(),
            'fill_role' => 'funding',
            'liquidity_role' => 'unknown',
            'price' => null,
            'quantity' => null,
            'notional' => null,
            'fee_amount' => null,
            'fee_currency' => null,
            'fee_usdt' => null,
            'funding_usdt' => $this->decimal($fundingUsdt),
            'spread_cost_usdt' => null,
            'slippage_cost_usdt' => null,
            'borrow_cost_usdt' => null,
            'liquidation_fee_usdt' => null,
            'occurred_at' => $occurredAt->format(\DateTimeInterface::ATOM),
            'source' => $source,
            'source_version' => $sourceVersion,
            'quality_flags' => $flags,
            'raw_reference' => $this->redactReference($rawReference + ['source' => $source]),
        ];

        return $this->persistSnapshot($idempotencyKey, $snapshot);
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function persistSnapshot(string $idempotencyKey, array $snapshot): FillCostLedgerIngestionResult
    {
        $payloadHash = $this->conflictHash($snapshot);
        $existing = $this->ledger->findOneByIdempotencyKey($idempotencyKey);
        if ($existing instanceof FillCostLedgerEntry) {
            if ($this->payloadHashMatches($existing->getPayloadHash(), $payloadHash, $snapshot)) {
                if ($this->lineageConflicts($existing, $snapshot)) {
                    throw new FillCostLedgerIngestionConflict(sprintf(
                        'Conflicting fill-cost ledger lineage for idempotency key "%s".',
                        $idempotencyKey,
                    ));
                }

                return new FillCostLedgerIngestionResult($existing, inserted: false, replayed: true);
            }

            throw new FillCostLedgerIngestionConflict(sprintf(
                'Conflicting fill-cost ledger payload for idempotency key "%s".',
                $idempotencyKey,
            ));
        }

        $entry = (new FillCostLedgerEntry(
            idempotencyKey: $idempotencyKey,
            payloadHash: $payloadHash,
            exchange: (string) $snapshot['exchange'],
            marketType: (string) $snapshot['market_type'],
            symbol: (string) $snapshot['symbol'],
            fillId: (string) $snapshot['fill_id'],
            fillRole: (string) $snapshot['fill_role'],
            occurredAt: new \DateTimeImmutable((string) $snapshot['occurred_at']),
            source: (string) $snapshot['source'],
            sourceVersion: (string) $snapshot['source_version'],
        ))
            ->setInternalTradeId($this->string($snapshot['internal_trade_id'] ?? null))
            ->setInternalPositionId($this->string($snapshot['internal_position_id'] ?? null))
            ->setPositionId($this->string($snapshot['position_id'] ?? null))
            ->setSide($this->string($snapshot['side'] ?? null))
            ->setExchangeFillId($this->string($snapshot['exchange_fill_id'] ?? null))
            ->setExchangeOrderId($this->string($snapshot['exchange_order_id'] ?? null))
            ->setClientOrderId($this->string($snapshot['client_order_id'] ?? null))
            ->setOrderIntentId(\is_int($snapshot['order_intent_id'] ?? null) ? $snapshot['order_intent_id'] : null)
            ->setLiquidityRole((string) $snapshot['liquidity_role'])
            ->setPrice($this->string($snapshot['price'] ?? null))
            ->setQuantity($this->string($snapshot['quantity'] ?? null))
            ->setNotional($this->string($snapshot['notional'] ?? null))
            ->setFeeAmount($this->string($snapshot['fee_amount'] ?? null))
            ->setFeeCurrency($this->string($snapshot['fee_currency'] ?? null))
            ->setFeeUsdt($this->string($snapshot['fee_usdt'] ?? null))
            ->setFundingUsdt($this->string($snapshot['funding_usdt'] ?? null))
            ->setSpreadCostUsdt($this->string($snapshot['spread_cost_usdt'] ?? null))
            ->setSlippageCostUsdt($this->string($snapshot['slippage_cost_usdt'] ?? null))
            ->setBorrowCostUsdt($this->string($snapshot['borrow_cost_usdt'] ?? null))
            ->setLiquidationFeeUsdt($this->string($snapshot['liquidation_fee_usdt'] ?? null))
            ->setQualityFlags(\is_array($snapshot['quality_flags']) ? $snapshot['quality_flags'] : [])
            ->setRawReference(\is_array($snapshot['raw_reference']) ? $snapshot['raw_reference'] : []);

        try {
            $this->ledger->save($entry);
        } catch (UniqueConstraintViolationException) {
            $concurrent = $this->ledger->resetManagerAndFindOneByIdempotencyKey($idempotencyKey);
            if ($concurrent instanceof FillCostLedgerEntry
                && $this->payloadHashMatches($concurrent->getPayloadHash(), $payloadHash, $snapshot)
            ) {
                if ($this->lineageConflicts($concurrent, $snapshot)) {
                    throw new FillCostLedgerIngestionConflict(sprintf(
                        'Conflicting fill-cost ledger lineage for idempotency key "%s".',
                        $idempotencyKey,
                    ));
                }

                return new FillCostLedgerIngestionResult($concurrent, inserted: false, replayed: true);
            }

            throw new FillCostLedgerIngestionConflict(sprintf(
                'Conflicting fill-cost ledger payload for idempotency key "%s".',
                $idempotencyKey,
            ));
        }

        return new FillCostLedgerIngestionResult($entry, inserted: true, replayed: false);
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     */
    private function resolveLineage(ExchangeFillDto $fill, array $metadata, array $payload): ?TradeLineage
    {
        return $this->lineageManager->resolve(
            new ExchangeContext($fill->exchange, $fill->marketType),
            internalTradeId: $this->string($metadata['internal_trade_id'] ?? $payload['internal_trade_id'] ?? null),
            clientOrderId: $fill->clientOrderId,
            exchangeOrderId: $fill->exchangeOrderId,
            positionId: $this->string($metadata['position_id'] ?? $payload['position_id'] ?? $metadata['exchange_position_id'] ?? $payload['exchange_position_id'] ?? null),
        );
    }

    private function fillRole(ExchangeFillDto $fill): string
    {
        return match ([$fill->positionSide, $fill->side]) {
            [ExchangePositionSide::LONG, ExchangeOrderSide::BUY],
            [ExchangePositionSide::SHORT, ExchangeOrderSide::SELL] => 'entry',
            [ExchangePositionSide::LONG, ExchangeOrderSide::SELL],
            [ExchangePositionSide::SHORT, ExchangeOrderSide::BUY] => 'exit',
            default => 'adjustment',
        };
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     */
    private function liquidityRole(array $metadata, array $payload): string
    {
        $role = strtolower((string) ($metadata['liquidity_role'] ?? $metadata['liquidity'] ?? $payload['liquidity_role'] ?? $payload['liquidity'] ?? 'unknown'));

        return \in_array($role, ['maker', 'taker'], true) ? $role : 'unknown';
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     * @param list<string> $qualityFlags
     */
    private function feeUsdt(ExchangeFillDto $fill, array $metadata, array $payload, array &$qualityFlags): ?string
    {
        if ($fill->fee === null) {
            $qualityFlags[] = 'fee_missing';
            return null;
        }
        if (abs($fill->fee) <= 0.000000000001) {
            return $this->decimal(0.0);
        }

        $currency = $this->string($fill->feeCurrency);
        if ($currency === null) {
            $qualityFlags[] = 'fee_currency_missing';
            return null;
        }

        if (strtoupper($currency) === 'USDT') {
            return $this->decimal(abs($fill->fee));
        }

        $conversion = $metadata['fee_conversion'] ?? $payload['fee_conversion'] ?? null;
        if (\is_array($conversion) && isset($conversion['usdt_rate']) && is_numeric($conversion['usdt_rate'])) {
            $rate = (float) $conversion['usdt_rate'];
            if (!\is_finite($rate) || $rate <= 0.0) {
                $qualityFlags[] = 'fee_conversion_invalid';
                return null;
            }

            $conversionCurrency = $this->string($conversion['currency'] ?? null);
            if ($conversionCurrency === null || strtoupper($conversionCurrency) === strtoupper($currency)) {
                return $this->decimal(abs($fill->fee) * $rate);
            }
        }

        $qualityFlags[] = 'fee_conversion_missing';

        return null;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     * @param list<string> $qualityFlags
     */
    private function nonNegativeCostUsdt(
        string $key,
        string $invalidFlag,
        array $metadata,
        array $payload,
        array &$qualityFlags,
    ): ?string {
        if (array_key_exists($key, $metadata)) {
            $value = $metadata[$key];
        } elseif (array_key_exists($key, $payload)) {
            $value = $payload[$key];
        } else {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            $qualityFlags[] = $invalidFlag;
            return null;
        }

        $cost = (float) $value;
        if (!\is_finite($cost) || $cost < 0.0) {
            $qualityFlags[] = $invalidFlag;
            return null;
        }

        return $this->decimal($cost);
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     */
    private function source(array $metadata, array $payload): string
    {
        return $this->string($metadata['source'] ?? $payload['source'] ?? null) ?? 'exchange_event';
    }

    /**
     * @return array<string,mixed>
     */
    private function rawReference(
        string $eventType,
        string $source,
        ?string $exchangeFillId,
        ?string $exchangeOrderId,
        ?string $clientOrderId,
    ): array {
        return array_filter([
            'source' => $source,
            'event_type' => $eventType,
            'exchange_fill_id' => $exchangeFillId,
            'exchange_order_id' => $exchangeOrderId,
            'client_order_id' => $clientOrderId,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string,mixed> $reference
     * @return array<string,mixed>
     */
    private function redactReference(array $reference): array
    {
        $redacted = [];
        foreach ($reference as $key => $value) {
            if ($this->isSensitiveReferenceKey((string) $key)) {
                continue;
            }
            if (\is_array($value)) {
                $redacted[$key] = $this->redactReference($value);
            } elseif ($value === null || \is_scalar($value)) {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    private function isSensitiveReferenceKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $key));
        foreach (self::SENSITIVE_KEY_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function lineageConflicts(FillCostLedgerEntry $existing, array $snapshot): bool
    {
        $pairs = [
            [$existing->getInternalTradeId(), $this->string($snapshot['internal_trade_id'] ?? null)],
            [$existing->getInternalPositionId(), $this->string($snapshot['internal_position_id'] ?? null)],
            [$existing->getPositionId(), $this->string($snapshot['position_id'] ?? null)],
            [
                $existing->getOrderIntentId() !== null ? (string) $existing->getOrderIntentId() : null,
                isset($snapshot['order_intent_id']) && \is_scalar($snapshot['order_intent_id'])
                    ? $this->string($snapshot['order_intent_id'])
                    : null,
            ],
        ];

        foreach ($pairs as [$existingValue, $incomingValue]) {
            if ($existingValue !== null && $incomingValue !== null && $existingValue !== $incomingValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function conflictHash(array $snapshot, bool $includeFundingDetails = true): string
    {
        $canonical = [];
        foreach ([
            'exchange',
            'market_type',
            'symbol',
            'side',
            'fill_id',
            'exchange_fill_id',
            'exchange_order_id',
            'client_order_id',
            'fill_role',
            'liquidity_role',
            'price',
            'quantity',
            'notional',
            'fee_amount',
            'fee_currency',
            'fee_usdt',
            'funding_usdt',
            'funding_native_amount',
            'funding_currency',
            'funding_rate',
            'funding_rate_interval_seconds',
            'funding_applied_interval_seconds',
            'spread_cost_usdt',
            'slippage_cost_usdt',
            'borrow_cost_usdt',
            'liquidation_fee_usdt',
            'occurred_at',
        ] as $key) {
            if (!$includeFundingDetails && \in_array($key, self::FUNDING_DETAIL_CONFLICT_KEYS, true)) {
                continue;
            }
            $canonical[$key] = $snapshot[$key] ?? null;
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function payloadHashMatches(string $storedHash, string $currentHash, array $snapshot): bool
    {
        return $storedHash === $currentHash
            || $storedHash === $this->conflictHash($snapshot, includeFundingDetails: false);
    }

    private function deterministicFillId(ExchangeFillDto $fill): string
    {
        return 'fill-' . substr(hash('sha256', implode(':', [
            $fill->exchange->value,
            $fill->marketType->value,
            $fill->symbol,
            $fill->exchangeOrderId,
            $fill->clientOrderId ?? '',
            $fill->filledAt->format('U.u'),
            (string) $fill->quantity,
            (string) $fill->price,
            $fill->side->value,
            $fill->positionSide?->value ?? '',
        ])), 0, 48);
    }

    private function decimal(float $value): string
    {
        return number_format($value, 12, '.', '');
    }

    private function string(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
