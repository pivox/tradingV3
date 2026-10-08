<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Lifecycle;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Context\ExchangeContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * 1m candles of the verified dataset of the current Paper replay, for the MFE/MAE evidence of the
 * Paper trade lifecycle (#132 j). Nothing else is served, and nothing is ever fetched elsewhere.
 *
 * The dataset is immutable: an uninterrupted replay and a replay resumed after a crash read the
 * same candles, whatever their in-memory market state. Only candles lying ENTIRELY inside the
 * requested window are served (open >= start and close <= end): no candle after the position
 * close, and no candle straddling it, is ever returned. The events file is hashed again while it
 * is read and must still match the checksum of the verified dataset the replay is bound to.
 */
final class PaperDatasetCandleWindow implements KlineProviderInterface
{
    private const CHANNEL_MARKER = '"channel":"candle_1m"';
    private const CANDLE_SECONDS = 60;

    private ?string $eventsPath = null;
    private ?string $eventsSha256 = null;

    /** @var array<string, list<KlineDto>>|null symbol => candles ordered by open time */
    private ?array $candles = null;

    public function bind(string $eventsPath, string $eventsSha256): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $eventsSha256) !== 1) {
            throw new \InvalidArgumentException('paper_lifecycle_dataset_checksum_invalid');
        }
        if ($this->eventsPath === $eventsPath && $this->eventsSha256 === $eventsSha256) {
            return;
        }
        $this->eventsPath = $eventsPath;
        $this->eventsSha256 = $eventsSha256;
        $this->candles = null;
    }

    /** @return list<KlineDto> */
    public function getKlinesInWindow(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit = 500,
        ?ExchangeContext $context = null,
    ): array {
        if ($timeframe !== Timeframe::TF_1M) {
            throw new \LogicException('paper_lifecycle_candle_timeframe_unsupported');
        }
        $selected = [];
        if ($limit < 1) {
            return $selected;
        }
        foreach ($this->load()[strtoupper($symbol)] ?? [] as $candle) {
            if ($candle->openTime < $start) {
                continue;
            }
            if ($candle->openTime->modify('+' . self::CANDLE_SECONDS . ' seconds') > $end) {
                break;
            }
            $selected[] = $candle;
            if (\count($selected) === $limit) {
                break;
            }
        }

        return $selected;
    }

    /** @return list<KlineDto> */
    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 490, ?ExchangeContext $context = null): array
    {
        throw self::windowOnly();
    }

    public function getLastKline(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): ?KlineDto
    {
        throw self::windowOnly();
    }

    public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
    {
        throw new \LogicException('paper_lifecycle_candle_window_read_only');
    }

    /** @param list<KlineDto> $klines */
    public function saveKlines(array $klines, string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): void
    {
        throw new \LogicException('paper_lifecycle_candle_window_read_only');
    }

    public function hasGaps(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): bool
    {
        throw self::windowOnly();
    }

    /** @return list<mixed> */
    public function getGaps(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): array
    {
        throw self::windowOnly();
    }

    /** @return array<string, list<KlineDto>> */
    private function load(): array
    {
        if ($this->candles !== null) {
            return $this->candles;
        }
        if ($this->eventsPath === null || $this->eventsSha256 === null) {
            throw new \LogicException('paper_lifecycle_dataset_unbound');
        }
        $handle = is_file($this->eventsPath) && !is_link($this->eventsPath) ? @fopen($this->eventsPath, 'rb') : false;
        if ($handle === false) {
            throw new \RuntimeException('paper_lifecycle_dataset_unreadable');
        }
        $hash = hash_init('sha256');
        /** @var array<string, array<int, KlineDto>> $bySymbol */
        $bySymbol = [];
        try {
            while (($line = fgets($handle)) !== false) {
                hash_update($hash, $line);
                if (!str_contains($line, self::CHANNEL_MARKER)) {
                    continue;
                }
                $candle = self::candle($line);
                $openTimestamp = $candle->openTime->getTimestamp();
                $existing = $bySymbol[$candle->symbol][$openTimestamp] ?? null;
                if ($existing instanceof KlineDto && !self::sameCandle($existing, $candle)) {
                    throw new \LogicException('paper_lifecycle_candle_conflict');
                }
                $bySymbol[$candle->symbol][$openTimestamp] = $candle;
            }
            if (!feof($handle)) {
                throw new \RuntimeException('paper_lifecycle_dataset_unreadable');
            }
        } finally {
            fclose($handle);
        }
        if (!hash_equals($this->eventsSha256, hash_final($hash))) {
            throw new \LogicException('paper_lifecycle_dataset_checksum_mismatch');
        }

        $candles = [];
        foreach ($bySymbol as $symbol => $byOpen) {
            ksort($byOpen);
            $candles[$symbol] = array_values($byOpen);
        }

        return $this->candles = $candles;
    }

    /** The same rules as PaperMarketStateProjector::applyCandle() for a confirmed 1m candle. */
    private static function candle(string $line): KlineDto
    {
        try {
            $event = json_decode($line, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \LogicException('paper_lifecycle_candle_invalid');
        }
        if (!\is_array($event) || ($event['channel'] ?? null) !== 'candle_1m') {
            throw new \LogicException('paper_lifecycle_candle_invalid');
        }
        $payload = $event['payload'] ?? null;
        $symbol = $event['symbol'] ?? null;
        $interval = \is_array($payload) ? ($payload['interval'] ?? $payload['bar'] ?? null) : null;
        if (!\is_array($payload)
            || !\is_string($symbol) || preg_match('/\A[A-Z0-9]{2,32}\z/D', $symbol) !== 1
            || ($payload['confirmed'] ?? null) !== true
            || !\is_string($interval) || strtolower($interval) !== '1m'
        ) {
            throw new \LogicException('paper_lifecycle_candle_invalid');
        }
        try {
            $open = self::positive($payload['open'] ?? null);
            $high = self::positive($payload['high'] ?? null);
            $low = self::positive($payload['low'] ?? null);
            $close = self::positive($payload['close'] ?? null);
            $volume = BigDecimal::of(self::decimalText($payload['volume'] ?? $payload['volume_base'] ?? null));
        } catch (\InvalidArgumentException|MathException) {
            throw new \LogicException('paper_lifecycle_candle_invalid');
        }
        if ($volume->isNegative()
            || $high->isLessThan($open) || $high->isLessThan($close)
            || $low->isGreaterThan($open) || $low->isGreaterThan($close)
        ) {
            throw new \LogicException('paper_lifecycle_candle_invalid');
        }

        return new KlineDto(
            symbol: $symbol,
            timeframe: Timeframe::TF_1M,
            openTime: self::openTime($payload['start_time'] ?? null, $event['exchange_timestamp'] ?? null),
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            volume: $volume,
            source: 'paper_dataset',
        );
    }

    private static function openTime(mixed $startTime, mixed $exchangeTimestamp): \DateTimeImmutable
    {
        if ($startTime === null) {
            if (!\is_string($exchangeTimestamp)) {
                throw new \LogicException('paper_lifecycle_candle_invalid');
            }
            try {
                $timestamp = (int) (new \DateTimeImmutable($exchangeTimestamp))->format('U');
            } catch (\Exception) {
                throw new \LogicException('paper_lifecycle_candle_invalid');
            }
        } elseif (\is_string($startTime) && preg_match('/\A[0-9]{13}\z/D', $startTime) === 1) {
            $timestamp = intdiv((int) $startTime, 1000);
        } else {
            throw new \LogicException('paper_lifecycle_candle_invalid');
        }
        if ($timestamp < 0 || $timestamp % self::CANDLE_SECONDS !== 0) {
            throw new \LogicException('paper_lifecycle_candle_invalid');
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function positive(mixed $value): BigDecimal
    {
        $decimal = BigDecimal::of(self::decimalText($value));
        if (!$decimal->isPositive()) {
            throw new \InvalidArgumentException();
        }

        return $decimal;
    }

    private static function decimalText(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private static function sameCandle(KlineDto $left, KlineDto $right): bool
    {
        return $left->open->isEqualTo($right->open)
            && $left->high->isEqualTo($right->high)
            && $left->low->isEqualTo($right->low)
            && $left->close->isEqualTo($right->close)
            && $left->volume->isEqualTo($right->volume);
    }

    private static function windowOnly(): \LogicException
    {
        return new \LogicException('paper_lifecycle_candle_window_only');
    }
}
