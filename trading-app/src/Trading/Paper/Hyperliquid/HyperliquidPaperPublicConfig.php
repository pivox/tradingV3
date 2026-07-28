<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid;

use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final readonly class HyperliquidPaperPublicConfig
{
    public const MAINNET_INFO_URI = 'https://api.hyperliquid.xyz/info';
    public const TESTNET_INFO_URI = 'https://api.hyperliquid-testnet.xyz/info';

    public function __construct(
        public PaperMarketDataNetwork $network,
        public bool $acquisitionEnabled,
        public string $infoUri,
        public string $dataRoot,
    ) {
        $allowedInfoUri = match ($this->network) {
            PaperMarketDataNetwork::MAINNET => self::MAINNET_INFO_URI,
            PaperMarketDataNetwork::TESTNET => self::TESTNET_INFO_URI,
            PaperMarketDataNetwork::LEGACY_UNKNOWN => throw new \InvalidArgumentException(
                'hyperliquid_paper_network_invalid',
            ),
        };

        if ($this->infoUri !== $allowedInfoUri) {
            throw new \InvalidArgumentException('hyperliquid_paper_info_uri_not_allowed');
        }
    }
}
