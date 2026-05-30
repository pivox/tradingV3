<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Dto;

use App\Exchange\Dto\ExchangeCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeCapabilities::class)]
final class ExchangeCapabilitiesTest extends TestCase
{
    public function testCapabilitiesExposeStableArrayShape(): void
    {
        $capabilities = new ExchangeCapabilities(
            supportsTestnet: true,
            supportsWebSocketPrivate: true,
            supportsClientOrderId: true,
            supportsIoc: true,
            supportsReduceOnly: true,
            requiresSeparateLeverageSubmit: true,
        );

        self::assertSame([
            'supportsTestnet' => true,
            'supportsWebSocketPrivate' => true,
            'supportsClientOrderId' => true,
            'supportsCancelByClientOrderId' => false,
            'supportsPostOnly' => false,
            'supportsIoc' => true,
            'supportsReduceOnly' => true,
            'supportsAttachedStopLossOnEntry' => false,
            'supportsAttachedTakeProfitOnEntry' => false,
            'supportsTriggerOrders' => false,
            'supportsModifyOrder' => false,
            'requiresSeparateLeverageSubmit' => true,
            'supportsPerSymbolLeverage' => false,
        ], $capabilities->toArray());
    }
}
