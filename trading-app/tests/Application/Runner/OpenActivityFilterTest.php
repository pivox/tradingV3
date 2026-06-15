<?php

declare(strict_types=1);

namespace App\Tests\Application\Runner;

use App\Application\Runner\OpenActivityFilter;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Context\ExchangeContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(OpenActivityFilter::class)]
final class OpenActivityFilterTest extends TestCase
{
    public function testFiltersSymbolsWithPrefetchedOpenPositionsOrOrdersAndReactivatesInactiveSwitches(): void
    {
        $context = $this->legacyContext();
        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->expects(self::once())->method('forContext')->with($context)->willReturnSelf();
        $mainProvider->expects(self::once())->method('getAccountProvider')->willReturn($this->createMock(AccountProviderInterface::class));
        $mainProvider->expects(self::once())->method('getOrderProvider')->willReturn($this->createMock(OrderProviderInterface::class));

        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $switchRepository
            ->expects(self::once())
            ->method('reactivateSwitchesForInactiveSymbols')
            ->with(['BTCUSDT', 'ETHUSDT'])
            ->willReturn(2);

        $filter = new OpenActivityFilter(
            $mainProvider,
            $switchRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $excludedSymbols = [];
        $remaining = $filter->filter(
            ['btcusdt', 'adausdt', 'ETHUSDT'],
            'run-123',
            $context,
            $excludedSymbols,
            [(object) ['symbol' => 'btcusdt']],
            [(object) ['symbol' => 'ethusdt']],
        );

        self::assertSame(['adausdt'], $remaining);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], $excludedSymbols);
    }

    public function testReturnsEmptySymbolListUnchangedWithoutReactivatingSwitches(): void
    {
        $context = $this->legacyContext();

        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->expects(self::once())->method('forContext')->with($context)->willReturnSelf();
        $mainProvider->expects(self::once())->method('getAccountProvider')->willReturn($this->createMock(AccountProviderInterface::class));
        $mainProvider->expects(self::once())->method('getOrderProvider')->willReturn($this->createMock(OrderProviderInterface::class));

        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $switchRepository->expects(self::never())->method('reactivateSwitchesForInactiveSymbols');

        $filter = new OpenActivityFilter(
            $mainProvider,
            $switchRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $excludedSymbols = [];
        self::assertSame([], $filter->filter([], 'run-123', $context, $excludedSymbols));
        self::assertSame([], $excludedSymbols);
    }

    private function legacyContext(): ExchangeContext
    {
        return new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL);
    }
}
