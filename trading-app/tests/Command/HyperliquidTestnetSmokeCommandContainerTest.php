<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HyperliquidTestnetSmokeCommand;
use App\TradingCore\Execution\Hyperliquid\HyperliquidTestnetExecutionPort;
use App\TradingCore\Execution\Hyperliquid\HyperliquidTestnetExecutionPortInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class HyperliquidTestnetSmokeCommandContainerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    public function testCompiledContainerResolvesRegisteredCommandAndTestnetOnlyPort(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(
            HyperliquidTestnetSmokeCommand::class,
            $container->get(HyperliquidTestnetSmokeCommand::class),
        );
        self::assertInstanceOf(
            HyperliquidTestnetExecutionPort::class,
            $container->get(HyperliquidTestnetExecutionPortInterface::class),
        );
    }
}
