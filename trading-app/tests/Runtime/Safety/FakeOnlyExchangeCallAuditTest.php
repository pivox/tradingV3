<?php

declare(strict_types=1);

namespace App\Tests\Runtime\Safety;

use App\Runtime\Safety\ExchangeCallGuardHttpClient;
use App\Runtime\Safety\FakeOnlyExchangeCallAudit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FakeOnlyExchangeCallAudit::class)]
#[CoversClass(ExchangeCallGuardHttpClient::class)]
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
        $guard = new ExchangeCallGuardHttpClient($inner, $audit, 'bitmart');
        $audit->begin();

        try {
            $guard->request('POST', '/contract/private/submit-order');
            self::fail('The Fake-only guard must block exchange HTTP before delegation.');
        } catch (TransportException $exception) {
            self::assertSame('fake_only_exchange_call_blocked:bitmart', $exception->getMessage());
        }

        self::assertSame(0, $delegatedCalls);
        self::assertSame(
            [
                'ambiguous_calls' => 0,
                'complete' => true,
                'exchange_calls' => ['bitmart' => 1, 'hyperliquid' => 0, 'okx' => 0],
                'schema_version' => 'fake-only-exchange-safety-v1',
                'source' => 'symfony_http_client_guard',
            ],
            $audit->finish(),
        );
    }
}
