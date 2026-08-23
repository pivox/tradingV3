<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Fake\FakeInstrument;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use Brick\Math\BigDecimal;

final readonly class PaperCanonicalFakeInstrumentDescriptor
{
    public const METADATA_KEY = 'paper_canonical_instrument_descriptor';

    private const SCHEMA = 'paper-canonical-fake-instrument.v2';
    private const LEGACY_SCHEMA = 'paper-canonical-fake-instrument.v1';

    private const PAYLOAD_FIELDS = [
        'schema',
        'paper_cell_id',
        'paper_network',
        'public_venue',
        'symbol',
        'market_type',
        'base_asset',
        'quote_asset',
        'settle_asset',
        'price_tick',
        'quantity_step',
        'min_quantity',
        'min_notional',
        'contract_size',
        'max_leverage',
        'maintenance_margin_rate',
        'allowed_order_types',
        'fixture_version',
        'precision_model_version',
    ];

    /** @param array<string, mixed> $payload */
    private function __construct(
        private array $payload,
        private string $descriptorHash,
        private FakeInstrument $instrument,
    ) {
    }

    public static function fromPlan(
        PaperExecutionCell $cell,
        CanonicalOrderPlan $plan,
        FakeInstrumentCatalog $fixtures = new FakeInstrumentCatalog(),
    ): self {
        try {
            $fixture = $fixtures->find($plan->symbol);
            $maxLeverage = (int) floor($plan->exchangeLeverageCap);
            $settleAsset = $cell->marketDataVenue === PaperMarketDataVenue::HYPERLIQUID
                ? 'USDC'
                : 'USDT';
            if (!$cell->isModern()
                || !hash_equals($plan->expectedPlanHash(), $plan->planHash)
                || $plan->exchange !== $cell->marketDataVenue->value
                || $plan->environment !== $cell->network->value
                || $plan->marketType !== MarketType::PERPETUAL->value
                || !$fixture instanceof FakeInstrument
                || $fixture->marketType !== MarketType::PERPETUAL
                || $fixture->quoteAsset !== $plan->quoteCurrency
                || !is_finite($plan->exchangeLeverageCap)
                || $maxLeverage < 1
                || $plan->finalLeverage > $maxLeverage
            ) {
                throw new \LogicException();
            }

            $payload = [
                'schema' => self::SCHEMA,
                'paper_cell_id' => $cell->id,
                'paper_network' => $cell->network->value,
                'public_venue' => $cell->marketDataVenue->value,
                'symbol' => $plan->symbol,
                'market_type' => $plan->marketType,
                'base_asset' => $fixture->baseAsset,
                'quote_asset' => $plan->quoteCurrency,
                'settle_asset' => $settleAsset,
                'price_tick' => self::decimal($plan->tickSize),
                'quantity_step' => self::decimal($plan->quantityStep),
                'min_quantity' => self::decimal($plan->minQuantity),
                'min_notional' => self::decimal($plan->exchangeMinNotional),
                'contract_size' => self::decimal($plan->contractSize),
                'max_leverage' => $maxLeverage,
                'maintenance_margin_rate' => self::canonicalDecimal($fixture->maintenanceMarginRate),
                'allowed_order_types' => array_map(
                    static fn (ExchangeOrderType $type): string => $type->value,
                    $fixture->allowedOrderTypes,
                ),
                'fixture_version' => $fixtures->metadataFixtureVersion(),
                'precision_model_version' => $fixtures->precisionModelVersion(),
            ];

            return self::create($payload, self::hash($payload), $fixtures);
        } catch (\Throwable $exception) {
            if ($exception instanceof \LogicException
                && $exception->getMessage() === 'paper_canonical_fake_instrument_descriptor_invalid'
            ) {
                throw $exception;
            }

            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid', 0, $exception);
        }
    }

    public static function decode(string $encoded): self
    {
        try {
            $document = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document) || array_is_list($document)) {
                throw new \LogicException();
            }
            $keys = array_keys($document);
            sort($keys, SORT_STRING);
            $expectedKeys = [...self::PAYLOAD_FIELDS, 'descriptor_hash'];
            sort($expectedKeys, SORT_STRING);
            if ($keys !== $expectedKeys || !is_string($document['descriptor_hash'])) {
                throw new \LogicException();
            }
            $descriptorHash = $document['descriptor_hash'];
            unset($document['descriptor_hash']);
            if (!hash_equals(self::hash($document), $descriptorHash)) {
                throw new \LogicException();
            }

            return self::create($document, $descriptorHash, new FakeInstrumentCatalog());
        } catch (\Throwable $exception) {
            if ($exception instanceof \LogicException
                && $exception->getMessage() === 'paper_canonical_fake_instrument_descriptor_invalid'
            ) {
                throw $exception;
            }

            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid', 0, $exception);
        }
    }

    public function encoded(): string
    {
        return CanonicalJson::encode($this->payload + ['descriptor_hash' => $this->descriptorHash]);
    }

    public function instrument(): FakeInstrument
    {
        return $this->instrument;
    }

    public function identityHash(): string
    {
        return $this->descriptorHash;
    }

    public function cellId(): string
    {
        return $this->string('paper_cell_id');
    }

    public function symbol(): string
    {
        return $this->instrument->symbol;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function create(
        array $payload,
        string $descriptorHash,
        FakeInstrumentCatalog $fixtures,
    ): self {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $expectedKeys = self::PAYLOAD_FIELDS;
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || !\in_array($payload['schema'] ?? null, [self::LEGACY_SCHEMA, self::SCHEMA], true)
            || !is_string($payload['paper_cell_id'] ?? null)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $payload['paper_cell_id']) !== 1
            || !is_string($payload['paper_network'] ?? null)
            || PaperMarketDataNetwork::tryFrom($payload['paper_network']) === null
            || !is_string($payload['public_venue'] ?? null)
            || PaperMarketDataVenue::tryFrom($payload['public_venue']) === null
            || !is_string($payload['symbol'] ?? null)
            || !is_string($payload['market_type'] ?? null)
            || $payload['market_type'] !== MarketType::PERPETUAL->value
            || !is_string($payload['base_asset'] ?? null)
            || !is_string($payload['quote_asset'] ?? null)
            || !is_string($payload['settle_asset'] ?? null)
            || !is_int($payload['max_leverage'] ?? null)
            || $payload['max_leverage'] < 1
            || !is_array($payload['allowed_order_types'] ?? null)
            || !array_is_list($payload['allowed_order_types'])
            || ($payload['fixture_version'] ?? null) !== $fixtures->metadataFixtureVersion()
            || ($payload['precision_model_version'] ?? null) !== $fixtures->precisionModelVersion()
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $descriptorHash) !== 1
        ) {
            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
        }

        $fixture = $fixtures->find($payload['symbol']);
        $expectedSettleAsset = $payload['schema'] === self::LEGACY_SCHEMA
            ? $fixture?->settleAsset
            : ($payload['public_venue'] === PaperMarketDataVenue::HYPERLIQUID->value
                ? 'USDC'
                : 'USDT');
        if (!$fixture instanceof FakeInstrument
            || $payload['base_asset'] !== $fixture->baseAsset
            || $payload['quote_asset'] !== $fixture->quoteAsset
            || $payload['settle_asset'] !== $expectedSettleAsset
            || ($payload['maintenance_margin_rate'] ?? null)
                !== self::canonicalDecimal($fixture->maintenanceMarginRate)
        ) {
            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
        }

        $allowedOrderTypes = [];
        foreach ($payload['allowed_order_types'] as $value) {
            if (!is_string($value) || !($type = ExchangeOrderType::tryFrom($value)) instanceof ExchangeOrderType) {
                throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
            }
            $allowedOrderTypes[] = $type;
        }
        if ($allowedOrderTypes !== $fixture->allowedOrderTypes) {
            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
        }

        $instrument = new FakeInstrument(
            symbol: $payload['symbol'],
            marketType: MarketType::PERPETUAL,
            baseAsset: $payload['base_asset'],
            quoteAsset: $payload['quote_asset'],
            settleAsset: $payload['settle_asset'],
            priceTick: self::fieldDecimal($payload, 'price_tick'),
            quantityStep: self::fieldDecimal($payload, 'quantity_step'),
            minQuantity: self::fieldDecimal($payload, 'min_quantity'),
            minNotional: self::fieldDecimal($payload, 'min_notional'),
            contractSize: self::fieldDecimal($payload, 'contract_size'),
            maxLeverage: $payload['max_leverage'],
            maintenanceMarginRate: self::fieldDecimal($payload, 'maintenance_margin_rate'),
            allowedOrderTypes: $allowedOrderTypes,
        );

        return new self($payload, $descriptorHash, $instrument);
    }

    private static function decimal(float $value): string
    {
        $decimal = CanonicalOrderPlanDecimal::fromFloat(
            $value,
            'paper_canonical_fake_instrument_descriptor_invalid',
        );

        return self::normalizedDecimal($decimal);
    }

    /** @param array<string, mixed> $payload */
    private static function fieldDecimal(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
        }

        return self::canonicalDecimal($value);
    }

    private static function canonicalDecimal(string $value): string
    {
        $decimal = BigDecimal::of($value);
        if (!$decimal->isPositive()) {
            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
        }
        $encoded = self::normalizedDecimal($decimal);
        if (!hash_equals($encoded, $value)) {
            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
        }

        return $encoded;
    }

    private static function normalizedDecimal(BigDecimal $decimal): string
    {
        $canonical = $decimal->stripTrailingZeros();

        return $canonical->getScale() < 0
            ? (string) $canonical->toScale(0)
            : (string) $canonical;
    }

    /** @param array<string, mixed> $payload */
    private static function hash(array $payload): string
    {
        return 'sha256:' . hash('sha256', CanonicalJson::encode($payload));
    }

    private function string(string $field): string
    {
        $value = $this->payload[$field] ?? null;
        if (!is_string($value)) {
            throw new \LogicException('paper_canonical_fake_instrument_descriptor_invalid');
        }

        return $value;
    }
}
