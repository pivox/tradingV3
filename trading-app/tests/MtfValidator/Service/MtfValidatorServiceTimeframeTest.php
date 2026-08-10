<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Config\MtfValidationConfigProvider;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\MtfValidator\Service\MtfValidatorCoreService;
use App\MtfValidator\Service\MtfValidatorService;
use App\MtfValidator\Service\MtfTimeframeResolver;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(MtfValidatorService::class)]
final class MtfValidatorServiceTimeframeTest extends TestCase
{
    public function testBindsIndependentCanonicalIdentityForEachSymbol(): void
    {
        $captured = [];
        $core = $this->createMock(MtfValidatorCoreService::class);
        $core->method('validate')->willReturnCallback(
            static function (MtfRunDto $dto) use (&$captured): never {
                $captured[] = $dto->lineageContext;
                throw new \RuntimeException('stop-after-capture');
            },
        );
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-08T00:00:00Z'));
        $projectDir = \dirname(__DIR__, 3);
        $service = new MtfValidatorService(
            $core,
            $clock,
            new MtfValidationConfigProvider(new ParameterBag(['kernel.project_dir' => $projectDir, 'mode' => []])),
            'regular',
            new MtfTimeframeResolver(),
        );
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        unset($data['symbol']);
        $requestIdentity = LineageContext::fromArray($data);

        $service->run(new MtfRunRequestDto(
            symbols: ['btcusdt', 'ETHUSDT'],
            profile: 'scalping',
            lineageContext: $requestIdentity,
        ));

        self::assertNull($requestIdentity->symbol);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], array_map(
            static fn (LineageContext $identity): ?string => $identity->symbol,
            $captured,
        ));
        self::assertNotSame($captured[0], $captured[1]);
    }

    public function testRegularProfileDerivesMissingExecutionTimeframesWithoutDuplicates(): void
    {
        $projectDir = \dirname(__DIR__, 3);
        $configProvider = new MtfValidationConfigProvider(new ParameterBag([
            'kernel.project_dir' => $projectDir,
            'mode' => [],
        ]));

        $service = new MtfValidatorService(
            $this->createMock(MtfValidatorCoreService::class),
            $this->createMock(ClockInterface::class),
            $configProvider,
            'regular',
            new MtfTimeframeResolver(),
        );

        self::assertSame(
            ['4h', '1h', '15m', '5m', '1m'],
            $service->getListTimeframe('regular'),
        );
    }
}
