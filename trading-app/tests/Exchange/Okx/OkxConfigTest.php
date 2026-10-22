<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Okx\OkxConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OKX discontinues WebSocket port 8443 on 2026-10-31: the defaults must use
 * the implicit wss:// port 443 while keeping the regional host and the path.
 */
#[CoversClass(OkxConfig::class)]
final class OkxConfigTest extends TestCase
{
    public function testDemoPrivateWebSocketDefaultsToEeaEndpoint(): void
    {
        self::assertSame(
            'wss://wseeapap.okx.com/ws/v5/private',
            (new OkxConfig(environment: 'demo'))->wsPrivateUri(),
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function defaultWebSocketUris(): iterable
    {
        yield 'demo public' => ['demo', 'public', 'wss://wseeapap.okx.com/ws/v5/public'];
        yield 'demo private' => ['demo', 'private', 'wss://wseeapap.okx.com/ws/v5/private'];
        yield 'demo business' => ['demo', 'business', 'wss://wseeapap.okx.com/ws/v5/business'];
        yield 'live public' => ['live', 'public', 'wss://ws.okx.com/ws/v5/public'];
        yield 'live private' => ['live', 'private', 'wss://ws.okx.com/ws/v5/private'];
        yield 'live business' => ['live', 'business', 'wss://ws.okx.com/ws/v5/business'];
        yield 'blank environment falls back to demo' => ['', 'private', 'wss://wseeapap.okx.com/ws/v5/private'];
        yield 'unknown environment falls back to demo' => ['staging', 'business', 'wss://wseeapap.okx.com/ws/v5/business'];
        yield 'live is case insensitive' => [' LIVE ', 'public', 'wss://ws.okx.com/ws/v5/public'];
    }

    #[DataProvider('defaultWebSocketUris')]
    public function testDefaultWebSocketUriKeepsEnvironmentHostAndPathWithoutPort8443(
        string $environment,
        string $channel,
        string $expected,
    ): void {
        $uri = $this->resolve(new OkxConfig(environment: $environment), $channel);

        self::assertSame($expected, $uri);
        self::assertStringNotContainsString(':8443', $uri);
        self::assertNull(parse_url($uri, \PHP_URL_PORT));
        self::assertSame('wss', parse_url($uri, \PHP_URL_SCHEME));
        self::assertSame('/ws/v5/' . $channel, parse_url($uri, \PHP_URL_PATH));
    }

    public function testDemoAndLiveDefaultsNeverShareAHost(): void
    {
        $demo = new OkxConfig(environment: 'demo');
        $live = new OkxConfig(environment: 'live');

        foreach (['public', 'private', 'business'] as $channel) {
            self::assertSame('wseeapap.okx.com', parse_url($this->resolve($demo, $channel), \PHP_URL_HOST));
            self::assertSame('ws.okx.com', parse_url($this->resolve($live, $channel), \PHP_URL_HOST));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function overriddenWebSocketUris(): iterable
    {
        yield 'demo public override' => ['demo', 'public'];
        yield 'demo private override' => ['demo', 'private'];
        yield 'demo business override' => ['demo', 'business'];
        yield 'live public override' => ['live', 'public'];
        yield 'live private override' => ['live', 'private'];
        yield 'live business override' => ['live', 'business'];
    }

    #[DataProvider('overriddenWebSocketUris')]
    public function testEnvironmentOverrideIsHonouredVerbatimAfterTrim(string $environment, string $channel): void
    {
        $override = 'wss://override.example.test/ws/v5/' . $channel;
        $config = new OkxConfig(
            environment: $environment,
            wsPublicUri: $channel === 'public' ? '  ' . $override . ' ' : '',
            wsPrivateUri: $channel === 'private' ? '  ' . $override . ' ' : '',
            wsBusinessUri: $channel === 'business' ? '  ' . $override . ' ' : '',
        );

        self::assertSame($override, $this->resolve($config, $channel));
    }

    public function testBlankOverrideFallsBackToPortlessDefault(): void
    {
        $config = new OkxConfig(
            environment: 'demo',
            wsPublicUri: '   ',
            wsPrivateUri: '',
            wsBusinessUri: "\t",
        );

        self::assertSame('wss://wseeapap.okx.com/ws/v5/public', $config->wsPublicUri());
        self::assertSame('wss://wseeapap.okx.com/ws/v5/private', $config->wsPrivateUri());
        self::assertSame('wss://wseeapap.okx.com/ws/v5/business', $config->wsBusinessUri());
    }

    public function testSourceNoLongerHardCodesPort8443(): void
    {
        $source = file_get_contents((string) (new \ReflectionClass(OkxConfig::class))->getFileName());

        self::assertIsString($source);
        self::assertStringNotContainsString(':8443', $source);
    }

    private function resolve(OkxConfig $config, string $channel): string
    {
        return match ($channel) {
            'public' => $config->wsPublicUri(),
            'private' => $config->wsPrivateUri(),
            'business' => $config->wsBusinessUri(),
            default => throw new \InvalidArgumentException('unknown channel ' . $channel),
        };
    }
}
