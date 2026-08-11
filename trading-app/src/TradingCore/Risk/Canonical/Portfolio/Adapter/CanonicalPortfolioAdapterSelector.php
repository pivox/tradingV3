<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio\Adapter;

use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;

final readonly class CanonicalPortfolioAdapterSelector
{
    public function __construct(
        private FakeCanonicalPortfolioAdapter $fake,
        private PaperCanonicalPortfolioAdapter $paper,
        private BacktestCanonicalPortfolioAdapter $backtest,
    ) {
    }

    public function select(ShadowExecutionCapability $capability): CanonicalPortfolioAdapterInterface
    {
        return match ($capability) {
            ShadowExecutionCapability::Fake => $this->fake,
            ShadowExecutionCapability::Paper => $this->paper,
            ShadowExecutionCapability::Backtest => $this->backtest,
            ShadowExecutionCapability::PrivateMainnet => throw new CanonicalPortfolioException(
                'private_mainnet_execution_forbidden',
            ),
        };
    }
}
