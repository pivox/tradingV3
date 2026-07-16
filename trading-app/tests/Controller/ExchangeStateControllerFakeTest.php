<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Runner\OpenStateSnapshotSerializer;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\AccountProviderInterface;
use App\Controller\ExchangeStateController;
use App\Provider\Context\ExchangeContext;
use App\Provider\Context\ExchangeContextResolver;
use App\Provider\Fake\FakeKlineProvider;
use App\Provider\Fake\FakeSystemProvider;
use App\Provider\MainProvider;
use App\Provider\Registry\ExchangeProviderBundle;
use App\Provider\Registry\ExchangeProviderRegistry;
use App\Tests\Provider\Fake\FakeProviderFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exercises GET /api/exchange/open-state for the FAKE exchange context using a
 * registry wired only with the fake provider bundle (no Bitmart / no env). The
 * snapshot must be an empty {open_positions: [], open_orders: []}.
 */
#[CoversClass(ExchangeStateController::class)]
final class ExchangeStateControllerFakeTest extends TestCase
{
    private function buildController(): ExchangeStateController
    {
        $fake = FakeProviderFixture::create();
        $registry = new ExchangeProviderRegistry(
            [
                new ExchangeProviderBundle(
                    new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
                    new FakeKlineProvider(),
                    $fake->contract,
                    $fake->order,
                    $fake->account,
                    new FakeSystemProvider(),
                ),
            ],
            Exchange::FAKE,
            MarketType::PERPETUAL,
        );

        $controller = new ExchangeStateController(
            new MainProvider($registry),
            new ExchangeContextResolver(),
            new OpenStateSnapshotSerializer(),
            new NullLogger(),
        );

        // AbstractController::json() needs a container with the serializer flag.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }

    public function testOpenStateForFakeContextReturnsEmptySnapshot(): void
    {
        $controller = $this->buildController();

        $request = new Request(['exchange' => 'fake', 'market_type' => 'perpetual']);
        $response = $controller->openState($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['open_positions' => [], 'open_orders' => []], $payload);
    }

    public function testFakeProviderFixtureExposesRealInitialBalance(): void
    {
        self::assertSame(100000.0, FakeProviderFixture::create()->account->getAccountBalance());
    }

    public function testInvalidExchangeReturnsBadRequest(): void
    {
        $controller = $this->buildController();

        $request = new Request(['exchange' => 'nope']);
        $response = $controller->openState($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testProviderFailureReturnsServiceUnavailable(): void
    {
        // Une panne exchange fait lever getOpenPositionsOrFail : l'endpoint doit
        // répondre non-200 (503) au lieu d'un snapshot vide trompeur, pour que
        // l'orchestrateur fail-close les sets live.
        $throwingAccount = $this->createMock(AccountProviderInterface::class);
        $throwingAccount->method('getOpenPositionsOrFail')
            ->willThrowException(new \RuntimeException('bitmart unavailable'));
        $fake = FakeProviderFixture::create();

        $registry = new ExchangeProviderRegistry(
            [
                new ExchangeProviderBundle(
                    new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
                    new FakeKlineProvider(),
                    $fake->contract,
                    $fake->order,
                    $throwingAccount,
                    new FakeSystemProvider(),
                ),
            ],
            Exchange::FAKE,
            MarketType::PERPETUAL,
        );

        $controller = new ExchangeStateController(
            new MainProvider($registry),
            new ExchangeContextResolver(),
            new OpenStateSnapshotSerializer(),
            new NullLogger(),
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        $request = new Request(['exchange' => 'fake', 'market_type' => 'perpetual']);
        $response = $controller->openState($request);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }
}
