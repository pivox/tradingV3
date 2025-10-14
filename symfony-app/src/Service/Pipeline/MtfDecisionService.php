<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

final class MtfDecisionService
{
    /**
     * @param array<string,array{signal?:string}> $signals
     * @return array{is_valid:bool, can_enter:bool}
     */
    public function decide(string $timeframe, array $signals): array
    {
        $relevant = match ($timeframe) {
            '4h'  => ['4h'],
            '1h'  => ['4h','1h'],
            '15m' => ['4h','1h','15m'],
            '5m'  => ['4h','1h','15m','5m'],
            '1m'  => ['4h','1h','15m','5m','1m'],
            default => array_keys($signals),
        };
        $filtered = [];
        foreach ($relevant as $tf) {
            if (isset($signals[$tf])) {
                $filtered[$tf] = $signals[$tf];
            }
        }

        $sides = [];
        foreach ($filtered as $tf => $payload) {
            $sides[$tf] = strtoupper((string)($payload['signal'] ?? 'NONE'));
        }
        $unique = array_values(array_unique($sides));
        $valid = false;
        if ($unique !== [] && !in_array('NONE', $unique, true) && count($unique) === 1 && in_array($unique[0], ['LONG','SHORT'], true)) {
            $valid = true;
        }

        $side4h  = $sides['4h']  ?? null;
        $side1h  = $sides['1h']  ?? null;
        $side15m = $sides['15m'] ?? null;
        $side5m  = $sides['5m']  ?? null;
        $side1m  = $sides['1m']  ?? null;

        $allLongShort = static function (?string ...$values): bool {
            foreach ($values as $value) {
                if ($value === null || !in_array($value, ['LONG','SHORT'], true)) {
                    return false;
                }
            }
            return true;
        };

        $canEnter = false;
        if ($timeframe === '5m') {
            if ($allLongShort($side4h, $side1h, $side15m, $side5m) && $side4h === $side1h && $side1h === $side15m && $side15m === $side5m) {
                $canEnter = true;
            }
        } elseif ($timeframe === '1m') {
            if ($allLongShort($side4h, $side1h, $side15m, $side5m, $side1m) && $side4h === $side1h && $side1h === $side15m && $side15m === $side5m && $side5m === $side1m) {
                $canEnter = true;
            }
        }

        return [
            'is_valid' => $valid,
            'can_enter' => $canEnter,
        ];
    }
}
