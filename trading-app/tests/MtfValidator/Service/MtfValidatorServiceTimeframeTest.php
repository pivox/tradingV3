<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Config\MtfValidationConfigProvider;
use App\MtfValidator\Service\MtfValidatorCoreService;
use App\MtfValidator\Service\MtfValidatorService;
use App\MtfValidator\Service\MtfTimeframeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(MtfValidatorService::class)]
final class MtfValidatorServiceTimeframeTest extends TestCase
{
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
