<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeFundingModelConfig
{
    public const MODEL_VERSION = 'fake-funding-notional-rate-interval-v1';

    /** @param list<string> $usdtCurrencies */
    private function __construct(
        public string $modelVersion,
        public int $amountScale,
        public array $usdtCurrencies,
    ) {
    }

    public static function v1(): self
    {
        return new self(self::MODEL_VERSION, 12, ['USDT']);
    }

    public function isUsdtCurrency(string $currency): bool
    {
        return \in_array(strtoupper($currency), $this->usdtCurrencies, true);
    }
}
