<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

/** Immediate shared configuration boundary for modern MTF and TradeEntry requests. */
final readonly class CanonicalTradingConfigRuntimeAdapter
{
    public function __construct(private EffectiveTradingConfigResolverInterface $resolver)
    {
    }

    public function forMtf(EffectiveTradingConfigRequest $request): EffectiveTradingConfigSnapshot
    {
        return $this->resolver->resolve($request);
    }

    public function forTradeEntry(EffectiveTradingConfigRequest $request): EffectiveTradingConfigSnapshot
    {
        return $this->resolver->resolve($request);
    }
}
