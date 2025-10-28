<?php
declare(strict_types=1);

namespace App\Util\Dto;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
class DtoList implements IteratorAggregate, Countable
{
    /** @var T[] */
    protected array $items;

    /**
     * @param T[] $items
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /** @return T[] */
    public function toArray(): array
    {
        return $this->items;
    }

    /** @return static */
    public function filter(callable $predicate): static
    {
        // $predicate(T $item): bool
        $filtered = array_values(array_filter($this->items, $predicate));
        return new static($filtered);
    }

    /** @return static */
    public function map(callable $mapper): static
    {
        // $mapper(T $item): T|object (idéalement T)
        $mapped = array_values(array_map($mapper, $this->items));
        return new static($mapped);
    }

    /**
     * @template R
     * @param R $initial
     * @param callable(R, T): R $reducer
     * @return R
     */
    public function reduce($initial, callable $reducer)
    {
        return array_reduce($this->items, $reducer, $initial);
    }

    /** @return T|null */
    public function first(): ?object
    {
        return $this->items[0] ?? null;
    }

    /** @return T|null */
    public function last(): ?object
    {
        $count = \count($this->items);
        return $count ? $this->items[$count - 1] : null;
    }
}
