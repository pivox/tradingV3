<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HotKlineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HotKlineRepository::class)]
#[ORM\Table(name: 'hot_kline')]
#[ORM\UniqueConstraint(name: 'uniq_hot_kline_pk', columns: ['symbol', 'timeframe', 'open_time'])]
#[ORM\Index(name: 'idx_hot_kline_symbol_tf', columns: ['symbol', 'timeframe'])]
#[ORM\Index(name: 'idx_hot_kline_last_update', columns: ['last_update'])]
class HotKline
{
    // === Composite PK : (symbol, timeframe, open_time) ===

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 50)]
    private string $symbol;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 10)]
    private string $timeframe;

    #[ORM\Id]
    #[ORM\Column(name: 'open_time', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $openTime;

    // === Données ===

    #[ORM\Column(type: 'json')]
    private array $ohlc = []; // ['o'=>..., 'h'=>..., 'l'=>..., 'c'=>..., 'v'=>...]

    #[ORM\Column(name: 'is_closed', type: 'boolean', options: ['default' => false])]
    private bool $isClosed = false;

    #[ORM\Column(name: 'last_update', type: 'datetimetz_immutable', options: ['default' => 'now()'])]
    private \DateTimeImmutable $lastUpdate;

    public function __construct(
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $openTime,
        array $ohlc,
        bool $isClosed = false,
        ?\DateTimeImmutable $lastUpdate = null
    ) {
        $this->symbol = $symbol;
        $this->timeframe = $timeframe;
        $this->openTime = $openTime;
        $this->ohlc = $ohlc;
        $this->isClosed = $isClosed;
        $this->lastUpdate = $lastUpdate ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // === Getters / Setters ===

    public function getSymbol(): string { return $this->symbol; }
    public function getTimeframe(): string { return $this->timeframe; }
    public function getOpenTime(): \DateTimeImmutable { return $this->openTime; }

    public function getOhlc(): array { return $this->ohlc; }
    public function setOhlc(array $ohlc): void { $this->ohlc = $ohlc; }

    public function isClosed(): bool { return $this->isClosed; }
    public function setClosed(bool $closed): void { $this->isClosed = $closed; }

    public function getLastUpdate(): \DateTimeImmutable { return $this->lastUpdate; }
    public function touchLastUpdate(?\DateTimeImmutable $at = null): void
    {
        $this->lastUpdate = $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}