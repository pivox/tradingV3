<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Usage;

interface EffectiveConfigUsageStoreInterface
{
    /** @return iterable<EffectiveConfigUsageFact> */
    public function find(EffectiveConfigUsageScope $scope, string $identifier): iterable;
}
