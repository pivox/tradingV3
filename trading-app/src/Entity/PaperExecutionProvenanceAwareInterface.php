<?php

declare(strict_types=1);

namespace App\Entity;

interface PaperExecutionProvenanceAwareInterface
{
    public function getExchange(): string;

    public function getMarketDataVenue(): ?string;

    public function getPaperNetwork(): ?string;

    public function getPaperExecutionCellId(): ?string;

    public function getConfigurationSnapshotId(): ?string;

    public function getPaperEligibility(): ?string;

    /** @param array<string, mixed> $provenance */
    public function applyPaperExecutionProvenance(array $provenance): static;
}
