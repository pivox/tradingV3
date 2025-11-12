<?php

declare(strict_types=1);

namespace App\MtfValidator\Entity;

use App\MtfValidator\Repository\MtfRunMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MtfRunMetricRepository::class)]
#[ORM\Table(name: 'mtf_run_metric')]
#[ORM\Index(name: 'idx_mtf_run_metric_run_id', columns: ['run_id'])]
#[ORM\Index(name: 'idx_mtf_run_metric_cat_op', columns: ['category', 'operation'])]
#[ORM\Index(name: 'idx_mtf_run_metric_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_mtf_run_metric_timeframe', columns: ['timeframe'])]
class MtfRunMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MtfRun::class)]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private MtfRun $run;

    #[ORM\Column(type: Types::STRING)]
    private string $category;

    #[ORM\Column(type: Types::STRING)]
    private string $operation;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $symbol = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $timeframe = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $count = 0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $duration = 0.0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct(MtfRun $run, string $category, string $operation)
    {
        $this->run = $run;
        $this->category = $category;
        $this->operation = $operation;
        $this->recordedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function setSymbol(?string $symbol): self { $this->symbol = $symbol; return $this; }
    public function setTimeframe(?string $timeframe): self { $this->timeframe = $timeframe; return $this; }
    public function setCount(int $count): self { $this->count = $count; return $this; }
    public function setDuration(float $duration): self { $this->duration = $duration; return $this; }

    public function getCategory(): string { return $this->category; }
    public function getOperation(): string { return $this->operation; }
    public function getSymbol(): ?string { return $this->symbol; }
    public function getTimeframe(): ?string { return $this->timeframe; }
    public function getCount(): int { return $this->count; }
    public function getDuration(): float { return $this->duration; }
}
