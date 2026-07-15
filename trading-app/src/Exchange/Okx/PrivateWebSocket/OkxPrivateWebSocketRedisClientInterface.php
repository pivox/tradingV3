<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

interface OkxPrivateWebSocketRedisClientInterface
{
    public function setex(string $key, int $ttl, string $value): bool;

    public function get(string $key): string|false;

    public function del(string $key): int|false;
}
