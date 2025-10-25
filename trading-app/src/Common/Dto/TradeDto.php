<?php

declare(strict_types=1);

namespace App\Common\Dto;

use App\Common\Enum\SignalSide;

final readonly class TradeDto
{
    public function __construct(
        public string $id,
        public string $symbol,
        public SignalSide $side,
        public \DateTimeImmutable $entryTime,
        public float $entryPrice,
        public float $quantity,
        public float $stopLoss,
        public float $takeProfit,
        public ?\DateTimeImmutable $exitTime = null,
        public ?float $exitPrice = null,
        public ?float $pnl = null,
        public ?float $pnlPercentage = null,
        public ?float $commission = null,
        public ?string $exitReason = null,
        public array $meta = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'entry_time' => $this->entryTime->format('Y-m-d H:i:s'),
            'entry_price' => $this->entryPrice,
            'quantity' => $this->quantity,
            'stop_loss' => $this->stopLoss,
            'take_profit' => $this->takeProfit,
            'exit_time' => $this->exitTime?->format('Y-m-d H:i:s'),
            'exit_price' => $this->exitPrice,
            'pnl' => $this->pnl,
            'pnl_percentage' => $this->pnlPercentage,
            'commission' => $this->commission,
            'exit_reason' => $this->exitReason,
            'meta' => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            symbol: $data['symbol'],
            side: SignalSide::from($data['side']),
            entryTime: new \DateTimeImmutable($data['entry_time'], new \DateTimeZone('UTC')),
            entryPrice: $data['entry_price'],
            quantity: $data['quantity'],
            stopLoss: $data['stop_loss'],
            takeProfit: $data['take_profit'],
            exitTime: isset($data['exit_time']) ? new \DateTimeImmutable($data['exit_time'], new \DateTimeZone('UTC')) : null,
            exitPrice: $data['exit_price'] ?? null,
            pnl: $data['pnl'] ?? null,
            pnlPercentage: $data['pnl_percentage'] ?? null,
            commission: $data['commission'] ?? null,
            exitReason: $data['exit_reason'] ?? null,
            meta: $data['meta'] ?? []
        );
    }

    public function isOpen(): bool
    {
        return $this->exitTime === null;
    }

    public function isClosed(): bool
    {
        return $this->exitTime !== null;
    }

    public function isWinning(): bool
    {
        return $this->pnl !== null && $this->pnl > 0;
    }

    public function isLosing(): bool
    {
        return $this->pnl !== null && $this->pnl < 0;
    }

    public function getDuration(): ?\DateInterval
    {
        if ($this->exitTime === null) {
            return null;
        }
        return $this->entryTime->diff($this->exitTime);
    }

    public function getDurationInMinutes(): ?int
    {
        $duration = $this->getDuration();
        if ($duration === null) {
            return null;
        }
        return $duration->days * 24 * 60 + $duration->h * 60 + $duration->i;
    }
}


