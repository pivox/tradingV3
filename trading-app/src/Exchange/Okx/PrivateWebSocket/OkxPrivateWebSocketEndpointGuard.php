<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

final class OkxPrivateWebSocketEndpointGuard
{
    private const ENDPOINT_ID = 'okx_demo_private_v1';

    private const ALLOWED_URIS = [
        'wss://wspap.okx.com:8443/ws/v5/private',
        'wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999',
    ];

    public function assertAllowed(string $uri): string
    {
        if (!in_array($uri, self::ALLOWED_URIS, true)) {
            throw new \InvalidArgumentException('okx_demo_private_ws_endpoint_not_allowed');
        }

        return self::ENDPOINT_ID;
    }
}
