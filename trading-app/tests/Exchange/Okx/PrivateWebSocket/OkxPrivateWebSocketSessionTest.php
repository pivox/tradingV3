<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Common\Enum\Exchange;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderCreated;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateRestSnapshot;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityPolicy;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketSession;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketSessionResult;
use App\Exchange\Readiness\ExchangePrivateObservabilityDecision;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;

#[CoversClass(OkxPrivateWebSocketSession::class)]
#[CoversClass(OkxPrivateWebSocketSessionResult::class)]
final class OkxPrivateWebSocketSessionTest extends TestCase
{
    private const string SECRET = 'private-login-secret';
    private const string RAW_SECRET = 'private-message-secret';

    private OkxPrivateWebSocketSession $session;

    protected function setUp(): void
    {
        $this->session = $this->newSession();
    }

    private function newSession(): OkxPrivateWebSocketSession
    {
        return new OkxPrivateWebSocketSession(
            new OkxExchangeEventNormalizer(new OkxInstrumentResolver(), $this->fixedClock()),
            self::at(0),
        );
    }

    public function testConnectionReturnsLoginCommandWithoutRetainingCredentials(): void
    {
        $loginArgs = [[
            'apiKey' => 'demo-key',
            'passphrase' => 'demo-passphrase',
            'timestamp' => '1783936800',
            'sign' => self::SECRET,
        ]];

        $result = $this->session->onConnected($loginArgs, self::at(1));

        self::assertInstanceOf(OkxPrivateWebSocketSessionResult::class, $result);
        self::assertSame([['op' => 'login', 'args' => $loginArgs]], $result->outgoingCommands);
        self::assertSame([], $result->normalizedEvents);
        $status = $this->session->status();
        self::assertTrue($status->connected);
        self::assertFalse($status->authenticated);
        self::assertFalse($status->reconnecting);
        self::assertEquals(self::at(1), $status->connectedAt);
        self::assertEquals(self::at(1), $status->lastHeartbeatAt);
        self::assertEquals(self::at(1), $status->observedAt);

        $serialized = serialize($this->session->status());
        self::assertStringNotContainsString(self::SECRET, $serialized);
        self::assertStringNotContainsString('demo-passphrase', $serialized);
        self::assertStringNotContainsString('demo-key', $serialized);
        self::assertFalse((new ReflectionClass($this->session))->hasProperty('loginArgs'));
    }

    public function testSuccessfulLoginReturnsExactReadOnlySubscriptions(): void
    {
        $this->connect();

        $result = $this->session->onMessage(['event' => 'login', 'code' => '0'], self::at(2));

        self::assertSame([[
            'op' => 'subscribe',
            'args' => [
                ['channel' => 'orders', 'instType' => 'SWAP'],
                ['channel' => 'positions', 'instType' => 'SWAP'],
                ['channel' => 'balance_and_position'],
                ['channel' => 'fills', 'instType' => 'SWAP'],
            ],
        ]], $result->outgoingCommands);
        self::assertSame([], $result->normalizedEvents);
        self::assertTrue($this->session->status()->authenticated);
    }

    public function testAllRequiredAcknowledgementsAreNeededForStreamReadiness(): void
    {
        $this->authenticate();

        $this->acknowledge('orders', 3);
        self::assertTrue($this->session->status()->ordersStreamReady);
        self::assertFalse($this->session->status()->positionsStreamReady);

        $this->acknowledge('positions', 4);
        self::assertFalse($this->session->status()->positionsStreamReady);

        $this->acknowledge('balance_and_position', 5);
        self::assertTrue($this->session->status()->positionsStreamReady);
        self::assertFalse($this->session->status()->fillsStreamReady);

        $this->acknowledge('fills', 6);
        $status = $this->session->status();
        self::assertTrue($status->fillsStreamReady);
        self::assertSame('fills_channel', $status->fillsSource);
        self::assertSame([], $status->blockingErrors);
        self::assertSame([], $status->warnings);
        self::assertEquals(self::at(6), $status->lastHeartbeatAt);
    }

    public function testVipFillRejectionUsesOrdersPlusRestFallback(): void
    {
        $this->authenticate();

        $result = $this->session->onMessage([
            'event' => 'error',
            'code' => '64003',
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
            'msg' => self::RAW_SECRET,
        ], self::at(3));

        self::assertSame([], $result->outgoingCommands);
        self::assertSame([], $result->normalizedEvents);
        $status = $this->session->status();
        self::assertTrue($status->fillsStreamReady);
        self::assertSame('orders_plus_rest', $status->fillsSource);
        self::assertSame(['okx_fills_channel_vip_unavailable'], $status->warnings);
        self::assertSame([], $status->blockingErrors);
        self::assertEquals(self::at(3), $status->lastHeartbeatAt);
        self::assertStringNotContainsString(self::RAW_SECRET, serialize($this->session->status()));
    }

