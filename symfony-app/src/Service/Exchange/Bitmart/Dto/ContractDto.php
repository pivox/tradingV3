<?php

namespace App\Service\Exchange\Bitmart\Dto;

class ContractDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $baseCurrency,
        public readonly string $quoteCurrency,
        public readonly string $indexName,
        public readonly float $contractSize,
        public readonly float $pricePrecision,
        public readonly float $volPrecision,
        public readonly ?float $lastPrice = null
    ) {}

    public static function fromApi(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            baseCurrency: $data['base_currency'],
            quoteCurrency: $data['quote_currency'],
            indexName: $data['index_name'],
            contractSize: (float) $data['contract_size'],
            pricePrecision: (float) $data['price_precision'],
            volPrecision: (float) $data['vol_precision'],
            lastPrice: isset($data['last_price']) ? (float) $data['last_price'] : null
        );
    }
}
