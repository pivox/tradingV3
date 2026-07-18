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
use App\Runtime\Safety\ExchangeCallGuardHttpClient;
use App\Runtime\Safety\FakeOnlyExchangeCallAudit;
use App\Tests\Provider\Fake\FakeProviderFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

    public function testFakeOnlyProofReturnsObservedExchangeCallEvidence(): void
    {
        $controller = $this->buildController();
        $request = new Request(
            ['exchange' => 'fake', 'market_type' => 'perpetual', 'dry_run' => 'true'],
            server: ['HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v2'],
        );

        $response = $controller->openState($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            [
                'ambiguous_calls' => 0,
                'async_exchange_capable_dispatches_suppressed' => true,
                'complete' => true,
                'exchange_call_proof' => [
                    'bitmart' => 'fake_provider_boundary',
                    'hyperliquid' => 'http_client_guard',
                    'okx' => 'http_client_guard',
                ],
                'exchange_calls' => ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0],
                'schema_version' => 'fake-only-exchange-safety-v2',
                'source' => 'symfony_fake_provider_boundary_and_http_guards',
            ],
            $payload['fake_only_safety_evidence'] ?? null,
        );
    }

    /**
     * @param array<string,string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProofContexts')]
    public function testFakeOnlyProofRejectsAnyContextThatIsNotFakeAndExplicitlyDryRun(array $query): void
    {
        $request = new Request(
            $query,
            server: ['HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v2'],
        );

        $response = $this->buildController()->openState($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('fake_only_safety_context_invalid', $payload['error_code'] ?? null);
        self::assertArrayNotHasKey('fake_only_safety_evidence', $payload);
    }

    /** @return iterable<string, array{array<string,string>}> */
    public static function invalidProofContexts(): iterable
    {
        yield 'missing explicit dry-run' => [[
            'exchange' => 'fake',
            'market_type' => 'perpetual',
        ]];
        yield 'real exchange' => [[
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'dry_run' => 'true',
        ]];
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

    public function testFakeOnlyProofProviderFailureReturnsServiceUnavailableAndResetsAudit(): void
    {
        $calls = 0;
        $throwingOnceAccount = $this->createMock(AccountProviderInterface::class);
        $throwingOnceAccount->method('getOpenPositionsOrFail')
            ->willReturnCallback(static function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new \RuntimeException('fake provider unavailable');
                }

                return [];
            });
        $fake = FakeProviderFixture::create();
        $audit = new FakeOnlyExchangeCallAudit();
        $registry = new ExchangeProviderRegistry(
            [
                new ExchangeProviderBundle(
                    new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
                    new FakeKlineProvider(),
                    $fake->contract,
                    $fake->order,
                    $throwingOnceAccount,
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
            $audit,
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        $request = new Request(
            ['exchange' => 'fake', 'market_type' => 'perpetual', 'dry_run' => 'true'],
            server: ['HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v2'],
        );

        $failedResponse = $controller->openState($request);
        $failedPayload = json_decode((string) $failedResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $failedResponse->getStatusCode());
        self::assertSame('error', $failedPayload['status'] ?? null);
        self::assertArrayNotHasKey('open_positions', $failedPayload);
        self::assertArrayNotHasKey('open_orders', $failedPayload);
        self::assertSame(1, $failedPayload['fake_only_safety_evidence']['ambiguous_calls'] ?? null);
        self::assertFalse($audit->isActive());

        $successfulResponse = $controller->openState($request);
        $successfulPayload = json_decode((string) $successfulResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $successfulResponse->getStatusCode());
        self::assertSame([], $successfulPayload['open_positions'] ?? null);
        self::assertSame([], $successfulPayload['open_orders'] ?? null);
        self::assertSame(0, $successfulPayload['fake_only_safety_evidence']['ambiguous_calls'] ?? null);
        self::assertFalse($audit->isActive());
    }

    public function testFakeOnlyProofDoesNotDoubleCountGuardedExchangeCallAsAmbiguous(): void
    {
        $delegatedCalls = 0;
        $innerClient = new MockHttpClient(static function () use (&$delegatedCalls): MockResponse {
            ++$delegatedCalls;

            return new MockResponse('{}');
        });
        $audit = new FakeOnlyExchangeCallAudit();
        $guard = new ExchangeCallGuardHttpClient($innerClient, $audit, 'okx');
        $guardedAccount = $this->createMock(AccountProviderInterface::class);
        $guardedAccount->method('getOpenPositionsOrFail')
            ->willReturnCallback(static function () use ($guard): array {
                try {
                    $guard->request('GET', 'https://www.okx.com/api/v5/account/positions');
                } catch (\Throwable) {
                    throw new \RuntimeException('fake provider wrapped guarded exchange call');
                }

                return [];
            });
        $fake = FakeProviderFixture::create();
        $registry = new ExchangeProviderRegistry(
            [
                new ExchangeProviderBundle(
                    new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
                    new FakeKlineProvider(),
                    $fake->contract,
                    $fake->order,
                    $guardedAccount,
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
            $audit,
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        $request = new Request(
            ['exchange' => 'fake', 'market_type' => 'perpetual', 'dry_run' => 'true'],
            server: ['HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v2'],
        );

        $response = $controller->openState($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame(0, $delegatedCalls);
        self::assertSame(
            ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 1],
            $payload['fake_only_safety_evidence']['exchange_calls'] ?? null,
        );
        self::assertSame(0, $payload['fake_only_safety_evidence']['ambiguous_calls'] ?? null);
        self::assertFalse($audit->isActive());
    }
}
