<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class RedisOkxPrivateWebSocketStatusStore implements OkxPrivateWebSocketStatusStoreInterface
{
    public const string KEY = 'tradingv3:okx:demo:private-observability:v1';
    public const int TTL_SECONDS = 10;

    public function __construct(
        private OkxPrivateWebSocketRedisClientInterface $redis,
        private int $ttlSeconds = self::TTL_SECONDS,
    ) {
        if (!in_array($ttlSeconds, [1, self::TTL_SECONDS], true)) {
            throw new InvalidArgumentException('okx_private_ws_status_ttl_invalid');
        }
    }

    public function save(OkxPrivateWebSocketObservabilityStatus $status): void
    {
        try {
            $json = json_encode($status->toArray(), JSON_THROW_ON_ERROR);
            if (!$this->redis->setex(self::KEY, $this->ttlSeconds, $json)) {
                throw new RuntimeException('redis_setex_failed');
            }
        } catch (Throwable) {
            throw new RuntimeException('okx_private_ws_status_write_failed');
        }
    }

    public function load(): ?OkxPrivateWebSocketObservabilityStatus
    {
        try {
            $json = $this->redis->get(self::KEY);
            if (false === $json) {
                return null;
            }

            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return null;
            }

            return OkxPrivateWebSocketObservabilityStatus::fromArray($data);
        } catch (Throwable) {
            return null;
        }
    }

    public function clear(): void
    {
        try {
            if (false === $this->redis->del(self::KEY)) {
                throw new RuntimeException('redis_del_failed');
            }
        } catch (Throwable) {
            throw new RuntimeException('okx_private_ws_status_write_failed');
        }
    }
}
