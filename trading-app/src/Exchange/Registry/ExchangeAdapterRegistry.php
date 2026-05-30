<?php

declare(strict_types=1);

namespace App\Exchange\Registry;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

#[AsAlias(id: ExchangeAdapterRegistryInterface::class)]
final class ExchangeAdapterRegistry implements ExchangeAdapterRegistryInterface
{
    /**
     * @var array<string, ExchangeAdapterInterface>
     */
    private array $adapters = [];

    /**
     * @param iterable<ExchangeAdapterInterface> $adapters
     */
    public function __construct(
        #[TaggedIterator('app.exchange_adapter')]
        iterable $adapters,
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[$this->key($adapter->exchange(), $adapter->marketType())] = $adapter;
        }
    }

    public function get(Exchange $exchange, MarketType $marketType): ExchangeAdapterInterface
    {
        $key = $this->key($exchange, $marketType);
        if (!isset($this->adapters[$key])) {
            throw ExchangeAdapterNotFoundException::forContext($exchange, $marketType, array_keys($this->adapters));
        }

        return $this->adapters[$key];
    }

    public function all(): array
    {
        return array_values($this->adapters);
    }

    private function key(Exchange $exchange, MarketType $marketType): string
    {
        return sprintf('%s::%s', $exchange->value, $marketType->value);
    }
}
