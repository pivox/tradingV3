<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

final readonly class RuleMarketIdentity
{
    public function __construct(
        public string $sourceNetwork,
        public string $marketDataVenue,
        public string $marketType,
        public string $symbol,
        public string $quantityUnit,
    ) {
        $expectedUnit = $marketDataVenue === 'okx' ? 'contracts' : 'base_asset';
        if (!\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || $marketType !== 'perpetual'
            || preg_match('/\A[A-Z0-9][A-Z0-9_.-]*\z/D', $symbol) !== 1
            || $quantityUnit !== $expectedUnit
        ) {
            throw new \InvalidArgumentException('rule_market_identity_invalid');
        }
    }

    public function matches(
        string $sourceNetwork,
        string $marketDataVenue,
        string $marketType,
        string $symbol,
        string $quantityUnit,
    ): bool {
        return $this->sourceNetwork === $sourceNetwork
            && $this->marketDataVenue === $marketDataVenue
            && $this->marketType === $marketType
            && $this->symbol === $symbol
            && $this->quantityUnit === $quantityUnit;
    }

    /** @return array{source_network:string,market_data_venue:string,market_type:string,symbol:string,quantity_unit:string} */
    public function toArray(): array
    {
        return [
            'source_network' => $this->sourceNetwork,
            'market_data_venue' => $this->marketDataVenue,
            'market_type' => $this->marketType,
            'symbol' => $this->symbol,
            'quantity_unit' => $this->quantityUnit,
        ];
    }
}
