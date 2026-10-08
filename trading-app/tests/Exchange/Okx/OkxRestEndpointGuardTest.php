<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxRestEndpointGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxRestEndpointGuard::class)]
#[CoversClass(OkxConfig::class)]
final class OkxRestEndpointGuardTest extends TestCase
{
    public function testAllowsOnlyTheCanonicalUriForEachEnvironment(): void
    {
        $guard = new OkxRestEndpointGuard();

        self::assertSame('okx_demo_rest_v1', $guard->assertAllowed('https://eea.okx.com', true));
        self::assertSame('okx_live_rest_v1', $guard->assertAllowed('https://www.okx.com', false));
    }

    /**
     * @return iterable<string,array{string,bool}>
     */
    public static function rejected(): iterable
    {
        yield 'live uri in demo' => ['https://www.okx.com', true];
        yield 'demo uri in live' => ['https://eea.okx.com', false];
        yield 'trailing slash' => ['https://eea.okx.com/', true];
        yield 'plain http' => ['http://eea.okx.com', true];
        yield 'lookalike host' => ['https://eea.okx.com.evil.example', true];
        yield 'empty' => ['', true];
    }

    #[DataProvider('rejected')]
    public function testRejectsEverythingElse(string $uri, bool $demo): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_private_rest_endpoint_not_allowed');

        (new OkxRestEndpointGuard())->assertAllowed($uri, $demo);
    }

    public function testConfigDelegatesToTheGuard(): void
    {
        (new OkxConfig(environment: 'demo'))->assertPrivateRestEndpointAllowed();
        (new OkxConfig(environment: 'demo', apiBaseUri: 'https://eea.okx.com'))->assertPrivateRestEndpointAllowed();

        $this->expectExceptionMessage('okx_private_rest_endpoint_not_allowed');
        (new OkxConfig(environment: 'demo', apiBaseUri: 'https://www.okx.com'))->assertPrivateRestEndpointAllowed();
    }
}
