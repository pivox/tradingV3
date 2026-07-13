<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\FuturesOrder;
use App\Exchange\Value\ExactOrderQuantities;
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
use DoctrineMigrations\Version20260713150000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ExactOrderQuantities::class)]
#[CoversClass(FuturesOrderSyncService::class)]
#[CoversClass(Version20260713150000::class)]
final class FuturesOrderExactQuantityPostgresTest extends TestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $schema;

    protected function setUp(): void
    {
        if (!class_exists(Version20260713150000::class)) {
            require_once dirname(__DIR__, 2) . '/migrations/Version20260713150000.php';
        }

        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if (!\is_string($dsn) || $dsn === '') {
            throw new \RuntimeException('DATABASE_URL is required for the PostgreSQL exact-quantity test.');
        }
        $this->connection = DriverManager::getConnection(['url' => $dsn]);
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('The exact-quantity integration test requires PostgreSQL.');
        }

        $this->schema = 'exact_quantity_' . bin2hex(random_bytes(6));
        $quotedSchema = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schema);
        $this->connection->executeStatement('CREATE SCHEMA ' . $quotedSchema);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET LOCAL search_path TO ' . $quotedSchema);
        $configuration = ORMSetup::createAttributeMetadataConfiguration([
            dirname(__DIR__, 2) . '/src/Entity',
        ], true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $this->entityManager = new EntityManager($this->connection, $configuration);
        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(FuturesOrder::class),
        ]);
        $this->connection->executeStatement('ALTER TABLE futures_order DROP quantity_decimal');
        $this->connection->executeStatement('ALTER TABLE futures_order DROP filled_quantity_decimal');
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

    public function testMigrationBackfillLimitsDoctrineReplayAndSafeDown(): void
    {
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO futures_order (
    id, exchange, market_type, order_id, symbol, side, size, filled_size,
    raw_data, created_at, updated_at
) VALUES (
    100, 'okx', 'perpetual', 'legacy-order', 'BTCUSDT', 1, 7, 3,
    '{}'::jsonb, NOW(), NOW()
)
SQL);

        $this->executeMigrationUp();

        $columns = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT column_name, numeric_precision, numeric_scale
FROM information_schema.columns
WHERE table_schema = ?
  AND table_name = 'futures_order'
  AND column_name IN ('quantity_decimal', 'filled_quantity_decimal')
ORDER BY column_name
SQL,
            [$this->schema],
        );
        self::assertSame([
            ['column_name' => 'filled_quantity_decimal', 'numeric_precision' => 36, 'numeric_scale' => 18],
            ['column_name' => 'quantity_decimal', 'numeric_precision' => 36, 'numeric_scale' => 18],
        ], $columns);
        self::assertSame([
            '7.000000000000000000',
            '3.000000000000000000',
        ], array_values($this->connection->fetchAssociative(
            'SELECT quantity_decimal::text, filled_quantity_decimal::text FROM futures_order WHERE order_id = ?',
            ['legacy-order'],
        ) ?: []));

        $this->connection->executeStatement(
            'UPDATE futures_order SET quantity_decimal = ?, filled_quantity_decimal = ? WHERE order_id = ?',
            ['999999999999999999.999999999999999999', '0.000000000000000001', 'legacy-order'],
        );
        self::assertSame(
            '999999999999999999.999999999999999999',
            $this->connection->fetchOne('SELECT quantity_decimal::text FROM futures_order WHERE order_id = ?', ['legacy-order']),
        );

        try {
            ExactOrderQuantities::fromQuantityAndFilled('1.0000000000000000000', '0');
            self::fail('Scale 19 must be rejected by the application before persistence.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $sync = new FuturesOrderSyncService(
            $this->futuresOrderRepository(),
            $this->futuresPlanOrderRepository(),
            $this->futuresOrderTradeRepository(),
            $this->entityManager,
            $this->failFastLogger(),
        );
        $payload = [
            'exchange' => 'okx',
            'market_type' => 'perpetual',
            'order_id' => 'doctrine-exact',
            'symbol' => 'ETHUSDT',
            'side' => 1,
            'size' => 1,
            'filled_size' => 0,
            'quantity_decimal' => '1.123456789012345678',
            'filled_quantity_decimal' => '0.400000000000000001',
            'remaining_quantity_decimal' => '0.723456789012345677',
        ];
        $first = $sync->syncOrderFromApi($payload);
        $replay = $sync->syncOrderFromApi($payload);
        self::assertInstanceOf(FuturesOrder::class, $first);
        self::assertSame($first, $replay);

        $legacyUpdate = $sync->syncOrderFromApi([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
            'order_id' => 'doctrine-exact',
            'symbol' => 'ETHUSDT',
            'size' => 2,
            'filled_size' => 1,
        ]);
        self::assertInstanceOf(FuturesOrder::class, $legacyUpdate);
        self::assertSame('2', $legacyUpdate->getQuantityDecimal());
        self::assertSame('1', $legacyUpdate->getFilledQuantityDecimal());

        $this->entityManager->clear();
        $reloaded = $this->futuresOrderRepository()->findOneByOrderId(
            'doctrine-exact',
            new \App\Provider\Context\ExchangeContext(
                \App\Common\Enum\Exchange::OKX,
                \App\Common\Enum\MarketType::PERPETUAL,
            ),
        );
        self::assertInstanceOf(FuturesOrder::class, $reloaded);
        self::assertSame('2.000000000000000000', $reloaded->getQuantityDecimal());
        self::assertSame('1.000000000000000000', $reloaded->getFilledQuantityDecimal());
        self::assertSame(1, $this->futuresOrderRepository()->count(['orderId' => 'doctrine-exact']));

        $this->executeMigrationDown();
        self::assertSame(0, (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*)
FROM information_schema.columns
WHERE table_schema = ?
  AND table_name = 'futures_order'
  AND column_name IN ('quantity_decimal', 'filled_quantity_decimal')
SQL,
            [$this->schema],
        ));
        self::assertSame(7, (int) $this->connection->fetchOne(
            'SELECT size FROM futures_order WHERE order_id = ?',
            ['legacy-order'],
        ));
    }

    private function executeMigrationUp(): void
    {
        $migration = new Version20260713150000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function executeMigrationDown(): void
    {
        $migration = new Version20260713150000($this->connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function futuresOrderRepository(): FuturesOrderRepository
    {
        return new FuturesOrderRepository($this->managerRegistry());
    }

    private function futuresPlanOrderRepository(): FuturesPlanOrderRepository
    {
        return new FuturesPlanOrderRepository($this->managerRegistry());
    }

    private function futuresOrderTradeRepository(): FuturesOrderTradeRepository
    {
        return new FuturesOrderTradeRepository($this->managerRegistry());
    }

    private function managerRegistry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        return $registry;
    }

    private function failFastLogger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            static function (string $message, array $context): never {
                throw new \RuntimeException((string) ($context['error'] ?? $message));
            },
        );

        return $logger;
    }
}
