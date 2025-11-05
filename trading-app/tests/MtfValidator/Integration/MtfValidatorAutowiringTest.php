<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Integration;

use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\MtfValidator\Service\MtfRunService;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\MtfValidator\Service\Timeframe\Timeframe15mService;
use App\MtfValidator\Service\Timeframe\Timeframe1hService;
use App\MtfValidator\Service\Timeframe\Timeframe1mService;
use App\MtfValidator\Service\Timeframe\Timeframe4hService;
use App\MtfValidator\Service\Timeframe\Timeframe5mService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MtfValidatorAutowiringTest extends KernelTestCase
{
    public function testFacadePipelineAndDecisionAreAutowired(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $facade = $container->get(MtfValidatorInterface::class);
        $this->assertInstanceOf(MtfRunService::class, $facade);

        $orchestrator = $container->get(MtfRunOrchestrator::class);
        $symbolProcessor = $container->get(SymbolProcessor::class);
        $decisionHandler = $container->get(TradingDecisionHandler::class);

        $this->assertInstanceOf(SymbolProcessor::class, $this->readProperty($orchestrator, 'symbolProcessor'));
        $this->assertInstanceOf(TradingDecisionHandler::class, $this->readProperty($orchestrator, 'tradingDecisionHandler'));
        $this->assertSame($symbolProcessor, $this->readProperty($orchestrator, 'symbolProcessor'));
        $this->assertSame($decisionHandler, $this->readProperty($orchestrator, 'tradingDecisionHandler'));

        $this->assertInstanceOf(MtfRunOrchestrator::class, $this->readProperty($facade, 'orchestrator'));

        foreach ([
            Timeframe4hService::class,
            Timeframe1hService::class,
            Timeframe15mService::class,
            Timeframe5mService::class,
            Timeframe1mService::class,
        ] as $serviceClass) {
            $processor = $container->get($serviceClass);
            $this->assertInstanceOf(TimeframeProcessorInterface::class, $processor);
        }
    }

    private function readProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
