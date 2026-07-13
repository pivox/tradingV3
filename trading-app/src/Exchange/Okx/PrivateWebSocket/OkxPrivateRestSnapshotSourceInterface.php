<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

interface OkxPrivateRestSnapshotSourceInterface
{
    public function accountReadable(): bool;

    /** @return list<PositionSnapshotItem> */
    public function positions(): array;

    /** @return list<OrderSnapshotItem> */
    public function openOrders(): array;

    /** @return list<FillSnapshotItem> */
    public function fills(): array;
}
