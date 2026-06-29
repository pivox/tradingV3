<?php

declare(strict_types=1);

namespace App\Tests\Provider\Okx;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Provider\Context\ExchangeContext;
use App\Provider\MainProvider;
use App\Provider\Okx\OkxAccountGateway;
use App\Provider\Okx\OkxMarketDataGateway;
use App\Provider\Okx\OkxMetadataProvider;
use App\Provider\Okx\OkxOrderGateway;
use App\Provider\Okx\OkxProviderNotReadyException;
use App\Provider\Okx\OkxSystemProvider;
use App\Provider\Registry\ExchangeProviderBundle;
use App\Provider\Registry\ExchangeProviderRegistry;
use App\Provider\Registry\Exception\ProviderNotFoundException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OkxExchangeBundleRegistryTest extends TestCase
{
    public function testRegistryResolvesOkxPerpetualBundle(): void
    {
        $registry = new ExchangeProviderRegistry(
            [
                $this->bitmartBundle(),
                $this->okxBundle(),
            ],
            Exchange::BITMART,
            MarketType::PERPETUAL,
        );

        $bundle = $registry->get(new ExchangeContext(Exchange::OKX, MarketType::PERPETUAL));

        self::assertTrue($bundle->context()->equals(new ExchangeContext(Exchange::OKX, MarketType::PERPETUAL)));
        self::assertInstanceOf(OkxMarketDataGateway::class, $bundle->kline());
        self::assertInstanceOf(OkxMetadataProvider::class, $bundle->contract());
        self::assertInstanceOf(OkxOrderGateway::class, $bundle->order());
        self::assertInstanceOf(OkxAccountGateway::class, $bundle->account());
        self::assertInstanceOf(OkxSystemProvider::class, $bundle->system());

        self::assertTrue($registry->getDefaultContext()->equals(new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL)));
    }

    public function testOkxSpotDoesNotFallbackToBitmart(): void
    {
        $registry = new ExchangeProviderRegistry(
            [
                $this->bitmartBundle(),
                $this->okxBundle(),
            ],
            Exchange::BITMART,
            MarketType::PERPETUAL,
        );

        $this->expectException(ProviderNotFoundException::class);
        $this->expectExceptionMessage('okx::spot');

        $registry->get(new ExchangeContext(Exchange::OKX, MarketType::SPOT));
    }

    public function testMainProviderScopesToOkxPerpetualBundle(): void
    {
        $mainProvider = new MainProvider(new ExchangeProviderRegistry(
            [
                $this->bitmartBundle(),
                $this->okxBundle(),
            ],
            Exchange::BITMART,
            MarketType::PERPETUAL,
        ));

        $scoped = $mainProvider->forContext(new ExchangeContext(Exchange::OKX, MarketType::PERPETUAL));

        $this->assertNotImplemented(
            static fn (): array => $scoped->getKlineProvider()->getKlines('BTCUSDT', Timeframe::TF_1M),
            'okx_market_data_not_implemented',
        );
        self::assertInstanceOf(OkxMetadataProvider::class, $scoped->getContractProvider());
        $this->assertNotImplemented(
            static fn (): array => $scoped->getOrderProvider()->getOpenOrdersOrFail('BTCUSDT'),
            'okx_order_read_not_implemented',
        );
        self::assertInstanceOf(OkxAccountGateway::class, $scoped->getAccountProvider());
        self::assertInstanceOf(OkxSystemProvider::class, $scoped->getSystemProvider());
    }

    private function okxBundle(): ExchangeProviderBundle
    {
        return new ExchangeProviderBundle(
            new ExchangeContext(Exchange::OKX, MarketType::PERPETUAL),
            new OkxMarketDataGateway(),
            new OkxMetadataProvider(),
            new OkxOrderGateway(),
            new OkxAccountGateway(),
            new OkxSystemProvider(),
        );
    }

    private function bitmartBundle(): ExchangeProviderBundle
    {
        return new ExchangeProviderBundle(
            new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
            $this->createMock(KlineProviderInterface::class),
            $this->createMock(ContractProviderInterface::class),
            $this->createMock(OrderProviderInterface::class),
            $this->createMock(AccountProviderInterface::class),
            $this->createMock(SystemProviderInterface::class),
        );
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertNotImplemented(callable $operation, string $reason): void
    {
        try {
            $operation();
            self::fail('Expected OKX scoped provider operation to fail explicitly.');
        } catch (OkxProviderNotReadyException $exception) {
            self::assertSame($reason, $exception->reason());
        }
    }
}
