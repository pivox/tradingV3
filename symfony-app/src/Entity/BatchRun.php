<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\BatchRunRepository::class)]
#[ORM\Table(name: 'batch_runs')]
#[ORM\UniqueConstraint(name: 'uniq_tf_slot', columns: ['timeframe', 'slot_start_utc'])]
#[ORM\Index(name: 'idx_tf_status', columns: ['timeframe', 'status'])]
class BatchRun
{
    public const STATUS_CREATED = 'created';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:'integer')]
    private int $id;

    #[ORM\Column(type:'string', length:10)]
    private string $timeframe; // ex: '4h','1h','15m','5m','1m'

    #[ORM\Column(name:'slot_start_utc', type:'datetime_immutable')]
    private \DateTimeImmutable $slotStartUtc;

    #[ORM\Column(name:'slot_end_utc', type:'datetime_immutable')]
    private \DateTimeImmutable $slotEndUtc;

    #[ORM\Column(type:'string', length:16)]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column(type:'boolean', options: ['default' => false])]
    private bool $snapshotDone = false;

    #[ORM\Column(type:'string', length:16, nullable:true)]
    private ?string $snapshotSource = null; // 'bitmart'|'prev_tf'|'cache'

    // Compteurs
    #[ORM\Column(type:'integer', options:['unsigned'=>true])]
    private int $totalPlanned = 0;

    #[ORM\Column(type:'integer', options:['unsigned'=>true])]
    private int $remaining = 0;

    #[ORM\Column(type:'integer', options:['unsigned'=>true])]
    private int $totalEnqueued = 0;

    #[ORM\Column(type:'integer', options:['unsigned'=>true])]
    private int $totalCompleted = 0;

    #[ORM\Column(type:'integer', options:['unsigned'=>true])]
    private int $totalFailed = 0;

    #[ORM\Column(type:'integer', options:['unsigned'=>true])]
    private int $totalSkipped = 0;

    #[ORM\Column(type:'json', nullable:true)]
    private ?array $meta = null;

    #[ORM\Column(type:'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type:'datetime_immutable', nullable:true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type:'datetime_immutable', nullable:true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Version]
    #[ORM\Column(type:'integer')]
    private int $version = 1;

    public function __construct(string $timeframe, \DateTimeImmutable $slotStartUtc, \DateTimeImmutable $slotEndUtc)
    {
        $this->timeframe = $timeframe;
        $this->slotStartUtc = $slotStartUtc;
        $this->slotEndUtc   = $slotEndUtc;
        $this->createdAt    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // --- Getters/Setters utiles (seulement l'essentiel) ---
    public function getId(): int { return $this->id; }
    public function getTimeframe(): string { return $this->timeframe; }
    public function getSlotStartUtc(): \DateTimeImmutable { return $this->slotStartUtc; }
    public function getSlotEndUtc(): \DateTimeImmutable { return $this->slotEndUtc; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): void { $this->status = $s; }
    public function isSnapshotDone(): bool { return $this->snapshotDone; }
    public function markSnapshotDone(string $source, int $total): void
    {
        $this->snapshotDone = true;
        $this->snapshotSource = $source;
        $this->totalPlanned = $total;
        $this->remaining = $total;
    }
    public function markRunning(): void { $this->status = self::STATUS_RUNNING; $this->startedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function markSuccess(): void { $this->status = self::STATUS_SUCCESS; $this->endedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function markFailed(string $reason = null): void
    {
        $this->status = self::STATUS_FAILED;
        $this->endedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($reason) { $this->meta = array_merge($this->meta ?? [], ['failed_reason' => $reason]); }
    }

    public function decRemaining(): void { if ($this->remaining > 0) $this->remaining--; }
    public function incEnqueued(): void { $this->totalEnqueued++; }
    public function incCompleted(): void { $this->totalCompleted++; }
    public function incFailed(): void { $this->totalFailed++; }
    public function incSkipped(): void { $this->totalSkipped++; }

    public function getRemaining(): int { return $this->remaining; }


    // === Décrément avec garde ===

    // (optionnel) si tu veux exposer un setter protégé
    protected function setRemaining(int $value): void
    {
        $this->remaining = max(0, $value);
    }
}
