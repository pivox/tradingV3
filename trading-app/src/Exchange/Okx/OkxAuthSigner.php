<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

final readonly class OkxAuthSigner
{
    public function sign(
        string $timestamp,
        string $method,
        string $requestPath,
        string $body,
        string $secret,
    ): string {
        $prehash = $timestamp . strtoupper($method) . $requestPath . $body;

        return base64_encode(hash_hmac('sha256', $prehash, $secret, true));
    }
}
