<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPrivateWebSocketEndpointGuard::class)]
final class OkxPrivateWebSocketEndpointGuardTest extends TestCase
{
    #[TestWith(['wss://wseeapap.okx.com:8443/ws/v5/private'])]
    public function testAcceptsOnlyCanonicalDemoPrivateUri(string $uri): void
    {
        self::assertSame(
            'okx_demo_private_v1',
            (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri),
        );
    }

    #[TestWith(['wss://wseeapap.okx.com:8443/ws/v5/business'])]
    public function testAcceptsOnlyCanonicalDemoBusinessUri(string $uri): void
    {
        self::assertSame(
            'okx_demo_business_v1',
            (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri),
        );
    }

    #[TestWith(['https://wspap.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999'])]
    #[TestWith(['wss://ws.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com.evil.test:8443/ws/v5/private'])]
    #[TestWith(['wss://user@wspap.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://user:pass@wspap.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com:443/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/public'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private/'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/%70rivate'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws%2Fv5%2Fprivate'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private?'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private?x=1'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999&x=1'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private?brokerId=%39%39%39%39'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private#fragment'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/business'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/business?brokerId=9999'])]
    #[TestWith(['wss://wseeapap.okx.com:8443/ws/v5/business?brokerId=9999'])]
    public function testRejectsEveryNonAllowlistedUri(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_demo_private_ws_endpoint_not_allowed');

        (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri);
    }
}
