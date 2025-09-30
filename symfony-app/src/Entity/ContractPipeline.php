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

    public const STATUS_OPENED_LOCKED = 'OPENED_LOCKED';
    public const STATUS_ORDER_OPENED = 'ORDER_OPENED';



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

    /**
     * Stocke les signaux multi-timeframes au format JSON
     *
     * Exemple :
     * {
     *   "4h": {
     *     "ema50": 0.0218,
     *     "ema200": 0.0216,
     *     "adx14": 45.47,
     *     "ichimoku": {
     *       "tenkan": 0.02,
     *       "kijun": 0.02,
     *       "senkou_a": 0.02,
     *       "senkou_b": 0.02
     *     },
     *     "signal": "LONG"
     *   },
     *   "1h": { ... }
     * }
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $signals = null;

    #[ORM\ManyToOne(targetEntity: Kline::class)]
    #[ORM\JoinColumn(name: 'from_kline_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Kline $fromKline = null;

    #[ORM\ManyToOne(targetEntity: Kline::class)]
    #[ORM\JoinColumn(name: 'to_kline_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Kline $toKline = null;

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
    public function setCurrentTimeframe(string $tf): self {
        $this->currentTimeframe = $tf;
        $this->maxRetries = match ($this->currentTimeframe) {
            self::TF_4H => 1,
            self::TF_15M, self::TF_1H => 3,
            self::TF_5M => 2,
            self::TF_1M => 4,
            default => 0,
        };
        return $this;
    }

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

    public function getSignals(): ?array { return $this->signals; }
    public function setSignals(?array $signals): self { $this->signals = $signals; return $this; }

    public function getSignalLongOrShortOrNone(): string
    {
        $signals = [];
        foreach ($this->signals as $signal) {
            $signals[] = $signal['signal'];
        }
        $signals = array_unique($signals);
        if (count($signals) === 1 && $signals[0] !== 'NONE') {
            return $signals[0];
        }
        return 'NONE';
    }

    public function isValid(): bool
    {
        return $this->getSignalLongOrShortOrNone() != 'NONE';
    }
    /**
     * Ajoute ou met à jour les signaux d'un timeframe en fusionnant récursivement.
     * Exemple d'appel : $pipeline->addOrMergeSignal('4h', ['ema50'=>..., 'ichimoku'=>['tenkan'=>...], 'signal'=>'LONG']);
     */
    public function addOrMergeSignal(string $tf, array $data): self
    {
        $signals = $this->signals ?? [];

        // Conteneur TF existant (ou nouveau)
        $current = $signals[$tf] ?? [];

        // Merge récursif (les nouvelles valeurs écrasent les anciennes clé par clé)
        $merged = $this->deepMerge($current, $data);

        $signals[$tf] = $merged;
        $this->signals = $signals;

        return $this->touchUpdatedAt();
    }

    /**
     * Merge récursif "overwrite" (les valeurs de $new priment sur $base).
     * - Arrays associatifs : fusion clé par clé
     * - Arrays indexés : remplacement complet (comportement généralement souhaité pour des listes)
     */
    private function deepMerge(array $base, array $new): array
    {
        foreach ($new as $key => $val) {
            if (array_key_exists($key, $base)) {
                if (is_array($base[$key]) && is_array($val)) {
                    // Détecter "assoc" vs "indexé"
                    $isAssoc = static function (array $a): bool {
                        return array_keys($a) !== range(0, count($a) - 1);
                    };
                    if ($isAssoc($base[$key]) && $isAssoc($val)) {
                        $base[$key] = $this->deepMerge($base[$key], $val);
                    } else {
                        // listes : on remplace entièrement
                        $base[$key] = $val;
                    }
                } else {
                    $base[$key] = $val;
                }
            } else {
                $base[$key] = $val;
            }
        }
        return $base;
    }

    // ---- Getters/Setters ----
    public function getFromKline(): ?Kline { return $this->fromKline; }
    public function setFromKline(?Kline $kline): self { $this->fromKline = $kline; return $this->touchUpdatedAt(); }

    public function getToKline(): ?Kline { return $this->toKline; }
    public function setToKline(?Kline $kline): self { $this->toKline = $kline; return $this->touchUpdatedAt(); }

    public function setKlineRange(?Kline $from, ?Kline $to): self
    {
        $this->fromKline = $from;
        $this->toKline   = $to;
        return $this->touchUpdatedAt();
    }

    // (optionnel) petit helper pratique pour audit/logs
    public function getKlineRangeAsArray(): array
    {
        return [
            'from' => $this->fromKline?->getId(),
            'to'   => $this->toKline?->getId(),
        ];
    }
    public function isToDelete(): bool
    {
        return $this->getCurrentTimeframe() == self::TF_4H ;
          // a voir pourquoi je l'ai mise cette condition  ||  $this->getStatus() === self::STATUS_FAILED && $this->getRetries() == $this->getMaxRetries();
    }
}
