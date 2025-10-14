<?php

declare(strict_types=1);

namespace App\Domain\Common\Dto;

use App\Domain\Common\Enum\SignalSide;
use Brick\Math\BigDecimal;

final readonly class OrderPlanDto
{
    public function __construct(
        public string $symbol,
        public SignalSide $side,
        public BigDecimal $leverage,
        public ?BigDecimal $stopLoss = null,
        public ?BigDecimal $takeProfit = null,
        public array $context = [],
        public array $risk = [],
        public array $execution = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'leverage' => $this->leverage->toFixed(2),
            'stop_loss' => $this->stopLoss?->toFixed(12),
            'take_profit' => $this->takeProfit?->toFixed(12),
            'context' => $this->context,
            'risk' => $this->risk,
            'execution' => $this->execution,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            side: SignalSide::from($data['side']),
            leverage: BigDecimal::of($data['leverage']),
            stopLoss: isset($data['stop_loss']) ? BigDecimal::of($data['stop_loss']) : null,
            takeProfit: isset($data['take_profit']) ? BigDecimal::of($data['take_profit']) : null,
            context: $data['context'] ?? [],
            risk: $data['risk'] ?? [],
            execution: $data['execution'] ?? []
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

    public function hasStopLoss(): bool
    {
        return $this->stopLoss !== null;
    }

    public function hasTakeProfit(): bool
    {
        return $this->takeProfit !== null;
    }

    public function getRiskRewardRatio(): ?float
    {
        if (!$this->hasStopLoss() || !$this->hasTakeProfit()) {
            return null;
        }

        $entryPrice = $this->context['entry_price'] ?? null;
        if ($entryPrice === null) {
            return null;
        }

        $entry = BigDecimal::of($entryPrice);
        $stopLoss = $this->stopLoss;
        $takeProfit = $this->takeProfit;

        if ($this->isLong()) {
            $risk = $entry->minus($stopLoss);
            $reward = $takeProfit->minus($entry);
        } else {
            $risk = $stopLoss->minus($entry);
            $reward = $entry->minus($takeProfit);
        }

        if ($risk->isZero()) {
            return null;
        }

        return $reward->dividedBy($risk)->toFloat();
    }

    public function getPositionSize(): ?BigDecimal
    {
        $size = $this->execution['size'] ?? null;
        return $size ? BigDecimal::of($size) : null;
    }

    public function withContext(array $context): self
    {
        return new self(
            symbol: $this->symbol,
            side: $this->side,
            leverage: $this->leverage,
            stopLoss: $this->stopLoss,
            takeProfit: $this->takeProfit,
            context: array_merge($this->context, $context),
            risk: $this->risk,
            execution: $this->execution
        );
    }

    public function withRisk(array $risk): self
    {
        return new self(
            symbol: $this->symbol,
            side: $this->side,
            leverage: $this->leverage,
            stopLoss: $this->stopLoss,
            takeProfit: $this->takeProfit,
            context: $this->context,
            risk: array_merge($this->risk, $risk),
            execution: $this->execution
        );
    }

    public function withExecution(array $execution): self
    {
        return new self(
            symbol: $this->symbol,
            side: $this->side,
            leverage: $this->leverage,
            stopLoss: $this->stopLoss,
            takeProfit: $this->takeProfit,
            context: $this->context,
            risk: $this->risk,
            execution: array_merge($this->execution, $execution)
        );
    }
}




