<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

final readonly class OkxPrivateRestSnapshotProbe
{
    public function __construct(private OkxPrivateRestSnapshotSourceInterface $source)
    {
    }

    public function probe(\DateTimeImmutable $now): OkxPrivateRestSnapshot
    {
        $blockingErrors = [];
        $accountReadable = false;
        $positions = [];
        $openOrders = [];
        $fills = [];

        try {
            $accountReadable = $this->source->accountReadable();
            if (!$accountReadable) {
                $blockingErrors[] = 'okx_private_rest_account_snapshot_failed';
            }
        } catch (\Throwable) {
            $blockingErrors[] = 'okx_private_rest_account_snapshot_failed';
        }

        try {
            $positions = $this->source->positions();
        } catch (\Throwable) {
            $blockingErrors[] = 'okx_private_rest_positions_snapshot_failed';
        }

        try {
            $openOrders = $this->source->openOrders();
        } catch (\Throwable) {
            $blockingErrors[] = 'okx_private_rest_orders_snapshot_failed';
        }

        try {
            $fills = $this->source->fills();
        } catch (\Throwable) {
            $blockingErrors[] = 'okx_private_rest_fills_snapshot_failed';
        }

        return new OkxPrivateRestSnapshot(
            observedAt: $now,
            accountReadable: $accountReadable,
            positions: $positions,
            openOrders: $openOrders,
            fills: $fills,
            complete: $blockingErrors === [],
            blockingErrors: $blockingErrors,
        );
    }
}
