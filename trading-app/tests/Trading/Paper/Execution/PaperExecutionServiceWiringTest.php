<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Command\PaperExecutionReplayCommand;
use App\Kernel;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Market\PaperKlineProviderAdapter;
use App\Indicator\Provider\IndicatorProviderService;
use App\MtfValidator\Service\MtfValidatorCoreService;
use App\MtfValidator\Service\MtfValidatorService;
use App\MtfValidator\Service\TimeframeValidationService;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Execution\Strategy\PaperMtfPreparationResolver;
use App\TradeEntry\Service\TradeEntryPreparationService;
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
        $runtimeClock = (new \ReflectionProperty(PaperFakeRuntimeFactory::class, 'clock'))->getValue($factory);
        $reader = $container->get(PaperReplayReader::class);
        $readerClock = (new \ReflectionProperty(PaperReplayReader::class, 'clock'))->getValue($reader);
        self::assertInstanceOf(PaperReplayClock::class, $runtimeClock);
        self::assertSame($readerClock, $runtimeClock, 'Replay reader and Fake matching must share dataset time.');
        $resolver = $container->get(PaperMtfPreparationResolver::class);
        $preparation = (new \ReflectionProperty(PaperMtfPreparationResolver::class, 'tradeEntry'))->getValue($resolver);
        self::assertInstanceOf(TradeEntryPreparationService::class, $preparation);
        $preparationClock = (new \ReflectionProperty(TradeEntryPreparationService::class, 'clock'))->getValue($preparation);
        self::assertSame($readerClock, $preparationClock, 'Paper fallback TTL must use dataset time.');
        $strategy = $container->get(\App\Trading\Paper\Execution\Strategy\PaperMtfStrategyBridge::class);
        $validator = (new \ReflectionProperty($strategy, 'validator'))->getValue($strategy);
        self::assertInstanceOf(MtfValidatorService::class, $validator);
        $validatorClock = (new \ReflectionProperty(MtfValidatorService::class, 'clock'))->getValue($validator);
        self::assertSame($readerClock, $validatorClock, 'Paper MTF runs must be stamped with dataset time.');
        $validatorCore = (new \ReflectionProperty(MtfValidatorService::class, 'core'))->getValue($validator);
        self::assertInstanceOf(MtfValidatorCoreService::class, $validatorCore);
        $validatorCoreClock = (new \ReflectionProperty(MtfValidatorCoreService::class, 'clock'))->getValue($validatorCore);
        self::assertSame($readerClock, $validatorCoreClock, 'Paper MTF core fallbacks must use dataset time.');

        $constructor = (new \ReflectionClass(PaperExecutionCoordinator::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $constructor->getParameters());
        foreach (['Http', 'WebSocket', 'Credential', 'Wallet', 'Signer'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, implode('|', $types));
        }

        $dispatcher = $container->get(PaperFakeEffectDispatcher::class);
        $execution = (new \ReflectionProperty(PaperFakeEffectDispatcher::class, 'execution'))->getValue($dispatcher);
        $registry = (new \ReflectionProperty($execution, 'adapters'))->getValue($execution);
        self::assertSame([], $registry->all(), 'The Paper execution graph must not retain any real exchange adapter.');

        foreach ([IndicatorProviderService::class, TimeframeValidationService::class] as $serviceId) {
            $service = $container->get($serviceId);
            $provider = (new \ReflectionProperty($service, 'fakeKlineProvider'))->getValue($service);
            self::assertInstanceOf(PaperKlineProviderAdapter::class, $provider, 'FAKE MTF reads must use the projected Paper windows.');
        }
    }
}
