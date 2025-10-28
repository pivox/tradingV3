<?php
declare(strict_types=1);

namespace App\Indicator\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsIndicatorCondition
{
    /**
     * @param string[] $timeframes ex: ['1m','5m','15m','1h','4h']
     * @param string|null $side 'long'|'short'|null (optionnel)
     * @param int $priority tri décroissant (plus grand = évalué d’abord)
     */
    public function __construct(
        public array $timeframes,
        public ?string $side = null,
        public ?string $name = null,  // si null => dérivé du FQCN
        public int $priority = 0,
    ) {}
}
