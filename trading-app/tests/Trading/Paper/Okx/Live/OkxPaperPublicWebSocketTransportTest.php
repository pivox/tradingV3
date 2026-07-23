<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Live\OkxPaperLiveIntegrityException;
use App\Trading\Paper\Okx\Live\OkxPaperLivePolicy;
use App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportFactoryInterface;
use App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\Okx\Live\PawlOkxPaperPublicWebSocketTransport;
use App\Trading\Paper\Okx\Live\PawlOkxPaperPublicWebSocketTransportFactory;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

#[CoversClass(PawlOkxPaperPublicWebSocketTransport::class)]
#[CoversClass(PawlOkxPaperPublicWebSocketTransportFactory::class)]
#[CoversClass(OkxPaperLivePolicy::class)]
#[CoversClass(OkxPaperLiveIntegrityException::class)]
final class OkxPaperPublicWebSocketTransportTest extends TestCase
{
    public function testSerializesCommandsAndInvokesConnectorWithOnlyThePublicUri(): void
    {
        $loop = new DeterministicLoop();
        $connection = new FakePawlPublicConnection();
        $connectorArguments = [];
        $transport = new PawlOkxPaperPublicWebSocketTransport(
            loop: $loop,
            connector: static function (string $uri) use ($connection, &$connectorArguments): PromiseInterface {
                $connectorArguments[] = func_get_args();

                return resolve($connection);
            },
        );
        $opened = false;
        $transport->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function () use (&$opened): void { $opened = true; },
            static function (string $frame): void {},
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );

        $transport->send([
            'op' => 'subscribe',
            'args' => [['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP']],
        ]);
        $transport->send(['op' => 'ping']);

