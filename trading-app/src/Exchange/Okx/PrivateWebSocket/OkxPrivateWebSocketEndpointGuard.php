<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

final class OkxPrivateWebSocketEndpointGuard
{
    private const ALLOWED_URIS = [
        'wss://wseeapap.okx.com:8443/ws/v5/private' => 'okx_demo_private_v1',
        'wss://wseeapap.okx.com:8443/ws/v5/business' => 'okx_demo_business_v1',
    ];

    public function assertAllowed(string $uri): string
    {
        if (!isset(self::ALLOWED_URIS[$uri])) {
            throw new \InvalidArgumentException('okx_demo_private_ws_endpoint_not_allowed');
        }

        return self::ALLOWED_URIS[$uri];
    }
}
