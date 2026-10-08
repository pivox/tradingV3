<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

final readonly class OkxPaperLivePolicy
{
    public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    public const HEARTBEAT_IDLE_SECONDS = 20.0;
    public const PONG_TIMEOUT_SECONDS = 10.0;
    /**
     * Frames read while a pong is missing prove the connection alive. Without any
     * backlog, a pong missing this long although frames arrive means our requests
     * no longer reach OKX (see OkxPaperPublicLiveSource::livenessDecision()).
     */
    public const LIVENESS_PONG_CAP_SECONDS = 60.0;
    /**
     * Two event-loop polls further apart than this (a long batch, a slow fsync): the
     * sockets were not read meanwhile, so their silence up to then proves nothing.
     */
    public const LIVENESS_POLL_GAP_SECONDS = 2.0;
    /**
     * Frames read while the durable queue is full wait in memory up to this bound
     * (accounted bytes), ~10 min of a 200 frames/s burst: see OkxPaperInboundFrameBuffer.
     */
    public const INBOUND_BUFFER_MAX_BYTES = 268_435_456;
    /** While streaming, admitted frames are persisted at most this often. */
    public const STREAMING_QUEUE_SAVE_INTERVAL_SECONDS = 0.25;
    public const MAX_FRAME_BYTES = 1_048_576;
    public const MAX_QUEUED_FRAMES = 512;
    public const MAX_QUEUED_BYTES = 2_097_152;
    public const PAUSE_QUEUED_FRAMES = 128;
    public const PAUSE_QUEUED_BYTES = 1_048_576;
    public const RESUME_QUEUED_FRAMES = 64;
    public const RESUME_QUEUED_BYTES = 524_288;
    public const MAX_RESYNC_ATTEMPTS = 3;
    public const RESYNC_ATTEMPT_TIMEOUT_SECONDS = 900.0;
    public const MAX_OVERLAP_HISTORY_PAGES = 250;
    public const PREVIOUS_MAX_OVERLAP_HISTORY_PAGES = 50;
    public const LEGACY_MAX_OVERLAP_HISTORY_PAGES = 10;
    public const MAX_RETAINED_RECOVERY_ROWS = 25_500;
    /**
     * A reconnect trade recovery of more than this many trade ids pages forward from
     * the frontier (history-trades `after` = frontier + 100: the frontier and the 99
     * next ids), each page emitted and acknowledged before the next, so nothing but
     * one page is retained whatever the gap (the backward pagination retains the
     * whole gap: MAX_RETAINED_RECOVERY_ROWS, ~5 min of BTC in the US session).
     */
    public const FORWARD_RECOVERY_MIN_TRADES = 2_000;
    /** Pages of one forward trade recovery: up to ~2 million trades. */
    public const MAX_FORWARD_RECOVERY_PAGES = 20_000;
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
