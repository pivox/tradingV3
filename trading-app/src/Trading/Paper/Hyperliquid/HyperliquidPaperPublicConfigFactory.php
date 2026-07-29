<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid;

use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final readonly class HyperliquidPaperPublicConfigFactory
{
    public function __construct(
        private bool $acquisitionEnabled,
        private string $dataRoot,
    ) {
    }

    public function create(string $network): HyperliquidPaperPublicConfig
    {
        [$paperNetwork, $infoUri, $webSocketUri] = match ($network) {
            'mainnet' => [
                PaperMarketDataNetwork::MAINNET,
                HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
                HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
            ],
            'testnet' => [
                PaperMarketDataNetwork::TESTNET,
                HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
                HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
            ],
            default => throw new \InvalidArgumentException('hyperliquid_paper_network_invalid'),
        };

        return new HyperliquidPaperPublicConfig(
            network: $paperNetwork,
            acquisitionEnabled: $this->acquisitionEnabled,
            infoUri: $infoUri,
            webSocketUri: $webSocketUri,
            dataRoot: $this->dataRoot,
        );
    }
}
