<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

final readonly class HyperliquidPaperLivePolicy
{
    public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    public const HEARTBEAT_IDLE_SECONDS = 45.0;
    public const PONG_TIMEOUT_SECONDS = 10.0;
    public const MAX_FRAME_BYTES = 1_048_576;
    public const MAX_QUEUED_FRAMES = 256;
    public const MAX_QUEUED_BYTES = 2_097_152;
    public const MAX_BOOK_LEVELS_PER_SIDE = 500;
    public const MAX_CHECKPOINT_BYTES = 1_048_576;
    public const MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM = 500;
}
