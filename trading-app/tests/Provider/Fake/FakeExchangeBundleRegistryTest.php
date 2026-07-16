<?php

declare(strict_types=1);

namespace App\Tests\Provider\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Provider\Context\ExchangeContext;
use App\Provider\Fake\FakeAccountProvider;
use App\Provider\Fake\FakeKlineProvider;
use App\Provider\Fake\FakeOrderProvider;
use App\Provider\Fake\FakeSystemProvider;
use App\Provider\MainProvider;
use App\Provider\Registry\ExchangeProviderBundle;
use App\Provider\Registry\ExchangeProviderRegistry;
use App\Provider\Registry\Exception\ProviderNotFoundException;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies that the FAKE exchange context resolves to a working provider bundle
 * through the registry and the MainProvider facade — the wiring that lets the
 * Python orchestrator demo sets (exchange=fake) run without a provider lookup
 * crash.
 */
#[CoversNothing]
final class FakeExchangeBundleRegistryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    private function fakeBundle(MarketType $marketType): ExchangeProviderBundle
    {
        $fake = FakeProviderFixture::create();

        return new ExchangeProviderBundle(
            new ExchangeContext(Exchange::FAKE, $marketType),
            new FakeKlineProvider(),
            $fake->contract,
            $fake->order,
            $fake->account,
            new FakeSystemProvider(),
        );
    }

    public function testRegistryResolvesFakePerpetualContext(): void
    {
        $registry = new ExchangeProviderRegistry(
            [$this->fakeBundle(MarketType::PERPETUAL)],
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

    public function testConfiguredRegistryRejectsFakeSpotContext(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ExchangeProviderRegistryInterface::class);
        self::assertInstanceOf(ExchangeProviderRegistry::class, $registry);

        $this->expectException(ProviderNotFoundException::class);
        $registry->get(new ExchangeContext(Exchange::FAKE, MarketType::SPOT));
    }

    public function testMainProviderForFakeContextReturnsRealEmptyStateAndBalance(): void
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
        self::assertSame(100000.0, $scoped->getAccountProvider()->getAccountBalance());
    }
}
