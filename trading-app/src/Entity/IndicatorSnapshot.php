<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndicatorSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndicatorSnapshotRepository::class)]
#[ORM\Table(name: 'indicator_snapshots')]
#[ORM\Index(name: 'idx_ind_snap_symbol_tf', columns: ['symbol', 'timeframe'])]
#[ORM\Index(name: 'idx_ind_snap_kline_time', columns: ['kline_time'])]
#[ORM\UniqueConstraint(name: 'ux_ind_snap_symbol_tf_time', columns: ['symbol', 'timeframe', 'kline_time'])]
class IndicatorSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: \App\Common\Enum\Timeframe::class)]
    private \App\Common\Enum\Timeframe $timeframe;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $klineTime;

    #[ORM\Column(type: Types::JSON)]
    private array $values = [];

    #[ORM\Column(type: Types::STRING, length: 10, options: ['default' => 'PHP'])]
    private string $source = 'PHP';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $insertedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->insertedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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

    public function getTimeframe(): \App\Common\Enum\Timeframe
    {
        return $this->timeframe;
    }

    public function setTimeframe(\App\Common\Enum\Timeframe $timeframe): static
    {
        $this->timeframe = $timeframe;
        return $this;
    }

    public function getKlineTime(): \DateTimeImmutable
    {
        return $this->klineTime;
    }

    public function setKlineTime(\DateTimeImmutable $klineTime): static
    {
        $this->klineTime = $klineTime;
        return $this;
    }

    public function getValues(): array
    {
        return $this->values;
    }

    public function setValues(array $values): static
    {
        $this->values = $values;
        return $this;
    }

    public function getValue(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function setValue(string $key, mixed $value): static
    {
        $this->values[$key] = $value;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getInsertedAt(): \DateTimeImmutable
    {
        return $this->insertedAt;
    }

    public function setInsertedAt(\DateTimeImmutable $insertedAt): static
    {
        $this->insertedAt = $insertedAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getEma20(): ?string
    {
        $v = $this->getValue('ema20');
        if ($v === null) {
            $ema = $this->getValue('ema');
            if (is_array($ema)) {
                $val = $ema['20'] ?? ($ema[20] ?? null);
                if ($val !== null) { $v = (string)$val; }
            }
        }
        return $v !== null ? (string)$v : null;
    }

    public function setEma20(?string $ema20): static
    {
        return $this->setValue('ema20', $ema20);
    }

    public function getEma50(): ?string
    {
        $v = $this->getValue('ema50');
        if ($v === null) {
            $ema = $this->getValue('ema');
            if (is_array($ema)) {
                $val = $ema['50'] ?? ($ema[50] ?? null);
                if ($val !== null) { $v = (string)$val; }
            }
        }
        return $v !== null ? (string)$v : null;
    }

    public function setEma50(?string $ema50): static
    {
        return $this->setValue('ema50', $ema50);
    }

    public function getEma200(): ?string
    {
        $v = $this->getValue('ema200');
        if ($v === null) {
            $ema = $this->getValue('ema');
            if (is_array($ema)) {
                $val = $ema['200'] ?? ($ema[200] ?? null);
                if ($val !== null) {
                    $v = (string) $val;
                }
            }
        }

        return $v !== null ? (string) $v : null;
    }

    public function setEma200(?string $ema200): static
    {
        return $this->setValue('ema200', $ema200);
    }

    public function getMacd(): ?string
    {
        $v = $this->getValue('macd');
        if (is_array($v)) {
            $v = $v['macd'] ?? ($v['value'] ?? null);
        }
        return $v !== null ? (string)$v : null;
    }

    public function setMacd(?string $macd): static
    {
        return $this->setValue('macd', $macd);
    }

    public function getMacdSignal(): ?string
    {
        return $this->getValue('macd_signal');
    }

    public function setMacdSignal(?string $macdSignal): static
    {
        return $this->setValue('macd_signal', $macdSignal);
    }

    public function getMacdHistogram(): ?string
    {
        return $this->getValue('macd_histogram');
    }

    public function setMacdHistogram(?string $macdHistogram): static
    {
        return $this->setValue('macd_histogram', $macdHistogram);
    }

    public function getAtr(): ?string
    {
        return $this->getValue('atr');
    }

    public function setAtr(?string $atr): static
    {
        return $this->setValue('atr', $atr);
    }

    public function getRsi(): ?float
    {
        return $this->getValue('rsi');
    }

    public function setRsi(?float $rsi): static
    {
        return $this->setValue('rsi', $rsi);
    }

    public function getVwap(): ?string
    {
        return $this->getValue('vwap');
    }

    public function setVwap(?string $vwap): static
    {
        return $this->setValue('vwap', $vwap);
    }

    public function getBbUpper(): ?string
    {
        return $this->getValue('bb_upper');
    }

    public function setBbUpper(?string $bbUpper): static
    {
        return $this->setValue('bb_upper', $bbUpper);
    }

    public function getBbMiddle(): ?string
    {
        return $this->getValue('bb_middle');
    }

    public function setBbMiddle(?string $bbMiddle): static
    {
        return $this->setValue('bb_middle', $bbMiddle);
    }

    public function getBbLower(): ?string
    {
        return $this->getValue('bb_lower');
    }

    public function setBbLower(?string $bbLower): static
    {
        return $this->setValue('bb_lower', $bbLower);
    }

    public function getMa9(): ?string
    {
        return $this->getValue('ma9');
    }

    public function setMa9(?string $ma9): static
    {
        return $this->setValue('ma9', $ma9);
    }

    public function getMa21(): ?string
    {
        return $this->getValue('ma21');
    }

    public function setMa21(?string $ma21): static
    {
        return $this->setValue('ma21', $ma21);
    }
}

