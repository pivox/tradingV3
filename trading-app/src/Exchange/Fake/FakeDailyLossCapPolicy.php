<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class FakeDailyLossCapPolicy
{
    public const VERSION = 'fake-daily-loss-cap-v1';

    public function __construct(
        public string $configuredLimitUsdt,
    ) {
    }

    public function normalizedLimitUsdt(): ?string
    {
        $limit = trim($this->configuredLimitUsdt);
        if (preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,12})?$/D', $limit) !== 1) {
            return null;
        }

        try {
            $decimal = BigDecimal::of($limit);
        } catch (\Throwable) {
            return null;
        }
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            return null;
        }

        return (string) $decimal->toScale(12, RoundingMode::HALF_EVEN);
    }
}
