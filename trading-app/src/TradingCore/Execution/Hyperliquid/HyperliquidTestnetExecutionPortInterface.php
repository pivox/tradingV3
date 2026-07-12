<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;

interface HyperliquidTestnetExecutionPortInterface
{
    public function execute(ExecutionRequest $request): ExecutionResult;
}
