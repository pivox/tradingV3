<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Command\PaperExecutionReplayCommand;
use App\Kernel;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PaperExecutionCoordinator::class)]
final class PaperExecutionServiceWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testCoordinatorAndReplayCommandAreWiredWithoutDirectPrivateExchangeDependencies(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = static::getContainer();

        self::assertInstanceOf(PaperExecutionCoordinator::class, $container->get(PaperEventCoordinatorInterface::class));
        self::assertInstanceOf(PaperExecutionReplayCommand::class, $container->get(PaperExecutionReplayCommand::class));
        $factory = $container->get(PaperFakeRuntimeFactory::class);
        self::assertInstanceOf(PaperFakeRuntimeFactory::class, $factory);
        $root = (new \ReflectionProperty(PaperFakeRuntimeFactory::class, 'root'))->getValue($factory);
        self::assertSame($container->getParameter('kernel.project_dir') . '/var/paper-fake-state', $root);

        $constructor = (new \ReflectionClass(PaperExecutionCoordinator::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $constructor->getParameters());
        foreach (['Http', 'WebSocket', 'Credential', 'Wallet', 'Signer'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, implode('|', $types));
        }
    }
}
