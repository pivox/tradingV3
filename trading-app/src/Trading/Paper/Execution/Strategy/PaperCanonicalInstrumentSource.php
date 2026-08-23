<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Backtesting\NormalizedBacktestInstrumentMetadata;
use App\Trading\Paper\Backtesting\PaperBacktestDatasetAdapter;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidOrderNotionalLimits;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\Execution\Hyperliquid\HyperliquidPriceStep;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalInstrumentSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class PaperCanonicalInstrumentSource
{
    public function __construct(
        private PaperMarketStateProjector $market,
        private PaperReplayClock $clock,
        private PaperBacktestDatasetAdapter $adapter = new PaperBacktestDatasetAdapter(),
    ) {
    }

    public function snapshotFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
    ): ?CanonicalInstrumentSnapshot {
        $metadata = $this->metadataFor($cell, $trigger);

        return $metadata === null || !$this->isCompleteOkxV2($metadata)
            ? null
            : $this->instrumentFor(
                $metadata,
                new \DateTimeImmutable($metadata->happenedAt),
                (string) $metadata->maxLimitQuantity,
                (string) $metadata->maxMarketQuantity,
                'sha256:' . $metadata->sourceRecordId,
            );
    }

    public function evidenceFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        CanonicalOrderBookSnapshot $book,
    ): ?PaperCanonicalInstrumentEvidence {
        $metadata = $this->metadataFor($cell, $trigger);
        if ($metadata === null) {
            return null;
        }
        $this->assertBookIdentity($metadata, $book);
        $observedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
        if ($book->observedAt > $observedAt) {
            return null;
        }
        if ($this->isCompleteOkxV2($metadata)) {
            $tick = $metadata->priceTick;
            $maxQuantity = (string) $metadata->maxLimitQuantity;
            $marketMaxQuantity = (string) $metadata->maxMarketQuantity;
            $inputHash = 'sha256:' . $metadata->sourceRecordId;
        } elseif ($this->isCompleteHyperliquidV2($metadata)) {
            $referencePrice = BigDecimal::of((string) $book->bestAsk);
            $quantityStep = BigDecimal::of($metadata->quantityStep);
            $tick = $this->canonical(HyperliquidPriceStep::forPrice(
                $referencePrice,
                BigDecimal::of($metadata->priceTick),
            ));
            $maxQuantity = $this->maximumQuantity(
                (string) $metadata->maxLimitNotional,
                $referencePrice,
                $quantityStep,
            );
            $marketMaxQuantity = $this->maximumQuantity(
                (string) $metadata->maxMarketNotional,
                $referencePrice,
                $quantityStep,
            );
            $inputHash = 'sha256:' . hash('sha256', CanonicalJson::encode([
                'metadata_record_id' => $metadata->sourceRecordId,
                'book_input_hash' => $book->inputHash,
                'reference_price' => $this->canonical($referencePrice),
                'price_tick' => $tick,
                'maximum_limit_quantity' => $maxQuantity,
                'maximum_market_quantity' => $marketMaxQuantity,
            ]));
        } else {
            return null;
        }

        return new PaperCanonicalInstrumentEvidence(
            $this->instrumentFor(
                $metadata,
                $observedAt,
                $maxQuantity,
                $marketMaxQuantity,
                $inputHash,
            ),
            new CanonicalTickSnapshot(
                exchange: $metadata->marketDataVenue,
                environment: $metadata->sourceNetwork,
                symbol: $metadata->symbol,
                marketType: 'perpetual',
                tickSize: $this->finitePositive(BigDecimal::of($tick)),
                observedAt: $observedAt,
                inputHash: $inputHash,
            ),
        );
    }

    private function metadataFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
    ): ?NormalizedBacktestInstrumentMetadata {
        if (!$cell->isModern()) {
            throw new \LogicException('paper_canonical_strategy_cell_identity_missing');
        }
        if ($trigger->sourceNetwork !== $cell->network
            || $trigger->sourceVenue !== $cell->marketDataVenue
        ) {
            throw new \LogicException('paper_canonical_strategy_market_scope_mismatch');
        }

        $now = $this->clock->now();
        if ($trigger->exchangeTimestamp > $now || $trigger->receivedTimestamp > $now) {
            return null;
        }
        $projectedEvents = array_values(array_filter(
            $this->market->events(),
            static fn (PaperMarketEvent $event): bool =>
                $event->sourceNetwork === $cell->network
                && $event->sourceVenue === $cell->marketDataVenue
                && $event->symbol === $trigger->symbol,
        ));
        $latest = $projectedEvents === [] ? null : $projectedEvents[array_key_last($projectedEvents)];
        if (!$latest instanceof PaperMarketEvent
            || !hash_equals($latest->eventId, $trigger->eventId)
            || !hash_equals(
                CanonicalJson::encode($latest->toArray()),
                CanonicalJson::encode($trigger->toArray()),
            )
        ) {
            throw new \LogicException('paper_canonical_strategy_trigger_not_current');
        }

        $metadataEvents = array_values(array_filter(
            $projectedEvents,
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::INSTRUMENT_METADATA
                && $event->exchangeTimestamp <= $now
                && $event->receivedTimestamp <= $now,
        ));
        if ($metadataEvents === []) {
            return null;
        }

        $checksum = hash_init('sha256');
        foreach ($metadataEvents as $event) {
            hash_update($checksum, CanonicalJson::encode($event->toArray()) . "\n");
        }
        $normalized = $this->adapter->adaptInstrumentMetadataEvents(
            $metadataEvents,
            'sha256:' . hash_final($checksum),
        );
        $metadata = $normalized === [] ? null : $normalized[array_key_last($normalized)];
        if (!$metadata instanceof NormalizedBacktestInstrumentMetadata
            || $metadata->sourceNetwork !== $cell->network->value
            || $metadata->marketDataVenue !== $cell->marketDataVenue->value
            || $metadata->symbol !== $trigger->symbol
        ) {
            throw new \LogicException('paper_canonical_instrument_identity_mismatch');
        }
        return $metadata;
    }

    private function instrumentFor(
        NormalizedBacktestInstrumentMetadata $metadata,
        \DateTimeImmutable $observedAt,
        string $maxQuantityValue,
        string $marketMaxQuantityValue,
        string $inputHash,
    ): CanonicalInstrumentSnapshot {
        $contractSize = $this->finitePositive(
            BigDecimal::of($metadata->contractValue)->multipliedBy($metadata->contractMultiplier),
        );
        $quantityStep = $this->finitePositive(BigDecimal::of($metadata->quantityStep));
        $minQuantity = $this->finitePositive(BigDecimal::of($metadata->minQuantity));
        $maxQuantity = $this->finitePositive(BigDecimal::of($maxQuantityValue));
        $marketMaxQuantity = $this->finitePositive(BigDecimal::of($marketMaxQuantityValue));
        $leverageCap = $this->finitePositive(BigDecimal::of((string) $metadata->maxLeverage));
        if (BigDecimal::of($metadata->quantityStep)->getScale()
                > CanonicalRiskCalculationRequest::MAX_QUANTITY_DECIMALS
            || $quantityStep < CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP
            || $minQuantity < $quantityStep
            || $maxQuantity < $minQuantity
            || $marketMaxQuantity < $minQuantity
            || $leverageCap < 1.0
        ) {
            throw new \LogicException('paper_canonical_instrument_constraints_invalid');
        }

        return new CanonicalInstrumentSnapshot(
            exchange: $metadata->marketDataVenue,
            environment: $metadata->sourceNetwork,
            symbol: $metadata->symbol,
            marketType: 'perpetual',
            quoteCurrency: $metadata->quoteAsset,
            contractSize: $contractSize,
            quantityStep: $quantityStep,
            minQuantity: $minQuantity,
            maxQuantity: $maxQuantity,
            marketMaxQuantity: $marketMaxQuantity,
            exchangeLeverageCap: $leverageCap,
            symbolLeverageCap: null,
            observedAt: $observedAt,
            inputHash: $inputHash,
        );
    }

    private function isCompleteHyperliquidV2(NormalizedBacktestInstrumentMetadata $metadata): bool
    {
        return $metadata->marketDataVenue === PaperMarketDataVenue::HYPERLIQUID->value
            && $metadata->metadataSchemaVersion === 'paper-instrument-metadata.v2'
            && $metadata->quoteAsset === 'USDT'
            && $metadata->settlementAsset === 'USDC'
            && $metadata->quantityUnit === 'base_asset'
            && $metadata->maxMarketQuantity === null
            && $metadata->maxLimitQuantity === null
            && $metadata->maxMarketNotional !== null
            && $metadata->maxLimitNotional !== null
            && $metadata->orderNotionalLimitModel
                === HyperliquidOrderNotionalLimits::MODEL
            && $metadata->maxLeverage !== null;
    }

    private function assertBookIdentity(
        NormalizedBacktestInstrumentMetadata $metadata,
        CanonicalOrderBookSnapshot $book,
    ): void {
        if ($book->exchange !== $metadata->marketDataVenue
            || $book->environment !== $metadata->sourceNetwork
            || $book->symbol !== $metadata->symbol
            || $book->marketType !== 'perpetual'
            || $book->source !== 'order_book'
            || ($metadata->marketDataVenue === PaperMarketDataVenue::HYPERLIQUID->value
                && $book->sourceEpoch !== $metadata->sourceEpoch)
        ) {
            throw new \LogicException('paper_canonical_instrument_book_identity_mismatch');
        }
    }

    private function maximumQuantity(
        string $maximumNotional,
        BigDecimal $referencePrice,
        BigDecimal $quantityStep,
    ): string {
        $raw = BigDecimal::of($maximumNotional)->dividedBy(
            $referencePrice,
            24,
            RoundingMode::DOWN,
        );
        $quantity = $raw
            ->dividedBy($quantityStep, 0, RoundingMode::DOWN)
            ->multipliedBy($quantityStep);
        if (!$quantity->isPositive()) {
            throw new \LogicException('paper_canonical_instrument_constraints_invalid');
        }

        return $this->canonical($quantity);
    }

    private function canonical(BigDecimal $value): string
    {
        $canonical = $value->stripTrailingZeros();

        return $canonical->getScale() < 0
            ? (string) $canonical->toScale(0)
            : (string) $canonical;
    }

    private function isCompleteOkxV2(NormalizedBacktestInstrumentMetadata $metadata): bool
    {
        return $metadata->marketDataVenue === PaperMarketDataVenue::OKX->value
            && $metadata->metadataSchemaVersion === 'paper-instrument-metadata.v2'
            && $metadata->quoteAsset === 'USDT'
            && $metadata->settlementAsset === 'USDT'
            && $metadata->quantityUnit === 'contracts'
            && $metadata->maxMarketQuantity !== null
            && $metadata->maxLimitQuantity !== null
            && $metadata->maxLeverage !== null;
    }

    private function finitePositive(BigDecimal $value): float
    {
        $float = $value->toFloat();
        if (!is_finite($float) || $float <= 0.0) {
            throw new \LogicException('paper_canonical_instrument_constraints_invalid');
        }

        return $float;
    }
}
