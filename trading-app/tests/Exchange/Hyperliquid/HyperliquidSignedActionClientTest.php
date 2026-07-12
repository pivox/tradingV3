<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Hyperliquid;

use App\Exchange\Hyperliquid\HttpHyperliquidSignedActionClient;
use App\Exchange\Hyperliquid\HyperliquidSignedActionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpHyperliquidSignedActionClient::class)]
#[CoversClass(HyperliquidSignedActionResult::class)]
final class HyperliquidSignedActionClientTest extends TestCase
{
    private const URI = 'http://hyperliquid-signer:8098';
    private const TOKEN = 'sidecar-test-token';
    private const ACCOUNT = '0x1111111111111111111111111111111111111111';
    private const AGENT = '0x2222222222222222222222222222222222222222';

    /** @return iterable<string, array{string}> */
    public static function invalidUris(): iterable
    {
        foreach ([
            'https://hyperliquid-signer:8098',
            'http://hyperliquid-signer',
            'http://hyperliquid-signer:80',
            'http://hyperliquid-signer:8098/',
            'http://hyperliquid-signer:8098/v1',
            'http://hyperliquid-signer:8098?x=1',
            'http://hyperliquid-signer:8098#fragment',
            'http://user@hyperliquid-signer:8098',
            'http://hyperliquid-signer.evil:8098',
            'http://hyperliquid-signer:8098.evil',
            ' http://hyperliquid-signer:8098',
        ] as $uri) {
            yield $uri => [$uri];
        }
    }

