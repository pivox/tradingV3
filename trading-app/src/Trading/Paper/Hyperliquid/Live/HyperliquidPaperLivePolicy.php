<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final readonly class HyperliquidPaperLivePolicy
{
    public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    public const HEARTBEAT_IDLE_SECONDS = 5.0;
    public const PONG_TIMEOUT_SECONDS = 10.0;
    public const NETWORK_PUMP_SECONDS = 0.001;
    public const NETWORK_PUMP_FRAME_HIGH_WATER = 128;
    public const NETWORK_RESUME_FRAME_LOW_WATER = 64;
    public const NETWORK_PUMP_BYTE_HIGH_WATER = 1_048_576;
    public const NETWORK_RESUME_BYTE_LOW_WATER = 524_288;
    public const FUNDING_REFRESH_SECONDS = 3000.0;
    public const MAX_FRAME_BYTES = 1_048_576;
    public const MAX_QUEUED_FRAMES = 256;
    public const MAX_QUEUED_BYTES = 2_097_152;
    public const MAX_BOOK_LEVELS_PER_SIDE = 500;
    public const MAX_CHECKPOINT_BYTES = 1_048_576;
    public const MAX_PENDING_TRADE_ROWS = 8;
    public const MAX_ACKNOWLEDGED_EVENT_IDENTITIES = 512;
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
            'warmup_before_websocket' => true,
            'subscriptions' => (new HyperliquidPaperPublicSubscriptionSet())->subscriptions(),
            'symbols' => ['BTCUSDT' => 'BTC', 'ETHUSDT' => 'ETH'],
            'limits' => [
                'frame_bytes' => self::MAX_FRAME_BYTES,
                'queue_frames' => self::MAX_QUEUED_FRAMES,
                'queue_bytes' => self::MAX_QUEUED_BYTES,
                'checkpoint_bytes' => self::MAX_CHECKPOINT_BYTES,
                'pending_trade_rows' => self::MAX_PENDING_TRADE_ROWS,
                'acknowledged_event_identities' => self::MAX_ACKNOWLEDGED_EVENT_IDENTITIES,
                'trade_identities_per_stream' => self::MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM,
                'book_levels_per_side' => self::MAX_BOOK_LEVELS_PER_SIDE,
                'heartbeat_idle_seconds' => self::HEARTBEAT_IDLE_SECONDS,
                'pong_timeout_seconds' => self::PONG_TIMEOUT_SECONDS,
                'network_pump_seconds' => self::NETWORK_PUMP_SECONDS,
                'network_pump_frame_high_water' => self::NETWORK_PUMP_FRAME_HIGH_WATER,
                'network_resume_frame_low_water' => self::NETWORK_RESUME_FRAME_LOW_WATER,
                'network_pump_byte_high_water' => self::NETWORK_PUMP_BYTE_HIGH_WATER,
                'network_resume_byte_low_water' => self::NETWORK_RESUME_BYTE_LOW_WATER,
                'funding_refresh_seconds' => self::FUNDING_REFRESH_SECONDS,
                'reconnect_delays_seconds' => self::RECONNECT_DELAYS_SECONDS,
            ],
        ]));
    }
}
