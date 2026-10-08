<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\OkxDemoGuardedExchangeAdapter;
use App\Exchange\Adapter\OkxExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Kernel;
use App\TradeEntry\Execution\ExchangeExecutionService;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(ExchangeExecutionService::class)]
final class ExchangeExecutionOkxWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testExchangeExecutionServiceReceivesTheDemoGuardedOkxAdapter(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = static::getContainer();

        $service = $container->get(ExchangeExecutionService::class);
        self::assertInstanceOf(ExchangeExecutionService::class, $service);
        $registry = (new \ReflectionProperty(ExchangeExecutionService::class, 'adapters'))->getValue($service);
        self::assertInstanceOf(ExchangeAdapterRegistryInterface::class, $registry);

        $adapter = $registry->get(Exchange::OKX, MarketType::PERPETUAL);

        self::assertInstanceOf(OkxDemoGuardedExchangeAdapter::class, $adapter);
        self::assertInstanceOf(
            OkxExchangeAdapter::class,
            (new \ReflectionProperty(OkxDemoGuardedExchangeAdapter::class, 'inner'))->getValue($adapter),
        );
        foreach ($registry->all() as $registered) {
            self::assertNotInstanceOf(OkxExchangeAdapter::class, $registered);
        }
    }
}
