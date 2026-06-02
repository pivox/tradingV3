<?php

declare(strict_types=1);

namespace App\Tests\Front;

use App\Kernel;
use App\Front\Controller\FrontConfigController;
use App\Front\Controller\FrontController;
use App\Front\Controller\FrontDecisionController;
use App\Front\Controller\FrontInvestigationController;
use App\Front\Controller\FrontRiskController;
use App\Front\Controller\FrontSystemController;
use App\Front\Controller\FrontTemporalController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(FrontController::class)]
#[CoversClass(FrontRiskController::class)]
#[CoversClass(FrontDecisionController::class)]
#[CoversClass(FrontInvestigationController::class)]
#[CoversClass(FrontSystemController::class)]
#[CoversClass(FrontConfigController::class)]
#[CoversClass(FrontTemporalController::class)]
final class FrontRouteRegistrationTest extends KernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        $_ENV['DEFAULT_URI'] = 'http://localhost';
        $_SERVER['DEFAULT_URI'] = 'http://localhost';
        putenv('DEFAULT_URI=http://localhost');
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMainFrontRoutesAreRegistered(): void
    {
        self::bootKernel();

        $router = self::$kernel?->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $expectedRoutes = [
            'front_cockpit' => '/app',
            'front_risk' => '/app/risk',
            'front_decisions' => '/app/decisions',
            'front_decision_detail' => '/app/decisions/decision-key',
            'front_investigate' => '/app/investigate',
            'front_system' => '/app/system',
            'front_temporal' => '/app/temporal',
            'front_config' => '/app/config',
            'front_api_cockpit_summary' => '/app/api/cockpit/summary',
            'front_api_risk_summary' => '/app/api/risk/summary',
            'front_api_decisions_latest' => '/app/api/decisions/latest',
            'front_api_system_health' => '/app/api/system/health',
            'front_api_temporal_summary' => '/app/api/temporal/summary',
        ];

        foreach ($expectedRoutes as $name => $path) {
            self::assertSame($path, $router->generate($name, $name === 'front_decision_detail' ? ['decisionKey' => 'decision-key'] : []));
        }
    }
}
