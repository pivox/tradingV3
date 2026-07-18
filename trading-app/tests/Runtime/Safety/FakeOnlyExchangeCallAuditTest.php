<?php

declare(strict_types=1);

namespace App\Tests\Runtime\Safety;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClient;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxRestClient;
use App\Runtime\Safety\ExchangeCallGuardHttpClient;
use App\Runtime\Safety\FakeOnlyExchangeCallBlockedException;
use App\Runtime\Safety\FakeOnlyExchangeCallAudit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Service\ResetInterface;

#[CoversClass(FakeOnlyExchangeCallAudit::class)]
#[CoversClass(ExchangeCallGuardHttpClient::class)]
#[CoversClass(FakeOnlyExchangeCallBlockedException::class)]
final class FakeOnlyExchangeCallAuditTest extends TestCase
{
    public function testArmedGuardCountsAndBlocksBeforeTheExchangeClientRuns(): void
    {
        $delegatedCalls = 0;
        $inner = new MockHttpClient(static function () use (&$delegatedCalls): MockResponse {
            ++$delegatedCalls;

            return new MockResponse('{}');
        });
        $audit = new FakeOnlyExchangeCallAudit();
        $guard = new ExchangeCallGuardHttpClient($inner, $audit, 'okx');
        $audit->begin(asyncExchangeCapableDispatchesSuppressed: true);

        try {
            $guard->request('POST', '/api/v5/trade/order');
            self::fail('The Fake-only guard must block exchange HTTP before delegation.');
        } catch (\Throwable $exception) {
            self::assertSame(FakeOnlyExchangeCallBlockedException::class, $exception::class);
            self::assertSame('fake_only_exchange_call_blocked:okx', $exception->getMessage());
            self::assertNotInstanceOf(TransportExceptionInterface::class, $exception);
        }

        self::assertSame(0, $delegatedCalls);
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
                'exchange_calls' => ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 1],
                'schema_version' => 'fake-only-exchange-safety-v2',
                'source' => 'symfony_fake_provider_boundary_and_http_guards',
            ],
            $audit->finish(),
        );
    }

    public function testServicesDoNotDecorateBitmartClientsForFakeAudit(): void
    {
        $services = file_get_contents(__DIR__ . '/../../../config/services.yaml');

        self::assertIsString($services);
        self::assertStringNotContainsString('app.http_client.exchange_guard.bitmart', $services);
        self::assertStringContainsString('app.http_client.exchange_guard.okx', $services);
        self::assertStringContainsString('app.http_client.exchange_guard.hyperliquid', $services);
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
                'exchange_call_proof' => [
                    'bitmart' => 'fake_provider_boundary',
                    'hyperliquid' => 'http_client_guard',
                    'okx' => 'http_client_guard',
                ],
                'exchange_calls' => ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0],
                'schema_version' => 'fake-only-exchange-safety-v2',
                'source' => 'symfony_fake_provider_boundary_and_http_guards',
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
