<?php

namespace App\Entity;

use App\Repository\ContractPipelineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContractPipelineRepository::class)]
#[ORM\Table(name: 'contract_pipeline')]
#[ORM\UniqueConstraint(name: 'uniq_pipeline_contract', columns: ['contract_symbol'])]
#[ORM\HasLifecycleCallbacks]
class ContractPipeline
{
    public const TF_4H  = '4h';
    public const TF_1H  = '1h';
    public const TF_15M = '15m';
    public const TF_5M  = '5m';
    public const TF_1M  = '1m';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_BACK      = 'back_to_parent';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * FK vers Contract(symbol) — Contract a une PK string 'symbol'
     */
    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(
        name: 'contract_symbol',
        referencedColumnName: 'symbol',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private Contract $contract;

    #[ORM\Column(type: 'string', length: 10)]
    private string $currentTimeframe = self::TF_4H;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retries = 0;

    #[ORM\Column(type: 'integer')]
    private int $maxRetries = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touchUpdatedAt(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    // ---------- Helpers métier (facultatifs) ----------

    public function markAttempt(): self
    {
        $this->lastAttemptAt = new \DateTimeImmutable();
        return $this->touchUpdatedAt();
    }

    public function resetRetries(): self
    {
        $this->retries = 0;
        return $this->touchUpdatedAt();
    }

    public function incRetries(): self
    {
        $this->retries++;
        return $this->touchUpdatedAt();
    }

    public function promoteTo(string $nextTf, int $maxRetries): self
    {
        $this->currentTimeframe = $nextTf;
        $this->maxRetries       = $maxRetries;
        $this->status           = self::STATUS_PENDING;
        return $this->resetRetries();
    }

    public function demoteTo(string $parentTf): self
    {
        $this->currentTimeframe = $parentTf;
        $this->status           = self::STATUS_PENDING;
        return $this->resetRetries();
    }

    // ---------- Getters / Setters ----------

    public function getId(): ?int { return $this->id; }

    public function getContract(): Contract { return $this->contract; }
    public function setContract(Contract $contract): self { $this->contract = $contract; return $this; }

    public function getCurrentTimeframe(): string { return $this->currentTimeframe; }
    public function setCurrentTimeframe(string $tf): self { $this->currentTimeframe = $tf; return $this; }

    public function getRetries(): int { return $this->retries; }
    public function setRetries(int $r): self { $this->retries = $r; return $this; }

    public function getMaxRetries(): int { return $this->maxRetries; }
    public function setMaxRetries(int $m): self { $this->maxRetries = $m; return $this; }

    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function setLastAttemptAt(?\DateTimeImmutable $dt): self { $this->lastAttemptAt = $dt; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }
}
