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

    public function testRejectsAccountMatchingAgentAfterNormalization(): void
    {
        $lowercase = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';
        $mixedCase = '0xABCDEFabcdefABCDEFabcdefABCDEFabcdefABCD';
        $this->expectExceptionMessage('hyperliquid_signer_account_matches_agent');

        new HttpHyperliquidSignedActionClient(
            new MockHttpClient(),
            self::URI,
            self::TOKEN,
            $mixedCase,
            $lowercase,
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

    /** @return iterable<string, array{string, string, list<array<string, mixed>>, ?string}> */
    public static function validActionStatuses(): iterable
    {
        yield 'order resting' => ['order', 'accepted', [['kind' => 'resting', 'oid' => PHP_INT_MAX]], null];
        yield 'order filled without decimals' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1]], null];
        yield 'order filled with positive decimals' => ['order', 'accepted', [[
            'kind' => 'filled',
            'oid' => 42,
            'total_size' => '0.0001',
            'average_price' => '1.25e+4',
        ]], null];
        yield 'order error' => ['order', 'rejected', [['kind' => 'error']], 'exchange_status_error'];
        yield 'cancel success' => ['cancel', 'accepted', [['kind' => 'success']], null];
        yield 'cancel error' => ['cancel', 'rejected', [['kind' => 'error']], 'exchange_status_error'];
        yield 'cancel by cloid success' => ['cancelByCloid', 'accepted', [['kind' => 'success']], null];
        yield 'update leverage empty' => ['updateLeverage', 'accepted', [], null];
    }

    /** @param list<array<string, mixed>> $statuses */
    #[DataProvider('validActionStatuses')]
    public function testAcceptsExactTaskTwoStatusShapes(
        string $actionType,
        string $outcome,
        array $statuses,
        ?string $reason,
    ): void {
        $response = $this->response($outcome, $reason, 'corr-valid-status', $statuses);

        $result = $this->client(new MockHttpClient($response))->submit(
            ['type' => $actionType],
            1,
            'corr-valid-status',
        );

        self::assertSame($outcome, $result->outcome);
        self::assertSame($statuses, $result->statuses);
        self::assertSame($reason, $result->reason);
    }

    /** @return iterable<string, array{string, string, list<array<string, mixed>>}> */
    public static function malformedActionStatuses(): iterable
    {
        yield 'order rejects arbitrary data' => ['order', 'accepted', [['kind' => 'resting', 'oid' => 1, 'wallet_seed' => 'leak']]];
        yield 'order rejects cancel kind' => ['order', 'accepted', [['kind' => 'success']]];
        yield 'resting requires oid' => ['order', 'accepted', [['kind' => 'resting']]];
        yield 'resting rejects zero oid' => ['order', 'accepted', [['kind' => 'resting', 'oid' => 0]]];
        yield 'resting rejects negative oid' => ['order', 'accepted', [['kind' => 'resting', 'oid' => -1]]];
        yield 'resting rejects string oid' => ['order', 'accepted', [['kind' => 'resting', 'oid' => '1']]];
        yield 'resting rejects boolean oid' => ['order', 'accepted', [['kind' => 'resting', 'oid' => true]]];
        yield 'filled requires oid' => ['order', 'accepted', [['kind' => 'filled', 'total_size' => '1']]];
        yield 'filled rejects extra field' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1, 'fee' => '1']]];
        yield 'filled decimal must be string' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1, 'total_size' => 1]]];
        yield 'filled decimal must be positive' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1, 'total_size' => '0']]];
        yield 'filled decimal rejects negative' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1, 'average_price' => '-1']]];
        yield 'filled decimal rejects nan' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1, 'average_price' => 'NaN']]];
        yield 'filled decimal rejects infinity' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1, 'average_price' => 'Infinity']]];
        yield 'filled decimal rejects malformed exponent' => ['order', 'accepted', [['kind' => 'filled', 'oid' => 1, 'average_price' => '1e']]];
        yield 'error row rejects details' => ['order', 'rejected', [['kind' => 'error', 'message' => 'raw exchange text']]];
        yield 'cancel rejects order kind' => ['cancel', 'accepted', [['kind' => 'resting', 'oid' => 1]]];
        yield 'cancel success rejects extra field' => ['cancel', 'accepted', [['kind' => 'success', 'wallet_seed' => 'leak']]];
        yield 'cancel by cloid rejects filled' => ['cancelByCloid', 'accepted', [['kind' => 'filled', 'oid' => 1]]];
        yield 'update leverage accepted requires empty statuses' => ['updateLeverage', 'accepted', [['kind' => 'success']]];
    }

    /** @param list<array<string, mixed>> $statuses */
    #[DataProvider('malformedActionStatuses')]
    public function testRejectsMalformedOrActionMismatchedStatusShapes(
        string $actionType,
        string $outcome,
        array $statuses,
    ): void {
        $response = $this->response($outcome, null, 'corr-invalid-status', $statuses);

        $result = $this->client(new MockHttpClient($response))->submit(
            ['type' => $actionType],
            1,
            'corr-invalid-status',
        );

        self::assertSame('ambiguous', $result->outcome);
        self::assertSame([], $result->statuses);
        self::assertSame('signer_response_invalid', $result->reason);
    }

    public function testRejectsStableButUnknownSidecarReason(): void
    {
        $response = $this->response('rejected', 'exchange_rejected', 'corr-unknown-reason');

        $result = $this->client(new MockHttpClient($response))->submit(
            ['type' => 'order'],
            1,
            'corr-unknown-reason',
        );

        self::assertSame('ambiguous', $result->outcome);
        self::assertSame('signer_response_invalid', $result->reason);
    }

    /** @return iterable<string, array{string}> */
    public static function knownReasons(): iterable
    {
        foreach ([
            'broadcast_disabled',
            'agent_address_mismatch',
            'exchange_timeout',
            'exchange_transport_error',
            'exchange_response_too_large',
            'exchange_response_invalid_length',
            'exchange_response_invalid_json',
            'exchange_response_not_object',
            'exchange_redirect_rejected',
            'testnet_endpoint_required',
            'unknown_exchange_response',
            'exchange_error',
            'empty_exchange_statuses',
            'too_many_exchange_statuses',
            'invalid_exchange_statuses',
            'exchange_status_error',
            'mixed_exchange_statuses',
            'unknown_exchange_status',
            'unexpected_exchange_response_type',
            'invalid_exchange_response',
            'signer_auth_failed',
            'signer_response_invalid',
        ] as $reason) {
            yield $reason => [$reason];
        }
    }

    #[DataProvider('knownReasons')]
    public function testResultAcceptsOnlyKnownReasonVocabulary(string $reason): void
    {
        $result = new HyperliquidSignedActionResult('ambiguous', [], $reason, 'corr-result');

        self::assertSame($reason, $result->reason);
    }

    public function testResultAcceptsGenericNormalizedRowUnion(): void
    {
        $statuses = [
            ['kind' => 'resting', 'oid' => 1],
            ['kind' => 'filled', 'oid' => PHP_INT_MAX, 'total_size' => '.5', 'average_price' => '1E-8'],
            ['kind' => 'success'],
            ['kind' => 'error'],
        ];

        $result = new HyperliquidSignedActionResult('accepted', $statuses, null, 'corr-result');

        self::assertSame($statuses, $result->statuses);
    }

    /** @return iterable<string, array{string, array<mixed>, ?string, string, string}> */
    public static function invalidResults(): iterable
    {
        yield 'unknown outcome' => ['unknown', [], null, 'corr', 'hyperliquid_signed_action_result_outcome_invalid'];
        yield 'statuses must be list' => ['accepted', ['row' => ['kind' => 'success']], null, 'corr', 'hyperliquid_signed_action_result_statuses_invalid'];
        yield 'statuses limited to twenty' => ['accepted', array_fill(0, 21, ['kind' => 'success']), null, 'corr', 'hyperliquid_signed_action_result_statuses_invalid'];
        yield 'status row must be object-shaped array' => ['accepted', ['success'], null, 'corr', 'hyperliquid_signed_action_result_statuses_invalid'];
        yield 'unknown reason' => ['rejected', [], 'exchange_rejected', 'corr', 'hyperliquid_signed_action_result_reason_invalid'];
        yield 'blank correlation' => ['accepted', [], null, ' ', 'hyperliquid_signed_action_result_correlation_id_invalid'];
        yield 'long correlation' => ['accepted', [], null, str_repeat('x', 129), 'hyperliquid_signed_action_result_correlation_id_invalid'];
        yield 'arbitrary status key' => ['accepted', [['kind' => 'success', 'wallet_seed' => 'leak']], null, 'corr', 'hyperliquid_signed_action_result_statuses_invalid'];
        yield 'unknown status kind' => ['accepted', [['kind' => 'pending']], null, 'corr', 'hyperliquid_signed_action_result_statuses_invalid'];
        yield 'invalid oid' => ['accepted', [['kind' => 'resting', 'oid' => '1']], null, 'corr', 'hyperliquid_signed_action_result_statuses_invalid'];
        yield 'invalid decimal' => ['accepted', [['kind' => 'filled', 'oid' => 1, 'total_size' => '0']], null, 'corr', 'hyperliquid_signed_action_result_statuses_invalid'];
        yield 'oversized statuses' => ['accepted', [[
            'kind' => 'filled',
            'oid' => 1,
            'total_size' => str_repeat('1', 65_536),
        ]], null, 'corr', 'hyperliquid_signed_action_result_statuses_too_large'];
    }

    /** @param array<mixed> $statuses */
    #[DataProvider('invalidResults')]
    public function testResultRejectsImpossibleOrSensitiveState(
        string $outcome,
        array $statuses,
        ?string $reason,
        string $correlationId,
        string $error,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        new HyperliquidSignedActionResult($outcome, $statuses, $reason, $correlationId);
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
