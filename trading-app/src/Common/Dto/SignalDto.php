<?php

declare(strict_types=1);

namespace App\Common\Dto;

use App\Common\Enum\SignalSide;
use App\Common\Enum\Timeframe;

final readonly class SignalDto
{
    public function __construct(
        public string $symbol,
        public Timeframe $timeframe,
        public \DateTimeImmutable $klineTime,
        public SignalSide $side,
        public ?float $score = null,
        public ?string $trigger = null,
        public array $meta = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe->value,
            'kline_time' => $this->klineTime->format('Y-m-d H:i:s'),
            'side' => $this->side->value,
            'score' => $this->score,
            'trigger' => $this->trigger,
            'meta' => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            timeframe: Timeframe::from($data['timeframe']),
            klineTime: new \DateTimeImmutable($data['kline_time'], new \DateTimeZone('UTC')),
            side: SignalSide::from($data['side']),
            score: $data['score'] ?? null,
            trigger: $data['trigger'] ?? null,
            meta: $data['meta'] ?? []
        );
    }

    public function isLong(): bool
    {
        return $this->side->isLong();
    }

    public function isShort(): bool
    {
        return $this->side->isShort();
    }

    public function isNone(): bool
    {
        return $this->side->isNone();
    }

    public function hasScore(): bool
    {
        return $this->score !== null;
    }

    public function isStrongSignal(float $threshold = 0.7): bool
    {
        return $this->score !== null && $this->score >= $threshold;
    }

    public function isWeakSignal(float $threshold = 0.3): bool
    {
        return $this->score !== null && $this->score <= $threshold;
    }

    public function getOpposite(): self
    {
        return new self(
            symbol: $this->symbol,
            timeframe: $this->timeframe,
            klineTime: $this->klineTime,
            side: $this->side->getOpposite(),
            score: $this->score,
            trigger: $this->trigger,
            meta: $this->meta
        );
    }
}




