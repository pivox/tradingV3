<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final readonly class HyperliquidPaperLivePolicy
{
    public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    public const HEARTBEAT_IDLE_SECONDS = 45.0;
    public const PONG_TIMEOUT_SECONDS = 10.0;
    public const FUNDING_REFRESH_SECONDS = 3000.0;
    public const MAX_FRAME_BYTES = 1_048_576;
    public const MAX_QUEUED_FRAMES = 256;
    public const MAX_QUEUED_BYTES = 2_097_152;
    public const MAX_BOOK_LEVELS_PER_SIDE = 500;
    public const MAX_CHECKPOINT_BYTES = 1_048_576;
    public const MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM = 500;

    public static function configurationSha256(PaperMarketDataNetwork $network): string
    {
        [$infoUri, $webSocketUri] = match ($network) {
            PaperMarketDataNetwork::MAINNET => [
                HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
                HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
            ],
            PaperMarketDataNetwork::TESTNET => [
                HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
                HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
            ],
            PaperMarketDataNetwork::LEGACY_UNKNOWN => throw new \InvalidArgumentException(
                'hyperliquid_paper_network_invalid',
            ),
        };

        return hash('sha256', CanonicalJson::encode([
            'schema_version' => HyperliquidPaperLiveCheckpoint::SCHEMA_VERSION,
            'policy_version' => HyperliquidPaperLiveCheckpoint::POLICY_VERSION,
            'network' => $network->value,
            'info_uri' => $infoUri,
            'websocket_uri' => $webSocketUri,
            'subscriptions' => (new HyperliquidPaperPublicSubscriptionSet())->subscriptions(),
            'symbols' => ['BTCUSDT' => 'BTC', 'ETHUSDT' => 'ETH'],
            'limits' => [
                'frame_bytes' => self::MAX_FRAME_BYTES,
                'queue_frames' => self::MAX_QUEUED_FRAMES,
                'queue_bytes' => self::MAX_QUEUED_BYTES,
                'book_levels_per_side' => self::MAX_BOOK_LEVELS_PER_SIDE,
                'heartbeat_idle_seconds' => self::HEARTBEAT_IDLE_SECONDS,
                'pong_timeout_seconds' => self::PONG_TIMEOUT_SECONDS,
                'funding_refresh_seconds' => self::FUNDING_REFRESH_SECONDS,
                'reconnect_delays_seconds' => self::RECONNECT_DELAYS_SECONDS,
            ],
        ]));
    }
}
