<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Usage;

use App\Tests\Support\PostgresIntegrationDatabaseGuard;
use App\TradingCore\Config\Usage\DoctrineEffectiveConfigUsageStore;
use App\TradingCore\Config\Usage\EffectiveConfigUsageScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineEffectiveConfigUsageStore::class)]
final class DoctrineEffectiveConfigUsageStoreTest extends TestCase
{
    private Connection $connection;
    private ?string $schemaName = null;
    private DoctrineEffectiveConfigUsageStore $store;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        $database = is_string($dsn) ? ltrim((string) parse_url($dsn, PHP_URL_PATH), '/') : '';
        $safePostgres = ($database === 'test' || str_ends_with($database, '_test'))
            && preg_match('/^(?:postgres|postgresql|pdo-pgsql):/', (string) $dsn) === 1;
        $requirePostgres = getenv('EFFECTIVE_CONFIG_USAGE_REQUIRE_POSTGRES') === '1';
        if ($requirePostgres && !$safePostgres) {
            throw new \LogicException('A safe PostgreSQL test DSN is required for the effective-config usage gate.');
        }
        if ($safePostgres) {
            PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase($dsn);
            try {
                $this->connection = DriverManager::getConnection(['url' => $dsn]);
                $this->schemaName = sprintf('effective_config_usage_%d_%s', getmypid(), bin2hex(random_bytes(4)));
                $quoted = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schemaName);
                $this->connection->executeStatement('CREATE SCHEMA ' . $quoted);
                $this->connection->executeStatement('SET search_path TO ' . $quoted . ', public');
            } catch (\Throwable $exception) {
                if ($requirePostgres) {
                    throw $exception;
                }
                if (isset($this->connection)) {
                    $this->connection->close();
                }
                $this->schemaName = null;
            }
        }
        if (!isset($this->connection) || !$this->connection->isConnected()) {
            $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }
        $this->createTables();
        $this->store = new DoctrineEffectiveConfigUsageStore($this->connection);
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        if ($this->schemaName !== null) {
            $quoted = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schemaName);
            $this->connection->executeStatement('SET search_path TO public');
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $quoted . ' CASCADE');
        }
        $this->connection->close();
    }

    public function testEachScopeUsesOnlyExactCanonicalColumnsAcrossAllSources(): void
    {
        $common = [
            'orchestration_run_id' => 'run-exact',
            'orchestration_set_id' => 'set-exact',
            'decision_id' => 'decision-exact',
            'internal_trade_id' => 'trade-exact',
            'config_hash' => 'sha256:' . str_repeat('c', 64),
            'effective_config_reference' => 'effective-config-snapshot:sha256:' . str_repeat('a', 64),
        ];
        $this->connection->insert('trade_lineage', ['id' => 1] + $common);
        $this->connection->insert('order_intent', ['id' => 2, 'trade_id' => 'trade-exact'] + $common);
        $this->connection->insert('trade_lifecycle_event', ['id' => 3, 'trade_id' => 'trade-exact'] + $common);

        $near = array_map(static fn (string $value): string => $value . '-near', $common);
        $this->connection->insert('trade_lineage', ['id' => 4] + $near);
        $this->connection->insert('order_intent', ['id' => 5, 'trade_id' => 'other', 'internal_trade_id' => 'trade-exact'] + array_diff_key($near, ['internal_trade_id' => true]));
        $this->connection->insert('trade_lifecycle_event', ['id' => 6, 'trade_id' => 'other', 'internal_trade_id' => 'trade-exact'] + array_diff_key($near, ['internal_trade_id' => true]));

        foreach ([
            [EffectiveConfigUsageScope::RUN, 'run-exact', [2, 3, 1]],
            [EffectiveConfigUsageScope::SET, 'set-exact', [2, 3, 1]],
            [EffectiveConfigUsageScope::DECISION, 'decision-exact', [2, 3, 1]],
            [EffectiveConfigUsageScope::TRADE, 'trade-exact', [2, 5, 3, 6, 1]],
        ] as [$scope, $identifier, $expectedIds]) {
            $facts = $this->store->find($scope, $identifier);
            self::assertSame(array_map('strval', $expectedIds), array_column($facts, 'rowIdentity'), $scope->value);
        }
    }

    public function testNullableFieldsRemainNullAndRowsAreDeterministic(): void
    {
        $this->connection->insert('order_intent', [
            'id' => 20,
            'orchestration_run_id' => 'run-null',
            'config_hash' => null,
            'effective_config_reference' => null,
        ]);
        $this->connection->insert('order_intent', [
            'id' => 10,
            'orchestration_run_id' => 'run-null',
            'config_hash' => null,
            'effective_config_reference' => null,
        ]);

        $facts = $this->store->find(EffectiveConfigUsageScope::RUN, 'run-null');

        self::assertSame(['10', '20'], array_column($facts, 'rowIdentity'));
        self::assertNull($facts[0]->configHash);
        self::assertNull($facts[0]->effectiveConfigReference);
        self::assertNull($facts[0]->decisionId);
        self::assertNull($facts[0]->tradeId);
        self::assertNull($facts[0]->internalTradeId);
    }

    private function createTables(): void
    {
        foreach (['trade_lineage', 'order_intent', 'trade_lifecycle_event'] as $table) {
            $tradeId = $table === 'trade_lineage' ? '' : ', trade_id VARCHAR(96) DEFAULT NULL';
            $this->connection->executeStatement(sprintf(<<<'SQL'
CREATE TABLE %s (
    id BIGINT NOT NULL,
    orchestration_run_id VARCHAR(255) DEFAULT NULL,
    orchestration_set_id VARCHAR(96) DEFAULT NULL,
    decision_id VARCHAR(36) DEFAULT NULL,
    internal_trade_id VARCHAR(96) DEFAULT NULL,
    config_hash VARCHAR(128) DEFAULT NULL,
    effective_config_reference VARCHAR(255) DEFAULT NULL%s
)
SQL, $table, $tradeId));
        }
    }
}
