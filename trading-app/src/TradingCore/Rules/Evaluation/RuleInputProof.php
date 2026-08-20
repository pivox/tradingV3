<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

interface RuleInputProof
{
    public function verify(): self;
}
