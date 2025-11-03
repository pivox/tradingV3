<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\Dto;

use Brick\Math\BigDecimal;

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
        
        // Gestion robuste des valeurs manquantes ou invalides
        $openPrice = $items['open_price'] ?? $items['open'] ?? null;
        $highPrice = $items['high_price'] ?? $items['high'] ?? null;
        $lowPrice = $items['low_price'] ?? $items['low'] ?? null;
        $closePrice = $items['close_price'] ?? $items['close'] ?? null;
        $volumeVal = $items['volume'] ?? null;
        
        // Validation : si une valeur critique est null/vide, lancer une exception claire
        if ($openPrice === null || $highPrice === null || $lowPrice === null || $closePrice === null) {
            throw new \InvalidArgumentException(sprintf(
                'KlineDto: données incomplètes - open=%s, high=%s, low=%s, close=%s, keys=%s',
                var_export($openPrice, true),
                var_export($highPrice, true),
                var_export($lowPrice, true),
                var_export($closePrice, true),
                implode(',', array_keys($items))
            ));
        }
        
        $this->open = BigDecimal::of($openPrice);
        $this->high = BigDecimal::of($highPrice);
        $this->low = BigDecimal::of($lowPrice);
        $this->close = BigDecimal::of($closePrice);
        $this->volume = $volumeVal !== null ? BigDecimal::of($volumeVal) : BigDecimal::of('0');
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
}
