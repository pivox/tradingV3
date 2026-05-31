<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

final class OkxInstrumentResolver
{
    public function instId(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \InvalidArgumentException('OKX symbol cannot be blank');
        }
        if (str_contains($symbol, '-')) {
            return str_ends_with($symbol, '-SWAP') ? $symbol : $symbol . '-SWAP';
        }

        foreach (['USDT', 'USDC', 'USD'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return substr($symbol, 0, -strlen($quote)) . '-' . $quote . '-SWAP';
            }
        }

        throw new \InvalidArgumentException(sprintf('Unable to normalize OKX swap symbol "%s"', $symbol));
    }

    public function symbol(string $instId): string
    {
        $instId = strtoupper(trim($instId));
        if (str_ends_with($instId, '-SWAP')) {
            $instId = substr($instId, 0, -5);
        }

        return str_replace('-', '', $instId);
    }
}
