<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\OkxAuthSigner;

final readonly class OkxPrivateWebSocketLoginSigner
{
    public function __construct(private OkxAuthSigner $authSigner)
    {
    }

    /** @return array{apiKey: string, passphrase: string, timestamp: string, sign: string} */
    public function buildLoginArgs(string $apiKey, string $secret, string $passphrase, string $timestamp): array
    {
        return [
            'apiKey' => $apiKey,
            'passphrase' => $passphrase,
            'timestamp' => $timestamp,
            'sign' => $this->authSigner->sign(
                $timestamp,
                'GET',
                '/users/self/verify',
                '',
                $secret,
            ),
        ];
    }
}
