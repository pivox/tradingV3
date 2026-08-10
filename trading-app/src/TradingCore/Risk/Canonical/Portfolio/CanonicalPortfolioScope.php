<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

final readonly class CanonicalPortfolioScope
{
    public function __construct(
        public string $network,
        public string $exchange,
        public string $environment,
        public string $accountId,
        public string $modeId,
        public string $quoteCurrency,
    ) {
        foreach ([$network, $exchange, $environment, $accountId, $modeId] as $identity) {
            if (preg_match('/\A[a-z0-9][a-z0-9_.:-]*\z/D', $identity) !== 1) {
                throw new CanonicalPortfolioException('canonical_portfolio_scope_invalid');
            }
        }
        if (preg_match('/\A[A-Z][A-Z0-9]{2,11}\z/D', $quoteCurrency) !== 1) {
            throw new CanonicalPortfolioException('canonical_portfolio_scope_invalid');
        }
    }

    /** @return array{network:string,exchange:string,environment:string,account_id:string,mode_id:string,quote_currency:string} */
    public function toArray(): array
    {
        return [
            'network' => $this->network,
            'exchange' => $this->exchange,
            'environment' => $this->environment,
            'account_id' => $this->accountId,
            'mode_id' => $this->modeId,
            'quote_currency' => $this->quoteCurrency,
        ];
    }
}
