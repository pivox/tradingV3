<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Port;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;

interface ExecutionPortInterface
{
    public function execute(ExecutionRequest $request): ExecutionResult;
}