    #[DataProvider('invalidUris')]
    public function testRejectsAnySignerEndpointExceptExactInternalUri(string $uri): void
    {
        $this->expectExceptionMessage('hyperliquid_signer_endpoint_not_allowed');

        new HttpHyperliquidSignedActionClient(
            new MockHttpClient(),
            $uri,
            self::TOKEN,
            self::ACCOUNT,
            self::AGENT,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidConstructorValues(): iterable
    {
        yield 'blank token' => ['', 'hyperliquid_signer_auth_token_required'];
        yield 'whitespace token' => ['  ', 'hyperliquid_signer_auth_token_required'];
    }

    #[DataProvider('invalidConstructorValues')]
    public function testRejectsBlankAuthenticationToken(string $token, string $error): void
    {
        $this->expectExceptionMessage($error);

        new HttpHyperliquidSignedActionClient(
            new MockHttpClient(),
            self::URI,
            $token,
            self::ACCOUNT,
            self::AGENT,
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidAddresses(): iterable
    {
        yield 'account is blank' => ['', self::AGENT, 'hyperliquid_signer_account_address_invalid'];
        yield 'account is private key length' => ['0x' . str_repeat('1', 64), self::AGENT, 'hyperliquid_signer_account_address_invalid'];
        yield 'agent has non-hex' => [self::ACCOUNT, '0x' . str_repeat('z', 40), 'hyperliquid_signer_agent_address_invalid'];
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidPublicAddresses(string $account, string $agent, string $error): void
    {
        $this->expectExceptionMessage($error);

        new HttpHyperliquidSignedActionClient(
            new MockHttpClient(),
            self::URI,
            self::TOKEN,
            $account,
            $agent,
        );
    }

    public function testSubmitsExactSidecarV1RequestWithHardenedHttpOptions(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = compact('method', 'url', 'options');

            return $this->response('accepted', correlationId: 'corr-1', statuses: [['kind' => 'resting', 'oid' => 42]]);
        });

        $result = $this->client($http)->submit(
            ['type' => 'order', 'orders' => [['a' => 0]]],
            1_700_000_000_001,
            'corr-1',
            1_700_000_030_000,
        );

        self::assertSame('accepted', $result->outcome);
        self::assertSame([['kind' => 'resting', 'oid' => 42]], $result->statuses);
        self::assertNull($result->reason);
        self::assertSame('corr-1', $result->correlationId);
        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame(self::URI . '/v1/exchange', $captured['url']);
        self::assertSame(5.0, $captured['options']['timeout']);
        self::assertSame(5.0, $captured['options']['max_duration']);
        self::assertSame(0, $captured['options']['max_redirects']);
        self::assertSame('*', $captured['options']['no_proxy']);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $captured['options']['headers']);
        self::assertSame([
            'schema_version' => '1',
            'environment' => 'testnet',
            'network' => 'testnet',
            'account_address' => self::ACCOUNT,
            'agent_address' => self::AGENT,
            'action' => ['type' => 'order', 'orders' => [['a' => 0]]],
            'nonce' => 1_700_000_000_001,
            'correlation_id' => 'corr-1',
            'expires_after' => 1_700_000_030_000,
        ], json_decode((string) $captured['options']['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::TOKEN, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('signature', get_object_vars($result));
    }

    public function testOmitsOptionalExpiryAndMapsExplicitRejection(): void
    {
        $body = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);

            return $this->response('rejected', 'exchange_error', 'corr-rejected');
        });

        $result = $this->client($http)->submit(['type' => 'cancel', 'cancels' => []], 1, 'corr-rejected');

        self::assertSame('rejected', $result->outcome);
        self::assertSame('exchange_error', $result->reason);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('expires_after', $body);
    }

    public function testAcceptsResponseWithOptionalReasonOmitted(): void
    {
        $response = new MockResponse(json_encode([
            'schema_version' => '1',
            'outcome' => 'accepted',
            'statuses' => [],
            'correlation_id' => 'corr-no-reason',
        ], JSON_THROW_ON_ERROR));

        $result = $this->client(new MockHttpClient($response))->submit(
            ['type' => 'updateLeverage'],
            1,
            'corr-no-reason',
        );

        self::assertSame('accepted', $result->outcome);
        self::assertNull($result->reason);
    }

    /** @return iterable<string, array{array<string, mixed>, int, string, ?int, string}> */
    public static function invalidSubmissions(): iterable
    {
        yield 'zero nonce' => [['type' => 'order'], 0, 'corr', null, 'hyperliquid_signer_nonce_invalid'];
        yield 'negative nonce' => [['type' => 'order'], -1, 'corr', null, 'hyperliquid_signer_nonce_invalid'];
        yield 'zero expiry' => [['type' => 'order'], 1, 'corr', 0, 'hyperliquid_signer_expires_after_invalid'];
        yield 'negative expiry' => [['type' => 'order'], 1, 'corr', -1, 'hyperliquid_signer_expires_after_invalid'];
        yield 'missing action type' => [[], 1, 'corr', null, 'hyperliquid_signer_action_not_allowed'];
        yield 'unknown action type' => [['type' => 'withdraw'], 1, 'corr', null, 'hyperliquid_signer_action_not_allowed'];
        yield 'blank correlation' => [['type' => 'order'], 1, ' ', null, 'hyperliquid_signer_correlation_id_invalid'];
    }

    /** @param array<string, mixed> $action */
    #[DataProvider('invalidSubmissions')]
    public function testRejectsInvalidSubmissionBeforeHttp(
        array $action,
        int $nonce,
        string $correlationId,
        ?int $expiresAfter,
        string $error,
    ): void {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });

        try {
            $this->client($http)->submit($action, $nonce, $correlationId, $expiresAfter);
            self::fail('Expected submission validation to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($error, $exception->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testMapsAuthFailureToRejectedWithoutLeakingToken(): void
    {
        $result = $this->client(new MockHttpClient(new MockResponse(
            '{"detail":"unauthorized","token":"server-leak"}',
            ['http_code' => 401],
        )))->submit(['type' => 'order'], 1, 'corr-auth');

        self::assertSame('rejected', $result->outcome);
        self::assertSame('signer_auth_failed', $result->reason);
        self::assertStringNotContainsString(self::TOKEN, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('server-leak', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function ambiguousResponses(): iterable
    {
        yield 'server failure' => [new MockResponse('{"detail":"token=server-secret"}', ['http_code' => 503])];
        yield 'rate limited' => [new MockResponse('{"detail":"busy"}', ['http_code' => 429])];
        yield 'timeout' => [new MockResponse('', ['error' => 'Operation timed out with token=transport-secret'])];
        yield 'wrong schema' => [new MockResponse('{"schema_version":"2","outcome":"accepted","statuses":[],"reason":null,"correlation_id":"corr-ambiguous"}')];
        yield 'unknown outcome' => [new MockResponse('{"schema_version":"1","outcome":"maybe","statuses":[],"reason":null,"correlation_id":"corr-ambiguous"}')];
        yield 'non-object json' => [new MockResponse('[]')];
        yield 'unexpected field' => [new MockResponse('{"schema_version":"1","outcome":"accepted","statuses":[],"reason":null,"correlation_id":"corr-ambiguous","raw_response":{}}')];
        yield 'correlation mismatch' => [new MockResponse('{"schema_version":"1","outcome":"accepted","statuses":[],"reason":null,"correlation_id":"other"}')];
        yield 'too many statuses' => [new MockResponse(json_encode([
            'schema_version' => '1',
            'outcome' => 'accepted',
            'statuses' => array_fill(0, 21, ['kind' => 'resting']),
            'reason' => null,
            'correlation_id' => 'corr-ambiguous',
        ], JSON_THROW_ON_ERROR))];
        yield 'sensitive nested status' => [new MockResponse('{"schema_version":"1","outcome":"accepted","statuses":[{"nested":{"Authorization":"secret"}}],"reason":null,"correlation_id":"corr-ambiguous"}')];
        yield 'derived signing status key' => [new MockResponse('{"schema_version":"1","outcome":"accepted","statuses":[{"signed_payload":"secret"}],"reason":null,"correlation_id":"corr-ambiguous"}')];
        yield 'unstable reason' => [new MockResponse('{"schema_version":"1","outcome":"rejected","statuses":[],"reason":"API token bad","correlation_id":"corr-ambiguous"}')];
        yield 'oversize' => [new MockResponse(str_repeat('x', 65_537))];
    }

    #[DataProvider('ambiguousResponses')]
    public function testMapsNetworkAndUnknownResponsesToAmbiguousWithoutRetry(MockResponse $response): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests, $response): MockResponse {
            ++$requests;

            return $response;
        });

        $result = $this->client($http)->submit(['type' => 'order'], 1, 'corr-ambiguous');

        self::assertSame('ambiguous', $result->outcome);
        self::assertSame([], $result->statuses);
        self::assertSame('signer_response_invalid', $result->reason);
        self::assertSame('corr-ambiguous', $result->correlationId);
        self::assertSame(1, $requests);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN, $encoded);
        self::assertStringNotContainsString('transport-secret', $encoded);
    }

    public function testHealthUsesExactAuthenticatedContract(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = compact('method', 'url', 'options');

            return new MockResponse(json_encode([
                'schema_version' => '1',
                'ready' => true,
                'environment' => 'testnet',
                'agent_address' => self::AGENT,
                'broadcast_enabled' => true,
            ], JSON_THROW_ON_ERROR));
        });

        self::assertTrue($this->client($http)->health());
        self::assertIsArray($captured);
        self::assertSame('GET', $captured['method']);
        self::assertSame(self::URI . '/v1/health', $captured['url']);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $captured['options']['headers']);
        self::assertSame(5.0, $captured['options']['timeout']);
        self::assertSame(5.0, $captured['options']['max_duration']);
        self::assertSame(0, $captured['options']['max_redirects']);
        self::assertSame('*', $captured['options']['no_proxy']);
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unhealthyResponses(): iterable
    {
        $valid = [
            'schema_version' => '1',
            'ready' => true,
            'environment' => 'testnet',
            'agent_address' => self::AGENT,
            'broadcast_enabled' => true,
        ];
        foreach ([
            'schema' => ['schema_version' => '2'],
            'ready' => ['ready' => false],
            'environment' => ['environment' => 'mainnet'],
            'agent' => ['agent_address' => self::ACCOUNT],
            'broadcast' => ['broadcast_enabled' => false],
            'extra field' => ['detail' => 'unexpected'],
        ] as $name => $override) {
            yield $name => [new MockResponse(json_encode(array_replace($valid, $override), JSON_THROW_ON_ERROR))];
        }
        yield 'non-200' => [new MockResponse('{}', ['http_code' => 503])];
        yield 'timeout' => [new MockResponse('', ['error' => 'timeout token=secret'])];
        yield 'oversize' => [new MockResponse(str_repeat('x', 65_537))];
    }

    #[DataProvider('unhealthyResponses')]
    public function testHealthReturnsFalseForEveryMismatchOrError(MockResponse $response): void
    {
        self::assertFalse($this->client(new MockHttpClient($response))->health());
    }

    private function client(MockHttpClient $http): HttpHyperliquidSignedActionClient
    {
        return new HttpHyperliquidSignedActionClient(
            $http,
            self::URI,
            self::TOKEN,
            self::ACCOUNT,
            self::AGENT,
        );
    }

    /** @param list<array<string, mixed>> $statuses */
    private function response(
        string $outcome,
        ?string $reason = null,
        string $correlationId = 'corr-1',
        array $statuses = [],
    ): MockResponse {
        return new MockResponse(json_encode([
            'schema_version' => '1',
            'outcome' => $outcome,
            'statuses' => $statuses,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ], JSON_THROW_ON_ERROR));
    }
}
