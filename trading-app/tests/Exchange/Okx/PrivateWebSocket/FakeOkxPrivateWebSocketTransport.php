<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketTransportInterface;

final class FakeOkxPrivateWebSocketTransport implements OkxPrivateWebSocketTransportInterface
{
    /** @var list<string> */
    public array $connections = [];

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public int $closeCount = 0;
    public ?\Throwable $sendError = null;

    /** @var list<array{open: \Closure, message: \Closure, close: \Closure, error: \Closure}> */
    private array $callbacks = [];

    public function __construct(private readonly ?EventTimeline $timeline = null)
    {
    }

    public function connect(
        string $uri,
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        $this->connections[] = $uri;
        $this->callbacks[] = [
            'open' => \Closure::fromCallable($onOpen),
            'message' => \Closure::fromCallable($onMessage),
            'close' => \Closure::fromCallable($onClose),
            'error' => \Closure::fromCallable($onError),
        ];
    }

    public function send(array $message): void
    {
        if (null !== $this->sendError) {
            throw $this->sendError;
        }
        $this->sent[] = $message;
    }

    public function close(): void
    {
        ++$this->closeCount;
        if (null !== $this->timeline) {
            $this->timeline->events[] = 'close';
        }
    }

    public function open(?int $attempt = null): void
    {
        ($this->callbacks($attempt)['open'])();
    }

    /** @param array<string, mixed>|string $message */
    public function message(array|string $message, ?int $attempt = null): void
    {
        ($this->callbacks($attempt)['message'])(
            is_string($message) ? $message : json_encode($message, \JSON_THROW_ON_ERROR),
        );
    }

    public function disconnect(?int $code = null, ?int $attempt = null): void
    {
        ($this->callbacks($attempt)['close'])($code);
    }

    public function fail(\Throwable $error, ?int $attempt = null): void
    {
        ($this->callbacks($attempt)['error'])($error);
    }

    /** @return array{open: \Closure, message: \Closure, close: \Closure, error: \Closure} */
    private function callbacks(?int $attempt): array
    {
        $attempt ??= array_key_last($this->callbacks);

        return null === $attempt
            ? throw new \LogicException('transport_not_connected')
            : ($this->callbacks[$attempt] ?? throw new \LogicException('transport_attempt_not_found'));
    }
}
