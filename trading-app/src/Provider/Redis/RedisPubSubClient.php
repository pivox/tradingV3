<?php

declare(strict_types=1);

namespace App\Provider\Redis;

use Psr\Log\LoggerInterface;

final class RedisPubSubClient
{
    private ?object $client = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->connect();
    }

    public function publish(string $channel, string $message): int|false
    {
        if ($this->client === null) {
            $this->logger?->warning('[RedisPubSubClient] Redis extension unavailable, message not published', [
                'channel' => $channel,
            ]);

            return 0;
        }

        try {
            $result = $this->client->publish($channel, $message);

            return \is_int($result) || $result === false ? $result : (int) $result;
        } catch (\Throwable $exception) {
            $this->logger?->error('[RedisPubSubClient] Publish failed', [
                'channel' => $channel,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function connect(): void
    {
        if (!\class_exists('Redis')) {
            return;
        }

        try {
            $client = new \Redis();
            $client->connect($this->host, $this->port);
            $this->client = $client;
        } catch (\Throwable $exception) {
            $this->logger?->warning('[RedisPubSubClient] Redis connection failed', [
                'host' => $this->host,
                'port' => $this->port,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