    public function testUnexpectedFillErrorIsBlocking(): void
    {
        $this->authenticate();

        $this->session->onMessage([
            'event' => 'error',
            'code' => '60012',
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
        ], self::at(3));

        $status = $this->session->status();
        self::assertFalse($status->fillsStreamReady);
        self::assertNull($status->fillsSource);
        self::assertSame(['okx_private_ws_subscription_failed'], $status->blockingErrors);
    }

    public function testUnexpectedOtherSubscriptionErrorIsBlocking(): void
    {
        $this->authenticate();

        $this->session->onMessage([
            'event' => 'error',
            'code' => '60012',
            'arg' => ['channel' => 'positions', 'instType' => 'SWAP'],
        ], self::at(3));

        self::assertSame(
            ['okx_private_ws_subscription_failed'],
            $this->session->status()->blockingErrors,
        );
    }

    public function testLoginFailureInvalidatesAuthenticationWithoutRetainingPayload(): void
    {
        $this->connect();

        $this->session->onMessage([
            'event' => 'login',
            'code' => '60009',
            'msg' => self::RAW_SECRET,
        ], self::at(2));

        $status = $this->session->status();
        self::assertFalse($status->authenticated);
        self::assertSame(['okx_private_ws_authentication_failed'], $status->blockingErrors);
        self::assertStringNotContainsString(self::RAW_SECRET, serialize($this->session->status()));
    }

    public function testCompleteAndIncompleteSnapshotsUpdateReconciliationState(): void
    {
        $this->authenticate();

        $this->session->applySnapshot(self::snapshot(true), self::at(1));
        $complete = $this->session->status();
        self::assertTrue($complete->initialSnapshotLoaded);
        self::assertTrue($complete->reconciliationFresh);
        self::assertSame([], $complete->blockingErrors);

        $this->session->applySnapshot(self::snapshot(false), self::at(2));
        $incomplete = $this->session->status();
        self::assertFalse($incomplete->initialSnapshotLoaded);
        self::assertFalse($incomplete->reconciliationFresh);
        self::assertSame(['okx_private_rest_snapshot_failed'], $incomplete->blockingErrors);

        $this->session->applySnapshot(self::snapshot(true), self::at(3));
        self::assertSame([], $this->session->status()->blockingErrors);
    }

    public function testSnapshotDoesNotRefreshHeartbeatAndStalePolicyRejectsStatus(): void
    {
        $this->makeReady();

        $this->session->applySnapshot(self::snapshot(true), self::at(20));

        $status = $this->session->status();
        self::assertEquals(self::at(6), $status->lastHeartbeatAt);
        self::assertNull($status->lastEventAt);
        self::assertEquals(self::at(20), $status->observedAt);
        $decision = self::policyDecision($status, 20);
        self::assertFalse($decision->allowed);
        self::assertContains('okx_private_observability_heartbeat_stale', $decision->blockingErrors);
    }

    public function testHeartbeatRefreshesReadyStatusWithoutChangingLastEvent(): void
    {
        $this->makeReady();
        $lastEventAt = $this->session->status()->lastEventAt;

        self::assertTrue(method_exists($this->session, 'onHeartbeat'));
        $this->session->onHeartbeat(self::at(20));

        $status = $this->session->status();
        self::assertEquals(self::at(20), $status->lastHeartbeatAt);
        self::assertEquals(self::at(20), $status->observedAt);
        self::assertSame($lastEventAt, $status->lastEventAt);
        self::assertTrue(self::policyDecision($status, 20)->allowed);
    }

    public function testHeartbeatNeverChangesLastEventTimestamp(): void
    {
        $this->authenticate();
        $this->session->onMessage(self::orderMessage(), self::at(3));

        self::assertTrue(method_exists($this->session, 'onHeartbeat'));
        $this->session->onHeartbeat(self::at(20));

        self::assertEquals(self::at(3), $this->session->status()->lastEventAt);
        self::assertEquals(self::at(20), $this->session->status()->lastHeartbeatAt);
    }

