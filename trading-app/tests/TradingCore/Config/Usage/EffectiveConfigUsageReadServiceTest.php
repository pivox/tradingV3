<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Usage;

use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRecord;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocument;
use App\TradingCore\Config\Usage\EffectiveConfigUsageFact;
use App\TradingCore\Config\Usage\EffectiveConfigUsageReadException;
use App\TradingCore\Config\Usage\EffectiveConfigUsageReadService;
use App\TradingCore\Config\Usage\EffectiveConfigUsageScope;
use App\TradingCore\Config\Usage\EffectiveConfigUsageStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveConfigUsageReadService::class)]
final class EffectiveConfigUsageReadServiceTest extends TestCase
{
    public function testRunReturnsEveryHistoricalSnapshotWithExplicitUsageCounts(): void
    {
        $a = $this->hash('a');
        $b = $this->hash('b');
        $service = $this->service([
            $this->fact('trade_lineage', '1', $a, 'decision-1', null, 'trade-1'),
            $this->fact('order_intent', '2', $a, 'decision-1', 'trade-1', 'trade-1'),
            $this->fact('trade_lifecycle_event', '3', $a, 'decision-1', 'trade-1', 'trade-1'),
            $this->fact('trade_lifecycle_event', '4', $a, 'decision-1', 'trade-1', 'trade-1'),
            $this->fact('order_intent', '5', $b, 'decision-2', 'trade-2', 'trade-2'),
        ], [$this->record($a, 'c'), $this->record($b, 'd')]);

        $result = $service->read(EffectiveConfigUsageScope::RUN, 'run-1');

        self::assertSame('run', $result['scope']);
        self::assertSame('run-1', $result['identifier']);
        self::assertSame(2, $result['count']);
        self::assertSame([$a, $b], array_column(array_column($result['snapshots'], 'snapshot'), 'snapshot_hash'));
        self::assertSame([
            'lineages' => 1,
            'order_intents' => 1,
            'lifecycle_events' => 2,
            'decision_ids' => 1,
            'trade_ids' => 1,
            'internal_trade_ids' => 1,
        ], $result['snapshots'][0]['usage']);
        self::assertSame('historical_snapshot', $result['snapshots'][0]['snapshot']['document_kind']);
    }

    public function testSetMayResolveMultipleSnapshots(): void
    {
        $a = $this->hash('a');
        $b = $this->hash('b');
        $service = $this->service([
            $this->fact('order_intent', '1', $b),
            $this->fact('order_intent', '2', $a),
        ], [$this->record($a, 'c'), $this->record($b, 'd')]);

        self::assertSame(2, $service->read(EffectiveConfigUsageScope::SET, 'set-1')['count']);
    }

    public function testInvalidIdentifierAndNoFactsFailClosed(): void
    {
        $service = $this->service([], []);

        $this->assertFailure($service, EffectiveConfigUsageScope::RUN, ' ', 'invalid_effective_config_usage_identifier', 400);
        $this->assertFailure($service, EffectiveConfigUsageScope::RUN, str_repeat('x', 256), 'invalid_effective_config_usage_identifier', 400);
        $this->assertFailure($service, EffectiveConfigUsageScope::RUN, 'missing', 'effective_config_usage_not_found', 404);
    }

    public function testMissingOrMalformedReferenceRejectsTheWholeLookup(): void
    {
        foreach ([null, '', 'sha256:' . str_repeat('a', 64), 'effective-config-snapshot:sha256:BAD'] as $reference) {
            $service = $this->service([
                new EffectiveConfigUsageFact('order_intent', '1', $this->hash('c'), $reference, null, null, null),
            ], []);

            $this->assertFailure($service, EffectiveConfigUsageScope::RUN, 'run-1', 'effective_config_reference_missing', 422);
        }
    }

