<?php
declare(strict_types=1);

namespace App\TradingCore\Entry\Enum;

enum EntryZoneStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
