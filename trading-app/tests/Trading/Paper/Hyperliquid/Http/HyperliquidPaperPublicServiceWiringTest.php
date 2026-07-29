<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Http;

use App\Kernel;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicHttpTransportInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRateLimiter;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClient;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\Http\NativeHyperliquidPaperPublicHttpTransport;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfigFactory;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRateLimiter;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClient;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversNothing]
final class HyperliquidPaperPublicServiceWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testPublicFactoryMapsOnlyExactNetworksToCanonicalInfoUris(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(HyperliquidPaperPublicConfigFactory::class);
        self::assertInstanceOf(HyperliquidPaperPublicConfigFactory::class, $factory);

        $mainnet = $factory->create('mainnet');
        $testnet = $factory->create('testnet');

        self::assertSame(PaperMarketDataNetwork::MAINNET, $mainnet->network);
        self::assertSame(HyperliquidPaperPublicConfig::MAINNET_INFO_URI, $mainnet->infoUri);
        self::assertFalse($mainnet->acquisitionEnabled);
        self::assertSame(PaperMarketDataNetwork::TESTNET, $testnet->network);
        self::assertSame(HyperliquidPaperPublicConfig::TESTNET_INFO_URI, $testnet->infoUri);
        self::assertFalse($testnet->acquisitionEnabled);
        self::assertSame(
            static::getContainer()->getParameter('kernel.project_dir') . '/var/paper-market-data',
            $mainnet->dataRoot,
        );
        self::assertSame($mainnet->dataRoot, $testnet->dataRoot);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNetworks(): iterable
    {
        yield 'legacy' => ['legacy_unknown'];
        yield 'uppercase' => ['MAINNET'];
        yield 'whitespace' => [' mainnet'];
        yield 'blank' => [''];
        yield 'endpoint' => [HyperliquidPaperPublicConfig::MAINNET_INFO_URI];
    }

    #[DataProvider('invalidNetworks')]
    public function testFactoryRejectsEveryNonExactNetwork(string $network): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(HyperliquidPaperPublicConfigFactory::class);
        self::assertInstanceOf(HyperliquidPaperPublicConfigFactory::class, $factory);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_network_invalid');

        $factory->create($network);
    }

    public function testPublicClientUsesOnlyDedicatedFreshPublicDependencies(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $client = $container->get(HyperliquidPaperPublicRestClientInterface::class);
        self::assertSame(HyperliquidPaperPublicRestClient::class, $client::class);

        $transport = self::property($client, 'transport');
        self::assertSame(NativeHyperliquidPaperPublicHttpTransport::class, $transport::class);
        $nativeHttpClient = self::property($transport, 'httpClient');
        self::assertInstanceOf(HttpClientInterface::class, $nativeHttpClient);
        self::assertNotSame($container->get('http_client'), $nativeHttpClient);

        $config = self::property($client, 'config');
        self::assertInstanceOf(HyperliquidPaperPublicConfig::class, $config);
        self::assertSame(PaperMarketDataNetwork::MAINNET, $config->network);
        self::assertSame(HyperliquidPaperPublicConfig::MAINNET_INFO_URI, $config->infoUri);
        self::assertFalse($config->acquisitionEnabled);

        $rateLimiter = self::property($client, 'rateLimiter');
        self::assertSame(HyperliquidPaperPublicRateLimiter::class, $rateLimiter::class);
        $limiter = self::property($rateLimiter, 'limiter');
        self::assertInstanceOf(SlidingWindowLimiter::class, $limiter);

        $okxClient = $container->get(OkxPaperPublicRestClientInterface::class);
        self::assertInstanceOf(OkxPaperPublicRestClient::class, $okxClient);
        $okxRateLimiter = self::property($okxClient, 'rateLimiter');
        self::assertInstanceOf(OkxPaperPublicRateLimiter::class, $okxRateLimiter);
        self::assertNotSame(self::property($okxRateLimiter, 'historyLimiter'), $limiter);
        self::assertNotSame(self::property($okxRateLimiter, 'snapshotLimiter'), $limiter);
    }

    public function testReachableApplicationDependencyAndCallableSurfacesArePublicReadOnly(): void
    {
        $constructors = [
            HyperliquidPaperPublicConfigFactory::class => ['acquisitionEnabled', 'dataRoot'],
            HyperliquidPaperPublicConfig::class => ['network', 'acquisitionEnabled', 'infoUri', 'dataRoot'],
            HyperliquidPaperPublicRestClient::class => ['transport', 'config', 'rateLimiter', 'clock'],
            HyperliquidPaperPublicRateLimiter::class => ['limiter'],
            NativeHyperliquidPaperPublicHttpTransport::class => [],
        ];
        $expectedTypes = [
            HyperliquidPaperPublicConfigFactory::class => ['bool', 'string'],
            HyperliquidPaperPublicConfig::class => [
                PaperMarketDataNetwork::class,
                'bool',
                'string',
                'string',
            ],
            HyperliquidPaperPublicRestClient::class => [
                HyperliquidPaperPublicHttpTransportInterface::class,
                HyperliquidPaperPublicConfig::class,
                HyperliquidPaperPublicRateLimiter::class,
                ClockInterface::class,
            ],
            HyperliquidPaperPublicRateLimiter::class => [LimiterInterface::class],
        ];
        $forbiddenDependency = '/(?:Account|Execution|Wallet|Signer|PrivateKey|SignedAction|ExchangeCall|ActionTransport)/i';

        foreach ($constructors as $class => $expectedParameters) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            $parameters = $constructor?->getParameters() ?? [];
            self::assertSame($expectedParameters, array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $parameters,
            ));
            foreach ($parameters as $parameter) {
                $type = $parameter->getType();
                self::assertInstanceOf(\ReflectionNamedType::class, $type);
                self::assertDoesNotMatchRegularExpression(
                    $forbiddenDependency,
                    $class . '::$' . $parameter->getName() . ':' . $type->getName(),
                );
            }
            if (isset($expectedTypes[$class])) {
                self::assertSame($expectedTypes[$class], array_map(
                    static function (\ReflectionParameter $parameter): string {
                        $type = $parameter->getType();
                        self::assertInstanceOf(\ReflectionNamedType::class, $type);

                        return $type->getName();
                    },
                    $parameters,
                ));
            }
        }

        self::assertSame(
            ['candleSnapshot'],
            self::declaredPublicMethodNames(HyperliquidPaperPublicRestClientInterface::class),
        );
        self::assertSame(
            ['postCandleSnapshot', 'stream'],
            self::declaredPublicMethodNames(HyperliquidPaperPublicHttpTransportInterface::class),
        );
        self::assertSame(
            ['create'],
            self::declaredPublicMethodNames(HyperliquidPaperPublicConfigFactory::class),
        );
        foreach ([
            HyperliquidPaperPublicRestClientInterface::class,
            HyperliquidPaperPublicHttpTransportInterface::class,
            HyperliquidPaperPublicConfigFactory::class,
        ] as $class) {
            foreach (self::declaredPublicMethodNames($class) as $method) {
                self::assertDoesNotMatchRegularExpression($forbiddenDependency, $class . '::' . $method);
            }
        }
    }

    public function testPublicSourceAndConfigurationExposeOnlyTheCandleInfoBoundary(): void
    {
        $projectDirectory = (string) static::getContainer()->getParameter('kernel.project_dir');
        $sourceFiles = [
            '/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfig.php',
            '/src/Trading/Paper/Hyperliquid/HyperliquidPaperPublicConfigFactory.php',
            '/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicHttpTransportInterface.php',
            '/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRateLimiter.php',
            '/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRestClient.php',
            '/src/Trading/Paper/Hyperliquid/Http/HyperliquidPaperPublicRestClientInterface.php',
            '/src/Trading/Paper/Hyperliquid/Http/NativeHyperliquidPaperPublicHttpTransport.php',
        ];
        $source = '';
        foreach ($sourceFiles as $sourceFile) {
            $contents = file_get_contents($projectDirectory . $sourceFile);
            self::assertIsString($contents);
            $source .= "\n" . $contents;
        }

        self::assertStringNotContainsString('/exchange', strtolower($source));
        self::assertDoesNotMatchRegularExpression(
            '/[\'"]type[\'"]\s*(?:=>|:)\s*[\'"]action[\'"]|postAction|\/action/i',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            '/Authorization|Api-Key|Private-Key|Signature|Wallet|Signer|Credential/i',
            $source,
        );
        preg_match_all('~https://[^\s\'"]+~', $source, $uriMatches);
        self::assertEqualsCanonicalizing([
            HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
            HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
        ], array_values(array_unique($uriMatches[0])));

        $services = file_get_contents($projectDirectory . '/config/services.yaml');
        $environment = file_get_contents(dirname($projectDirectory) . '/.env.example');
        self::assertIsString($services);
        self::assertIsString($environment);
        preg_match_all('/HYPERLIQUID_PAPER_PUBLIC_[A-Z_]+/', $services . "\n" . $environment, $envMatches);
        self::assertEqualsCanonicalizing([
            'HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED',
            'HYPERLIQUID_PAPER_PUBLIC_NETWORK',
        ], array_values(array_unique($envMatches[0])));
        self::assertStringNotContainsString('HYPERLIQUID_PAPER_PUBLIC_URI', $services . $environment);
        self::assertStringContainsString(
            "factory: ['@limiter.hyperliquid_paper_history', 'create']",
            $services,
        );
    }

    /** @return list<string> */
    private static function declaredPublicMethodNames(string $class): array
    {
        return array_values(array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class
                    && !$method->isConstructor(),
            ),
        ));
    }

    private static function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object::class, $name))->getValue($object);
    }
}
