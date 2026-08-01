<?php

declare(strict_types=1);

namespace App\Entity;

use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance as ProvenanceEnvelope;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait PaperExecutionProvenance
{
    #[ORM\Column(name: 'paper_network', type: Types::STRING, length: 16, nullable: true)]
    private ?string $paperNetwork = null;

    #[ORM\Column(name: 'paper_execution_cell_id', type: Types::STRING, length: 71, nullable: true)]
    private ?string $paperExecutionCellId = null;

    #[ORM\Column(name: 'configuration_snapshot_id', type: Types::STRING, length: 71, nullable: true)]
    private ?string $configurationSnapshotId = null;

    #[ORM\Column(name: 'paper_eligibility', type: Types::STRING, length: 32, nullable: true)]
    private ?string $paperEligibility = null;

    public function getPaperNetwork(): ?string
    {
        return $this->paperNetwork;
    }

    public function setPaperNetwork(?string $paperNetwork): self
    {
        if ($paperNetwork !== null && !in_array($paperNetwork, ['mainnet', 'testnet'], true)) {
            throw new \InvalidArgumentException('paper_network_invalid');
        }

        $this->paperNetwork = $paperNetwork;

        return $this;
    }

    public function getPaperExecutionCellId(): ?string
    {
        return $this->paperExecutionCellId;
    }

    public function setPaperExecutionCellId(?string $paperExecutionCellId): self
    {
        self::assertPaperSha256Id($paperExecutionCellId, 'paper_execution_cell_id_invalid');
        $this->paperExecutionCellId = $paperExecutionCellId;

        return $this;
    }

    public function getConfigurationSnapshotId(): ?string
    {
        return $this->configurationSnapshotId;
    }

    public function setConfigurationSnapshotId(?string $configurationSnapshotId): self
    {
        self::assertPaperSha256Id($configurationSnapshotId, 'configuration_snapshot_id_invalid');
        $this->configurationSnapshotId = $configurationSnapshotId;

        return $this;
    }

    public function getPaperEligibility(): ?string
    {
        return $this->paperEligibility;
    }

    public function setPaperEligibility(?string $paperEligibility): self
    {
        if ($paperEligibility !== null && $paperEligibility !== 'reference_only') {
            throw new \InvalidArgumentException('paper_eligibility_invalid');
        }

        $this->paperEligibility = $paperEligibility;

        return $this;
    }

    public function applyPaperExecutionProvenance(array $provenance): static
    {
        $provenance = ProvenanceEnvelope::validate($provenance);
        $this
            ->setExchange($provenance['exchange'])
            ->setMarketDataVenue($provenance['market_data_venue'])
            ->setPaperNetwork($provenance['paper_network'])
            ->setPaperExecutionCellId($provenance['paper_execution_cell_id'])
            ->setConfigurationSnapshotId($provenance['configuration_snapshot_id'])
            ->setPaperEligibility($provenance['paper_eligibility']);

        return $this;
    }

    private static function assertPaperSha256Id(?string $id, string $reason): void
    {
        if ($id !== null && preg_match('/\Asha256:[a-f0-9]{64}\z/D', $id) !== 1) {
            throw new \InvalidArgumentException($reason);
        }
    }
}
