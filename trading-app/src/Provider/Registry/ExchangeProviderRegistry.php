<?php

declare(strict_types=1);

namespace App\Provider\Registry;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\Exception\ProviderNotFoundException;

final class ExchangeProviderRegistry implements ExchangeProviderRegistryInterface
{
    /**
     * @var array<string, ExchangeProviderBundle>
     */
    private array $bundles = [];

    private readonly ExchangeContext $defaultContext;

    /**
     * @param iterable<ExchangeProviderBundle> $bundles
     */
    public function __construct(
        iterable $bundles,
        ?Exchange $defaultExchange = null,
        ?MarketType $defaultMarketType = null,
    ) {
        foreach ($bundles as $bundle) {
            $this->bundles[(string) $bundle->context()] = $bundle;
        }

        $defaultExchange ??= Exchange::BITMART;
        $defaultMarketType ??= MarketType::PERPETUAL;
        $this->defaultContext = new ExchangeContext($defaultExchange, $defaultMarketType);
    }

    public function get(?ExchangeContext $context = null): ExchangeProviderBundle
    {
        $context ??= $this->defaultContext;
        $key = (string) $context;

        if (!isset($this->bundles[$key])) {
            throw ProviderNotFoundException::forContext($context, array_keys($this->bundles));
        }

        return $this->bundles[$key];
    }

    public function getDefaultContext(): ExchangeContext
    {
        return $this->defaultContext;
    }

    public function all(): array
    {
        return array_values($this->bundles);
    }
}

