<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Exception;

use App\TradingCore\Config\EffectiveTradingConfigRequest;

final class NonExecutableTradingConfigException extends TradingConfigException
{
    /** @param non-empty-list<string> $blockers */
    public function __construct(
        public readonly EffectiveTradingConfigRequest $request,
        public readonly array $blockers,
    ) {
        parent::__construct('Effective runtime configuration rejected: ' . implode('; ', $blockers) . '.');
    }
}
