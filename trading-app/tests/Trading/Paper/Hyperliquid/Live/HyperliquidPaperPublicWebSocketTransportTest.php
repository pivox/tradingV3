<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveIntegrityException;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Live\PawlHyperliquidPaperPublicWebSocketTransport;
use App\Trading\Paper\Hyperliquid\Live\PawlHyperliquidPaperPublicWebSocketTransportFactory;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

#[CoversClass(PawlHyperliquidPaperPublicWebSocketTransport::class)]
#[CoversClass(PawlHyperliquidPaperPublicWebSocketTransportFactory::class)]
final class HyperliquidPaperPublicWebSocketTransportTest extends TestCase
{
    public function testBindsTheValidatedEndpointAndSendsOnlyCanonicalPublicMessages(): void
    {
        $connection = new HyperliquidFakePawlConnection();
        $connectorArguments = [];
        $transport = new PawlHyperliquidPaperPublicWebSocketTransport(
            loop: new StreamSelectLoop(),
            config: self::config(PaperMarketDataNetwork::TESTNET),
            connector: static function (string $uri) use (
                $connection,
                &$connectorArguments,
            ): PromiseInterface {
                $connectorArguments[] = func_get_args();

                return resolve($connection);
            },
        );
        $opened = false;
        $transport->connect(
            static function () use (&$opened): void { $opened = true; },
            static function (string $frame): void {},
            static function (?int $code): void {},
            static function (\Throwable $error): void {},
        );
        $transport->send([
            'method' => 'subscribe',
            'subscription' => ['type' => 'trades', 'coin' => 'BTC'],
        ]);
        $transport->send(['method' => 'ping']);

        self::assertTrue($opened);
        self::assertSame(
            [[HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI]],
            $connectorArguments,
        );
        self::assertSame(['message', 'close', 'error'], $connection->listenerNames());
        self::assertSame([
            '{"method":"subscribe","subscription":{"coin":"BTC","type":"trades"}}',
            '{"method":"ping"}',
        ], $connection->sent);
    }

    public function testRejectsPostBeforeWritingToTheSocket(): void
    {
        $connection = new HyperliquidFakePawlConnection();
        $transport = self::connectedTransport($connection);

        try {
            $transport->send([
                'method' => 'post',
                'id' => 1,
                'request' => ['type' => 'action', 'payload' => ['wallet' => 'secret']],
            ]);
            self::fail('Expected post rejection.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_subscription_invalid',
                $exception->getMessage(),
            );
        }
        self::assertSame([], $connection->sent);
    }

    public function testOversizedFrameClosesAndReportsOnlyAStableReason(): void
    {
        $connection = new HyperliquidFakePawlConnection();
        $messages = [];
        $errors = [];
        $transport = self::connectedTransport(
            $connection,
            static function (string $frame) use (&$messages): void {
                $messages[] = $frame;
            },
            static function (\Throwable $error) use (&$errors): void {
                $errors[] = $error;
            },
        );

        $connection->emit(
            'message',
            str_repeat('wallet=secret', HyperliquidPaperLivePolicy::MAX_FRAME_BYTES),
        );

        self::assertSame([], $messages);
        self::assertSame(1, $connection->closeCount);
        self::assertCount(1, $errors);
        self::assertInstanceOf(HyperliquidPaperLiveIntegrityException::class, $errors[0]);
        self::assertSame(
            'hyperliquid_paper_public_ws_frame_too_large',
            $errors[0]->getMessage(),
        );
        self::assertStringNotContainsString('secret', $errors[0]->getMessage());
    }

    public function testSocketErrorInvalidatesConnectionBeforeReportingIt(): void
    {
        $connection = new HyperliquidFakePawlConnection();
        $messages = [];
        $errors = [];
        self::connectedTransport(
            $connection,
            static function (string $frame) use (&$messages): void {
                $messages[] = $frame;
            },
            static function (\Throwable $error) use (&$errors): void {
                $errors[] = $error;
            },
        );
        $failure = new \RuntimeException('transport_failure');

        $connection->emit('error', $failure);
        $connection->emit('message', '{"channel":"pong"}');
        $connection->emit('error', $failure);

        self::assertSame(1, $connection->closeCount);
        self::assertSame([], $messages);
        self::assertSame([$failure], $errors);
    }

    public function testFactoryCreatesFreshNetworkBoundTransports(): void
    {
        $factory = new PawlHyperliquidPaperPublicWebSocketTransportFactory();
        $loop = new StreamSelectLoop();
        $config = self::config(PaperMarketDataNetwork::MAINNET);

        $first = $factory->create($loop, $config);
        $second = $factory->create($loop, $config);

        self::assertNotSame($first, $second);
        self::assertSame($config, self::property($first, 'config'));
        self::assertSame($config, self::property($second, 'config'));
    }

    private static function connectedTransport(
        HyperliquidFakePawlConnection $connection,
        ?\Closure $onMessage = null,
        ?\Closure $onError = null,
    ): PawlHyperliquidPaperPublicWebSocketTransport {
        $transport = new PawlHyperliquidPaperPublicWebSocketTransport(
            loop: new StreamSelectLoop(),
            config: self::config(PaperMarketDataNetwork::MAINNET),
            connector: static fn (string $uri): PromiseInterface => resolve($connection),
        );
        $transport->connect(
            static function (): void {},
            $onMessage ?? static function (string $frame): void {},
            static function (?int $code): void {},
            $onError ?? static function (\Throwable $error): void {},
        );

        return $transport;
    }

    private static function config(
        PaperMarketDataNetwork $network,
    ): HyperliquidPaperPublicConfig {
        return new HyperliquidPaperPublicConfig(
            network: $network,
            acquisitionEnabled: true,
            infoUri: $network === PaperMarketDataNetwork::MAINNET
                ? HyperliquidPaperPublicConfig::MAINNET_INFO_URI
                : HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
            webSocketUri: $network === PaperMarketDataNetwork::MAINNET
                ? HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI
                : HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
            dataRoot: '/tmp/paper',
        );
    }

    private static function property(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);

        return $property->getValue($object);
    }
}

final class HyperliquidFakePawlConnection
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
