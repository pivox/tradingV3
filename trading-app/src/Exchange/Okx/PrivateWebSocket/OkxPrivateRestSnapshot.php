<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

final readonly class OkxPrivateRestSnapshot
{
    private const ALLOWED_ERRORS = [
        'okx_private_rest_account_snapshot_failed',
        'okx_private_rest_positions_snapshot_failed',
        'okx_private_rest_orders_snapshot_failed',
        'okx_private_rest_fills_snapshot_failed',
    ];

    public \DateTimeImmutable $observedAt;
    public bool $accountReadable;

    /** @var list<PositionSnapshotItem> */
    public array $positions;

    /** @var list<OrderSnapshotItem> */
    public array $openOrders;

    /** @var list<FillSnapshotItem> */
    public array $fills;

    public bool $complete;

    /** @var list<string> */
    public array $blockingErrors;

    /**
     * @param list<PositionSnapshotItem> $positions
     * @param list<OrderSnapshotItem>    $openOrders
     * @param list<FillSnapshotItem>     $fills
     * @param list<string>               $blockingErrors
     */
    public function __construct(
        \DateTimeImmutable $observedAt,
        bool $accountReadable,
        array $positions,
        array $openOrders,
        array $fills,
        bool $complete,
        array $blockingErrors,
    ) {
        self::assertItems($positions, PositionSnapshotItem::class);
        self::assertItems($openOrders, OrderSnapshotItem::class);
        self::assertItems($fills, FillSnapshotItem::class);

        $blockingErrors = array_values(array_unique($blockingErrors));
        foreach ($blockingErrors as $error) {
            if (!\in_array($error, self::ALLOWED_ERRORS, true)) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_errors_invalid');
            }
        }
        if ($complete !== ($blockingErrors === [] && $accountReadable)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_complete_invalid');
        }

        $this->observedAt = $observedAt->setTimezone(new \DateTimeZone('UTC'));
        $this->accountReadable = $accountReadable;
        $this->positions = array_values($positions);
        $this->openOrders = array_values($openOrders);
        $this->fills = array_values($fills);
        $this->complete = $complete;
        $this->blockingErrors = $blockingErrors;
    }

    /**
     * @param array<mixed>     $items
     * @param class-string<T> $expectedClass
     * @template T of object
     */
    private static function assertItems(array $items, string $expectedClass): void
    {
        foreach ($items as $item) {
            if (!$item instanceof $expectedClass) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_items_invalid');
            }
        }
    }
}
