<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;

final class PaperPreparedEffectCodec
{
    private const SCHEMA_VERSION = 2;
    private const ENVELOPE_KEYS = ['schema_version', 'payload', 'payload_checksum'];
    private const PAYLOAD_KEYS = [
        'plan',
        'decision_key',
        'internal_trade_id',
        'execution_timeframe',
        'profile',
        'lifecycle',
        'order_intent_identity',
        'cell_provenance',
    ];
    private const PLAN_KEYS = [
        'symbol', 'side', 'order_type', 'open_type', 'order_mode', 'entry', 'stop',
        'take_profit', 'size', 'leverage', 'price_precision', 'contract_size',
        'entry_zone_low', 'entry_zone_high', 'zone_expires_at', 'entry_zone_meta',
        'stop_atr', 'stop_risk', 'stop_pivot', 'stop_final_source', 'exchange_context',
    ];

    /**
     * @param array<string, mixed> $orderIntentIdentity
     * @param array<string, mixed> $provenance
     * @return array{schema_version: int, payload: array<string, mixed>, payload_checksum: string}
     */
    public function encode(PreparedTradeEntry $prepared, array $orderIntentIdentity, array $provenance): array
    {
        try {
            if (!$prepared->plan instanceof OrderPlanModel || array_keys($orderIntentIdentity) !== ['client_order_id', 'order_intent_id']) {
                throw new \InvalidArgumentException();
            }
            $clientOrderId = $orderIntentIdentity['client_order_id'];
            if (!is_string($clientOrderId) || $clientOrderId === '') {
                throw new \InvalidArgumentException();
            }
            if (!is_int($orderIntentIdentity['order_intent_id']) || $orderIntentIdentity['order_intent_id'] < 1) {
                throw new \InvalidArgumentException();
            }

            $validatedProvenance = PaperExecutionProvenance::validate($provenance);
            $lifecycle = $prepared->lifecycle->toArray();
            PaperMarketEventRedactor::assertSafe($lifecycle);
            PaperMarketEventRedactor::assertSafe($orderIntentIdentity);

            $payload = [
                'plan' => $this->encodePlan($prepared->plan),
                'decision_key' => $prepared->decisionKey,
                'internal_trade_id' => $prepared->internalTradeId,
                'execution_timeframe' => $prepared->executionTimeframe,
                'profile' => $prepared->mode,
                'lifecycle' => $lifecycle,
                'order_intent_identity' => $orderIntentIdentity,
                'cell_provenance' => $validatedProvenance,
            ];

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'payload' => $payload,
                'payload_checksum' => hash('sha256', CanonicalJson::encode($payload)),
            ];
        } catch (\Throwable) {
            throw new \InvalidArgumentException('paper_prepared_effect_payload_invalid');
        }
    }

    /** @param array<string, mixed> $encoded */
    public function decode(array $encoded): PaperPreparedDecision
    {
        try {
            $this->assertKeys($encoded, self::ENVELOPE_KEYS);
            if ($encoded['schema_version'] !== self::SCHEMA_VERSION
                || !is_array($encoded['payload'])
                || !is_string($encoded['payload_checksum'])
                || !preg_match('/\A[a-f0-9]{64}\z/D', $encoded['payload_checksum'])
            ) {
                throw new \InvalidArgumentException();
            }
            $payload = $encoded['payload'];
            $this->assertKeys($payload, self::PAYLOAD_KEYS);
            if (!hash_equals(hash('sha256', CanonicalJson::encode($payload)), $encoded['payload_checksum'])) {
                throw new \InvalidArgumentException();
            }
            if (!is_array($payload['plan']) || !is_array($payload['lifecycle'])
                || !is_array($payload['order_intent_identity']) || !is_array($payload['cell_provenance'])
            ) {
                throw new \InvalidArgumentException();
            }
            foreach (['decision_key', 'internal_trade_id', 'execution_timeframe', 'profile'] as $key) {
                if (!is_string($payload[$key]) || $payload[$key] === '') {
                    throw new \InvalidArgumentException();
                }
            }
            $this->assertKeys($payload['order_intent_identity'], ['client_order_id', 'order_intent_id']);
            if (!is_string($payload['order_intent_identity']['client_order_id']) || $payload['order_intent_identity']['client_order_id'] === '') {
                throw new \InvalidArgumentException();
            }
            if (!is_int($payload['order_intent_identity']['order_intent_id']) || $payload['order_intent_identity']['order_intent_id'] < 1) {
                throw new \InvalidArgumentException();
            }
            PaperMarketEventRedactor::assertSafe($payload['lifecycle']);
            PaperMarketEventRedactor::assertSafe($payload['order_intent_identity']);
            $provenance = PaperExecutionProvenance::validate($payload['cell_provenance']);

            $symbol = $payload['lifecycle']['symbol'] ?? null;
            if (!is_string($symbol) || $symbol === '') {
                throw new \InvalidArgumentException();
            }
            $lifecycle = (new LifecycleContextBuilder($symbol))->merge($payload['lifecycle']);
            $prepared = new PreparedTradeEntry(
                $this->decodePlan($payload['plan']),
                null,
                $payload['decision_key'],
                $payload['internal_trade_id'],
                $lifecycle,
                $payload['profile'],
                $payload['execution_timeframe'],
            );

            /** @var array{client_order_id: string, order_intent_id: int} $identity */
            $identity = $payload['order_intent_identity'];

            return new PaperPreparedDecision($prepared, $identity, $provenance);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('paper_prepared_effect_payload_invalid');
        }
    }

    /** @return array<string, mixed> */
    private function encodePlan(OrderPlanModel $plan): array
    {
        return [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'order_type' => $plan->orderType,
            'open_type' => $plan->openType,
            'order_mode' => $plan->orderMode,
            'entry' => $plan->entry,
            'stop' => $plan->stop,
            'take_profit' => $plan->takeProfit,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'price_precision' => $plan->pricePrecision,
            'contract_size' => $plan->contractSize,
            'entry_zone_low' => $plan->entryZoneLow,
            'entry_zone_high' => $plan->entryZoneHigh,
            'zone_expires_at' => $plan->zoneExpiresAt?->format(DATE_ATOM),
            'entry_zone_meta' => $plan->entryZoneMeta,
            'stop_atr' => $plan->stopAtr,
            'stop_risk' => $plan->stopRisk,
            'stop_pivot' => $plan->stopPivot,
            'stop_final_source' => $plan->stopFinalSource,
            'exchange_context' => $plan->exchangeContext === null ? null : [
                'exchange' => $plan->exchangeContext->exchange->value,
                'market_type' => $plan->exchangeContext->marketType->value,
            ],
        ];
    }

    /** @param array<string, mixed> $plan */
    private function decodePlan(array $plan): OrderPlanModel
    {
        $this->assertKeys($plan, self::PLAN_KEYS);
        foreach (['symbol', 'side', 'order_type', 'open_type'] as $key) {
            if (!is_string($plan[$key]) || $plan[$key] === '') {
                throw new \InvalidArgumentException();
            }
        }
        foreach (['order_mode', 'size', 'leverage', 'price_precision'] as $key) {
            if (!is_int($plan[$key])) {
                throw new \InvalidArgumentException();
            }
        }
        foreach (['entry', 'stop', 'take_profit', 'contract_size'] as $key) {
            if (!is_float($plan[$key]) && !is_int($plan[$key])) {
                throw new \InvalidArgumentException();
            }
        }
        foreach (['entry_zone_low', 'entry_zone_high', 'stop_atr', 'stop_risk', 'stop_pivot'] as $key) {
            if ($plan[$key] !== null && !is_float($plan[$key]) && !is_int($plan[$key])) {
                throw new \InvalidArgumentException();
            }
        }
        if ($plan['entry_zone_meta'] !== null && !is_array($plan['entry_zone_meta'])) {
            throw new \InvalidArgumentException();
        }
        if ($plan['stop_final_source'] !== null && !is_string($plan['stop_final_source'])) {
            throw new \InvalidArgumentException();
        }
        $zoneExpiresAt = null;
        if ($plan['zone_expires_at'] !== null) {
            if (!is_string($plan['zone_expires_at'])) {
                throw new \InvalidArgumentException();
            }
            $zoneExpiresAt = new \DateTimeImmutable($plan['zone_expires_at']);
        }
        $exchangeContext = null;
        if ($plan['exchange_context'] !== null) {
            if (!is_array($plan['exchange_context']) || array_keys($plan['exchange_context']) !== ['exchange', 'market_type']
                || !is_string($plan['exchange_context']['exchange']) || !is_string($plan['exchange_context']['market_type'])
            ) {
                throw new \InvalidArgumentException();
            }
            $exchangeContext = new ExchangeContext(
                Exchange::from($plan['exchange_context']['exchange']),
                MarketType::from($plan['exchange_context']['market_type']),
            );
        }

        return new OrderPlanModel(
            $plan['symbol'], Side::from($plan['side']), $plan['order_type'], $plan['open_type'], $plan['order_mode'],
            (float) $plan['entry'], (float) $plan['stop'], (float) $plan['take_profit'], $plan['size'],
            $plan['leverage'], $plan['price_precision'], (float) $plan['contract_size'],
            $plan['entry_zone_low'] === null ? null : (float) $plan['entry_zone_low'],
            $plan['entry_zone_high'] === null ? null : (float) $plan['entry_zone_high'],
            $zoneExpiresAt, $plan['entry_zone_meta'],
            $plan['stop_atr'] === null ? null : (float) $plan['stop_atr'],
            $plan['stop_risk'] === null ? null : (float) $plan['stop_risk'],
            $plan['stop_pivot'] === null ? null : (float) $plan['stop_pivot'],
            $plan['stop_final_source'], $exchangeContext,
        );
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $keys
     */
    private function assertKeys(array $value, array $keys): void
    {
        if (array_keys($value) !== $keys) {
            throw new \InvalidArgumentException();
        }
    }
}
