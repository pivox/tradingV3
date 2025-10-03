<?php

namespace App\Service\Exception\Trade\Position;

class LeverageLowException extends \Exception
{
    public static function trigger($symbol, $leverage, $minLeverage): self
    {
        return new self("Leverage too low for $symbol: $leverage < $minLeverage");
    }
}
