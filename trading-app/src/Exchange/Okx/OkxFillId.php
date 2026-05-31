<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

final class OkxFillId
{
    public static function fromTradeId(mixed $instId, mixed $tradeId): ?string
    {
        $tradeId = trim((string) $tradeId);
        if ($tradeId === '') {
            return null;
        }

        return 'okx-fill-' . substr(hash('sha256', strtoupper(trim((string) $instId)) . ':' . $tradeId), 0, 32);
    }
}
