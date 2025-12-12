<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Integration;

use App\Contract\MtfValidator\MtfValidatorInterface;
use App\MtfValidator\Service\MtfValidatorService;
use App\MtfValidator\Service\TradingDecisionHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MtfValidatorAutowiringTest extends KernelTestCase
{
    public function testFacadeAndDecisionAreAutowired(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $facade = $container->get(MtfValidatorInterface::class);
        $this->assertInstanceOf(MtfValidatorService::class, $facade);

        $decisionHandler = $container->get(TradingDecisionHandler::class);
        $this->assertInstanceOf(TradingDecisionHandler::class, $decisionHandler);
    }

}