        self::assertTrue($opened);
        self::assertSame([[OkxPaperPublicConfig::WEB_SOCKET_URI]], $connectorArguments);
        self::assertSame(['message', 'close', 'error'], $connection->listenerNames());
        self::assertSame(
            ['{"op":"subscribe","args":[{"channel":"trades","instId":"BTC-USDT-SWAP"}]}', 'ping'],
            $connection->sent,
        );
    }

    public function testStructuredSendUsesThrowingJsonEncoding(): void
    {
        $connection = new FakePawlPublicConnection();
        $transport = new PawlOkxPaperPublicWebSocketTransport(
            loop: new DeterministicLoop(),
            connector: static fn (string $uri): PromiseInterface => resolve($connection),
        );
        $transport->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function (): void {},
            static function (string $frame): void {},
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );
        $recursive = [];
        $recursive['self'] = &$recursive;

        $this->expectException(\JsonException::class);

        $transport->send($recursive);
    }

    public function testSendBeforeOpenFailsClosed(): void
    {
        $transport = new PawlOkxPaperPublicWebSocketTransport(
            loop: new DeterministicLoop(),
            connector: static fn (string $uri): PromiseInterface => (new Deferred())->promise(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('okx_paper_public_ws_not_connected');

        $transport->send(['op' => 'ping']);
    }

    public function testOversizedFrameClosesActiveConnectionAndReportsOnlyStableError(): void
    {
        $connection = new FakePawlPublicConnection();
        $transport = new PawlOkxPaperPublicWebSocketTransport(
            loop: new DeterministicLoop(),
            connector: static fn (string $uri): PromiseInterface => resolve($connection),
        );
        $messages = [];
        $closes = [];
        $errors = [];
        $transport->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function (): void {},
            static function (string $frame) use (&$messages): void { $messages[] = $frame; },
            static function (?int $code) use (&$closes): void { $closes[] = $code; },
            static function (\Throwable $error) use (&$errors): void { $errors[] = $error; },
        );

        $connection->emit('message', str_repeat('x', OkxPaperLivePolicy::MAX_FRAME_BYTES + 1));

        self::assertSame([], $messages);
        self::assertSame([], $closes);
        self::assertSame(1, $connection->closeCount);
        self::assertCount(1, $errors);
        self::assertInstanceOf(OkxPaperLiveIntegrityException::class, $errors[0]);
        self::assertSame('okx_paper_public_ws_frame_too_large', $errors[0]->getMessage());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('okx_paper_public_ws_not_connected');
        $transport->send(['op' => 'ping']);
    }

    public function testDelayedConnectionResolvedAfterCloseIsImmediatelyClosedWithoutOpening(): void
    {
        $deferred = new Deferred();
        $connection = new FakePawlPublicConnection();
        $opened = false;
        $transport = new PawlOkxPaperPublicWebSocketTransport(
            loop: new DeterministicLoop(),
            connector: static fn (string $uri): PromiseInterface => $deferred->promise(),
        );
        $transport->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function () use (&$opened): void { $opened = true; },
            static function (string $frame): void {},
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );

        $transport->close();
        $deferred->resolve($connection);

        self::assertFalse($opened);
        self::assertSame(1, $connection->closeCount);
        self::assertSame([], $connection->listenerNames());
    }

    public function testCallbacksFromAnOldGenerationAreIgnoredAfterReconnect(): void
    {
        $old = new FakePawlPublicConnection();
        $active = new FakePawlPublicConnection();
        $connections = [$old, $active];
        $transport = new PawlOkxPaperPublicWebSocketTransport(
            loop: new DeterministicLoop(),
            connector: static function (string $uri) use (&$connections): PromiseInterface {
                return resolve(array_shift($connections));
            },
        );
        $oldCallbacks = ['message' => 0, 'close' => 0, 'error' => 0];
        $activeMessages = [];
        $transport->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function (): void {},
            static function (string $frame) use (&$oldCallbacks): void { ++$oldCallbacks['message']; },
            static function (?int $code) use (&$oldCallbacks): void { ++$oldCallbacks['close']; },
            static function (\Throwable $error) use (&$oldCallbacks): void { ++$oldCallbacks['error']; },
        );
        $transport->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function (): void {},
            static function (string $frame) use (&$activeMessages): void { $activeMessages[] = $frame; },
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );

        $old->emit('message', 'old-frame');
        $old->emit('error', new \RuntimeException('old-error'));
        $old->emit('close', 4999);
        $active->emit('message', 'active-frame');
        $transport->send(['op' => 'ping']);

        self::assertSame(1, $old->closeCount);
        self::assertSame(['message' => 0, 'close' => 0, 'error' => 0], $oldCallbacks);
        self::assertSame(['active-frame'], $activeMessages);
        self::assertSame(['ping'], $active->sent);
    }

    public function testFactoryCreatesDistinctLoopBoundPawlSessionsWithoutCrossTalk(): void
    {
        $loopA = new DeterministicLoop();
        $loopB = new DeterministicLoop();
        $factory = new PawlOkxPaperPublicWebSocketTransportFactory();

        $transportA = $factory->create($loopA);
        $transportB = $factory->create($loopB);

        self::assertNotSame($transportA, $transportB);
        $connectorA = self::pawlConnector($transportA);
        $connectorB = self::pawlConnector($transportB);
        self::assertNotSame($connectorA, $connectorB);
        self::assertSame($loopA, self::connectorLoop($connectorA));
        self::assertSame($loopB, self::connectorLoop($connectorB));
    }

    public function testSeparateTransportInstancesNeverShareConnectionsOrCallbacks(): void
    {
        $connectionA = new FakePawlPublicConnection();
        $connectionB = new FakePawlPublicConnection();
        $receivedA = [];
        $receivedB = [];
        $transportA = new PawlOkxPaperPublicWebSocketTransport(
            loop: new DeterministicLoop(),
            connector: static fn (string $uri): PromiseInterface => resolve($connectionA),
        );
        $transportB = new PawlOkxPaperPublicWebSocketTransport(
            loop: new DeterministicLoop(),
            connector: static fn (string $uri): PromiseInterface => resolve($connectionB),
        );
        $transportA->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function (): void {},
            static function (string $frame) use (&$receivedA): void { $receivedA[] = $frame; },
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );
        $transportB->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function (): void {},
            static function (string $frame) use (&$receivedB): void { $receivedB[] = $frame; },
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );

        $connectionA->emit('message', 'a');
        $connectionB->emit('message', 'b');
        $transportA->send(['session' => 'a']);
        $transportB->send(['session' => 'b']);

        self::assertSame(['a'], $receivedA);
        self::assertSame(['b'], $receivedB);
        self::assertSame(['{"session":"a"}'], $connectionA->sent);
        self::assertSame(['{"session":"b"}'], $connectionB->sent);
    }

    public function testDeterministicFakeRetainsCallbacksByAttemptAndIsolatesInstances(): void
    {
        $first = new FakeOkxPaperPublicWebSocketTransport();
        $second = new FakeOkxPaperPublicWebSocketTransport();
        $received = [];
        foreach (['old', 'current'] as $label) {
            $first->connect(
                OkxPaperPublicConfig::WEB_SOCKET_URI,
                static function () use (&$received, $label): void { $received[] = $label . ':open'; },
                static function (string $frame) use (&$received, $label): void { $received[] = $label . ':' . $frame; },
                static function (?int $code) use (&$received, $label): void { $received[] = $label . ':close:' . ($code ?? 'null'); },
                static function (\Throwable $error) use (&$received, $label): void { $received[] = $label . ':error'; },
            );
        }
        $second->connect(
            OkxPaperPublicConfig::WEB_SOCKET_URI,
            static function () use (&$received): void { $received[] = 'second:open'; },
            static function (string $frame) use (&$received): void { $received[] = 'second:' . $frame; },
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );

        $first->open(0);
        $first->message(['attempt' => 0], 0);
        $first->message('attempt-1');
        $first->disconnect(4000, 0);
        $first->fail(new \RuntimeException('stable'), 1);
        $second->open();

        self::assertSame(
            ['old:open', 'old:{"attempt":0}', 'current:attempt-1', 'old:close:4000', 'current:error', 'second:open'],
            $received,
        );
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI, OkxPaperPublicConfig::WEB_SOCKET_URI], $first->connections);
        self::assertSame([OkxPaperPublicConfig::WEB_SOCKET_URI], $second->connections);
        self::assertSame(0, $first->closeCount);
        self::assertSame([], $first->sent);
    }

    public function testDeterministicLoopFiresAndCancelsTimersExactly(): void
    {
        $loop = new DeterministicLoop();
        $fired = [];
        $cancelled = $loop->addTimer(1.0, static function () use (&$fired): void { $fired[] = 'cancelled'; });
        $loop->addTimer(2.0, static function () use (&$fired): void { $fired[] = 'next'; });
        $loop->addTimer(4.0, static function () use (&$fired): void { $fired[] = 'interval'; });
        $cancelledPeriodic = $loop->addPeriodicTimer(3.0, static function () use (&$fired): void { $fired[] = 'cancelled-periodic'; });
        $loop->addPeriodicTimer(5.0, static function () use (&$fired): void { $fired[] = 'periodic'; });

        $loop->cancelTimer($cancelled);
        $loop->cancelTimer($cancelledPeriodic);

        self::assertSame(2.0, $loop->fireNextTimer());
        self::assertSame(4.0, $loop->fireTimerInterval(4.0));
        $loop->firePeriodicInterval(5.0);
        self::assertSame(['next', 'interval', 'periodic'], $fired);
        self::assertSame([], $loop->timers);
        self::assertCount(1, $loop->periodicTimers);
    }

    public function testPolicyIsCompleteAndBounded(): void
    {
        self::assertSame([1.0, 2.0, 4.0, 8.0, 15.0, 30.0], OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS);
        self::assertSame(20.0, OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS);
        self::assertSame(10.0, OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS);
        self::assertSame(1_048_576, OkxPaperLivePolicy::MAX_FRAME_BYTES);
        self::assertSame(256, OkxPaperLivePolicy::MAX_QUEUED_FRAMES);
        self::assertSame(2_097_152, OkxPaperLivePolicy::MAX_QUEUED_BYTES);
        self::assertSame(3, OkxPaperLivePolicy::MAX_RESYNC_ATTEMPTS);
        self::assertSame(10.0, OkxPaperLivePolicy::RESYNC_ATTEMPT_TIMEOUT_SECONDS);
        self::assertSame(10, OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES);
        self::assertSame(30.0, OkxPaperLivePolicy::RECONNECT_STABLE_SECONDS);
        self::assertSame(12, OkxPaperLivePolicy::RECONNECT_STABLE_ACCEPTED_EVENTS);
    }

    public function testPublicInterfacesAndConstructorsExposeNoRestrictedParameterVocabulary(): void
    {
        $reflections = [
            new \ReflectionClass(OkxPaperPublicWebSocketTransportInterface::class),
            new \ReflectionClass(OkxPaperPublicWebSocketTransportFactoryInterface::class),
            new \ReflectionClass(PawlOkxPaperPublicWebSocketTransportFactory::class),
            new \ReflectionClass(PawlOkxPaperPublicWebSocketTransport::class),
        ];
        $parameterNames = [];
        foreach ($reflections as $reflection) {
            $constructor = $reflection->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $parameterNames[] = strtolower($parameter->getName());
            }
            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    $parameterNames[] = strtolower($parameter->getName());
                }
            }
        }

        self::assertSame([], array_values(array_filter(
            $parameterNames,
            static fn (string $name): bool => preg_match(
                '/key|secret|passphrase|sign|authorization|header|private|business|simulated/',
                $name,
            ) === 1,
        )));
    }

    private static function pawlConnector(
        OkxPaperPublicWebSocketTransportInterface $transport,
    ): Connector {
        $property = new \ReflectionProperty($transport, 'connector');
        $value = $property->getValue($transport);
        self::assertInstanceOf(\Closure::class, $value);
        $connector = (new \ReflectionFunction($value))->getClosureThis();
        self::assertInstanceOf(Connector::class, $connector);

        return $connector;
    }

    private static function connectorLoop(Connector $connector): LoopInterface
    {
        $property = new \ReflectionProperty($connector, '_loop');
        $loop = $property->getValue($connector);
        self::assertInstanceOf(LoopInterface::class, $loop);

        return $loop;
    }
}

final class FakePawlPublicConnection
{
    /** @var array<string, callable> */
    private array $listeners = [];

    /** @var list<string> */
    public array $sent = [];

    public int $closeCount = 0;

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event] = $listener;
    }

    /** @return list<string> */
    public function listenerNames(): array
    {
        return array_keys($this->listeners);
    }

    public function emit(string $event, mixed ...$arguments): void
    {
        ($this->listeners[$event])(...$arguments);
    }

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
    }

    public function close(): void
    {
        ++$this->closeCount;
    }
}
