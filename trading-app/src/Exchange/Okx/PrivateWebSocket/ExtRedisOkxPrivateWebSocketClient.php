<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use Closure;
use Redis;

final class ExtRedisOkxPrivateWebSocketClient implements OkxPrivateWebSocketRedisClientInterface
{
    /** @var Closure(): Redis */
    private readonly Closure $redisFactory;

    private ?Redis $redis;

    /** @param Redis|Closure(): Redis $redis */
    public function __construct(Redis|Closure $redis)
    {
        $this->redis = $redis instanceof Redis ? $redis : null;
        $this->redisFactory = $redis instanceof Redis ? static fn (): Redis => $redis : $redis;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->redis()->setex($key, $ttl, $value);
    }

    public function get(string $key): string|false
    {
        return $this->redis()->get($key);
    }

    public function del(string $key): int|false
    {
        return $this->redis()->del($key);
    }

    private function redis(): Redis
    {
        return $this->redis ??= ($this->redisFactory)();
    }
}
