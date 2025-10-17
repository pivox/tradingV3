<?php
// src/Dto/ContractDetailsCollection.php
declare(strict_types=1);

namespace App\Dto;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * Collection typée de ContractDetailsDto
 */
final class ContractDetailsCollection implements IteratorAggregate, Countable
{
    /** @var ContractDetailsDto[] */
    private array $items;

    /**
     * @param ContractDetailsDto[] $items
     */
    public function __construct(array $items)
    {
        foreach ($items as $i) {
            if (!$i instanceof ContractDetailsDto) {
                throw new \InvalidArgumentException('ContractDetailsCollection n’accepte que des ContractDetailsDto');
            }
        }
        $this->items = \array_values($items);
    }

    /**
     * @return ArrayIterator<int,ContractDetailsDto>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function first(): ?ContractDetailsDto
    {
        return $this->items[0] ?? null;
    }

    public function findBySymbol(string $symbol): ?ContractDetailsDto
    {
        foreach ($this->items as $item) {
            if (strcasecmp($item->symbol, $symbol) === 0) {
                return $item;
            }
        }

        return null;
    }

    /** @return string[] */
    public function symbols(): array
    {
        return array_map(fn (ContractDetailsDto $d) => $d->symbol, $this->items);
    }

    /** @return ContractDetailsDto[] */
    public function all(): array
    {
        return $this->items;
    }
}
