<?php

declare(strict_types=1);

namespace App\Indicator\Provider;

use App\Contract\Indicator\IndicatorEngineInterface;
use App\Contract\Indicator\IndicatorMainProviderInterface;
use App\Contract\Indicator\IndicatorProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Service central d'accès aux capacités indicateurs (façade),
 * calqué sur App\Provider\MainProvider mais limité au domaine Indicator.
 */
#[AsAlias(id: IndicatorMainProviderInterface::class)]
final readonly class IndicatorMainProvider implements IndicatorMainProviderInterface
{
    public function __construct(
        private IndicatorEngineInterface $engine,
        private IndicatorProviderInterface $indicatorProvider,
    ) {}

    public function getEngine(): IndicatorEngineInterface
    {
        return $this->engine;
    }

    public function getIndicatorProvider(): IndicatorProviderInterface
    {
        return $this->indicatorProvider;
    }
}

