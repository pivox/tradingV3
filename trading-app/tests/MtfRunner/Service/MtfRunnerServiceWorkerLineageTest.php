<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Service;

use App\MtfRunner\Service\MtfRunnerService;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerService::class)]
final class MtfRunnerServiceWorkerLineageTest extends TestCase
{
    public function testBuildsLosslessSymbolBoundWorkerEnvironment(): void
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        unset($data['symbol']);
        $requestIdentity = LineageContext::fromArray($data);
        $service = (new \ReflectionClass(MtfRunnerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(MtfRunnerService::class, 'buildWorkerEnvironment');

        /** @var array<string,string> $environment */
        $environment = $method->invoke($service, ' ethusdt ', $requestIdentity);
        $decoded = json_decode(
            (string) base64_decode($environment['MTF_CANONICAL_LINEAGE'], true),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('0', $environment['APP_DEBUG']);
        self::assertSame('ETHUSDT', $decoded['symbol']);
        self::assertSame($data['config_hash'], $decoded['config_hash']);
        self::assertSame($data['effective_config_snapshot'], $decoded['effective_config_snapshot']);
        self::assertNull($requestIdentity->symbol);
    }

    public function testLegacyWorkerEnvironmentClearsInheritedCanonicalIdentity(): void
    {
        $service = (new \ReflectionClass(MtfRunnerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(MtfRunnerService::class, 'buildWorkerEnvironment');

        /** @var array<string,string> $environment */
        $environment = $method->invoke(
            $service,
            'BTCUSDT',
            LineageContext::legacy('BTCUSDT', 'bitmart', 'perpetual', 'regular'),
        );

        self::assertSame('', $environment['MTF_CANONICAL_LINEAGE']);
    }
}
