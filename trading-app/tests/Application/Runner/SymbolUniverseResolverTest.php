<?php

declare(strict_types=1);

namespace App\Tests\Application\Runner;

use App\Application\Runner\SymbolUniverseResolver;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Context\ExchangeContext;
use App\Provider\Repository\ContractRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(SymbolUniverseResolver::class)]
final class SymbolUniverseResolverTest extends TestCase
{
    public function testNormalizesProvidedSymbolsAndAppendsQueuedSymbols(): void
    {
        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository->expects(self::never())->method('allActiveSymbolNames');

        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $switchRepository
            ->expects(self::once())
            ->method('consumeSymbolsWithFutureExpiration')
            ->willReturn(['solusdt', 'ETHUSDT']);

        $resolver = new SymbolUniverseResolver(
            $contractRepository,
            $switchRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(
            ['BTCUSDT', 'ETHUSDT', 'solusdt'],
            $resolver->resolve([' btcusdt ', 'ETHUSDT', 'btcusdt', ''], 'scalper', $this->legacyContext()),
        );
    }

    public function testLoadsActiveSymbolsWhenNoInputSymbolsAreProvided(): void
    {
        $context = $this->legacyContext();

        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository
            ->expects(self::once())
            ->method('allActiveSymbolNames')
            ->with([], false, 'scalper_micro', $context)
            ->willReturn(['BTCUSDT', 'ETHUSDT']);

        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $switchRepository
            ->expects(self::once())
            ->method('consumeSymbolsWithFutureExpiration')
            ->willReturn([]);

        $resolver = new SymbolUniverseResolver(
            $contractRepository,
            $switchRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(['BTCUSDT', 'ETHUSDT'], $resolver->resolve([], 'scalper_micro', $context));
    }

    public function testFallsBackWhenContractRepositoryAndQueueReturnNoSymbols(): void
    {
        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository
            ->expects(self::once())
            ->method('allActiveSymbolNames')
            ->willReturn([]);

        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $switchRepository
            ->expects(self::once())
            ->method('consumeSymbolsWithFutureExpiration')
            ->willReturn([]);

        $resolver = new SymbolUniverseResolver(
            $contractRepository,
            $switchRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(
            ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'],
            $resolver->resolve([], null, $this->legacyContext()),
        );
    }

    private function legacyContext(): ExchangeContext
    {
        return new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL);
    }
}
