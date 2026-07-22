<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Http;

use App\Kernel;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClient;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRateLimiter;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;

#[CoversNothing]
final class OkxPaperPublicServiceWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testCredentialFreeDefaultsAndClientAreWiredWithoutPrivateServices(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $client = $container->get(OkxPaperPublicRestClientInterface::class);
        self::assertInstanceOf(OkxPaperPublicRestClient::class, $client);

        $configProperty = new \ReflectionProperty(OkxPaperPublicRestClient::class, 'config');
        $config = $configProperty->getValue($client);
        self::assertInstanceOf(OkxPaperPublicConfig::class, $config);
        self::assertFalse($config->acquisitionEnabled);
        self::assertSame('https://www.okx.com', $config->restBaseUri);
        self::assertSame('wss://ws.okx.com:8443/ws/v5/public', $config->webSocketUri);
        self::assertSame('wss://ws.okx.com:8443/ws/v5/business', $config->businessWebSocketUri);
        self::assertSame(self::getContainer()->getParameter('kernel.project_dir') . '/var/paper-market-data', $config->dataRoot);
    }

    public function testConfiguredSlidingWindowsUseThePlanHeadroomLimits(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $client = $container->get(OkxPaperPublicRestClientInterface::class);
        self::assertInstanceOf(OkxPaperPublicRestClient::class, $client);
        $rateLimiterProperty = new \ReflectionProperty(OkxPaperPublicRestClient::class, 'rateLimiter');
        $rateLimiter = $rateLimiterProperty->getValue($client);
        self::assertInstanceOf(OkxPaperPublicRateLimiter::class, $rateLimiter);

        $historyProperty = new \ReflectionProperty(OkxPaperPublicRateLimiter::class, 'historyLimiter');
        $history = $historyProperty->getValue($rateLimiter);
        self::assertInstanceOf(SlidingWindowLimiter::class, $history);
        $intervalProperty = new \ReflectionProperty(SlidingWindowLimiter::class, 'interval');
        self::assertSame(2, $intervalProperty->getValue($history));
        for ($attempt = 0; $attempt < 16; ++$attempt) {
            self::assertTrue($history->consume()->isAccepted());
        }
        self::assertFalse($history->consume()->isAccepted());

        $snapshotProperty = new \ReflectionProperty(OkxPaperPublicRateLimiter::class, 'snapshotLimiter');
        $snapshot = $snapshotProperty->getValue($rateLimiter);
        self::assertInstanceOf(SlidingWindowLimiter::class, $snapshot);
        self::assertSame(2, $intervalProperty->getValue($snapshot));
        for ($attempt = 0; $attempt < 32; ++$attempt) {
            self::assertTrue($snapshot->consume()->isAccepted());
        }
        self::assertFalse($snapshot->consume()->isAccepted());
    }
}
