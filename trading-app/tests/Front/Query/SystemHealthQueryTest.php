<?php

declare(strict_types=1);

namespace App\Tests\Front\Query;

use App\Front\Query\SystemHealthQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SystemHealthQuery::class)]
final class SystemHealthQueryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['messenger_messages_order_timeout', 'messenger_messages_mtf_projection', 'messenger_messages_mtf_decision', 'messenger_messages_failed'] as $table) {
            $this->connection->executeStatement(sprintf(
                'CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT, queue_name VARCHAR(190) NOT NULL)',
                $table,
            ));
        }
    }

    public function testQueuesReadConfiguredMessengerTransportTables(): void
    {
        $this->connection->insert('messenger_messages_mtf_decision', ['queue_name' => 'mtf_decision']);
        $this->connection->insert('messenger_messages_mtf_decision', ['queue_name' => 'mtf_decision']);
        $this->connection->insert('messenger_messages_order_timeout', ['queue_name' => 'order_timeout']);

        $health = (new SystemHealthQuery(
            $this->connection,
            new MockClock('2026-06-01 10:00:00 UTC'),
            sys_get_temp_dir(),
        ))->health();

        self::assertContains([
            'queue_name' => 'mtf_decision',
            'message_count' => 2,
            'table_name' => 'messenger_messages_mtf_decision',
        ], $health['queues']);
        self::assertContains([
            'queue_name' => 'order_timeout',
            'message_count' => 1,
            'table_name' => 'messenger_messages_order_timeout',
        ], $health['queues']);
    }
}
