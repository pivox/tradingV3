<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

use App\Common\Enum\Timeframe;
use App\TradingCore\Rules\Catalog\ConditionDefinition;

final readonly class CanonicalSeriesChronologyValidator
{
    public function requiresProof(ConditionDefinition $definition): bool
    {
        return str_starts_with($definition->valueType, 'series<');
    }

    /** @param array<string, mixed> $context */
    public function isCanonical(ConditionDefinition $definition, array $context, string $timeframe): bool
    {
        if (!$this->requiresProof($definition)) {
            return true;
        }
        if (($context['series_order'] ?? null) !== $definition->seriesOrder) {
            return false;
        }

        $series = $context[$definition->metric] ?? null;
        $timestamps = $context[$definition->metric . '_timestamps'] ?? null;
        if (!is_array($series)
            || !array_is_list($series)
            || count($series) < 2
            || !is_array($timestamps)
            || !array_is_list($timestamps)
            || count($timestamps) !== count($series)
        ) {
            return false;
        }
        if ($definition->valueType === 'series<number>') {
            foreach ($series as $value) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                    return false;
                }
            }
        }

        $timeframeValue = Timeframe::tryFrom($timeframe);
        if ($timeframeValue === null) {
            return false;
        }
        $candleTimestamp = $this->canonicalSecond($context['kline_time'] ?? null);
        if ($candleTimestamp === null) {
            return false;
        }
        $step = $timeframeValue->getStepInSeconds();
        foreach ($timestamps as $index => $timestamp) {
            if (!is_int($timestamp)) {
                return false;
            }
            if ($timestamp > $candleTimestamp) {
                return false;
            }
            if ($index > 0 && $timestamp - $timestamps[$index - 1] !== $step) {
                return false;
            }
        }

        return $timestamps[array_key_last($timestamps)] === $candleTimestamp;
    }

    private function canonicalSecond(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            $instant = \DateTimeImmutable::createFromInterface($value);

            return $instant->format('u') === '000000' ? $instant->getTimestamp() : null;
        }
        if (is_int($value)) {
            if ($value > 10_000_000_000) {
                return $value % 1000 === 0 ? intdiv($value, 1000) : null;
            }

            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $seconds = $value > 10_000_000_000 ? $value / 1000.0 : $value;

            return floor($seconds) === $seconds ? (int) $seconds : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $instant = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $instant->format('u') === '000000' ? $instant->getTimestamp() : null;
    }
}
