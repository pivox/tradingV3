<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Okx\OkxConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxConfig::class)]
final class OkxConfigTest extends TestCase
{
    public function testDemoPrivateWebSocketDefaultsToEeaEndpoint(): void
    {
        self::assertSame(
            'wss://wseeapap.okx.com:8443/ws/v5/private',
            (new OkxConfig(environment: 'demo'))->wsPrivateUri(),
        );
    }
}
