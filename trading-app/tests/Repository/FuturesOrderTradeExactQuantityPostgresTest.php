<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\MtfRunner\Service\FuturesOrderSyncService;
use App\Repository\FuturesOrderRepository;
use App\Repository\FuturesOrderTradeRepository;
use App\Repository\FuturesPlanOrderRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20260715150000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FuturesOrderTrade::class)]
#[CoversClass(FuturesOrderSyncService::class)]
#[CoversClass(Version20260715150000::class)]
final class FuturesOrderTradeExactQuantityPostgresTest extends TestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $schema;

    protected function setUp(): void
    {
        if (!class_exists(Version20260715150000::class)) {
            require_once dirname(__DIR__, 2) . '/migrations/Version20260715150000.php';
        }

        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if (!\is_string($dsn) || $dsn === '') {
            throw new \RuntimeException('DATABASE_URL is required for the PostgreSQL exact trade-quantity test.');
        }
        $this->connection = DriverManager::getConnection(['url' => $dsn]);
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('The exact trade-quantity integration test requires PostgreSQL.');
        }

        $this->schema = 'exact_trade_quantity_' . bin2hex(random_bytes(6));
        $quotedSchema = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schema);
        $this->connection->executeStatement('CREATE SCHEMA ' . $quotedSchema);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET LOCAL search_path TO ' . $quotedSchema);
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $this->entityManager = new EntityManager($this->connection, $configuration);
        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(FuturesOrder::class),
            $this->entityManager->getClassMetadata(FuturesOrderTrade::class),
        ]);
        $this->connection->executeStatement('ALTER TABLE futures_order_trade DROP quantity_decimal');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement(
                'DROP SCHEMA IF EXISTS '
                . $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schema)
                . ' CASCADE',
            );
            $this->connection->close();
        }
        parent::tearDown();
    }

    public function testMigrationBackfillAndIdempotentReplayPreserveExactFillQuantity(): void
    {
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO futures_order_trade (
    id, exchange, market_type, trade_id, order_id, symbol, side, price, size,
    trade_time, raw_data, created_at, updated_at
) VALUES (
    100, 'okx', 'perpetual', 'legacy-fill', 'legacy-order', 'BTCUSDT', 1, 25000, 7,
    1767225600000, '{}'::jsonb, NOW(), NOW()
)
SQL);

        $this->executeMigration('up');
        self::assertSame('7.000000000000000000', $this->connection->fetchOne(
            'SELECT quantity_decimal::text FROM futures_order_trade WHERE trade_id = ?',
            ['legacy-fill'],
        ));

        $service = new FuturesOrderSyncService(
            new FuturesOrderRepository($this->managerRegistry()),
            new FuturesPlanOrderRepository($this->managerRegistry()),
            new FuturesOrderTradeRepository($this->managerRegistry()),
            $this->entityManager,
            new NullLogger(),
        );
        $payload = [
            'exchange' => 'okx',
            'market_type' => 'perpetual',
            'trade_id' => 'fractional-fill',
            'order_id' => 'fractional-order',
            'symbol' => 'ETHUSDT',
            'side' => 1,
            'price' => '2500.25',
            'size' => 0,
            'quantity_decimal' => '0.250000000000000001',
            'trade_time' => 1767225600123,
        ];

        $first = $service->syncTradeFromApi($payload);
        $replay = $service->syncTradeFromApi($payload);
        self::assertInstanceOf(FuturesOrderTrade::class, $first);
        self::assertSame($first, $replay);
        self::assertSame('0.250000000000000001', $replay->getQuantityDecimal());

        $legacyReplay = $payload;
        unset($legacyReplay['quantity_decimal']);
        self::assertSame($first, $service->syncTradeFromApi($legacyReplay));
        self::assertSame('0.250000000000000001', $first->getQuantityDecimal());

        $conflict = $payload;
        $conflict['quantity_decimal'] = '0.250000000000000002';
        self::assertNull($service->syncTradeFromApi($conflict));
        self::assertSame('0.250000000000000001', $first->getQuantityDecimal());

        $this->entityManager->clear();
        self::assertSame(
            '0.250000000000000001',
            $this->connection->fetchOne(
                'SELECT quantity_decimal::text FROM futures_order_trade WHERE trade_id = ?',
                ['fractional-fill'],
            ),
        );
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM futures_order_trade WHERE trade_id = ?',
            ['fractional-fill'],
        ));

        $this->executeMigration('down');
        self::assertSame(0, (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*)
FROM information_schema.columns
WHERE table_schema = ? AND table_name = 'futures_order_trade' AND column_name = 'quantity_decimal'
SQL,
            [$this->schema],
        ));
        self::assertSame(7, (int) $this->connection->fetchOne(
            'SELECT size FROM futures_order_trade WHERE trade_id = ?',
            ['legacy-fill'],
        ));
    }

    private function executeMigration(string $direction): void
    {
        $migration = new Version20260715150000($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function managerRegistry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        return $registry;
    }
}
