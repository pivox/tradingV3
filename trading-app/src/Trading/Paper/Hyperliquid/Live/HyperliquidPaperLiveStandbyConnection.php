<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use React\EventLoop\TimerInterface;

/**
 * The second connection of a make-before-break rotation: it subscribes and buffers while the
 * active connection keeps being recorded, until every stream is proven continuous.
 *
 * @phpstan-import-type StandbyItem from HyperliquidPaperLiveRotationOverlap
 */
final class HyperliquidPaperLiveStandbyConnection
{
    public const OPENING = 'opening';
    public const OVERLAPPING = 'overlapping';
    public const DRAINING = 'draining';

    public string $state = self::OPENING;
    public ?\DateTimeImmutable $connectedAt = null;
    public ?\DateTimeImmutable $lastFrameAt = null;
    public int $frames = 0;
    public ?TimerInterface $timeoutTimer = null;
    public bool $timedOut = false;

    /**
     * A permanent standby buffers a bounded window while no switch is in progress; it is
     * promoted (hot = false) when a rotation or a loss of the active connection uses it.
     */
    public bool $hot = false;
    public ?\DateTimeImmutable $promotedAt = null;
    public int $prunedItems = 0;
    public float $prunedAt = 0.0;

    /** @var list<StandbyItem> */
    public array $items = [];

    /**
     * The first trades message per coin (the snapshot Hyperliquid sends on subscription).
     *
     * @var array<string, array{rows: int, first_time: int, last_time: int}>
     */
    public array $snapshots = [];

    /**
     * The first trade time of the second trades message per coin: the start of the live
     * stream, whose distance to the snapshot's last row is Hyperliquid's subscription crack.
     *
     * @var array<string, int>
     */
    public array $firstLiveTradeTimes = [];

    public readonly HyperliquidPaperPublicFrameQueue $queue;
    public readonly HyperliquidPaperPublicSubscriptionSet $subscriptions;
    public readonly HyperliquidPaperPublicFrameDecoder $decoder;

    public function __construct(
        public readonly HyperliquidPaperPublicWebSocketTransportInterface $transport,
        public readonly int $generation,
        public readonly int $attempt,
        public readonly \DateTimeImmutable $startedAt,
    ) {
        $this->queue = new HyperliquidPaperPublicFrameQueue();
        $this->subscriptions = new HyperliquidPaperPublicSubscriptionSet();
        $this->decoder = new HyperliquidPaperPublicFrameDecoder($this->subscriptions);
    }
}
