<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MtfController;
use App\MtfValidator\Service\MtfService;
use App\Repository\MtfSwitchRepository;
use App\Repository\MtfStateRepository;
use App\Repository\KlineRepository;
use App\Repository\OrderPlanRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class MtfControllerTest extends TestCase
{
    private MtfController $controller;
    private MtfService $mtfService;
    private MtfSwitchRepository $mtfSwitchRepository;
    private MtfStateRepository $mtfStateRepository;
    private KlineRepository $klineRepository;
    private OrderPlanRepository $orderPlanRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->mtfService = $this->createMock(MtfService::class);
        $this->mtfSwitchRepository = $this->createMock(MtfSwitchRepository::class);
        $this->mtfStateRepository = $this->createMock(MtfStateRepository::class);
        $this->klineRepository = $this->createMock(KlineRepository::class);
        $this->orderPlanRepository = $this->createMock(OrderPlanRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new MtfController(
            $this->mtfService,
            $this->mtfSwitchRepository,
            $this->mtfStateRepository,
            $this->klineRepository,
            $this->orderPlanRepository,
            $this->logger
        );
    }

    public function testRunMtfCycleWithDefaultParameters(): void
    {
        // Mock des dépendances
        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('isGlobalSwitchOn')
            ->willReturn(true);

        $this->mtfSwitchRepository
            ->expects($this->exactly(5)) // 5 symboles par défaut
            ->method('canProcessSymbol')
            ->willReturn(true);

        // Mock du service MTF
        $this->mtfService
            ->expects($this->exactly(5))
            ->method('getTimeService')
            ->willReturn($this->createMock(\App\MtfValidator\Service\MtfTimeService::class));

        // Mock du repository d'état
        $this->mtfStateRepository
            ->expects($this->exactly(5))
            ->method('getOrCreateForSymbol')
            ->willReturn($this->createMock(\App\Entity\MtfState::class));

        // Mock du repository de klines
        $this->klineRepository
            ->expects($this->exactly(5))
            ->method('findOneBy')
            ->willReturn($this->createMock(\App\Entity\Kline::class));

        // Mock du repository d'order plans
        $this->orderPlanRepository
            ->expects($this->exactly(5))
            ->method('getEntityManager')
            ->willReturn($this->createMock(\Doctrine\ORM\EntityManagerInterface::class));

        // Créer une requête avec les paramètres par défaut
        $request = new Request();
        $request->request->set('symbols', []);
        $request->request->set('dry_run', true);
        $request->request->set('force_run', false);

        // Exécuter la méthode
        $response = $this->controller->runMtfCycle($request);

        // Vérifier la réponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('MTF run completed', $data['message']);
        $this->assertArrayHasKey('summary', $data['data']);
        $this->assertArrayHasKey('results', $data['data']);
    }

    public function testRunMtfCycleWithCustomSymbols(): void
    {
        // Mock des dépendances
        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('isGlobalSwitchOn')
            ->willReturn(true);

        $this->mtfSwitchRepository
            ->expects($this->exactly(2)) // 2 symboles personnalisés
            ->method('canProcessSymbol')
            ->willReturn(true);

        // Mock du service MTF
        $this->mtfService
            ->expects($this->exactly(2))
            ->method('getTimeService')
            ->willReturn($this->createMock(\App\MtfValidator\Service\MtfTimeService::class));

        // Mock du repository d'état
        $this->mtfStateRepository
            ->expects($this->exactly(2))
            ->method('getOrCreateForSymbol')
            ->willReturn($this->createMock(\App\Entity\MtfState::class));

        // Mock du repository de klines
        $this->klineRepository
            ->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturn($this->createMock(\App\Entity\Kline::class));

        // Mock du repository d'order plans
        $this->orderPlanRepository
            ->expects($this->exactly(2))
            ->method('getEntityManager')
            ->willReturn($this->createMock(\Doctrine\ORM\EntityManagerInterface::class));

        // Créer une requête avec des symboles personnalisés
        $request = new Request();
        $request->request->set('symbols', ['BTCUSDT', 'ETHUSDT']);
        $request->request->set('dry_run', true);
        $request->request->set('force_run', false);

        // Exécuter la méthode
        $response = $this->controller->runMtfCycle($request);

        // Vérifier la réponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('MTF run completed', $data['message']);
        $this->assertArrayHasKey('summary', $data['data']);
        $this->assertArrayHasKey('results', $data['data']);
        $this->assertEquals(2, $data['data']['summary']['symbols_requested']);
    }

    public function testRunMtfCycleWithGlobalKillSwitchOff(): void
    {
        // Mock des dépendances
        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('isGlobalSwitchOn')
            ->willReturn(false);

        // Créer une requête
        $request = new Request();
        $request->request->set('symbols', ['BTCUSDT']);
        $request->request->set('dry_run', true);
        $request->request->set('force_run', false);

        // Exécuter la méthode
        $response = $this->controller->runMtfCycle($request);

        // Vérifier la réponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertStringContainsString('Global kill switch is OFF', $data['message']);
    }

    public function testRunMtfCycleWithForceRun(): void
    {
        // Mock des dépendances
        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('isGlobalSwitchOn')
            ->willReturn(false); // Kill switch OFF mais force_run = true

        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('canProcessSymbol')
            ->willReturn(true);

        // Mock du service MTF
        $this->mtfService
            ->expects($this->once())
            ->method('getTimeService')
            ->willReturn($this->createMock(\App\MtfValidator\Service\MtfTimeService::class));

        // Mock du repository d'état
        $this->mtfStateRepository
            ->expects($this->once())
            ->method('getOrCreateForSymbol')
            ->willReturn($this->createMock(\App\Entity\MtfState::class));

        // Mock du repository de klines
        $this->klineRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn($this->createMock(\App\Entity\Kline::class));

        // Mock du repository d'order plans
        $this->orderPlanRepository
            ->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($this->createMock(\Doctrine\ORM\EntityManagerInterface::class));

        // Créer une requête avec force_run = true
        $request = new Request();
        $request->request->set('symbols', ['BTCUSDT']);
        $request->request->set('dry_run', true);
        $request->request->set('force_run', true);

        // Exécuter la méthode
        $response = $this->controller->runMtfCycle($request);

        // Vérifier la réponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('MTF run completed', $data['message']);
    }

    public function testRunMtfCycleWithSymbolKillSwitchOff(): void
    {
        // Mock des dépendances
        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('isGlobalSwitchOn')
            ->willReturn(true);

        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('canProcessSymbol')
            ->willReturn(false); // Symbol kill switch OFF

        // Créer une requête
        $request = new Request();
        $request->request->set('symbols', ['BTCUSDT']);
        $request->request->set('dry_run', true);
        $request->request->set('force_run', false);

        // Exécuter la méthode
        $response = $this->controller->runMtfCycle($request);

        // Vérifier la réponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('MTF run completed', $data['message']);
        $this->assertEquals(1, $data['data']['summary']['symbols_skipped']);
        $this->assertEquals(0, $data['data']['summary']['symbols_successful']);
    }

    public function testRunMtfCycleWithException(): void
    {
        // Mock des dépendances pour lever une exception
        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('isGlobalSwitchOn')
            ->willThrowException(new \Exception('Database connection failed'));

        // Créer une requête
        $request = new Request();
        $request->request->set('symbols', ['BTCUSDT']);
        $request->request->set('dry_run', true);
        $request->request->set('force_run', false);

        // Exécuter la méthode
        $response = $this->controller->runMtfCycle($request);

        // Vérifier la réponse
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Database connection failed', $data['message']);
    }
}




