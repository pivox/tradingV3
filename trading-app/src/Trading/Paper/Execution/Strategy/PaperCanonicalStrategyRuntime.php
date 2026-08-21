<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradingCore\Shadow\CanonicalShadowRuntime;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeOutcome;
use App\TradingCore\Shadow\ShadowRuntimeRequest;

final readonly class PaperCanonicalStrategyRuntime implements PaperCanonicalStrategyRuntimeInterface
{
    public function __construct(private CanonicalShadowRuntime $runtime)
    {
    }

    public function run(
        ShadowRuntimeRequest $request,
        ShadowRuntimeIdentityPolicy $policy,
    ): ShadowRuntimeOutcome {
        return $this->runtime->run($request, $policy);
    }
}
