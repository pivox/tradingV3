<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Ast;

interface RuleNode
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
