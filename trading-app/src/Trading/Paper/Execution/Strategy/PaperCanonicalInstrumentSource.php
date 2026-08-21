<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Backtesting\NormalizedBacktestInstrumentMetadata;
use App\Trading\Paper\Backtesting\PaperBacktestDatasetAdapter;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\Risk\Canonical\CanonicalInstrumentSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use Brick\Math\BigDecimal;

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

        return $metadata === null
            ? null
            : $this->instrumentFor($metadata, new \DateTimeImmutable($metadata->happenedAt));
    }

    public function evidenceFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
    ): ?PaperCanonicalInstrumentEvidence {
        $metadata = $this->metadataFor($cell, $trigger);
        if ($metadata === null) {
            return null;
        }
        $observedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
        $inputHash = 'sha256:' . $metadata->sourceRecordId;

        return new PaperCanonicalInstrumentEvidence(
            $this->instrumentFor($metadata, $observedAt),
            new CanonicalTickSnapshot(
                exchange: $metadata->marketDataVenue,
                environment: $metadata->sourceNetwork,
                symbol: $metadata->symbol,
                marketType: 'perpetual',
                tickSize: $this->finitePositive(BigDecimal::of($metadata->priceTick)),
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
        if (!$this->isCompleteOkxV2($metadata)) {
            return null;
        }

        return $metadata;
    }

    private function instrumentFor(
        NormalizedBacktestInstrumentMetadata $metadata,
        \DateTimeImmutable $observedAt,
    ): CanonicalInstrumentSnapshot {
        $contractSize = $this->finitePositive(
            BigDecimal::of($metadata->contractValue)->multipliedBy($metadata->contractMultiplier),
        );
        $quantityStep = $this->finitePositive(BigDecimal::of($metadata->quantityStep));
        $minQuantity = $this->finitePositive(BigDecimal::of($metadata->minQuantity));
        $maxQuantity = $this->finitePositive(BigDecimal::of((string) $metadata->maxLimitQuantity));
        $marketMaxQuantity = $this->finitePositive(BigDecimal::of((string) $metadata->maxMarketQuantity));
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

        $inputHash = 'sha256:' . $metadata->sourceRecordId;

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
