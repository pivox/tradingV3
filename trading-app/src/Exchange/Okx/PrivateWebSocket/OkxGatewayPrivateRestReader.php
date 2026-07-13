<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Provider\Okx\OkxAccountGateway;
use App\Provider\Okx\OkxOrderGateway;

final readonly class OkxGatewayPrivateRestReader implements OkxPrivateRestGatewayReaderInterface
{
    public function __construct(
        private OkxAccountGateway $accountGateway,
        private OkxOrderGateway $orderGateway,
    ) {
    }

    public function accountReadable(): bool
    {
        return $this->accountGateway->healthCheck();
    }

    /** @return list<PositionDto> */
    public function positions(): array
    {
        return $this->accountGateway->getOpenPositionsOrFail(null);
    }

    /** @return list<OrderDto> */
    public function openOrders(): array
    {
        return $this->orderGateway->getOpenOrdersOrFail(null);
    }

    /** @return list<mixed> */
    public function fills(): array
    {
        return $this->accountGateway->getRecentFillsForSnapshotOrFail();
    }
}
