<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\OkxAuthSigner;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketLoginSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPrivateWebSocketLoginSigner::class)]
#[CoversClass(OkxAuthSigner::class)]
final class OkxPrivateWebSocketLoginSignerTest extends TestCase
{
    public function testBuildsTheDocumentedLoginSignature(): void
    {
        $args = (new OkxPrivateWebSocketLoginSigner(new OkxAuthSigner()))->buildLoginArgs(
            'demo-key',
            'demo-secret',
            'demo-passphrase',
            '1538054050',
        );

        self::assertSame([
            'apiKey' => 'demo-key',
            'passphrase' => 'demo-passphrase',
            'timestamp' => '1538054050',
            'sign' => 'tJKVyU0IUHP31zHaOqrS3Ao0CWl+kCfEs/un+qmw324=',
        ], $args);
    }
}
