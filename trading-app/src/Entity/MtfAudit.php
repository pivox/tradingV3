<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MtfAuditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: MtfAuditRepository::class)]
#[ORM\Table(name: 'mtf_audit')]
#[ORM\Index(name: 'idx_mtf_audit_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_mtf_audit_run_id', columns: ['run_id'])]
#[ORM\Index(name: 'idx_mtf_audit_created_at', columns: ['created_at'])]
class MtfAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::GUID)]
    private UuidInterface $runId;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $step;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: \App\Domain\Common\Enum\Timeframe::class, nullable: true)]
    private ?\App\Domain\Common\Enum\Timeframe $timeframe = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cause = null;

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $details = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true, options: ['comment' => 'Heure de clôture de la bougie concernée'])]
    private ?\DateTimeImmutable $candleCloseTs = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0, 'comment' => 'Niveau de sévérité 0..n'])]
    private int $severity = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getRunId(): UuidInterface
    {
        return $this->runId;
    }

    public function setRunId(UuidInterface $runId): static
    {
        $this->runId = $runId;
        return $this;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function setStep(string $step): static
    {
        $this->step = $step;
        return $this;
    }

    public function getTimeframe(): ?\App\Domain\Common\Enum\Timeframe
    {
        return $this->timeframe;
    }

    public function setTimeframe(?\App\Domain\Common\Enum\Timeframe $timeframe): static
    {
        $this->timeframe = $timeframe;
        return $this;
    }

    public function getCause(): ?string
    {
        return $this->cause;
    }

    public function setCause(?string $cause): static
    {
        $this->cause = $cause;
        return $this;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function setDetails(array $details): static
    {
        $this->details = $details;
        return $this;
    }

    public function getDetailValue(string $key): mixed
    {
        return $this->details[$key] ?? null;
    }

    public function setDetailValue(string $key, mixed $value): static
    {
        $this->details[$key] = $value;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCandleCloseTs(): ?\DateTimeImmutable
    {
        return $this->candleCloseTs;
    }

    public function setCandleCloseTs(?\DateTimeImmutable $ts): static
    {
        $this->candleCloseTs = $ts;
        return $this;
    }

    public function getSeverity(): int
    {
        return $this->severity;
    }

    public function setSeverity(int $severity): static
    {
        $this->severity = $severity;
        return $this;
    }
}




