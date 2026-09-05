<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

final readonly class OkxPaperLivePolicy
{
    public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    public const HEARTBEAT_IDLE_SECONDS = 20.0;
    public const PONG_TIMEOUT_SECONDS = 10.0;
    public const MAX_FRAME_BYTES = 1_048_576;
    public const MAX_QUEUED_FRAMES = 512;
    public const MAX_QUEUED_BYTES = 2_097_152;
    public const PAUSE_QUEUED_FRAMES = 384;
    public const PAUSE_QUEUED_BYTES = 1_048_576;
    public const RESUME_QUEUED_FRAMES = 256;
    public const RESUME_QUEUED_BYTES = 524_288;
    public const MAX_RESYNC_ATTEMPTS = 3;
    public const RESYNC_ATTEMPT_TIMEOUT_SECONDS = 900.0;
    public const MAX_OVERLAP_HISTORY_PAGES = 250;
    public const LEGACY_MAX_OVERLAP_HISTORY_PAGES = 10;
    public const MAX_RETAINED_RECOVERY_ROWS = 25_500;
    public const INITIAL_HOURLY_CANDLE_TARGET = 1_000;
    public const MAX_INITIAL_HOURLY_HISTORY_PAGES = 4;
    public const MAX_TRADE_ACKNOWLEDGED_IDENTITIES = 500;
    public const MAX_CANDLE_ACKNOWLEDGED_IDENTITIES = 300;
    public const MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM =
        self::MAX_TRADE_ACKNOWLEDGED_IDENTITIES;
    public const MAX_CHECKPOINT_BYTES = 4_194_304;
    public const RECONNECT_STABLE_SECONDS = 30.0;
    public const RECONNECT_STABLE_ACCEPTED_EVENTS = 12;

    public static function acknowledgedIdentityHistoryWindow(string $logicalStream): int
    {
        return match (true) {
            preg_match(
                '/\A(?:BTCUSDT|ETHUSDT)\/public_trade\z/D',
                $logicalStream,
            ) === 1 => self::MAX_TRADE_ACKNOWLEDGED_IDENTITIES,
            preg_match(
                '/\A(?:BTCUSDT|ETHUSDT)\/candle_(?:1m|5m|15m|1H)\z/D',
                $logicalStream,
            ) === 1 => self::MAX_CANDLE_ACKNOWLEDGED_IDENTITIES,
            default => throw new \InvalidArgumentException(
                'okx_paper_live_identity_stream_invalid',
            ),
        };
    }
}
