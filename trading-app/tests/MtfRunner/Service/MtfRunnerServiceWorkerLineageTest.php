<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Service;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\MtfRunner\Service\MtfRunnerService;
use App\Provider\Context\ExchangeContext;
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

    public function testModernWorkerCommandDerivesEveryIdentityDuplicateFromEnvelopeAuthority(): void
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        unset($data['symbol']);
        $data['dry_run'] = true;
        $identity = LineageContext::fromArray($data);
        $request = new MtfRunnerRequestDto(
            dryRun: true,
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            profile: 'scalping',
            originalRunId: 'run-fixture',
            correlationRunId: 'run-fixture',
            setId: 'set-fixture',
            lineageContext: $identity,
        );
        $service = (new \ReflectionClass(MtfRunnerService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(MtfRunnerService::class, 'projectDir'))->setValue($service, '/project');
        $optionsMethod = new \ReflectionMethod(MtfRunnerService::class, 'buildWorkerOptions');
        $commandMethod = new \ReflectionMethod(MtfRunnerService::class, 'buildWorkerCommand');

        /** @var array<string,mixed> $options */
        $options = $optionsMethod->invoke(
            $service,
            $request,
            new ExchangeContext(Exchange::BITMART, MarketType::SPOT),
            'generated-run-other',
        );
        /** @var string[] $command */
        $command = $commandMethod->invoke($service, 'BTCUSDT', $options);

        self::assertContains('--dry-run=1', $command);
        self::assertContains('--exchange=fake', $command);
        self::assertContains('--market-type=perpetual', $command);
        self::assertContains('--trade-profile=scalping', $command);
        self::assertContains('--request-id=run-fixture', $command);
        self::assertContains('--orchestration-run-id=run-fixture', $command);
        self::assertContains('--set-id=set-fixture', $command);
        self::assertContains('--origin=orchestrator', $command);
        self::assertContains('--config-hash=' . $identity->configHash, $command);
        self::assertNotContains('--trade-profile=', $command);
    }
}
