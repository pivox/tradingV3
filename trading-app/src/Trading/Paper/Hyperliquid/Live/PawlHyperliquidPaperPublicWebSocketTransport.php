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
    private bool $readingPaused = false;

    /** @var list<string> */
    private array $pausedFrames = [];

    private int $pausedFrameBytes = 0;

    /** @var null|\Closure(string): void */
    private ?\Closure $onMessage = null;

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
        $this->resetInboundState();
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
                $this->onMessage = $onMessage(...);
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

                        if ($this->readingPaused) {
                            if (self::isPong($frame)) {
                                $onMessage($frame);

                                return;
                            }
                            $frameBytes = \strlen($frame);
                            if (\count($this->pausedFrames)
                                    >= HyperliquidPaperLivePolicy::MAX_QUEUED_FRAMES
                                || $frameBytes
                                    > HyperliquidPaperLivePolicy::MAX_QUEUED_BYTES
                                        - $this->pausedFrameBytes
                            ) {
                                $this->close();
                                $onError(new HyperliquidPaperLiveIntegrityException(
                                    'market_data_backpressure_exhausted',
                                ));

                                return;
                            }
                            $this->pausedFrames[] = $frame;
                            $this->pausedFrameBytes += $frameBytes;

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
                        $this->resetInboundState();
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

    public function pauseReading(): void
    {
        $connection = $this->connection;
        if ($connection === null || $this->readingPaused) {
            return;
        }
        $this->readingPaused = true;
    }

    public function resumeReading(): void
    {
        $connection = $this->connection;
        if ($connection === null) {
            return;
        }
        if (!$this->readingPaused) {
            return;
        }

        $this->readingPaused = false;
        while (!$this->isReadingPaused() && $this->pausedFrames !== []) {
            $frame = array_shift($this->pausedFrames);
            $this->pausedFrameBytes -= \strlen($frame);
            $onMessage = $this->onMessage ?? throw new \LogicException(
                'hyperliquid_paper_public_ws_not_connected',
            );
            $onMessage($frame);
            if ($connection !== $this->connection) {
                return;
            }
        }
    }

    public function close(): void
    {
        ++$this->generation;
        $connection = $this->connection;
        $this->connection = null;
        $this->resetInboundState();
        $connection?->close();
    }

    private function resetInboundState(): void
    {
        $this->readingPaused = false;
        $this->pausedFrames = [];
        $this->pausedFrameBytes = 0;
        $this->onMessage = null;
    }

    private function isReadingPaused(): bool
    {
        return $this->readingPaused;
    }

    private static function isPong(string $frame): bool
    {
        if (!str_contains($frame, 'pong')) {
            return false;
        }

        try {
            return json_decode($frame, true, 512, \JSON_THROW_ON_ERROR) === [
                'channel' => 'pong',
            ];
        } catch (\JsonException) {
            return false;
        }
    }
}
