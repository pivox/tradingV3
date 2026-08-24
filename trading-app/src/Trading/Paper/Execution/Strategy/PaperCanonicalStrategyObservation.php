<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;

final readonly class PaperCanonicalStrategyObservation
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public string $cellId,
        public string $sourceEventId,
        public string $status,
        public string $reasonCode,
        private array $payload,
    ) {
    }

    public static function fromPreparation(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        PaperCanonicalStrategyPreparationResult $result,
    ): self {
        $identity = $cell->modernIdentity
            ?? throw new \LogicException('paper_strategy_observation_cell_identity_missing');
        if ($event->sourceNetwork !== $cell->network || $event->sourceVenue !== $cell->marketDataVenue) {
            throw new \LogicException('paper_strategy_observation_market_scope_mismatch');
        }
        $payload = [
            'schema_version' => 'paper-strategy-observation.v1',
            'status' => $result->status,
            'reason_code' => $result->reasonCode,
            'source_event_id' => $event->eventId,
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'side' => $identity->side,
            'config_hash' => $identity->configHash,
            'condition_catalog_hash' => $identity->conditionCatalogHash,
        ];
        PaperMarketEventRedactor::assertSafe($payload);

        return new self($cell->id, $event->eventId, $result->status, $result->reasonCode, $payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
