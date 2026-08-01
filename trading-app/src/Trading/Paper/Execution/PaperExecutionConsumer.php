<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution;

use App\Trading\Paper\Dataset\PaperLiveEventConsumerInterface;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class PaperExecutionConsumer implements PaperLiveEventConsumerInterface
{
    private ?string $datasetId = null;

    public function __construct(
        private readonly PaperEventCoordinatorInterface $coordinator,
        private readonly PaperExecutionStoreInterface $store,
        private readonly PaperExecutionCell $cell,
        private readonly PaperProfileEligibility $eligibility,
    ) {
    }

    public function consume(string $datasetId, PaperMarketEvent $event): void
    {
        $this->assertDataset($datasetId);
        $position = $this->store->checkpoint($this->cell)->nextSourcePosition;
        $this->coordinator->consumeAt($this->cell, $this->eligibility, $datasetId, $position, $event);
    }

    public function consumeReplay(string $datasetId, int $sourcePosition, PaperMarketEvent $event): void
    {
        $this->assertDataset($datasetId);
        if ($sourcePosition < 0) {
            throw new \InvalidArgumentException('paper_execution_source_position_invalid');
        }
        $this->coordinator->consumeAt($this->cell, $this->eligibility, $datasetId, $sourcePosition, $event);
    }

    private function assertDataset(string $datasetId): void
    {
        if ($this->datasetId !== null && !hash_equals($this->datasetId, $datasetId)) {
            throw new \LogicException('paper_execution_dataset_mismatch');
        }
        $this->datasetId = $datasetId;
    }
}
