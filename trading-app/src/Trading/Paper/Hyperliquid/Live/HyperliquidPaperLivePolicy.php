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
    /*
     * The bbo subscriptions push up to one frame per block per coin (about 20 frames/s with
     * trades and candles, 60 in a burst), and Hyperliquid sends every fill of a block in one
     * trades frame (thousands in a sweep): the budgets absorb a long burst backlog.
     */
    public const NETWORK_PUMP_FRAME_HIGH_WATER = 2048;
    public const NETWORK_RESUME_FRAME_LOW_WATER = 1024;
    public const NETWORK_PUMP_BYTE_HIGH_WATER = 16_777_216;
    public const NETWORK_RESUME_BYTE_LOW_WATER = 8_388_608;
    public const FUNDING_REFRESH_SECONDS = 3000.0;
    public const MAX_FRAME_BYTES = 8_388_608;
    public const MAX_QUEUED_FRAMES = 4096;
    public const MAX_QUEUED_BYTES = 33_554_432;
    public const MAX_BOOK_LEVELS_PER_SIDE = 500;
    public const MAX_CHECKPOINT_BYTES = 1_048_576;
    public const MAX_PENDING_TRADE_ROWS = 256;
    public const MAX_ACKNOWLEDGED_EVENT_IDENTITIES = 512;
    public const MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM = 500;
    /** Connection age at which a make-before-break rotation starts (HL drops ~3 h connections). */
    public const CONNECTION_ROTATION_SECONDS = 8100.0;
    public const ROTATION_OVERLAP_TIMEOUT_SECONDS = 60.0;
    public const ROTATION_RETRY_SECONDS = 30.0;
    /** A lost streaming connection is replaced within this budget, or the capture fails closed. */
    public const RECOVERY_DEADLINE_SECONDS = 30.0;
    public const RECOVERY_ATTEMPT_TIMEOUT_SECONDS = 10.0;
    public const RECOVERY_MAX_ATTEMPTS = 3;
    public const RECOVERY_RETRY_SECONDS = 1.0;
    /**
     * The permanent standby keeps this window of frames (and, per stream, everything since
     * the last frame the active connection caught up with); above the soft cap the caught-up
     * frames go regardless of age, so a busy market never reaches the hard cap.
     */
    public const HOT_STANDBY_WINDOW_SECONDS = 20.0;
    public const HOT_STANDBY_MIN_ITEMS = 128;
    public const HOT_STANDBY_SOFT_MAX_ITEMS = 768;
    public const HOT_STANDBY_REOPEN_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    /**
     * Books (one per bbo message, about 14/s) are held and emitted together as one durable
     * batch at the latest this long after the first, or at this count, and always before any
     * other event, switch or stop: their order and timestamps are unchanged. A pending batch
     * is saved whole in the checkpoint (88 canonical JSON nodes per book): 128 books on top
     * of full identity windows stay under CanonicalJson::MAX_NODES with a fifth to spare.
     */
    public const BOOK_BATCH_SECONDS = 1.0;
    public const MAX_BOOK_BATCH_EVENTS = 128;
    /**
     * A fully covered minute with fewer recorded trades than its closed 1m candle counts is a
     * hole (see HyperliquidTradeCountAudit). It is always logged; whether the capture then
     * fails closed is a deployment decision, warn-only until the check has run for days. Not
     * part of configurationSha256: flipping it must not make earlier datasets unverifiable
     * (verifyForBaseline rejects such a hole regardless).
     */
    public const TRADE_COUNT_BELOW_CANDLE_FAILS_CLOSED = false;

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
                'connection_rotation_seconds' => self::CONNECTION_ROTATION_SECONDS,
                'rotation_overlap_timeout_seconds' => self::ROTATION_OVERLAP_TIMEOUT_SECONDS,
                'rotation_retry_seconds' => self::ROTATION_RETRY_SECONDS,
                'recovery_deadline_seconds' => self::RECOVERY_DEADLINE_SECONDS,
                'recovery_attempt_timeout_seconds' => self::RECOVERY_ATTEMPT_TIMEOUT_SECONDS,
                'recovery_max_attempts' => self::RECOVERY_MAX_ATTEMPTS,
                'recovery_retry_seconds' => self::RECOVERY_RETRY_SECONDS,
                'hot_standby_window_seconds' => self::HOT_STANDBY_WINDOW_SECONDS,
                'hot_standby_min_items' => self::HOT_STANDBY_MIN_ITEMS,
                'hot_standby_soft_max_items' => self::HOT_STANDBY_SOFT_MAX_ITEMS,
                'hot_standby_reopen_delays_seconds' => self::HOT_STANDBY_REOPEN_DELAYS_SECONDS,
                'book_batch_seconds' => self::BOOK_BATCH_SECONDS,
                'max_book_batch_events' => self::MAX_BOOK_BATCH_EVENTS,
            ],
        ]));
    }
}
