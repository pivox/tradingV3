<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\MarketData\CanonicalJson;
use Ratchet\Client\Connector;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

final class PawlHyperliquidPaperPublicWebSocketTransport implements
    HyperliquidPaperPublicWebSocketTransportInterface
{
    /** @var \Closure(string): PromiseInterface<object> */
    private readonly \Closure $connector;

    private ?object $connection = null;
    private int $generation = 0;

    /** @param null|\Closure(string): PromiseInterface<object> $connector */
    public function __construct(
        LoopInterface $loop,
        private readonly HyperliquidPaperPublicConfig $config,
        ?\Closure $connector = null,
    ) {
        if ($connector === null) {
            $pawl = new Connector($loop);
            $connector = \Closure::fromCallable($pawl);
        }

        $this->connector = $connector;
    }

    public function connect(
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        $generation = ++$this->generation;
        $previous = $this->connection;
        $this->connection = null;
        $previous?->close();

        ($this->connector)($this->config->webSocketUri)->then(
            function (object $connection) use (
                $generation,
                $onOpen,
                $onMessage,
                $onClose,
                $onError,
            ): void {
                if ($generation !== $this->generation) {
                    $connection->close();

                    return;
                }

                $this->connection = $connection;
                $connection->on(
                    'message',
                    function (mixed $message) use (
                        $connection,
                        $generation,
                        $onMessage,
                        $onError,
                    ): void {
                        if ($generation !== $this->generation
                            || $connection !== $this->connection
                        ) {
                            return;
                        }

                        $frame = (string) $message;
                        if (\strlen($frame) > HyperliquidPaperLivePolicy::MAX_FRAME_BYTES) {
                            $this->close();
                            $onError(new HyperliquidPaperLiveIntegrityException(
                                'hyperliquid_paper_public_ws_frame_too_large',
                            ));

                            return;
                        }

                        $onMessage($frame);
                    },
                );
                $connection->on(
                    'close',
                    function (mixed $code = null) use (
                        $connection,
                        $generation,
                        $onClose,
                    ): void {
                        if ($generation !== $this->generation
                            || $connection !== $this->connection
                        ) {
                            return;
                        }

                        $this->connection = null;
                        $onClose(\is_int($code) ? $code : null);
                    },
                );
                $connection->on(
                    'error',
                    function (\Throwable $error) use (
                        $connection,
                        $generation,
                        $onError,
                    ): void {
                        if ($generation === $this->generation
                            && $connection === $this->connection
                        ) {
                            $this->close();
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
        if ($this->connection === null) {
            throw new \LogicException('hyperliquid_paper_public_ws_not_connected');
        }

        HyperliquidPaperPublicSubscriptionSet::assertOutbound($message);
        $this->connection->send(CanonicalJson::encode($message));
    }

    public function close(): void
    {
        ++$this->generation;
        $connection = $this->connection;
        $this->connection = null;
        $connection?->close();
    }
}
