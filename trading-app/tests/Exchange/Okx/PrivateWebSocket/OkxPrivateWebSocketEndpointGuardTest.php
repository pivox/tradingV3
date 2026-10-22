<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPrivateWebSocketEndpointGuard::class)]
final class OkxPrivateWebSocketEndpointGuardTest extends TestCase
{
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/private'])]
    public function testAcceptsOnlyCanonicalDemoPrivateUri(string $uri): void
    {
        self::assertSame(
            'okx_demo_private_v1',
            (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri),
        );
    }

    #[TestWith(['wss://wseeapap.okx.com/ws/v5/business'])]
    public function testAcceptsOnlyCanonicalDemoBusinessUri(string $uri): void
    {
        self::assertSame(
            'okx_demo_business_v1',
            (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri),
        );
    }

    public function testAcceptsTheDemoDefaultsResolvedByOkxConfig(): void
    {
        $config = new OkxConfig(environment: 'demo');
        $guard = new OkxPrivateWebSocketEndpointGuard();

        self::assertSame('okx_demo_private_v1', $guard->assertAllowed($config->wsPrivateUri()));
        self::assertSame('okx_demo_business_v1', $guard->assertAllowed($config->wsBusinessUri()));
    }

    #[TestWith(['https://wseeapap.okx.com/ws/v5/private'])]
    #[TestWith(['ws://wseeapap.okx.com/ws/v5/private'])]
    #[TestWith(['wss://wseeapap.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://wseeapap.okx.com:8443/ws/v5/business'])]
    #[TestWith(['wss://wseeapap.okx.com:443/ws/v5/private'])]
    #[TestWith(['wss://wseeapap.okx.com:443/ws/v5/business'])]
    #[TestWith(['wss://wspap.okx.com/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://wspap.okx.com/ws/v5/private?brokerId=9999'])]
    #[TestWith(['wss://ws.okx.com/ws/v5/private'])]
    #[TestWith(['wss://ws.okx.com:8443/ws/v5/private'])]
    #[TestWith(['wss://wseeapap.okx.com.evil.test/ws/v5/private'])]
    #[TestWith(['wss://user@wseeapap.okx.com/ws/v5/private'])]
    #[TestWith(['wss://user:pass@wseeapap.okx.com/ws/v5/private'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/public'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/private/'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/%70rivate'])]
    #[TestWith(['wss://wseeapap.okx.com/ws%2Fv5%2Fprivate'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/private?'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/private?x=1'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/private?brokerId=9999&x=1'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/private?brokerId=%39%39%39%39'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/private#fragment'])]
    #[TestWith(['wss://wspap.okx.com/ws/v5/business'])]
    #[TestWith(['wss://wspap.okx.com/ws/v5/business?brokerId=9999'])]
    #[TestWith(['wss://wseeapap.okx.com/ws/v5/business?brokerId=9999'])]
    #[TestWith(['wss://WSEEAPAP.okx.com/ws/v5/private'])]
    public function testRejectsEveryNonAllowlistedUri(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_demo_private_ws_endpoint_not_allowed');

        (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri);
    }
}
