<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

final class OkxRestEndpointGuard
{
    private const DEMO_URIS = [
        'https://eea.okx.com' => 'okx_demo_rest_v1',
    ];

    private const LIVE_URIS = [
        'https://www.okx.com' => 'okx_live_rest_v1',
    ];

    public function assertAllowed(string $uri, bool $demo): string
    {
        $allowed = $demo ? self::DEMO_URIS : self::LIVE_URIS;
        if (!isset($allowed[$uri])) {
            throw new \RuntimeException('okx_private_rest_endpoint_not_allowed');
        }

        return $allowed[$uri];
    }
}
