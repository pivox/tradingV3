<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\HyperliquidTestnetExecutionAttempt;
use App\Repository\HyperliquidTestnetExecutionAttemptRepository;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionAttemptClaim;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(HyperliquidTestnetExecutionAttemptRepository::class)]
final class HyperliquidTestnetExecutionAttemptPostgresTest extends TestCase
{
    private string $dsn;
    private string $schema;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if (!is_string($dsn) || preg_match('/^(postgres|postgresql|pdo-pgsql)/', $dsn) !== 1) {
            self::markTestSkipped('A PostgreSQL DATABASE_URL is required.');
        }
        $this->dsn = $dsn;
        $this->schema = 'hl012_attempt_' . bin2hex(random_bytes(6));

        try {
            $this->connection = DriverManager::getConnection(['url' => $this->dsn]);
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            self::markTestSkipped('PostgreSQL is not reachable: ' . $exception->getMessage());
        }
        $quotedSchema = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schema);
        $this->connection->executeStatement('CREATE SCHEMA ' . $quotedSchema);
        $this->connection->executeStatement('SET search_path TO ' . $quotedSchema);
        $this->entityManager = $this->entityManager($this->connection);
        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(HyperliquidTestnetExecutionAttempt::class),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->executeStatement(
                'DROP SCHEMA IF EXISTS ' . $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schema) . ' CASCADE',
            );
            $this->connection->close();
        }
        parent::tearDown();
    }

    #[DataProvider('concurrentClaims')]
    public function testConcurrentClaimWaitsThenFailsClosed(
        string $claimKey,
        string $claimFingerprint,
        string $claimClientOrderId,
        string $expectedOutcome,
    ): void {
        $heldKey = 'decision:btc:long';
        $heldFingerprint = str_repeat('a', 64);
        $this->connection->beginTransaction();
        $this->insertActive($this->connection, $heldKey, $heldFingerprint, 'CID-1');

        $process = new Process(
            [PHP_BINARY, __DIR__ . '/Fixtures/hyperliquid_attempt_claim.php'],
            null,
            [
                'HL012_TEST_DSN' => $this->dsn,
                'HL012_TEST_SCHEMA' => $this->schema,
                'HL012_TEST_KEY' => $claimKey,
                'HL012_TEST_FINGERPRINT' => $claimFingerprint,
                'HL012_TEST_CLIENT_ORDER_ID' => $claimClientOrderId,
            ],
        );
        $process->setTimeout(5.0);
        $process->start();

        try {
            $deadline = microtime(true) + 3.0;
            while (!str_contains($process->getErrorOutput(), 'ready') && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertStringContainsString('ready', $process->getErrorOutput());
            usleep(200_000);
            self::assertTrue($process->isRunning(), 'Concurrent claim must wait for the active transaction.');
            self::assertSame('', $process->getOutput());

            $this->connection->commit();
            $process->wait();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame($expectedOutcome, $process->getOutput());
            self::assertSame(1, (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM hyperliquid_testnet_execution_attempt WHERE active_slot = 1',
            ));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0.1);
            }
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function concurrentClaims(): iterable
    {
        yield 'same key' => [
            'decision:btc:long',
            str_repeat('a', 64),
            'CID-1',
            HyperliquidExecutionAttemptClaim::ACTIVE_REPLAY,
        ];
        yield 'different key' => [
            'decision:eth:long',
            str_repeat('b', 64),
            'CID-2',
            HyperliquidExecutionAttemptClaim::GLOBAL_ACTIVE,
        ];
    }

    private function entityManager(Connection $connection): EntityManagerInterface
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([
            dirname(__DIR__, 2) . '/src/Entity',
        ], true);

        return new EntityManager($connection, $configuration);
    }

    private function insertActive(Connection $connection, string $key, string $fingerprint, string $clientOrderId): void
    {
        $connection->executeStatement(
            <<<'SQL'
INSERT INTO hyperliquid_testnet_execution_attempt (
    idempotency_key, scope, active_slot, plan_fingerprint, client_order_id,
    correlation_id, state, result_payload, created_at, updated_at
) VALUES (?, 'hyperliquid_testnet', 1, ?, ?, 'corr-parent', 'reserved', NULL, NOW(), NOW())
SQL,
            [$key, $fingerprint, $clientOrderId],
        );
    }
}
