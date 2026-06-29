<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\Dto\PositionDto;

final class OkxPositionGateway
{
    /**
     * @return PositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositionsOrFail(?string $symbol = null): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        throw $this->notImplemented(__METHOD__);
    }

    private function notImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_position_not_implemented', $operation);
    }
}
