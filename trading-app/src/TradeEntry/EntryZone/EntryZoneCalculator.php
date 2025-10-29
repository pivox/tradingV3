<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\EntryZone;

final class EntryZoneCalculator
{
    public function __construct() {}

    public function compute(string $symbol): EntryZone
    {
        // TODO: brancher VWAP/MA/Donchian si nécessaire
        return new EntryZone(min: PHP_FLOAT_MIN, max: PHP_FLOAT_MAX, rationale: 'open zone');
    }
}
