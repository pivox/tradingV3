<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Http;

use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperPublicConfig::class)]
final class OkxPaperPublicConfigTest extends TestCase
{
    public function testAcceptsOnlyTheCanonicalCredentialFreePublicUris(): void
    {
        $config = new OkxPaperPublicConfig(
            acquisitionEnabled: false,
            restBaseUri: 'https://www.okx.com',
            webSocketUri: 'wss://ws.okx.com:8443/ws/v5/public',
            dataRoot: '/srv/app/var/paper-market-data',
        );

        self::assertFalse($config->acquisitionEnabled);
        self::assertSame('https://www.okx.com', $config->restBaseUri);
        self::assertSame('wss://ws.okx.com:8443/ws/v5/public', $config->webSocketUri);
        self::assertSame('/srv/app/var/paper-market-data', $config->dataRoot);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedRestUris(): iterable
    {
        yield 'http' => ['http://www.okx.com'];
        yield 'userinfo' => ['https://user:password@www.okx.com'];
        yield 'explicit port' => ['https://www.okx.com:443'];
        yield 'trailing slash' => ['https://www.okx.com/'];
        yield 'path' => ['https://www.okx.com/api/v5/market'];
        yield 'query' => ['https://www.okx.com?target=market'];
        yield 'fragment' => ['https://www.okx.com#market'];
        yield 'demo host' => ['https://eea.okx.com'];
        yield 'application host' => ['https://app.okx.com'];
        yield 'host suffix' => ['https://www.okx.com.example.test'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedRestUris')]
    public function testRejectsEveryRestUriOutsideTheExactAllowlist(string $restBaseUri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_public_rest_uri_not_allowed');

        new OkxPaperPublicConfig(
            acquisitionEnabled: false,
            restBaseUri: $restBaseUri,
            webSocketUri: 'wss://ws.okx.com:8443/ws/v5/public',
            dataRoot: '/srv/app/var/paper-market-data',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedWebSocketUris(): iterable
    {
        yield 'unencrypted' => ['ws://ws.okx.com:8443/ws/v5/public'];
        yield 'userinfo' => ['wss://user:password@ws.okx.com:8443/ws/v5/public'];
        yield 'missing port' => ['wss://ws.okx.com/ws/v5/public'];
        yield 'wrong port' => ['wss://ws.okx.com:443/ws/v5/public'];
        yield 'trailing slash' => ['wss://ws.okx.com:8443/ws/v5/public/'];
        yield 'private path' => ['wss://ws.okx.com:8443/ws/v5/private'];
        yield 'business path' => ['wss://ws.okx.com:8443/ws/v5/business'];
        yield 'query' => ['wss://ws.okx.com:8443/ws/v5/public?channel=books'];
        yield 'fragment' => ['wss://ws.okx.com:8443/ws/v5/public#books'];
        yield 'demo host' => ['wss://wseeapap.okx.com:8443/ws/v5/public'];
        yield 'host suffix' => ['wss://ws.okx.com.example.test:8443/ws/v5/public'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedWebSocketUris')]
    public function testRejectsEveryWebSocketUriOutsideTheExactAllowlist(string $webSocketUri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_public_ws_uri_not_allowed');

        new OkxPaperPublicConfig(
            acquisitionEnabled: false,
            restBaseUri: 'https://www.okx.com',
            webSocketUri: $webSocketUri,
            dataRoot: '/srv/app/var/paper-market-data',
        );
    }

    public function testConfigurationCannotReceiveCredentialOrPrivateEndpointValues(): void
    {
        $constructor = (new \ReflectionClass(OkxPaperPublicConfig::class))->getConstructor();
        self::assertNotNull($constructor);

        $parameterNames = array_map(
            static fn (\ReflectionParameter $parameter): string => strtolower($parameter->getName()),
            $constructor->getParameters(),
        );

        self::assertSame(
            ['acquisitionenabled', 'restbaseuri', 'websocketuri', 'dataroot'],
            $parameterNames,
        );
        self::assertSame([], array_values(array_filter(
            $parameterNames,
            static fn (string $name): bool => preg_match('/key|secret|passphrase|private|business|sign|simulated/', $name) === 1,
        )));
    }
}
