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
            $instrument = str_ends_with($symbol, '-SWAP') ? substr($symbol, 0, -5) : $symbol;
            $parts = explode('-', $instrument);
            if (\count($parts) === 2 && $parts[0] !== '' && \in_array($parts[1], ['USDT', 'USDC', 'USD'], true)) {
                return $parts[0] . '-' . $parts[1] . '-SWAP';
            }

            throw new \InvalidArgumentException(sprintf('Unable to normalize OKX swap symbol "%s"', $symbol));
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
