<?php
declare(strict_types=1);

namespace App\Util;

final class TimeWindow {
    public function __construct(
        public readonly int $fromTs, // secondes UTC (inclus)
        public readonly int $toTs    // secondes UTC (inclus) = début DERNIÈRE bougie close
    ) {}
}
