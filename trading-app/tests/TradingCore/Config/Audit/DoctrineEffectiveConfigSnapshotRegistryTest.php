<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Audit;

use App\TradingCore\Config\Audit\DoctrineEffectiveConfigSnapshotRegistry;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocument;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260820150000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DoctrineEffectiveConfigSnapshotRegistry::class)]
final class DoctrineEffectiveConfigSnapshotRegistryTest extends TestCase
{
    private Connection $connection;
    private string $schemaName;
    private DoctrineEffectiveConfigSnapshotRegistry $registry;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        $database = is_string($dsn) ? ltrim((string) parse_url($dsn, PHP_URL_PATH), '/') : '';
        if (!str_ends_with($database, '_paper_test')) {
            self::markTestSkipped('Effective config registry integration tests require a database ending in _paper_test.');
        }

        $this->connection = DriverManager::getConnection(['url' => $dsn]);
        $this->schemaName = sprintf('effective_config_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $quoted = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schemaName);
        $this->connection->executeStatement('CREATE SCHEMA ' . $quoted);
        $this->connection->executeStatement('SET search_path TO ' . $quoted . ', public');
        $this->executeMigration();
        $this->registry = new DoctrineEffectiveConfigSnapshotRegistry($this->connection);
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        $quoted = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schemaName);
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $quoted . ' CASCADE');
        $this->connection->close();
    }

    public function testRegistrationIsIdempotentAndReloadsCanonicalDocument(): void
    {
        $document = $this->document('a', 'c', '/base-a.yaml');
        $this->registry->register($document);
        $this->registry->register($document);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM effective_trading_config_snapshot'));
        self::assertSame($document->payload, $this->registry->find($document->snapshotHash())?->document);
        self::assertStringNotContainsString('raw-secret', (string) $this->connection->fetchOne('SELECT redacted_snapshot::text FROM effective_trading_config_snapshot'));
    }

    public function testSameHashWithDifferentContentFailsClosed(): void
    {
        $this->registry->register($this->document('a', 'c', '/base-a.yaml'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('effective_config_snapshot_conflict');
        $this->registry->register($this->document('a', 'c', '/different.yaml'));
    }

    public function testHistoryPreservesProvenanceDistinctSnapshotsSharingAConfigHash(): void
    {
        $this->registry->register($this->document('b', 'c', '/base-b.yaml'));
        $this->registry->register($this->document('a', 'c', '/base-a.yaml'));

        $history = $this->registry->findByConfigHash('sha256:' . str_repeat('c', 64));
        self::assertCount(2, $history);
        self::assertSame(
            ['sha256:' . str_repeat('b', 64), 'sha256:' . str_repeat('a', 64)],
            array_map(static fn ($record): string => (string) $record->document['snapshot_hash'], $history),
        );
    }

    private function document(string $snapshot, string $config, string $path): EffectiveConfigViewerDocument
    {
        return new EffectiveConfigViewerDocument([
            'document_kind' => 'current_preview',
            'resolver_version' => '1.0.0',
            'validation_status' => 'valid',
            'redacted_paths' => ['config.api_secret'],
            'request' => [
                'mode_id' => 'day_trading', 'mode_version' => '1.1.0',
                'setup_id' => 'day_trading.trend_continuation.long', 'setup_version' => '1.1.0',
                'exchange' => 'fake', 'environment' => 'test', 'side' => 'long',
            ],
            'config' => ['schema_version' => 'effective-trading-config.v2', 'api_secret' => '***REDACTED***'],
            'config_hash' => 'sha256:' . str_repeat($config, 64),
            'condition_catalog_hash' => 'sha256:' . str_repeat('d', 64),
            'ordered_layers' => [['type' => 'base', 'name' => 'base', 'path' => $path, 'required' => true]],
            'ordered_files' => [$path],
            'provenance' => ['schema_version' => ['type' => 'base', 'name' => 'base', 'path' => $path, 'required' => true]],
            'executable' => true,
            'blockers' => [],
            'snapshot_hash' => 'sha256:' . str_repeat($snapshot, 64),
        ]);
    }

    private function executeMigration(): void
    {
        require_once __DIR__ . '/../../../../migrations/Version20260820150000.php';
        $migration = new Version20260820150000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
