<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\Dto;

use App\Domain\Common\Enum\Timeframe;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class KlineDto
{
    public \DateTimeImmutable $openTime;
    public int $timestamp;
        public BigDecimal $open;
        public BigDecimal $high;
        public BigDecimal $low;
        public BigDecimal $close;
        public BigDecimal $volume;
        public string $source;

    public function __construct(array $items) {
        $this->timestamp = (int) ($items['open_time'] ?? $items['timestamp'] ?? 0);
        $this->open = BigDecimal::of($items['open_price']);
        $this->high = BigDecimal::of($items['high_price']);
        $this->low = BigDecimal::of($items['low_price']);
        $this->close = BigDecimal::of($items['close_price']);
        $this->volume = BigDecimal::of($items['volume']);
        $this->source = $items['source'] ?? 'REST';
        $this->openTime = self::toUtcDateTime($this->timestamp);
    }

    public function toArray(): array
    {
        return [
            'open_time' => $this->openTime->format('Y-m-d H:i:s'),
            'open'      => $this->open->toFixed(12),
            'high'      => $this->high->toFixed(12),
            'low'       => $this->low->toFixed(12),
            'close'     => $this->close->toFixed(12),
            'volume'    => $this->volume->toFixed(12),
            'source'    => $this->source,
        ];
    }



    private static function toUtcDateTime(string|int $tsOrStr): \DateTimeImmutable
    {
        // Si entier => epoch (gère secondes vs millisecondes)
        if (is_numeric($tsOrStr)) {
            $ts = (int) $tsOrStr;
            if ($ts > 2_000_000_000_000) { // ms
                $ts = intdiv($ts, 1000);
            }
            // format "@$ts" force l’epoch
            return (new \DateTimeImmutable("@$ts"))->setTimezone(new \DateTimeZone('UTC'));
        }
        // Sinon, on considère une string au format ISO/SQL déjà UTC
        return new \DateTimeImmutable((string) $tsOrStr, new \DateTimeZone('UTC'));
    }

    public function isBullish(): bool
    {
        // compareTo() est sûr sur toutes versions brick/math
        return $this->close->compareTo($this->open) > 0;
    }

    public function isBearish(): bool
    {
        return $this->close->compareTo($this->open) < 0;
    }

    public function getBodySize(): BigDecimal
    {
        $diff = $this->close->minus($this->open);
        return $diff->isNegative() ? $diff->negated() : $diff; // abs()
    }

    public function getWickSize(): BigDecimal
    {
        $total = $this->high->minus($this->low);
        $wick  = $total->minus($this->getBodySize());
        return $wick->isNegative() ? BigDecimal::zero() : $wick;
    }

    public function getBodyPercentage(): float
    {
        $totalRange = $this->high->minus($this->low);
        if ($totalRange->isZero()) {
            return 0.0;
        }
        // ⚠️ dividedBy() nécessite un scale (et parfois un mode)
        return $this->getBodySize()
            ->dividedBy($totalRange, 12, RoundingMode::HALF_UP)
            ->toFloat();
    }
}
