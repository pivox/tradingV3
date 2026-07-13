<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\PrivateWebSocket\ExtRedisOkxPrivateWebSocketClient;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityStatus;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketRedisFactory;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketRedisClientInterface;
use App\Exchange\Okx\PrivateWebSocket\RedisOkxPrivateWebSocketStatusStore;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

#[CoversClass(OkxPrivateWebSocketObservabilityStatus::class)]
#[CoversClass(RedisOkxPrivateWebSocketStatusStore::class)]
#[CoversClass(ExtRedisOkxPrivateWebSocketClient::class)]
#[CoversClass(OkxPrivateWebSocketRedisFactory::class)]
final class RedisOkxPrivateWebSocketStatusStoreTest extends TestCase
{
    private const KEY = 'tradingv3:okx:demo:private-observability:v1';
    private const TEST_REDIS_DATABASE = 15;

    public function testStatusDtoIsReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(OkxPrivateWebSocketObservabilityStatus::class))->isReadOnly());
    }

    #[RequiresPhpExtension('redis')]
    public function testRedisFactoryUsesExplicitConnectAndReadTimeouts(): void
    {
        $redis = new InspectableRedisConnection(true);
        $factory = new OkxPrivateWebSocketRedisFactory(static fn (): Redis => $redis);

        self::assertSame($redis, $factory->connect('redis', 6379, 1.0, 1.0));
        self::assertSame(['redis', 6379, 1.0, null, 0, 1.0], $redis->connectArguments);
    }

    #[RequiresPhpExtension('redis')]
    public function testRedisFactoryRejectsAFailedConnectionWithAStableException(): void
    {
        $redis = new InspectableRedisConnection(false);
        $factory = new OkxPrivateWebSocketRedisFactory(static fn (): Redis => $redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('okx_private_ws_redis_connect_failed');

        $factory->connect('redis', 6379, 1.0, 1.0);
    }

    public function testRoundTripsTheCompleteClosedSchema(): void
    {
        $status = self::healthyStatus();

        self::assertEquals($status, OkxPrivateWebSocketObservabilityStatus::fromArray($status->toArray()));
        self::assertSame([
            'schema_version',
            'exchange',
            'environment',
            'endpoint_id',
            'connected',
            'authenticated',
            'orders_stream_ready',
            'fills_stream_ready',
            'fills_source',
            'positions_stream_ready',
            'initial_snapshot_loaded',
            'reconciliation_fresh',
            'reconnecting',
            'connected_at',
            'last_heartbeat_at',
            'last_event_at',
            'observed_at',
            'blocking_errors',
            'warnings',
        ], array_keys($status->toArray()));
        self::assertSame('2026-07-13T10:00:00+00:00', $status->toArray()['connected_at']);
    }

    public function testConnectingCreatesANonReadyRedactedStatus(): void
    {
        $status = OkxPrivateWebSocketObservabilityStatus::connecting(
            new DateTimeImmutable('2026-07-13T10:00:00Z'),
        );

        self::assertFalse($status->connected);
        self::assertFalse($status->authenticated);
        self::assertFalse($status->ordersStreamReady);
        self::assertFalse($status->fillsStreamReady);
        self::assertNull($status->fillsSource);
        self::assertFalse($status->positionsStreamReady);
        self::assertFalse($status->initialSnapshotLoaded);
        self::assertFalse($status->reconciliationFresh);
        self::assertTrue($status->reconnecting);
        self::assertNull($status->connectedAt);
        self::assertNull($status->lastEventAt);
        self::assertSame([], $status->blockingErrors);
        self::assertSame([], $status->warnings);
        self::assertEquals($status, OkxPrivateWebSocketObservabilityStatus::fromArray($status->toArray()));
    }

    public function testRejectsReadyFillsWithoutASource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_ws_status_fills_source_invalid');

        self::statusWithFills(true, null);
    }

    public function testRejectsFillsSourceWhenStreamIsNotReady(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_ws_status_fills_source_invalid');

        self::statusWithFills(false, 'fills_channel');
    }

    public function testSaveUsesTheExactProductionKeyAndTtl(): void
    {
        $redis = new SpyOkxPrivateWebSocketRedisClient();
        $store = new RedisOkxPrivateWebSocketStatusStore($redis);

        $store->save(self::healthyStatus());

        self::assertSame(self::KEY, $redis->lastKey);
        self::assertSame(10, $redis->lastTtl);
        self::assertNotNull($redis->lastValue);
        self::assertSame(self::healthyStatus()->toArray(), json_decode($redis->lastValue, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testLoadReturnsNullForAbsenceCorruptionAndEverySchemaViolation(): void
    {
        $valid = self::healthyStatus()->toArray();
        $invalidPayloads = [
            false,
            '{invalid',
            json_encode(array_diff_key($valid, ['exchange' => true]), JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'unexpected' => true], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'schema_version' => 2], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'exchange' => 'bitmart'], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'environment' => 'live'], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'endpoint_id' => 'mainnet'], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'connected' => 1], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'fills_source' => 'raw_fills'], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'last_heartbeat_at' => null], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'observed_at' => 'not-a-timestamp'], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'blocking_errors' => ['api_secret=leaked']], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'warnings' => ['okx_fills_channel_vip_unavailable', 'okx_fills_channel_vip_unavailable']], JSON_THROW_ON_ERROR),
        ];

        foreach ($invalidPayloads as $payload) {
            $redis = new SpyOkxPrivateWebSocketRedisClient();
            $redis->value = $payload;

            self::assertNull((new RedisOkxPrivateWebSocketStatusStore($redis))->load());
        }
    }

    public function testReadFailureUsesAStableRedactedExceptionWithoutChainingTransportDetails(): void
    {
        $redis = new SpyOkxPrivateWebSocketRedisClient();
        $redis->throwOnRead = true;

        try {
            (new RedisOkxPrivateWebSocketStatusStore($redis))->load();
            self::fail('Expected the Redis read failure to be surfaced.');
        } catch (RuntimeException $exception) {
            self::assertSame('okx_private_ws_status_read_failed', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('demo-secret', $exception->getMessage());
        }
    }

    public function testWriteFailureUsesAStableRedactedException(): void
    {
        $redis = new SpyOkxPrivateWebSocketRedisClient();
        $redis->throwOnWrite = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('okx_private_ws_status_write_failed');

        (new RedisOkxPrivateWebSocketStatusStore($redis))->save(self::healthyStatus());
    }

    public function testClearDeletesTheExactKey(): void
    {
        $redis = new SpyOkxPrivateWebSocketRedisClient();
        $store = new RedisOkxPrivateWebSocketStatusStore($redis);

        $store->clear();

        self::assertSame(self::KEY, $redis->deletedKey);
    }

    public function testJsonContainsNoSecretsAndSensitiveCodesAreRejected(): void
    {
        $redis = new SpyOkxPrivateWebSocketRedisClient();
        (new RedisOkxPrivateWebSocketStatusStore($redis))->save(self::healthyStatus());

        self::assertNotNull($redis->lastValue);
        self::assertStringNotContainsString('demo-secret', $redis->lastValue);
        self::assertStringNotContainsString('demo-key', $redis->lastValue);
        self::assertStringNotContainsString('demo-passphrase', $redis->lastValue);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_private_ws_status_codes_invalid');

        new OkxPrivateWebSocketObservabilityStatus(
            connected: false,
            authenticated: false,
            ordersStreamReady: false,
            fillsStreamReady: false,
            fillsSource: null,
            positionsStreamReady: false,
            initialSnapshotLoaded: false,
            reconciliationFresh: false,
            reconnecting: true,
            connectedAt: null,
            lastHeartbeatAt: new DateTimeImmutable('2026-07-13T10:00:00Z'),
            lastEventAt: null,
            observedAt: new DateTimeImmutable('2026-07-13T10:00:00Z'),
            blockingErrors: ['api_secret=demo-secret'],
            warnings: [],
        );
    }

    /** @param array<mixed> $payload */
    #[DataProvider('invalidDirectArrays')]
    public function testFromArrayRejectsInvalidSchemaWithStableExceptions(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        OkxPrivateWebSocketObservabilityStatus::fromArray($payload);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidDirectArrays(): iterable
    {
        $valid = self::healthyStatus()->toArray();

        yield 'missing field' => [array_diff_key($valid, ['exchange' => true])];
        yield 'extra field' => [[...$valid, 'raw_payload' => 'secret']];
        yield 'unknown version' => [[...$valid, 'schema_version' => 2]];
        yield 'wrong target' => [[...$valid, 'exchange' => 'okx-live']];
        yield 'wrong type' => [[...$valid, 'connected' => 'true']];
        yield 'invalid timestamp' => [[...$valid, 'observed_at' => '2026-07-13 10:00:00']];
    }

    #[RequiresPhpExtension('redis')]
    public function testExpiresAgainstRealRedis(): void
    {
        if (!class_exists(Redis::class)) {
            self::markTestSkipped('ext-redis unavailable');
        }

        $redis = new Redis();
        self::assertTrue($redis->connect(
            (string) (getenv('REDIS_HOST') ?: 'redis'),
            (int) (getenv('REDIS_PORT') ?: 6379),
            1.0,
            null,
            0,
            1.0,
        ));

        $testDatabase = (int) (getenv('OKX_PRIVATE_WS_TEST_REDIS_DB') ?: self::TEST_REDIS_DATABASE);
        self::assertTrue($redis->select($testDatabase));
        self::assertSame($testDatabase, $redis->getDBNum());

        $store = new RedisOkxPrivateWebSocketStatusStore(new ExtRedisOkxPrivateWebSocketClient($redis), 1);
        $store->clear();

        try {
            $store->save(self::healthyStatus());
            self::assertSame(1, $redis->ttl(self::KEY));
            sleep(2);
            self::assertNull($store->load());
        } finally {
            $store->clear();
            $redis->close();
        }
    }

    private static function healthyStatus(): OkxPrivateWebSocketObservabilityStatus
    {
        return new OkxPrivateWebSocketObservabilityStatus(
            connected: true,
            authenticated: true,
            ordersStreamReady: true,
            fillsStreamReady: true,
            fillsSource: 'fills_channel',
            positionsStreamReady: true,
            initialSnapshotLoaded: true,
            reconciliationFresh: true,
            reconnecting: false,
            connectedAt: new DateTimeImmutable('2026-07-13T10:00:00Z'),
            lastHeartbeatAt: new DateTimeImmutable('2026-07-13T10:00:08Z'),
            lastEventAt: new DateTimeImmutable('2026-07-13T10:00:07Z'),
            observedAt: new DateTimeImmutable('2026-07-13T10:00:09Z'),
            blockingErrors: [],
            warnings: ['okx_fills_channel_vip_unavailable'],
        );
    }

    private static function statusWithFills(
        bool $fillsStreamReady,
        ?string $fillsSource,
    ): OkxPrivateWebSocketObservabilityStatus {
        return new OkxPrivateWebSocketObservabilityStatus(
            connected: false,
            authenticated: false,
            ordersStreamReady: false,
            fillsStreamReady: $fillsStreamReady,
            fillsSource: $fillsSource,
            positionsStreamReady: false,
            initialSnapshotLoaded: false,
            reconciliationFresh: false,
            reconnecting: true,
            connectedAt: null,
            lastHeartbeatAt: new DateTimeImmutable('2026-07-13T10:00:00Z'),
            lastEventAt: null,
            observedAt: new DateTimeImmutable('2026-07-13T10:00:00Z'),
            blockingErrors: [],
            warnings: [],
        );
    }
}

final class SpyOkxPrivateWebSocketRedisClient implements OkxPrivateWebSocketRedisClientInterface
{
    public ?string $lastKey = null;
    public ?int $lastTtl = null;
    public ?string $lastValue = null;
    public string|false $value = false;
    public ?string $deletedKey = null;
    public bool $throwOnRead = false;
    public bool $throwOnWrite = false;

    public function setex(string $key, int $ttl, string $value): bool
    {
        if ($this->throwOnWrite) {
            throw new RuntimeException('raw secret demo-secret');
        }

        $this->lastKey = $key;
        $this->lastTtl = $ttl;
        $this->lastValue = $value;
        $this->value = $value;

        return true;
    }

    public function get(string $key): string|false
    {
        if ($this->throwOnRead) {
            throw new RuntimeException('raw secret demo-secret');
        }

        return $this->value;
    }

    public function del(string $key): int|false
    {
        if ($this->throwOnWrite) {
            throw new RuntimeException('raw secret demo-secret');
        }

        $this->deletedKey = $key;
        $this->value = false;

        return 1;
    }
}

if (class_exists(Redis::class)) {
    final class InspectableRedisConnection extends Redis
    {
        /** @var array{string, int, float, ?string, int, float}|null */
        public ?array $connectArguments = null;

        public function __construct(private readonly bool $connectResult)
        {
        }

        /** @param null|array<mixed> $context */
        public function connect(
            string $host,
            int $port = 6379,
            float $timeout = 0.0,
            ?string $persistent_id = null,
            int $retry_interval = 0,
            float $read_timeout = 0.0,
            ?array $context = null,
        ): bool {
            $this->connectArguments = [
                $host,
                $port,
                $timeout,
                $persistent_id,
                $retry_interval,
                $read_timeout,
            ];

            return $this->connectResult;
        }
    }
}
