<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

final readonly class OkxPaperLivePolicy
{
    public const RECONNECT_DELAYS_SECONDS = [1.0, 2.0, 4.0, 8.0, 15.0, 30.0];
    public const HEARTBEAT_IDLE_SECONDS = 20.0;
    public const PONG_TIMEOUT_SECONDS = 10.0;
    public const MAX_FRAME_BYTES = 1_048_576;
    public const MAX_QUEUED_FRAMES = 256;
    public const MAX_QUEUED_BYTES = 2_097_152;
    public const MAX_RESYNC_ATTEMPTS = 3;
    public const RESYNC_ATTEMPT_TIMEOUT_SECONDS = 10.0;
    public const MAX_OVERLAP_HISTORY_PAGES = 10;
    public const RECONNECT_STABLE_SECONDS = 30.0;
    public const RECONNECT_STABLE_ACCEPTED_EVENTS = 12;
}
