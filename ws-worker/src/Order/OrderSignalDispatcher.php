<?php

declare(strict_types=1);

namespace App\Order;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OrderSignalDispatcher
{
    private const SUCCESS_STATUS_CODES = [200, 201, 202, 204, 409];

    /**
     * @param array<int,float> $backoffDelays
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $endpoint,
        private readonly string $sharedSecret,
        private readonly float $timeoutSeconds = 2.0,
        private readonly int $maxRetries = 5,
        private readonly array $backoffDelays = [0.0, 5.0, 15.0, 45.0, 120.0],
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?string $failureLogPath = null,
    ) {
        if ($this->endpoint === '') {
            throw new \InvalidArgumentException('OrderSignalDispatcher endpoint cannot be empty');
        }
    }

    public function dispatch(OrderSignal $signal): void
    {
        $this->send($signal, 0);
    }

    private function send(OrderSignal $signal, int $attempt): void
    {
        $timestamp = (string) (int) round(microtime(true) * 1000);
        $payload = $signal->withRetryCount($attempt)->toArray();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $this->logger->error('[OrderSignalDispatcher] Failed to encode JSON payload', [
                'trace_id' => $signal->traceId(),
            ]);
            return;
        }

        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $this->sharedSecret);
        $headers = [
            'Content-Type' => 'application/json',
            'X-WS-Worker-Timestamp' => $timestamp,
            'X-WS-Worker-Signature' => $signature,
        ];

        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => $headers,
                'timeout' => $this->timeoutSeconds,
                'body' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if (in_array($statusCode, self::SUCCESS_STATUS_CODES, true)) {
                $this->logger->info('[OrderSignalDispatcher] Signal sent', [
                    'trace_id' => $signal->traceId(),
                    'status_code' => $statusCode,
                    'attempt' => $attempt,
                ]);
                return;
            }

            if ($statusCode >= 400 && $statusCode < 500) {
                $this->logger->error('[OrderSignalDispatcher] Permanent failure', [
                    'trace_id' => $signal->traceId(),
                    'status_code' => $statusCode,
                    'response' => $responseBody,
                ]);
                $this->persistFailure($signal, $statusCode, $responseBody);
                return;
            }

            $this->logger->warning('[OrderSignalDispatcher] Temporary failure, will retry', [
                'trace_id' => $signal->traceId(),
                'status_code' => $statusCode,
                'attempt' => $attempt,
                'response' => $responseBody,
            ]);
            $this->retryLater($signal, $attempt);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('[OrderSignalDispatcher] Transport error, will retry', [
                'trace_id' => $signal->traceId(),
                'attempt' => $attempt,
                'error' => $exception->getMessage(),
            ]);
            $this->retryLater($signal, $attempt);
        } catch (\Throwable $exception) {
            $this->logger->error('[OrderSignalDispatcher] Unexpected error', [
                'trace_id' => $signal->traceId(),
                'attempt' => $attempt,
                'error' => $exception->getMessage(),
            ]);
            $this->persistFailure($signal, 0, $exception->getMessage());
        }
    }

    private function retryLater(OrderSignal $signal, int $attempt): void
    {
        $nextAttempt = $attempt + 1;
        if ($nextAttempt > $this->maxRetries) {
            $this->logger->error('[OrderSignalDispatcher] Max retries reached', [
                'trace_id' => $signal->traceId(),
            ]);
            $this->persistFailure($signal, 0, 'max_retries_reached');
            return;
        }

        $delay = $this->backoffDelays[$nextAttempt] ?? end($this->backoffDelays);
        Loop::addTimer($delay, function () use ($signal, $nextAttempt) {
            $this->send($signal, $nextAttempt);
        });
    }

    private function persistFailure(OrderSignal $signal, int $statusCode, string $response): void
    {
        if ($this->failureLogPath === null) {
            return;
        }

        $directory = \dirname($this->failureLogPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $record = json_encode([
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'status_code' => $statusCode,
            'response' => $response,
            'payload' => $signal->toArray(),
        ], JSON_UNESCAPED_SLASHES);

        if ($record !== false) {
            @file_put_contents($this->failureLogPath, $record . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
