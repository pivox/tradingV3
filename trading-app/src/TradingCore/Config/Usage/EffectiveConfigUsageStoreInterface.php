<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Usage;

interface EffectiveConfigUsageStoreInterface
{
    /** @return list<EffectiveConfigUsageFact> */
    public function find(EffectiveConfigUsageScope $scope, string $identifier): array;
}
