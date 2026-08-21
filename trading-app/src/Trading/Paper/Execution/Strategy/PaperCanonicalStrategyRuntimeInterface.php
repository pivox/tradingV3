<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeOutcome;
use App\TradingCore\Shadow\ShadowRuntimeRequest;

interface PaperCanonicalStrategyRuntimeInterface
{
    public function run(
        ShadowRuntimeRequest $request,
        ShadowRuntimeIdentityPolicy $policy,
    ): ShadowRuntimeOutcome;
}
