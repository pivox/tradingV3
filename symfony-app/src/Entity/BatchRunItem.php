<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\BatchRunItemRepository::class)]
#[ORM\Table(name: 'batch_run_items')]
#[ORM\Index(name: 'idx_batch_symbol', columns: ['batch_run_id','symbol'])]
class BatchRunItem
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ENQUEUED = 'enqueued';
    public const STATUS_DONE     = 'done';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_SKIPPED  = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: BatchRun::class)]
    #[ORM\JoinColumn(name:'batch_run_id', referencedColumnName:'id', nullable:false, onDelete:'CASCADE')]
    private BatchRun $batchRun;

    #[ORM\Column(type:'string', length:50)]
    private string $symbol;

    #[ORM\Column(type:'string', length:12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type:'smallint', options:['unsigned'=>true])]
    private int $attempts = 0;

    #[ORM\Column(type:'string', length:255, nullable:true)]
    private ?string $lastError = null;

    #[ORM\Column(type:'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type:'datetime_immutable', nullable:true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(BatchRun $run, string $symbol)
    {
        $this->batchRun = $run;
        $this->symbol   = strtoupper($symbol);
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): int { return $this->id; }
    public function getBatchRun(): BatchRun { return $this->batchRun; }
    public function getSymbol(): string { return $this->symbol; }
    public function getStatus(): string { return $this->status; }

    public function markEnqueued(): void     { $this->status = self::STATUS_ENQUEUED; $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function markDone(): void         { $this->status = self::STATUS_DONE;     $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function markFailed(?string $e): void { $this->status = self::STATUS_FAILED; $this->lastError = $e; $this->attempts++; $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function markSkipped(?string $why = null): void { $this->status = self::STATUS_SKIPPED; $this->lastError = $why; $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
}
