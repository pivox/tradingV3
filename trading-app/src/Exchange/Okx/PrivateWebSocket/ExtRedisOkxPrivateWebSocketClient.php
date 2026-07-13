<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use Redis;

final readonly class ExtRedisOkxPrivateWebSocketClient implements OkxPrivateWebSocketRedisClientInterface
{
    public function __construct(private Redis $redis)
    {
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->redis->setex($key, $ttl, $value);
    }

    public function get(string $key): string|false
    {
        return $this->redis->get($key);
    }

    public function del(string $key): int|false
    {
        return $this->redis->del($key);
    }
}
