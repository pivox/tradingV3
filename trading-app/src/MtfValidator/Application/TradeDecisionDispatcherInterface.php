<?php

declare(strict_types=1);

namespace App\MtfValidator\Application;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;

interface TradeDecisionDispatcherInterface
{
    public function dispatchFromResponse(MtfRunRequestDto $request, MtfRunResponseDto $response): void;
}
