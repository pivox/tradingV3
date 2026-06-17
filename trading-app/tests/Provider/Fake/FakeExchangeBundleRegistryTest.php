<?php

declare(strict_types=1);

namespace App\Tests\Provider\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use App\Provider\Fake\FakeAccountProvider;
use App\Provider\Fake\FakeContractProvider;
use App\Provider\Fake\FakeKlineProvider;
use App\Provider\Fake\FakeOrderProvider;
use App\Provider\Fake\FakeSystemProvider;
use App\Provider\MainProvider;
use App\Provider\Registry\ExchangeProviderBundle;
use App\Provider\Registry\ExchangeProviderRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the FAKE exchange context resolves to a working provider bundle
 * through the registry and the MainProvider facade — the wiring that lets the
 * Python orchestrator demo sets (exchange=fake) run without a provider lookup
 * crash.
 */
#[CoversNothing]
final class FakeExchangeBundleRegistryTest extends TestCase
{
    private function fakeBundle(MarketType $marketType): ExchangeProviderBundle
    {
        return new ExchangeProviderBundle(
            new ExchangeContext(Exchange::FAKE, $marketType),
            new FakeKlineProvider(),
            new FakeContractProvider(),
            new FakeOrderProvider(),
            new FakeAccountProvider(),
            new FakeSystemProvider(),
        );
    }

    public function testRegistryResolvesFakePerpetualContext(): void
    {
        $registry = new ExchangeProviderRegistry(
            [
                $this->fakeBundle(MarketType::PERPETUAL),
                $this->fakeBundle(MarketType::SPOT),
            ],
            Exchange::BITMART,
            MarketType::PERPETUAL,
        );

        $bundle = $registry->get(new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL));

        self::assertTrue(
            $bundle->context()->equals(new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL))
        );
        self::assertInstanceOf(FakeOrderProvider::class, $bundle->order());
        self::assertInstanceOf(FakeAccountProvider::class, $bundle->account());

        // Default context is left unchanged (Bitmart/perpetual).
        self::assertTrue(
            $registry->getDefaultContext()->equals(new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL))
        );
    }

    public function testMainProviderForFakeContextReturnsEmptyOpenState(): void
    {
        $registry = new ExchangeProviderRegistry(
            [$this->fakeBundle(MarketType::PERPETUAL)],
            Exchange::FAKE,
            MarketType::PERPETUAL,
        );

        $mainProvider = new MainProvider($registry);
        $scoped = $mainProvider->forContext(new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL));

        self::assertSame([], $scoped->getAccountProvider()->getOpenPositions());
        self::assertSame([], $scoped->getOrderProvider()->getOpenOrders());
        self::assertSame(0.0, $scoped->getAccountProvider()->getAccountBalance());
    }
}
