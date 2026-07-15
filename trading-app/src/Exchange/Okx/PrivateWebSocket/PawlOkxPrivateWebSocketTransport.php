<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use Ratchet\Client\Connector;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

final class PawlOkxPrivateWebSocketTransport implements OkxPrivateWebSocketTransportInterface
{
    /** @var \Closure(string): PromiseInterface<object> */
    private readonly \Closure $connector;

    private ?object $connection = null;
    private int $generation = 0;

    /**
     * @param null|\Closure(string): PromiseInterface<object> $connector
     */
    public function __construct(?LoopInterface $loop = null, ?\Closure $connector = null)
    {
        $this->connector = $connector ?? static function (string $uri) use ($loop): PromiseInterface {
            $pawl = new Connector($loop ?? Loop::get());

            return $pawl($uri);
        };
    }

    public function connect(
        string $uri,
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        $generation = ++$this->generation;
        $previous = $this->connection;
        $this->connection = null;
        $previous?->close();
        ($this->connector)($uri)->then(
            function (object $connection) use ($generation, $onOpen, $onMessage, $onClose, $onError): void {
                if ($generation !== $this->generation) {
                    $connection->close();

                    return;
                }
                $this->connection = $connection;
                $connection->on('message', function (mixed $message) use ($connection, $generation, $onMessage): void {
                    if ($generation === $this->generation && $connection === $this->connection) {
                        $onMessage((string) $message);
                    }
                });
                $connection->on('close', function (mixed $code = null) use ($connection, $generation, $onClose): void {
                    if ($generation !== $this->generation || $connection !== $this->connection) {
                        return;
                    }
                    $this->connection = null;
                    $onClose(is_int($code) ? $code : null);
                });
                $connection->on('error', function (\Throwable $error) use ($connection, $generation, $onError): void {
                    if ($generation === $this->generation && $connection === $this->connection) {
                        $onError($error);
                    }
                });
                $onOpen();
            },
            function (\Throwable $error) use ($generation, $onError): void {
                if ($generation === $this->generation) {
                    $onError($error);
                }
            },
        );
    }

    public function send(array $message): void
    {
        if (null === $this->connection) {
            throw new \LogicException('okx_private_ws_not_connected');
        }

        $this->connection->send(
            ['op' => 'ping'] === $message
                ? 'ping'
                : json_encode($message, \JSON_THROW_ON_ERROR),
        );
    }

    public function close(): void
    {
        ++$this->generation;
        $connection = $this->connection;
        $this->connection = null;
        $connection?->close();
    }
}
