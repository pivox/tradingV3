<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Lifecycle;

use App\Contract\Provider\MainProviderInterface;
use App\Exchange\Event\DoctrineExchangeLocalProjectionStore;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Logging\TradeLifecycleLogger;
use App\Trading\Event\OrderStateChangedEvent;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Event\SymbolSkippedEvent;
use App\Trading\Listener\TradeLifecycleLoggerListener;
use App\Trading\Paper\Execution\Lifecycle\PaperLifecycleMainProvider;
use App\Trading\Paper\Execution\Lifecycle\PaperTradeLifecycleEmitter;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Pnl\CanonicalFillEvidenceRefresherInterface;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * #132 j: the Paper trade lifecycle uses Paper instances only (config/services.yaml, "paper.*"):
 * its listener is not an event listener, its klines come from the replayed dataset, its logger
 * runs on the replay clock, and its graph holds no live MainProvider and no HTTP client.
 */
#[CoversClass(PaperTradeLifecycleEmitter::class)]
final class PaperTradeLifecycleWiringTest extends KernelTestCase
{
    private const FORBIDDEN = [
        MainProviderInterface::class,
        'Symfony\Contracts\HttpClient\HttpClientInterface',
        'Psr\Http\Client\ClientInterface',
        'GuzzleHttp\ClientInterface',
    ];

    /** Infrastructure the walk does not enter: none of it can reach a venue by itself. */
    private const OPAQUE = [
        ObjectManager::class,
        Connection::class,
        ManagerRegistry::class,
        ClassMetadata::class,
        LoggerInterface::class,
        ContainerInterface::class,
        \Closure::class,
    ];

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    public function testThePaperLifecycleIsWiredToPaperInstancesOnly(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $coordinator = $container->get(PaperExecutionCoordinator::class);
        $sink = self::property($coordinator, 'lifecycle');
        self::assertInstanceOf(PaperTradeLifecycleEmitter::class, $sink, 'The coordinator records the Paper trade lifecycle.');

        $projection = $sink->projection();
        self::assertInstanceOf(DoctrineExchangeLocalProjectionStore::class, $projection);
        self::assertNotSame($container->get(ExchangeLocalProjectionStoreInterface::class), $projection, 'The live projection store is not the Paper one.');
        $ledger = self::property($projection, 'fillCostLedger');
        self::assertInstanceOf(FillCostLedgerIngestionService::class, $ledger);

        $listener = self::property($sink, 'listener');
        self::assertInstanceOf(TradeLifecycleLoggerListener::class, $listener);
        self::assertSame($listener, self::property($ledger, 'evidenceRefresher'), 'Fill evidence of a Paper trade is refreshed by the Paper listener.');
        self::assertInstanceOf(PaperLifecycleMainProvider::class, self::property($listener, 'mainProvider'));
        $logger = self::property($listener, 'tradeLifecycleLogger');
        self::assertInstanceOf(TradeLifecycleLogger::class, $logger);
        self::assertSame($logger, self::property($sink, 'logger'));
        self::assertInstanceOf(PaperReplayClock::class, self::property($logger, 'clock'));

        $liveListener = $container->get(CanonicalFillEvidenceRefresherInterface::class);
        self::assertInstanceOf(TradeLifecycleLoggerListener::class, $liveListener);
        self::assertNotSame($listener, $liveListener, 'The live listener keeps its live providers.');
        self::assertNotInstanceOf(PaperLifecycleMainProvider::class, self::property($liveListener, 'mainProvider'));

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([PositionOpenedEvent::class, PositionClosedEvent::class, OrderStateChangedEvent::class, SymbolSkippedEvent::class] as $event) {
            $listeners = array_map(
                static fn (mixed $candidate): mixed => \is_array($candidate) ? $candidate[0] : $candidate,
                $dispatcher->getListeners($event),
            );
            self::assertNotContains($listener, $listeners, 'The Paper listener must never be an event listener: ' . $event);
        }
        self::assertContains($liveListener, array_map(
            static fn (mixed $candidate): mixed => \is_array($candidate) ? $candidate[0] : $candidate,
            $dispatcher->getListeners(PositionClosedEvent::class),
        ), 'The live listener stays registered.');

        $found = [];
        $visited = [];
        self::walk($sink, 0, 'sink', $visited, $found);
        self::assertSame([], $found, 'The Paper lifecycle graph must not reach a venue.');
        self::assertGreaterThan(10, \count($visited), 'The walk must cover the graph.');
    }

    /**
     * @param array<int, true> $visited
     * @param list<string> $found
     */
    private static function walk(object $object, int $depth, string $path, array &$visited, array &$found): void
    {
        $id = spl_object_id($object);
        if (isset($visited[$id]) || $depth > 16) {
            return;
        }
        $visited[$id] = true;
        foreach (self::FORBIDDEN as $type) {
            if ($object instanceof $type && !$object instanceof PaperLifecycleMainProvider) {
                $found[] = $path . ' (' . $object::class . ')';

                return;
            }
        }
        foreach (self::OPAQUE as $type) {
            if ($object instanceof $type) {
                return;
            }
        }
        for ($class = new \ReflectionObject($object); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || !$property->isInitialized($object)) {
                    continue;
                }
                self::walkValue($property->getValue($object), $depth + 1, $path . '->' . $property->getName(), $visited, $found);
            }
        }
    }

    /**
     * @param array<int, true> $visited
     * @param list<string> $found
     */
    private static function walkValue(mixed $value, int $depth, string $path, array &$visited, array &$found): void
    {
        if (\is_object($value)) {
            self::walk($value, $depth, $path, $visited, $found);
        } elseif (\is_array($value)) {
            foreach ($value as $key => $item) {
                self::walkValue($item, $depth, $path . '[' . $key . ']', $visited, $found);
            }
        }
    }

    private static function property(object $object, string $name): mixed
    {
        for ($class = new \ReflectionObject($object); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                return $class->getProperty($name)->getValue($object);
            }
        }

        self::fail($object::class . '::$' . $name . ' is missing');
    }
}
