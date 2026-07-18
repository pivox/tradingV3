<?php

declare(strict_types=1);

namespace App\Tests\Runtime\Safety;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClient;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxRestClient;
use App\Provider\Bitmart\BitmartOrderProvider;
use App\Provider\Bitmart\Http\BitmartConfig;
use App\Provider\Bitmart\Http\BitmartHttpClientPrivate;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\Provider\Bitmart\Http\BitmartRequestSigner;
use App\Runtime\Safety\ExchangeCallGuardHttpClient;
use App\Runtime\Safety\FakeOnlyExchangeCallBlockedException;
use App\Runtime\Safety\FakeOnlyExchangeCallAudit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Service\ResetInterface;

#[CoversClass(FakeOnlyExchangeCallAudit::class)]
#[CoversClass(ExchangeCallGuardHttpClient::class)]
#[CoversClass(FakeOnlyExchangeCallBlockedException::class)]
final class FakeOnlyExchangeCallAuditTest extends TestCase
{
    private ?string $bitmartProjectDir = null;

    protected function tearDown(): void
    {
        if ($this->bitmartProjectDir !== null) {
            (new Filesystem())->remove($this->bitmartProjectDir);
        }
    }

    public function testArmedGuardCountsAndBlocksBeforeTheExchangeClientRuns(): void
    {
        $delegatedCalls = 0;
        $inner = new MockHttpClient(static function () use (&$delegatedCalls): MockResponse {
            ++$delegatedCalls;

            return new MockResponse('{}');
        });
        $audit = new FakeOnlyExchangeCallAudit();
        $guard = new ExchangeCallGuardHttpClient($inner, $audit, 'bitmart');
        $audit->begin(asyncExchangeCapableDispatchesSuppressed: true);

        try {
            $guard->request('POST', '/contract/private/submit-order');
            self::fail('The Fake-only guard must block exchange HTTP before delegation.');
        } catch (\Throwable $exception) {
            self::assertSame(FakeOnlyExchangeCallBlockedException::class, $exception::class);
            self::assertSame('fake_only_exchange_call_blocked:bitmart', $exception->getMessage());
            self::assertNotInstanceOf(TransportExceptionInterface::class, $exception);
        }

        self::assertSame(0, $delegatedCalls);
        self::assertSame(
            [
                'ambiguous_calls' => 0,
                'async_exchange_capable_dispatches_suppressed' => true,
                'complete' => true,
                'exchange_calls' => ['bitmart' => 1, 'hyperliquid' => 0, 'okx' => 0],
                'schema_version' => 'fake-only-exchange-safety-v1',
                'source' => 'symfony_http_client_guard',
            ],
            $audit->finish(),
        );
    }

    public function testBitmartWrapperDoesNotRetryOrRerouteGuardBlockAsTransportFailure(): void
    {
        [$guard, $audit, $delegatedCalls] = $this->armedGuard('bitmart');
        $this->bitmartProjectDir = sys_get_temp_dir() . '/trading-v3-fake-only-' . bin2hex(random_bytes(8));
        $client = new BitmartHttpClientPublic(
            $guard,
            $guard,
            new LockFactory(new InMemoryStore()),
            $this->bitmartProjectDir,
            new MockClock('2026-07-18T00:00:00+00:00'),
            new NullLogger(),
        );

        $this->assertGuardBlockSurvivesWrapper(
            static fn (): int => $client->getSystemTimeMs(),
            $audit,
            'bitmart',
            $delegatedCalls,
        );
    }

    public function testBitmartOpenOrdersPrivatePathDoesNotRetryGuardBlock(): void
    {
        [$guard, $audit, $delegatedCalls] = $this->armedGuard('bitmart');
        $this->bitmartProjectDir = sys_get_temp_dir() . '/trading-v3-fake-only-' . bin2hex(random_bytes(8));
        $config = new BitmartConfig('test-key', 'test-secret', 'test-memo');
        $lockFactory = new LockFactory(new InMemoryStore());
        $logger = new NullLogger();
        $privateClient = new BitmartHttpClientPrivate(
            $guard,
            new BitmartRequestSigner($config),
            $config,
            $lockFactory,
            $this->bitmartProjectDir,
            $logger,
        );
        $publicClient = new BitmartHttpClientPublic(
            $guard,
            $guard,
            $lockFactory,
            $this->bitmartProjectDir,
            new MockClock('2026-07-18T00:00:00+00:00'),
            $logger,
        );
        $provider = new BitmartOrderProvider($privateClient, $publicClient, $logger);

        $this->assertGuardBlockSurvivesWrapper(
            static fn (): array => $provider->getOpenOrdersOrFail('BTCUSDT'),
            $audit,
            'bitmart',
            $delegatedCalls,
        );
    }

