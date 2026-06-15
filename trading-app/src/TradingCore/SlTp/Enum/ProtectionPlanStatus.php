<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Enum;

enum ProtectionPlanStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
