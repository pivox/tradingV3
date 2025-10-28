<?php

declare(strict_types=1);

namespace App\Contract\Indicator;

/**
 * Façade centrale des services indicateurs, à la manière de MainProvider.
 * Expose uniquement des contrats, jamais les classes Core internes.
 */
interface IndicatorMainProviderInterface
{
    public function getEngine(): IndicatorEngineInterface;

    public function getIndicatorProvider(): IndicatorProviderInterface;
}

