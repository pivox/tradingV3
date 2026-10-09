<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use Brick\Math\BigDecimal;

final readonly class ResearchCandle
{
    public const SYMBOLS = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'DOGEUSDT', 'SOLUSDT', 'LTCUSDT', 'LINKUSDT', 'AVAXUSDT'];
    /** @param array<string, mixed> $values */
    private function __construct(public string $symbol, public int $openMs, private array $values)
    {
    }

    /** @param array<string, mixed> $record */
    public static function fromArray(array $record): self
    {
        self::exactKeys($record, ['symbol', 'open_ms', 'open', 'high', 'low', 'close', 'volume', 'source_record_id']);
        if (!is_string($record['symbol']) || !in_array($record['symbol'], self::SYMBOLS, true)
            || !is_int($record['open_ms']) || $record['open_ms'] < 0 || $record['open_ms'] % 60000 !== 0
            || $record['open_ms'] > PHP_INT_MAX - 60000
            || !is_string($record['source_record_id']) || preg_match('/\A[0-9a-f]{64}\z/D', $record['source_record_id']) !== 1) {
            throw new \InvalidArgumentException('research_candle_identity_invalid');
        }
        foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
            $value = $record[$key];
            if (!is_string($value) || strlen($value) > 64 || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
                || !is_finite((float) $value) || ($key !== 'volume' && (float) $value <= 0.0)) {
                throw new \InvalidArgumentException('research_candle_value_invalid');
            }
        }
        $high = BigDecimal::of($record['high']);
        $low = BigDecimal::of($record['low']);
        if ($high->isLessThan($record['open']) || $high->isLessThan($record['close'])
            || $low->isGreaterThan($record['open']) || $low->isGreaterThan($record['close'])) {
            throw new \InvalidArgumentException('research_candle_ohlc_invalid');
        }
        return new self($record['symbol'], $record['open_ms'], $record);
    }

    /** @return array<string, mixed> */
    public function bar(): array
    {
        return [...$this->values, 'available_ms' => $this->openMs + 60000];
    }

    /**
     * @param array<array-key, mixed> $record
     * @param list<string> $expected
     */
    public static function exactKeys(array $record, array $expected): void
    {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('research_frame_shape_invalid');
        }
    }
}
