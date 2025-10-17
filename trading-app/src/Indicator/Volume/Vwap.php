<?php
declare(strict_types=1);

namespace App\Indicator\Volume;

final class Vwap
{
    public function calculateFull(array $highs, array $lows, array $closes, array $volumes): array
    {
        $n = min(count($highs), count($lows), count($closes), count($volumes));
        $out = [];
        if ($n === 0) return $out;

        $cumPV = 0.0; $cumV = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $tp = (((float)$highs[$i]) + ((float)$lows[$i]) + ((float)$closes[$i])) / 3.0;
            $v  = max(0.0, (float)$volumes[$i]);
            $cumPV += $tp * $v;
            $cumV  += $v;
            $out[] = $cumV > 0.0 ? ($cumPV / $cumV) : 0.0;
        }
        return $out;
    }

    public function calculate(array $highs, array $lows, array $closes, array $volumes): float
    {
        $s = $this->calculateFull($highs, $lows, $closes, $volumes);
        return empty($s) ? 0.0 : (float) end($s);
    }

    public function calculateDailyWithTimestamps(
        array $timestamps,
        array $highs,
        array $lows,
        array $closes,
        array $volumes,
        string $timezone = 'UTC'
    ): array {
        $n = min(count($timestamps), count($highs), count($lows), count($closes), count($volumes));
        $out = [];
        if ($n === 0) return $out;
        $timestamps = array_map(function ($ts) {
            if ($ts instanceof \DateTimeInterface) {
                return $ts->getTimestamp(); // seconds
            }
            return (int)$ts; // déjà un int
        }, $timestamps);

        if ((int)$timestamps[0] > 1_000_000_000_000) {
            for ($i = 0; $i < $n; $i++) {
                $timestamps[$i] = (int) floor(((int)$timestamps[$i]) / 1000);
            }
        }

        $tz = new \DateTimeZone($timezone);
        $curDay = null; $cumPV = 0.0; $cumV = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dt = (new \DateTimeImmutable('@' . (int)$timestamps[$i]))->setTimezone($tz);
            $dayKey = $dt->format('Y-m-d');
            if ($curDay === null || $dayKey !== $curDay) { $curDay = $dayKey; $cumPV = 0.0; $cumV = 0.0; }

            $tp = (((float)$highs[$i]) + ((float)$lows[$i]) + ((float)$closes[$i])) / 3.0;
            $v  = max(0.0, (float)$volumes[$i]);
            $cumPV += $tp * $v;
            $cumV  += $v;
            $out[] = $cumV > 0.0 ? ($cumPV / $cumV) : 0.0;
        }
        return $out;
    }

    public function calculateLastDailyWithTimestamps(
        array $timestamps,
        array $highs,
        array $lows,
        array $closes,
        array $volumes,
        string $timezone = 'UTC'
    ): float {
        $s = $this->calculateDailyWithTimestamps($timestamps, $highs, $lows, $closes, $volumes, $timezone);
        return empty($s) ? 0.0 : (float) end($s);
    }
}
