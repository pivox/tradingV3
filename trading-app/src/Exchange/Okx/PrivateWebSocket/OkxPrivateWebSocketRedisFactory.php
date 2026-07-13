<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use Closure;
use Redis;
use RedisException;
use RuntimeException;

final class OkxPrivateWebSocketRedisFactory
{
    /** @var Closure(): Redis */
    private readonly Closure $redisFactory;

    /** @param null|Closure(): Redis $redisFactory */
    public function __construct(?Closure $redisFactory = null)
    {
        $this->redisFactory = $redisFactory ?? static fn (): Redis => new Redis();
    }

    public function connect(
        string $host,
        int $port,
        float $connectTimeout,
        float $readTimeout,
    ): Redis {
        $redis = ($this->redisFactory)();

        try {
            $connected = $redis->connect(
                $host,
                $port,
                $connectTimeout,
                null,
                0,
                $readTimeout,
            );
        } catch (RedisException) {
            throw new RuntimeException('okx_private_ws_redis_connect_failed');
        }

        if (!$connected) {
            throw new RuntimeException('okx_private_ws_redis_connect_failed');
        }

        return $redis;
    }
}
