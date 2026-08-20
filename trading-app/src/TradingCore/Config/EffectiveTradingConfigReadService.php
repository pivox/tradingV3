<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\TradingCore\Config\Audit\EffectiveConfigViewerDocumentFactory;

final readonly class EffectiveTradingConfigReadService
{
    public function __construct(
        private EffectiveTradingConfigResolverInterface $resolver,
        private EffectiveConfigViewerDocumentFactory $documents,
    ) {
    }

    /** @return array<string,mixed> */
    public function describe(EffectiveTradingConfigRequest $request): array
    {
        return $this->documents->fromSnapshot($this->resolver->resolve($request))->payload;
    }
}
