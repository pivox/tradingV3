<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Provider\Hyperliquid\HyperliquidAccountGateway;
use App\Provider\Hyperliquid\HyperliquidMetadataProvider;

final readonly class StrictHyperliquidExecutionStateProvider implements HyperliquidExecutionStateProviderInterface
{
    public function __construct(
        private HyperliquidMetadataProvider $metadata,
        private HyperliquidAccountGateway $account,
    ) {
    }

    public function current(string $symbol): HyperliquidExecutionState
    {
        $book = $this->metadata->getOrderBook($symbol, 1);
        $bid = $book['bids'][0]['price'] ?? null;
        $ask = $book['asks'][0]['price'] ?? null;
        $observedAt = $book['timestamp'] ?? null;
        if ((!is_int($bid) && !is_float($bid))
            || (!is_int($ask) && !is_float($ask))
            || !$observedAt instanceof \DateTimeImmutable
        ) {
            throw new \RuntimeException('hyperliquid_execution_quote_unavailable');
        }

        $position = $this->account->getPosition($symbol);
        $observedLeverage = null;
        $observedMarginMode = null;
        if ($position !== null) {
            try {
                $observedLeverage = $position->leverage->toInt();
            } catch (\Throwable) {
                throw new \RuntimeException('hyperliquid_observed_leverage_invalid');
            }
            $observedMarginMode = $position->metadata['margin_mode'] ?? null;
            if (!is_string($observedMarginMode) || !in_array($observedMarginMode, ['isolated', 'cross'], true)) {
                throw new \RuntimeException('hyperliquid_observed_margin_mode_invalid');
            }
        }

        return new HyperliquidExecutionState(
            symbol: strtoupper(trim($symbol)),
            bestBid: (float) $bid,
            bestAsk: (float) $ask,
            observedAt: $observedAt,
            observedLeverage: $observedLeverage,
            observedMarginMode: $observedMarginMode,
            hasOpenPosition: $position !== null,
        );
    }
}
