<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\Dto;

use App\Util\Dto\DtoList;

/**
 * @extends DtoList<ContractDto>
 */
class ListContractDto extends DtoList
{
    public function __construct(
        array $data = []
    ) {
        $items = [];
        foreach ($data as $dataItem) {
            $items[] = $dataItem instanceof ContractDto ? $dataItem : new ContractDto($dataItem);
        }
        parent::__construct($items);
    }

    /**
     * @param array<int, array<string,mixed>|ContractDto> $rows
     */
    public static function fromArray(array $rows): static
    {
        $converted = [];
        foreach ($rows as $index => $row) {
            if ($row instanceof ContractDto) {
                $converted[$index] = $row;
                continue;
            }
            if (is_array($row)) {
                if (method_exists(ContractDto::class, 'fromProvider')) {
                    $converted[$index] = ContractDto::fromProvider($row);
                } else {
                    $converted[$index] = new ContractDto($row);
                }
            }
        }
        return new static($converted);
    }
}
