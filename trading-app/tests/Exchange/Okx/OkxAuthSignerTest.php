<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Okx\OkxAuthSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxAuthSigner::class)]
final class OkxAuthSignerTest extends TestCase
{
    public function testSignsOkxFixture(): void
    {
        $signer = new OkxAuthSigner();

        $signature = $signer->sign(
            timestamp: '2026-01-01T00:00:00.000Z',
            method: 'GET',
            requestPath: '/api/v5/account/balance?ccy=USDT',
            body: '',
            secret: 'test-secret',
        );

        self::assertSame(
            base64_encode(hash_hmac(
                'sha256',
                '2026-01-01T00:00:00.000ZGET/api/v5/account/balance?ccy=USDT',
                'test-secret',
                true,
            )),
            $signature,
        );
    }
}