    public function testUnregisteredSnapshotAndConfigHashMismatchAreConflicts(): void
    {
        $snapshot = $this->hash('a');
        $missing = $this->service([$this->fact('order_intent', '1', $snapshot)], []);
        $this->assertFailure($missing, EffectiveConfigUsageScope::RUN, 'run-1', 'effective_config_snapshot_unregistered', 409);

        $mismatch = $this->service(
            [$this->fact('order_intent', '1', $snapshot, configHash: $this->hash('e'))],
            [$this->record($snapshot, 'c')],
        );
        $this->assertFailure($mismatch, EffectiveConfigUsageScope::RUN, 'run-1', 'effective_config_hash_conflict', 409);
    }

    public function testDecisionAndTradeRejectMoreThanOneSnapshot(): void
    {
        $a = $this->hash('a');
        $b = $this->hash('b');
        $service = $this->service([
            $this->fact('order_intent', '1', $a),
            $this->fact('trade_lifecycle_event', '2', $b),
        ], [$this->record($a, 'c'), $this->record($b, 'd')]);

        $this->assertFailure($service, EffectiveConfigUsageScope::DECISION, 'decision-1', 'effective_config_usage_conflict', 409);
        $this->assertFailure($service, EffectiveConfigUsageScope::TRADE, 'trade-1', 'effective_config_usage_conflict', 409);
    }

    /**
     * @param list<EffectiveConfigUsageFact>     $facts
     * @param list<EffectiveConfigSnapshotRecord> $records
     */
    private function service(array $facts, array $records): EffectiveConfigUsageReadService
    {
        $store = new class($facts) implements EffectiveConfigUsageStoreInterface {
            /** @param list<EffectiveConfigUsageFact> $facts */
            public function __construct(private readonly array $facts) {}
            public function find(EffectiveConfigUsageScope $scope, string $identifier): array { return $this->facts; }
        };
        $registry = new class($records) implements EffectiveConfigSnapshotRegistryInterface {
            /** @param list<EffectiveConfigSnapshotRecord> $records */
            public function __construct(private readonly array $records) {}
            public function register(EffectiveConfigViewerDocument $document): void {}
            public function find(string $snapshotHash): ?EffectiveConfigSnapshotRecord
            {
                foreach ($this->records as $record) {
                    if (($record->document['snapshot_hash'] ?? null) === $snapshotHash) {
                        return $record;
                    }
                }

                return null;
            }
            public function findByConfigHash(string $configHash): array { return []; }
        };

        return new EffectiveConfigUsageReadService($store, $registry);
    }

    private function fact(
        string $source,
        string $rowIdentity,
        string $snapshotHash,
        ?string $decisionId = null,
        ?string $tradeId = null,
        ?string $internalTradeId = null,
        ?string $configHash = null,
    ): EffectiveConfigUsageFact {
        return new EffectiveConfigUsageFact(
            $source,
            $rowIdentity,
            $configHash ?? $this->hash($snapshotHash === $this->hash('a') ? 'c' : 'd'),
            'effective-config-snapshot:' . $snapshotHash,
            $decisionId,
            $tradeId,
            $internalTradeId,
        );
    }

    private function record(string $snapshotHash, string $configCharacter): EffectiveConfigSnapshotRecord
    {
        return new EffectiveConfigSnapshotRecord([
            'document_kind' => 'current_preview',
            'snapshot_hash' => $snapshotHash,
            'config_hash' => $this->hash($configCharacter),
            'config' => ['secret' => '***REDACTED***'],
        ], new \DateTimeImmutable('2026-08-20T12:00:00Z'));
    }

    private function hash(string $character): string
    {
        return 'sha256:' . str_repeat($character, 64);
    }

    private function assertFailure(
        EffectiveConfigUsageReadService $service,
        EffectiveConfigUsageScope $scope,
        string $identifier,
        string $code,
        int $status,
    ): void {
        try {
            $service->read($scope, $identifier);
            self::fail('Expected EffectiveConfigUsageReadException.');
        } catch (EffectiveConfigUsageReadException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->httpStatus);
        }
    }
}