    public function testDisconnectedHeartbeatIsIgnoredWithoutRevalidatingState(): void
    {
        $this->makeReady();
        $this->session->onDisconnected(self::at(8));
        $before = $this->session->status()->toArray();

        self::assertTrue(method_exists($this->session, 'onHeartbeat'));
        $this->session->onHeartbeat(self::at(20));

        self::assertSame($before, $this->session->status()->toArray());
        self::assertFalse($this->session->status()->connected);
        self::assertFalse($this->session->status()->reconciliationFresh);
    }

    public function testUsesRealNormalizerForOrdersFillsAndPositionsWithoutRetainingMessages(): void
    {
        $this->authenticate();

        $orderResult = $this->session->onMessage(self::orderMessage(), self::at(3));
        $fillResult = $this->session->onMessage(self::fillMessage(), self::at(4));
        $positionResult = $this->session->onMessage(self::positionMessage(), self::at(5));

        self::assertCount(1, $orderResult->normalizedEvents);
        self::assertInstanceOf(ExchangeOrderCreated::class, $orderResult->normalizedEvents[0]);
        self::assertCount(1, $fillResult->normalizedEvents);
        self::assertInstanceOf(ExchangeFillReceived::class, $fillResult->normalizedEvents[0]);
        self::assertCount(1, $positionResult->normalizedEvents);
        self::assertInstanceOf(ExchangePositionUpdated::class, $positionResult->normalizedEvents[0]);
        self::assertEquals(self::at(5), $this->session->status()->lastEventAt);
        self::assertEquals(self::at(5), $this->session->status()->lastHeartbeatAt);
        self::assertStringNotContainsString(self::RAW_SECRET, serialize($this->session->status()));
    }

    public function testBalanceAndPositionDataOnlyUpdatesTimestamps(): void
    {
        $this->authenticate();

        $result = $this->session->onMessage([
            'arg' => ['channel' => 'balance_and_position'],
            'data' => [['pTime' => '1783936800000', 'secret' => self::RAW_SECRET]],
        ], self::at(3));

        self::assertSame([], $result->normalizedEvents);
        self::assertEquals(self::at(3), $this->session->status()->lastEventAt);
        self::assertEquals(self::at(3), $this->session->status()->lastHeartbeatAt);
        self::assertStringNotContainsString(self::RAW_SECRET, serialize($this->session->status()));
    }

