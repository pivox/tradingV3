<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;

interface OkxPrivateRestGatewayReaderInterface
{
    public function accountReadable(): bool;

    /** @return list<PositionDto> */
    public function positions(): array;

    /** @return list<OrderDto> */
    public function openOrders(): array;

    /** @return list<mixed> */
    public function fills(): array;
}
