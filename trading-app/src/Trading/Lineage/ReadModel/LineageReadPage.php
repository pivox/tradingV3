<?php

declare(strict_types=1);

namespace App\Trading\Lineage\ReadModel;

final readonly class LineageReadPage
{
    public bool $hasMore;

    /**
     * @param list<array<string,mixed>> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {
        $this->hasMore = $this->offset + count($this->items) < $this->total;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'pagination' => [
                'limit' => $this->limit,
                'offset' => $this->offset,
                'total' => $this->total,
                'has_more' => $this->hasMore,
            ],
            'data' => $this->items,
        ];
    }
}