    public function testMalformedKnownMessageIsBlockingAndThrowsOnlyStableCode(): void
    {
        $this->authenticate();

        try {
            $this->session->onMessage([
                'event' => 'subscribe',
                'arg' => ['secret' => self::RAW_SECRET],
            ], self::at(3));
            self::fail('Expected malformed message rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('okx_private_ws_message_invalid', $exception->getMessage());
            self::assertStringNotContainsString(self::RAW_SECRET, $exception->getMessage());
        }

        self::assertSame(
            ['okx_private_ws_message_invalid'],
            $this->session->status()->blockingErrors,
        );
        self::assertStringNotContainsString(self::RAW_SECRET, serialize($this->session->status()));
    }

    public function testUnknownMessageIsIgnored(): void
    {
        $this->authenticate();
        $before = $this->session->status()->toArray();

        $result = $this->session->onMessage([
            'event' => 'notice',
            'payload' => self::RAW_SECRET,
        ], self::at(3));

        self::assertSame([], $result->outgoingCommands);
        self::assertSame([], $result->normalizedEvents);
        self::assertSame($before, $this->session->status()->toArray());
    }

    public function testDisconnectAndReconnectInvalidateEveryReadinessPrerequisite(): void
    {
        $this->makeReady();

        $this->session->onDisconnected(self::at(8));

        $disconnected = $this->session->status();
        self::assertFalse($disconnected->connected);
        self::assertFalse($disconnected->authenticated);
        self::assertFalse($disconnected->ordersStreamReady);
        self::assertFalse($disconnected->fillsStreamReady);
        self::assertNull($disconnected->fillsSource);
        self::assertFalse($disconnected->positionsStreamReady);
        self::assertFalse($disconnected->initialSnapshotLoaded);
        self::assertFalse($disconnected->reconciliationFresh);
        self::assertTrue($disconnected->reconnecting);
        self::assertNull($disconnected->connectedAt);
        self::assertNull($disconnected->lastEventAt);
        self::assertSame(['okx_private_ws_connection_failed'], $disconnected->blockingErrors);

        $this->connect(9);
        $this->authenticate(10);
        $this->acknowledge('orders', 11);
        $this->acknowledge('positions', 12);
        $this->acknowledge('fills', 13);
        $this->session->applySnapshot(self::snapshot(true), self::at(14));

        self::assertFalse($this->session->status()->positionsStreamReady);
        $this->acknowledge('balance_and_position', 15);
        self::assertTrue($this->session->status()->positionsStreamReady);
    }

    public function testResetInvalidatesTheWholeSession(): void
    {
        $this->makeReady();

        $this->session->reset(self::at(8));

        $status = $this->session->status();
        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
        self::assertFalse($status->ordersStreamReady);
        self::assertFalse($status->fillsStreamReady);
        self::assertFalse($status->positionsStreamReady);
        self::assertFalse($status->initialSnapshotLoaded);
        self::assertFalse($status->reconciliationFresh);
        self::assertTrue($status->reconnecting);
        self::assertSame(['okx_private_ws_connection_failed'], $status->blockingErrors);
    }

    public function testEveryKnownTransitionIsInvalidBeforeConnection(): void
    {
        $transitions = [
            'login ack' => fn () => $this->session->onMessage(['event' => 'login', 'code' => '0'], self::at(1)),
            'subscription ack' => fn () => $this->session->onMessage(self::subscriptionMessage('orders'), self::at(2)),
            'fills fallback' => fn () => $this->session->onMessage(self::subscriptionError('fills', '64003'), self::at(3)),
            'data' => fn () => $this->session->onMessage(self::orderMessage(), self::at(4)),
            'snapshot' => fn () => $this->session->applySnapshot(self::snapshot(true), self::at(5)),
        ];

        foreach ($transitions as $name => $transition) {
            try {
                $transition();
                self::fail(sprintf('Expected %s to be rejected.', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame('okx_private_ws_message_invalid', $exception->getMessage());
            }
        }

        $status = $this->session->status();
        self::assertFalse($status->authenticated);
        self::assertFalse($status->ordersStreamReady);
        self::assertFalse($status->fillsStreamReady);
        self::assertFalse($status->positionsStreamReady);
        self::assertFalse($status->initialSnapshotLoaded);
        self::assertSame(['okx_private_ws_message_invalid'], $status->blockingErrors);
    }

    public function testLoginAcknowledgementAfterResetIsInvalid(): void
    {
        $this->connect();
        $this->session->reset(self::at(2));

        $this->assertInvalidTransition(
            fn () => $this->session->onMessage(['event' => 'login', 'code' => '0'], self::at(3)),
        );

        self::assertFalse($this->session->status()->authenticated);
        self::assertSame([
            'okx_private_ws_connection_failed',
            'okx_private_ws_message_invalid',
        ], $this->session->status()->blockingErrors);
    }

    public function testLoginAcknowledgementAfterDisconnectIsInvalid(): void
    {
        $this->connect();
        $this->session->onDisconnected(self::at(2));

        $this->assertInvalidTransition(
            fn () => $this->session->onMessage(['event' => 'login', 'code' => '0'], self::at(3)),
        );

        self::assertFalse($this->session->status()->authenticated);
    }

    public function testSubscriptionDataAndSnapshotAreInvalidBeforeAuthentication(): void
    {
        $transitions = [
            fn () => $this->session->onMessage(self::subscriptionMessage('orders'), self::at(2)),
            fn () => $this->session->onMessage(self::subscriptionError('fills', '64003'), self::at(3)),
            fn () => $this->session->onMessage(self::orderMessage(), self::at(4)),
            fn () => $this->session->applySnapshot(self::snapshot(true), self::at(5)),
        ];

        foreach ($transitions as $transition) {
            $this->connect();
            $this->assertInvalidTransition($transition);
        }

        $status = $this->session->status();
        self::assertFalse($status->authenticated);
        self::assertFalse($status->ordersStreamReady);
        self::assertFalse($status->fillsStreamReady);
        self::assertFalse($status->initialSnapshotLoaded);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function invalidSubscriptionArguments(): iterable
    {
        yield 'orders SPOT' => ['orders', ['channel' => 'orders', 'instType' => 'SPOT']];
        yield 'positions SPOT' => ['positions', ['channel' => 'positions', 'instType' => 'SPOT']];
        yield 'fills SPOT' => ['fills', ['channel' => 'fills', 'instType' => 'SPOT']];
        yield 'balance with instType' => [
            'balance_and_position',
            ['channel' => 'balance_and_position', 'instType' => 'SWAP'],
        ];
        yield 'orders missing instType' => ['orders', ['channel' => 'orders']];
        yield 'orders with extra arg' => [
            'orders',
            ['channel' => 'orders', 'instType' => 'SWAP', 'extra' => 'value'],
        ];
        yield 'missing channel' => ['orders', ['instType' => 'SWAP']];
    }

    /** @param array<string, string> $arg */
    #[DataProvider('invalidSubscriptionArguments')]
    public function testSubscriptionAcknowledgementRequiresExactArguments(string $channel, array $arg): void
    {
        $this->authenticate();

        $this->assertInvalidTransition(fn () => $this->session->onMessage([
            'event' => 'subscribe',
            'code' => '0',
            'arg' => $arg,
        ], self::at(3)));

        $status = $this->session->status();
        self::assertFalse(match ($channel) {
            'orders' => $status->ordersStreamReady,
            'fills' => $status->fillsStreamReady,
            default => $status->positionsStreamReady,
        });
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidFillsFallbackArguments(): iterable
    {
        yield 'SPOT' => [['channel' => 'fills', 'instType' => 'SPOT']];
        yield 'missing instType' => [['channel' => 'fills']];
        yield 'extra arg' => [['channel' => 'fills', 'instType' => 'SWAP', 'extra' => 'value']];
    }

    /** @param array<string, string> $arg */
    #[DataProvider('invalidFillsFallbackArguments')]
    public function testFillsFallbackRequiresExactSwapArguments(array $arg): void
    {
        $this->authenticate();

        $this->assertInvalidTransition(fn () => $this->session->onMessage([
            'event' => 'error',
            'code' => '64003',
            'arg' => $arg,
        ], self::at(3)));

        self::assertFalse($this->session->status()->fillsStreamReady);
        self::assertNull($this->session->status()->fillsSource);
    }

    public function testSuccessfulAckRecoversOnlyItsFailedSubscription(): void
    {
        $this->authenticate();
        $this->session->applySnapshot(self::snapshot(false), self::at(3));
        $this->session->onMessage(self::subscriptionError('orders'), self::at(4));
        $this->session->onMessage(self::subscriptionError('positions'), self::at(5));

        $this->acknowledge('orders', 6);
        self::assertContains('okx_private_ws_subscription_failed', $this->session->status()->blockingErrors);

        $this->acknowledge('positions', 7);
        self::assertNotContains('okx_private_ws_subscription_failed', $this->session->status()->blockingErrors);
        self::assertContains('okx_private_rest_snapshot_failed', $this->session->status()->blockingErrors);
    }

    public function testFillsFallbackRecoversAPreviousFillsFailure(): void
    {
        $this->authenticate();
        $this->session->onMessage(self::subscriptionError('fills'), self::at(3));

        $this->session->onMessage(self::subscriptionError('fills', '64003'), self::at(4));

        self::assertNotContains('okx_private_ws_subscription_failed', $this->session->status()->blockingErrors);
        self::assertTrue($this->session->status()->fillsStreamReady);
        self::assertSame('orders_plus_rest', $this->session->status()->fillsSource);
    }

    public function testRepeatedSubscriptionErrorsKeepOneBlockingCode(): void
    {
        $this->authenticate();

        $this->session->onMessage(self::subscriptionError('orders'), self::at(3));
        $this->session->onMessage(self::subscriptionError('orders'), self::at(4));
        $this->session->onMessage(self::subscriptionError('positions'), self::at(5));

        self::assertSame(
            ['okx_private_ws_subscription_failed'],
            $this->session->status()->blockingErrors,
        );
    }

    public function testReconnectRequiresACompleteNewProtocolSequence(): void
    {
        $this->makeReady();

        $this->connect(8);
        $this->assertInvalidTransition(
            fn () => $this->session->onMessage(self::subscriptionMessage('orders'), self::at(9)),
        );
        $this->authenticate(10);

        $status = $this->session->status();
        self::assertFalse($status->ordersStreamReady);
        self::assertFalse($status->fillsStreamReady);
        self::assertFalse($status->positionsStreamReady);
        self::assertFalse($status->initialSnapshotLoaded);
        self::assertFalse($status->reconciliationFresh);
    }

    public function testSessionOnlyStoresTypedStateAndNeverRawProtocolPayloads(): void
    {
        $this->authenticate();
        $this->session->onMessage(self::orderMessage(), self::at(3));

        $reflection = new ReflectionClass($this->session);
        self::assertSame(
            [
                'normalizer',
                'status',
                'positionsAcknowledged',
                'balanceAndPositionAcknowledged',
                'loginExpected',
                'failedSubscriptions',
            ],
            array_map(static fn (\ReflectionProperty $property): string => $property->getName(), $reflection->getProperties()),
        );
        self::assertStringNotContainsString(self::RAW_SECRET, serialize($this->session->status()));
    }

    private function connect(int $second = 1): void
    {
        $this->session->onConnected([['sign' => self::SECRET]], self::at($second));
    }

    private function authenticate(int $second = 2): void
    {
        if (!$this->session->status()->connected) {
            $this->connect($second - 1);
        }
        $this->session->onMessage(['event' => 'login', 'code' => '0'], self::at($second));
    }

    private function acknowledge(string $channel, int $second): void
    {
        $this->session->onMessage(self::subscriptionMessage($channel), self::at($second));
    }

    /** @return array<string, mixed> */
    private static function subscriptionMessage(string $channel): array
    {
        return [
            'event' => 'subscribe',
            'code' => '0',
            'arg' => self::subscriptionArg($channel),
        ];
    }

    /** @return array<string, mixed> */
    private static function subscriptionError(string $channel, string $code = '60012'): array
    {
        return [
            'event' => 'error',
            'code' => $code,
            'arg' => self::subscriptionArg($channel),
        ];
    }

    /** @return array<string, string> */
    private static function subscriptionArg(string $channel): array
    {
        return 'balance_and_position' === $channel
            ? ['channel' => $channel]
            : ['channel' => $channel, 'instType' => 'SWAP'];
    }

    /** @param callable(): mixed $transition */
    private function assertInvalidTransition(callable $transition): void
    {
        try {
            $transition();
            self::fail('Expected invalid protocol transition.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('okx_private_ws_message_invalid', $exception->getMessage());
        }

        self::assertContains('okx_private_ws_message_invalid', $this->session->status()->blockingErrors);
    }

    private function makeReady(): void
    {
        $this->authenticate();
        $this->acknowledge('orders', 3);
        $this->acknowledge('positions', 4);
        $this->acknowledge('balance_and_position', 5);
        $this->acknowledge('fills', 6);
        $this->session->applySnapshot(self::snapshot(true), self::at(7));
    }

    private static function snapshot(bool $complete): OkxPrivateRestSnapshot
    {
        return new OkxPrivateRestSnapshot(
            observedAt: self::at(0),
            accountReadable: $complete,
            positions: [],
            openOrders: [],
            fills: [],
            complete: $complete,
            blockingErrors: $complete ? [] : ['okx_private_rest_account_snapshot_failed'],
        );
    }

    /** @return array<string, mixed> */
    private static function orderMessage(): array
    {
        return [
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '0',
                'cTime' => '1783936800000',
                'clOrdId' => 'OKXENTRY',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'order-1',
                'ordType' => 'limit',
                'posSide' => 'long',
                'px' => '25000',
                'reduceOnly' => 'false',
                'side' => 'buy',
                'state' => 'live',
                'sz' => '1',
                'uTime' => '1783936800000',
                'secret' => self::RAW_SECRET,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function fillMessage(): array
    {
        return [
            'arg' => ['channel' => 'fills', 'instType' => 'SWAP'],
            'data' => [[
                'fillPx' => '25000.5',
                'fillSz' => '0.1',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'ordId' => 'order-1',
                'posSide' => 'long',
                'side' => 'buy',
                'tradeId' => 'trade-1',
                'ts' => '1783936800000',
                'secret' => self::RAW_SECRET,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function positionMessage(): array
    {
        return [
            'arg' => ['channel' => 'positions', 'instType' => 'SWAP'],
            'data' => [[
                'avgPx' => '25000',
                'instId' => 'BTC-USDT-SWAP',
                'instType' => 'SWAP',
                'lever' => '3',
                'markPx' => '25100',
                'margin' => '100',
                'pos' => '0.5',
                'posSide' => 'long',
                'uTime' => '1783936800000',
                'upl' => '50',
                'secret' => self::RAW_SECRET,
            ]],
        ];
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-07-13T10:00:00+00:00');
            }
        };
    }

    private static function at(int $second): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-07-13T10:00:%02d+00:00', $second));
    }

    private static function policyDecision(
        \App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityStatus $status,
        int $second,
    ): ExchangePrivateObservabilityDecision {
        $commonStatus = (new OkxPrivateWebSocketObservabilityPolicy())->evaluate(
            $status,
            self::at($second),
        );

        return (new ExchangePrivateObservabilityPolicy())->evaluate(
            $commonStatus,
            dryRun: false,
            expectedExchange: Exchange::OKX,
            expectedEnvironment: 'demo',
        );
    }
}
