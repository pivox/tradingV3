<?php

namespace App\Service\Trading;

use App\Service\Bitmart\Private\PositionsService;

class PositionFetcher
{
    public function __construct(
        private readonly PositionsService $positionsService,
    ) {}

    public function fetchPosition(string $symbol): ?object
    {
        $resp = $this->positionsService->list(['symbol' => $symbol]);
        $data = $resp['data'] ?? null;
        if (!$data) {
            return null;
        }
        $entries = $this->normalizeEntries($data);
        if ($entries === []) {
            return null;
        }

        $symbolUpper = strtoupper($symbol);
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entrySymbol = strtoupper((string) ($entry['symbol'] ?? $entry['contract'] ?? ''));
            if ($entrySymbol !== $symbolUpper) {
                continue;
            }

            $qty = $entry['size']
                ?? $entry['current_amount']
                ?? $entry['hold_volume']
                ?? $entry['position_volume']
                ?? null;

            $entryPrice = $entry['entry_price']
                ?? $entry['avg_entry_price']
                ?? $entry['average_price']
                ?? null;

            $markPrice = $entry['mark_price']
                ?? $entry['markPrice']
                ?? $entry['mark_price_value']
                ?? null;

            if ($qty === null || $entryPrice === null || $markPrice === null) {
                continue;
            }

            return (object) [
                'side'         => $this->normalizeSide($entry),
                'quantity'     => (float) $qty,
                'entryPrice'   => (float) $entryPrice,
                'markPrice'    => (float) $markPrice,
                'leverage'     => $this->extractFloat($entry, ['leverage', 'position_leverage', 'open_leverage']),
                'margin'       => $this->extractFloat($entry, ['margin', 'margin_amount', 'used_margin', 'frozen_margin']),
                'liqPrice'     => $this->extractFloat($entry, ['liq_price', 'liq_price_value', 'liquidation_price']),
                'takeProfit'   => $this->extractFloat($entry, ['take_profit', 'tp_price', 'preset_take_profit_price']),
                'stopLoss'     => $this->extractFloat($entry, ['stop_loss', 'sl_price', 'preset_stop_loss_price']),
                'openTime'     => $this->extractInt($entry, ['open_time', 'created_at', 'open_timestamp']),
            ];
        }

        return null;
    }

    /**
     * @param mixed $data
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEntries(mixed $data): array
    {
        if (isset($data['positions']) && is_array($data['positions'])) {
            return $data['positions'];
        }

        if (is_array($data) && $this->isList($data)) {
            return array_filter($data, 'is_array');
        }

        if (is_array($data) && isset($data['symbol'])) {
            return [$data];
        }

        return [];
    }

    private function isList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function normalizeSide(array $entry): ?string
    {
        $raw = $entry['side']
            ?? $entry['hold_side']
            ?? $entry['position_side']
            ?? $entry['holdSide']
            ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_numeric($raw)) {
            $num = (int)$raw;
            return match ($num) {
                1 => 'LONG',
                2, -1 => 'SHORT',
                default => null,
            };
        }

        $normalized = strtoupper(trim((string)$raw));

        return match ($normalized) {
            'LONG', 'BUY', 'BID', 'OPEN_LONG', 'HOLD_LONG' => 'LONG',
            'SHORT', 'SELL', 'ASK', 'OPEN_SHORT', 'HOLD_SHORT' => 'SHORT',
            default => null,
        };
    }

    /**
     * @param array<int, string> $keys
     */
    private function extractFloat(array $entry, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }
            $value = $entry[$key];
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $keys
     */
    private function extractInt(array $entry, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }
            $value = $entry[$key];
            if (is_numeric($value)) {
                return (int)$value;
            }
        }

        return null;
    }
}
