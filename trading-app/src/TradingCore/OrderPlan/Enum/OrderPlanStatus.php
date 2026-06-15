<?php
declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Enum;

enum OrderPlanStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
