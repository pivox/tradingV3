<?php
// src/Dto/FuturesKlineCollection.php

declare(strict_types=1);

namespace App\Dto;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Collection typée de bougies FuturesKlineDto.
 *
 * @implements IteratorAggregate<int, FuturesKlineDto>
 */
final class FuturesKlineCollection implements IteratorAggregate
{
    /** @var FuturesKlineDto[] */
    private array $items = [];

    /**
     * @param FuturesKlineDto[] $items
     */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(FuturesKlineDto $kline): void
    {
        $this->items[] = $kline;
    }

    /**
     * @return Traversable<int, FuturesKlineDto>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * Accès indexé (optionnel).
     */
    public function get(int $index): ?FuturesKlineDto
    {
        return $this->items[$index] ?? null;
    }

    /**
     * Dernière bougie.
     */
    public function last(): ?FuturesKlineDto
    {
        return !empty($this->items) ? end($this->items) : null;
    }
}
