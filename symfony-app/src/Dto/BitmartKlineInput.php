<?php
namespace App\Dto;

final class BitmartKlineInput
{
    public string $symbol;
    public string $interval;
    public ?string $start = null;
    public ?string $end = null;
    public bool $persist = true;
}
