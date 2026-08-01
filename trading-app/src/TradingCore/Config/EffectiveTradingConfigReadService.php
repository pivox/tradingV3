<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

final readonly class EffectiveTradingConfigReadService
{
    public function __construct(private EffectiveTradingConfigResolver $resolver)
    {
    }

    /** @return array<string,mixed> */
    public function describe(EffectiveTradingConfigRequest $request): array
    {
        return $this->resolver->resolve($request)->toArray();
    }
}
