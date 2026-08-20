<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\Kernel;
use App\TradingCore\Config\Audit\DoctrineEffectiveConfigSnapshotRegistry;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;
use App\TradingCore\Config\Audit\PersistentEffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PersistentEffectiveTradingConfigResolver::class)]
#[CoversClass(DoctrineEffectiveConfigSnapshotRegistry::class)]
final class EffectiveTradingConfigContainerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testProductionAliasesUsePersistentResolverAndDoctrineRegistry(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = self::getContainer();

        self::assertInstanceOf(PersistentEffectiveTradingConfigResolver::class, $container->get(EffectiveTradingConfigResolverInterface::class));
        self::assertInstanceOf(DoctrineEffectiveConfigSnapshotRegistry::class, $container->get(EffectiveConfigSnapshotRegistryInterface::class));
    }
}
