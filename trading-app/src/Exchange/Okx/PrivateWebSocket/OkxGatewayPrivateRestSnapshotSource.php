<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

final readonly class OkxGatewayPrivateRestSnapshotSource implements OkxPrivateRestSnapshotSourceInterface
{
    public function __construct(private OkxPrivateRestGatewayReaderInterface $reader)
    {
    }

    public function accountReadable(): bool
    {
        return $this->reader->accountReadable();
    }

    /** @return list<PositionSnapshotItem> */
    public function positions(): array
    {
        return array_map(
            PositionSnapshotItem::fromProviderDto(...),
            $this->reader->positions(),
        );
    }

    /** @return list<OrderSnapshotItem> */
    public function openOrders(): array
    {
        return array_map(
            OrderSnapshotItem::fromProviderDto(...),
            $this->reader->openOrders(),
        );
    }

    /** @return list<FillSnapshotItem> */
    public function fills(): array
    {
        $items = [];
        foreach ($this->reader->fills() as $fill) {
            if (!\is_array($fill)) {
                throw new \UnexpectedValueException('okx_private_rest_fill_snapshot_item_invalid');
            }

            $items[] = FillSnapshotItem::fromProviderArray($fill);
        }

        return $items;
    }
}