    public function testOkxWrapperDoesNotExposeGuardBlockAsTransportFailure(): void
    {
        [$guard, $audit, $delegatedCalls] = $this->armedGuard('okx');
        $client = new OkxRestClient(
            $guard,
            new OkxConfig(environment: 'demo'),
            new MockClock('2026-07-18T00:00:00+00:00'),
        );

        $this->assertGuardBlockSurvivesWrapper(
            static fn (): array => $client->publicGet('/api/v5/public/instruments'),
            $audit,
            'okx',
            $delegatedCalls,
        );
    }

    public function testHyperliquidWrapperDoesNotRerouteGuardBlockAsTransportFailure(): void
    {
        [$guard, $audit, $delegatedCalls] = $this->armedGuard('hyperliquid');
        $client = new HyperliquidRestClient(
            $guard,
            new HyperliquidConfig(
                environment: 'testnet',
                apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
                network: 'testnet',
            ),
        );

        $this->assertGuardBlockSurvivesWrapper(
            static fn (): array => $client->info(['type' => 'meta']),
            $audit,
            'hyperliquid',
            $delegatedCalls,
        );
    }

    public function testResetPreventsAuditStateFromLeakingAcrossRequestsOrWorkers(): void
    {
        $audit = new FakeOnlyExchangeCallAudit();
        self::assertInstanceOf(ResetInterface::class, $audit);
        $audit->begin(asyncExchangeCapableDispatchesSuppressed: true);
        $audit->recordAttempt('okx');

        $audit->reset();

        self::assertFalse($audit->isActive());
        $audit->begin(asyncExchangeCapableDispatchesSuppressed: true);
        self::assertSame(
            [
                'ambiguous_calls' => 0,
                'async_exchange_capable_dispatches_suppressed' => true,
                'complete' => true,
                'exchange_calls' => ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0],
                'schema_version' => 'fake-only-exchange-safety-v1',
                'source' => 'symfony_http_client_guard',
            ],
            $audit->finish(),
        );
    }

    public function testEvidenceIsIncompleteWithoutAsyncDispatchSuppression(): void
    {
        $audit = new FakeOnlyExchangeCallAudit();
        $audit->begin(asyncExchangeCapableDispatchesSuppressed: false);

        $evidence = $audit->finish();

        self::assertFalse($evidence['async_exchange_capable_dispatches_suppressed']);
        self::assertFalse($evidence['complete']);
    }

    /**
     * @return array{ExchangeCallGuardHttpClient, FakeOnlyExchangeCallAudit, \Closure(): int}
     */
    private function armedGuard(string $exchange): array
    {
        $delegatedCalls = 0;
        $inner = new MockHttpClient(static function () use (&$delegatedCalls): MockResponse {
            ++$delegatedCalls;

            return new MockResponse('{}');
        });
        $audit = new FakeOnlyExchangeCallAudit();
        $audit->begin(asyncExchangeCapableDispatchesSuppressed: true);

        return [
            new ExchangeCallGuardHttpClient($inner, $audit, $exchange),
            $audit,
            static function () use (&$delegatedCalls): int {
                return $delegatedCalls;
            },
        ];
    }

    private function assertGuardBlockSurvivesWrapper(
        callable $operation,
        FakeOnlyExchangeCallAudit $audit,
        string $exchange,
        callable $delegatedCalls,
    ): void {
        try {
            $operation();
            self::fail('The wrapper must propagate the dedicated Fake-only guard block.');
        } catch (\Throwable $exception) {
            self::assertSame(FakeOnlyExchangeCallBlockedException::class, $exception::class);
            self::assertNotInstanceOf(TransportExceptionInterface::class, $exception);
            self::assertSame(sprintf('fake_only_exchange_call_blocked:%s', $exchange), $exception->getMessage());
        }

        self::assertSame(0, $delegatedCalls());
        $expectedCalls = ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0];
        $expectedCalls[$exchange] = 1;
        $evidence = $audit->finish();
        self::assertSame($expectedCalls, $evidence['exchange_calls']);
        self::assertSame(0, $evidence['ambiguous_calls']);
        self::assertFalse($audit->isActive());
    }
}
