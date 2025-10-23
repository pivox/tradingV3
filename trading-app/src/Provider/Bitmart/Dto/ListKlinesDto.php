<?php
declare(strict_types=1);

namespace App\Provider\Bitmart\Dto;

use App\Util\Dto\DtoList;

/**
 * @extends DtoList<KlineDto>
 */
class ListKlinesDto extends DtoList
{
    public function __construct(
        array $data = []
    ) {
        $items = [];
        foreach ($data as $dataItem) {
            $this->items[] = $dataItem instanceof KlineDto ? $dataItem : new KlineDto($dataItem);
        }
        parent::__construct($items);
    }

    /**
     * @param array<int, array<string,mixed>|KlineDto> $rows
     */
    public static function fromArray(array $rows, string $symbol, string $timeframe = ''): static
    {
        $converted = [];
        foreach ($rows as $index => $row) {
            if ($row instanceof KlineDto) {
                $converted[$index] = $row; // déjà prêt
                continue;
            }
            if (is_array($row)) {
                $row['timeframe'] = $timeframe;
                $converted[$index] = KlineDto::fromProvider($row, $symbol = $row['symbol'] ?? '');
            }
        }
        return new static($converted, $timeframe);
    }

    /** @return static */
    public function onlySymbol(string $symbol): static
    {
        return $this->filter(fn(KlineDto $k) => $k->symbol === $symbol);
    }

    /** @return static */
    public function sortByOpenTimeAsc(): static
    {
        $arr = $this->toArray();
        usort($arr, fn(KlineDto $a, KlineDto $b) => $a->openTime <=> $b->openTime);
        return new static($arr, $this->timeframe);
    }
}
