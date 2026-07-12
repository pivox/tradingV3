<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: HyperliquidCompensationSleeperInterface::class)]
final class NativeHyperliquidCompensationSleeper implements HyperliquidCompensationSleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            throw new \InvalidArgumentException('hyperliquid_compensation_sleep_invalid');
        }

        usleep($milliseconds * 1_000);
    }
}
