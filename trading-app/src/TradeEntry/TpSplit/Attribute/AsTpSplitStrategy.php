<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsTpSplitStrategy
{
    public function __construct(
        public ?string $name = null,
        public int $priority = 0,
    ) {}
}

