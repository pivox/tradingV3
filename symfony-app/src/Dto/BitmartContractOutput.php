<?php

namespace App\Dto;

final class BitmartContractOutput
{
    /** @var array<string, mixed> */
    public array $contracts;

    public function __construct(array $contracts = [])
    {
        $this->contracts = $contracts;
    }
}
