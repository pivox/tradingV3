<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperPublicConfig::class)]
final class HyperliquidPaperPublicConfigTest extends TestCase
{
    public function testAcceptsOnlyTheCanonicalNetworkAndInfoUriPairs(): void
    {
        $mainnet = new HyperliquidPaperPublicConfig(
            network: PaperMarketDataNetwork::MAINNET,
            acquisitionEnabled: true,
            infoUri: HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
            dataRoot: '/srv/app/var/paper-market-data',
        );
        $testnet = new HyperliquidPaperPublicConfig(
            network: PaperMarketDataNetwork::TESTNET,
            acquisitionEnabled: false,
            infoUri: HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
            dataRoot: '/srv/app/var/paper-market-data',
        );

        self::assertSame(PaperMarketDataNetwork::MAINNET, $mainnet->network);
        self::assertTrue($mainnet->acquisitionEnabled);
        self::assertSame('https://api.hyperliquid.xyz/info', $mainnet->infoUri);
        self::assertSame(PaperMarketDataNetwork::TESTNET, $testnet->network);
        self::assertFalse($testnet->acquisitionEnabled);
        self::assertSame('https://api.hyperliquid-testnet.xyz/info', $testnet->infoUri);
    }

    public function testRejectsTheLegacyUnknownNetwork(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_network_invalid');

        new HyperliquidPaperPublicConfig(
            network: PaperMarketDataNetwork::LEGACY_UNKNOWN,
            acquisitionEnabled: false,
            infoUri: HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
            dataRoot: '/srv/app/var/paper-market-data',
        );
    }

    /** @return iterable<string, array{PaperMarketDataNetwork, string}> */
    public static function rejectedInfoUris(): iterable
    {
        yield 'mainnet with testnet URI' => [PaperMarketDataNetwork::MAINNET, 'https://api.hyperliquid-testnet.xyz/info'];
        yield 'testnet with mainnet URI' => [PaperMarketDataNetwork::TESTNET, 'https://api.hyperliquid.xyz/info'];
        yield 'exchange endpoint' => [PaperMarketDataNetwork::MAINNET, 'https://api.hyperliquid.xyz/exchange'];
        yield 'trailing slash' => [PaperMarketDataNetwork::MAINNET, 'https://api.hyperliquid.xyz/info/'];
        yield 'query' => [PaperMarketDataNetwork::MAINNET, 'https://api.hyperliquid.xyz/info?type=meta'];
        yield 'fragment' => [PaperMarketDataNetwork::MAINNET, 'https://api.hyperliquid.xyz/info#meta'];
        yield 'userinfo' => [PaperMarketDataNetwork::MAINNET, 'https://user:password@api.hyperliquid.xyz/info'];
        yield 'http' => [PaperMarketDataNetwork::MAINNET, 'http://api.hyperliquid.xyz/info'];
        yield 'explicit port' => [PaperMarketDataNetwork::MAINNET, 'https://api.hyperliquid.xyz:443/info'];
        yield 'host suffix' => [PaperMarketDataNetwork::MAINNET, 'https://api.hyperliquid.xyz.example.test/info'];
        yield 'blank' => [PaperMarketDataNetwork::MAINNET, ''];
    }

    #[DataProvider('rejectedInfoUris')]
    public function testRejectsEveryInfoUriOutsideTheExactNetworkAllowlist(
        PaperMarketDataNetwork $network,
        string $infoUri,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_info_uri_not_allowed');

        new HyperliquidPaperPublicConfig(
            network: $network,
            acquisitionEnabled: false,
            infoUri: $infoUri,
            dataRoot: '/srv/app/var/paper-market-data',
        );
    }

    public function testConstructorSurfaceIsCredentialFreeAndExact(): void
    {
        $constructor = (new \ReflectionClass(HyperliquidPaperPublicConfig::class))->getConstructor();
        self::assertNotNull($constructor);

        $parameterNames = array_map(
            static fn (\ReflectionParameter $parameter): string => strtolower($parameter->getName()),
            $constructor->getParameters(),
        );

        self::assertSame(['network', 'acquisitionenabled', 'infouri', 'dataroot'], $parameterNames);
        self::assertSame([], array_values(array_filter(
            $parameterNames,
            static fn (string $name): bool => preg_match(
                '/key|secret|wallet|address|signer|signature|account|header|action|private|login/',
                $name,
            ) === 1,
        )));
    }
}
