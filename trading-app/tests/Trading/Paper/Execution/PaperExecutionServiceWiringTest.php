<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Command\PaperExecutionReplayCommand;
use App\Command\PaperReplayRuntimeCheckCommand;
use App\Kernel;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeEffectDispatcher;
use App\Trading\Paper\Execution\Market\PaperKlineProviderAdapter;
use App\Indicator\Provider\IndicatorProviderService;
use App\MtfValidator\Service\MtfValidatorCoreService;
use App\MtfValidator\Service\MtfValidatorService;
use App\MtfValidator\Service\TimeframeValidationService;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use App\Trading\Paper\Execution\Strategy\PaperMtfPreparationResolver;
use App\Trading\Paper\Execution\Persistence\PaperCanonicalOrderIntentRecorderInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyRuntime;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparation;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceProvider;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceProviderInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceSourceInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalOrderPlanEvidenceSource;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakePortfolioSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPortfolioReservationStore;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\AbstractCanonicalPortfolioAdapter;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
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

        $coordinator = $container->get(PaperEventCoordinatorInterface::class);
        self::assertInstanceOf(PaperExecutionCoordinator::class, $coordinator);
        self::assertInstanceOf(
            PaperCanonicalStrategyPreparation::class,
            (new \ReflectionProperty(PaperExecutionCoordinator::class, 'canonicalStrategy'))->getValue($coordinator),
        );
        self::assertInstanceOf(
            PaperCanonicalStrategyEvidenceProvider::class,
            $container->get(PaperCanonicalStrategyEvidenceProviderInterface::class),
        );
        self::assertInstanceOf(
            PaperCanonicalStrategyEvidenceSource::class,
            $container->get(PaperCanonicalStrategyEvidenceSourceInterface::class),
        );
        self::assertInstanceOf(
            PaperCanonicalPreparedEffectCodec::class,
            (new \ReflectionProperty(PaperExecutionCoordinator::class, 'canonicalCodec'))->getValue($coordinator),
        );
        $canonicalDispatcher = (new \ReflectionProperty(
            PaperExecutionCoordinator::class,
            'canonicalDispatcher',
        ))->getValue($coordinator);
        self::assertInstanceOf(
            PaperCanonicalFakeEffectDispatcher::class,
            $canonicalDispatcher,
        );
        self::assertInstanceOf(
            PaperCanonicalOrderIntentRecorderInterface::class,
            (new \ReflectionProperty(PaperExecutionCoordinator::class, 'canonicalOrderIntents'))->getValue($coordinator),
        );
        self::assertInstanceOf(PaperExecutionReplayCommand::class, $container->get(PaperExecutionReplayCommand::class));
        self::assertInstanceOf(PaperReplayRuntimeCheckCommand::class, $container->get(PaperReplayRuntimeCheckCommand::class));
        $factory = $container->get(PaperFakeRuntimeFactory::class);
        self::assertInstanceOf(PaperFakeRuntimeFactory::class, $factory);
        $root = (new \ReflectionProperty(PaperFakeRuntimeFactory::class, 'root'))->getValue($factory);
        self::assertSame($container->getParameter('kernel.project_dir') . '/var/paper-fake-state', $root);
        $runtimeClock = (new \ReflectionProperty(PaperFakeRuntimeFactory::class, 'clock'))->getValue($factory);
        $reader = $container->get(PaperReplayReader::class);
        $readerClock = (new \ReflectionProperty(PaperReplayReader::class, 'clock'))->getValue($reader);
        self::assertInstanceOf(PaperReplayClock::class, $runtimeClock);
        self::assertSame($readerClock, $runtimeClock, 'Replay reader and Fake matching must share dataset time.');
        self::assertSame(
            $readerClock,
            (new \ReflectionProperty(PaperCanonicalFakeEffectDispatcher::class, 'clock'))->getValue($canonicalDispatcher),
            'Canonical plan deadlines must be evaluated against dataset time.',
        );
        self::assertSame(
            $readerClock,
            (new \ReflectionProperty(PaperCanonicalOrderPlanEvidenceSource::class, 'clock'))
                ->getValue($container->get(PaperCanonicalOrderPlanEvidenceSource::class)),
        );
        self::assertSame(
            $readerClock,
            (new \ReflectionProperty(PaperCanonicalFakePortfolioSource::class, 'clock'))
                ->getValue($container->get(PaperCanonicalFakePortfolioSource::class)),
        );
        $canonicalRuntime = new PaperCanonicalStrategyRuntime(
            $container->get(EffectiveTradingConfigResolver::class),
            $container->get(CanonicalSetupRuleRuntime::class),
            new CanonicalExecutionPolicyCompiler(),
            $readerClock,
        );
        $shadowRuntime = (new \ReflectionProperty(
            PaperCanonicalStrategyRuntime::class,
            'runtime',
        ))->getValue($canonicalRuntime);
        self::assertSame(
            $readerClock,
            (new \ReflectionProperty($shadowRuntime::class, 'clock'))->getValue($shadowRuntime),
            'Canonical Paper rules and market freshness must use dataset time.',
        );
        $planBuilder = (new \ReflectionProperty(
            $shadowRuntime::class,
            'orderPlanBuilder',
        ))->getValue($shadowRuntime);
        self::assertInstanceOf(CanonicalOrderPlanBuilder::class, $planBuilder);
        self::assertSame(
            $readerClock,
            (new \ReflectionProperty(CanonicalOrderPlanBuilder::class, 'clock'))->getValue($planBuilder),
            'Canonical Paper plan deadlines must use dataset time.',
        );
        $portfolioAdapters = (new \ReflectionProperty(
            $shadowRuntime::class,
            'portfolioAdapters',
        ))->getValue($shadowRuntime);
        self::assertInstanceOf(CanonicalPortfolioAdapterSelector::class, $portfolioAdapters);
        $paperPortfolio = (new \ReflectionProperty(
            CanonicalPortfolioAdapterSelector::class,
            'paper',
        ))->getValue($portfolioAdapters);
        $admissionEngine = (new \ReflectionProperty(
            AbstractCanonicalPortfolioAdapter::class,
            'admissionEngine',
        ))->getValue($paperPortfolio);
        self::assertInstanceOf(
            PaperCanonicalPortfolioReservationStore::class,
            (new \ReflectionProperty(
                AbstractCanonicalPortfolioAdapter::class,
                'reservationStore',
            ))->getValue($paperPortfolio),
            'Canonical Paper admission must not keep a second lifecycle state.',
        );
        self::assertSame(
            $readerClock,
            (new \ReflectionProperty($admissionEngine::class, 'clock'))->getValue($admissionEngine),
            'Canonical Paper portfolio admission must use dataset time.',
        );
        $readiness = $container->get(PaperReplayReadinessService::class);
        $readinessClock = (new \ReflectionProperty(PaperReplayReadinessService::class, 'clock'))->getValue($readiness);
        self::assertSame($readerClock, $readinessClock, 'Readiness and replay must validate the same controlled clock.');
        $readinessReader = (new \ReflectionProperty(PaperReplayReadinessService::class, 'reader'))->getValue($readiness);
        self::assertSame($reader, $readinessReader, 'Readiness must enforce the exact replay reader event limit.');
        $effectiveConfigResolver = (new \ReflectionProperty(PaperReplayReadinessService::class, 'effectiveConfigResolver'))->getValue($readiness);
        self::assertInstanceOf(EffectiveTradingConfigResolver::class, $effectiveConfigResolver, 'Read-only readiness must not use the persistent Effective Config resolver.');
        $resolver = $container->get(PaperMtfPreparationResolver::class);
        self::assertSame(0, (new \ReflectionClass($resolver))->getConstructor()?->getNumberOfParameters() ?? 0, 'Paper preparation must have no ambient account, HTTP, lock, or exchange dependency.');
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
