<?php

declare(strict_types=1);

namespace App\MtfValidator\Entity;

use App\MtfValidator\Repository\MtfRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: MtfRunRepository::class)]
#[ORM\Table(name: 'mtf_run')]
#[ORM\Index(name: 'idx_mtf_run_started_at', columns: ['started_at'])]
#[ORM\Index(name: 'idx_mtf_run_status', columns: ['status'])]
class MtfRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidInterface $runId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $status = 'running';

    #[ORM\Column(type: Types::FLOAT)]
    private float $executionTimeSeconds = 0.0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $symbolsRequested = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $symbolsProcessed = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $symbolsSuccessful = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $symbolsFailed = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $symbolsSkipped = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $successRate = '0.00';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $dryRun = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $forceRun = false;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $currentTf = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $workers = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $optionsJson = null;

    public function __construct(UuidInterface $runId)
    {
        $this->runId = $runId;
        $this->startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getRunId(): UuidInterface { return $this->runId; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function setExecutionTimeSeconds(float $v): self { $this->executionTimeSeconds = $v; return $this; }
    public function setSymbolsRequested(int $v): self { $this->symbolsRequested = $v; return $this; }
    public function setSymbolsProcessed(int $v): self { $this->symbolsProcessed = $v; return $this; }
    public function setSymbolsSuccessful(int $v): self { $this->symbolsSuccessful = $v; return $this; }
    public function setSymbolsFailed(int $v): self { $this->symbolsFailed = $v; return $this; }
    public function setSymbolsSkipped(int $v): self { $this->symbolsSkipped = $v; return $this; }
    public function setSuccessRate(float $v): self { $this->successRate = number_format($v, 2, '.', ''); return $this; }
    public function setDryRun(bool $v): self { $this->dryRun = $v; return $this; }
    public function setForceRun(bool $v): self { $this->forceRun = $v; return $this; }
    public function setCurrentTf(?string $v): self { $this->currentTf = $v; return $this; }
    public function setFinishedAt(?\DateTimeImmutable $v): self { $this->finishedAt = $v; return $this; }
    public function setWorkers(?int $v): self { $this->workers = $v; return $this; }
    public function setUserId(?string $v): self { $this->userId = $v; return $this; }
    public function setIpAddress(?string $v): self { $this->ipAddress = $v; return $this; }
    public function setOptionsJson(?array $v): self { $this->optionsJson = $v; return $this; }

    public function getExecutionTimeSeconds(): float { return $this->executionTimeSeconds; }
    public function getSymbolsRequested(): int { return $this->symbolsRequested; }
    public function getSymbolsProcessed(): int { return $this->symbolsProcessed; }
    public function getSymbolsSuccessful(): int { return $this->symbolsSuccessful; }
    public function getSymbolsFailed(): int { return $this->symbolsFailed; }
    public function getSymbolsSkipped(): int { return $this->symbolsSkipped; }
    public function getSuccessRate(): float { return (float)$this->successRate; }
    public function isDryRun(): bool { return $this->dryRun; }
    public function isForceRun(): bool { return $this->forceRun; }
    public function getCurrentTf(): ?string { return $this->currentTf; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
}
