<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use Ratchet\Client\Connector;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

final class PawlOkxPaperPublicWebSocketTransport implements OkxPaperPublicWebSocketTransportInterface
{
    /** @var \Closure(string): PromiseInterface<object> */
    private readonly \Closure $connector;

    private ?object $connection = null;
    private int $generation = 0;

    /** @param null|\Closure(string): PromiseInterface<object> $connector */
    public function __construct(LoopInterface $loop, ?\Closure $connector = null)
    {
        if (null === $connector) {
            $pawl = new Connector($loop);
            $connector = \Closure::fromCallable($pawl);
        }

        $this->connector = $connector;
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
                $connection->on(
                    'message',
                    function (mixed $message) use ($connection, $generation, $onMessage, $onError): void {
                        if ($generation !== $this->generation || $connection !== $this->connection) {
                            return;
                        }

                        $frame = (string) $message;
                        if (strlen($frame) > OkxPaperLivePolicy::MAX_FRAME_BYTES) {
                            $this->close();
                            $onError(new OkxPaperLiveIntegrityException(
                                'okx_paper_public_ws_frame_too_large',
                            ));

                            return;
                        }

                        $onMessage($frame);
                    },
                );
                $connection->on(
                    'close',
                    function (mixed $code = null) use ($connection, $generation, $onClose): void {
                        if ($generation !== $this->generation || $connection !== $this->connection) {
                            return;
                        }

                        $this->connection = null;
                        $onClose(is_int($code) ? $code : null);
                    },
                );
                $connection->on(
                    'error',
                    function (\Throwable $error) use ($connection, $generation, $onError): void {
                        if ($generation === $this->generation && $connection === $this->connection) {
                            $onError($error);
                        }
                    },
                );
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
            throw new \LogicException('okx_paper_public_ws_not_connected');
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
