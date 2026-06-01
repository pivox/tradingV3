<?php

declare(strict_types=1);

namespace App\Front\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final class SystemHealthQuery
{
    private readonly FrontDatabase $db;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly string $projectDir,
    ) {
        $this->db = new FrontDatabase($connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [
            'generated_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'checks' => $this->checks(),
            'queues' => $this->queues(),
            'workers' => $this->workers(),
            'logs' => $this->logs(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checks(): array
    {
        $checks = [];

        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $checks[] = ['name' => 'Postgres/DBAL', 'status' => 'ok', 'detail' => 'Connexion disponible'];
        } catch (\Throwable $exception) {
            $checks[] = ['name' => 'Postgres/DBAL', 'status' => 'critical', 'detail' => $exception->getMessage()];
        }

        foreach (['mtf_run', 'mtf_run_symbol', 'positions', 'futures_order', 'symbol_execution_lock', 'messenger_messages'] as $table) {
            $checks[] = [
                'name' => $table,
                'status' => $this->db->tableExists($table) ? 'ok' : 'warning',
                'detail' => $this->db->tableExists($table) ? 'table presente' : 'table absente',
            ];
        }

        return $checks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queues(): array
    {
        return $this->db->fetchAll(
            ['messenger_messages'],
            "SELECT queue_name, COUNT(*) AS message_count
             FROM messenger_messages
             GROUP BY queue_name
             ORDER BY queue_name ASC",
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workers(): array
    {
        return [
            ['name' => 'trading-app-messenger-trading', 'transport' => 'mtf_decision', 'status' => 'a_verifier'],
            ['name' => 'trading-app-messenger-order-timeout', 'transport' => 'order_timeout', 'status' => 'a_verifier'],
            ['name' => 'trading-app-messenger-projection', 'transport' => 'mtf_projection', 'status' => 'a_verifier'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function logs(): array
    {
        $files = glob($this->projectDir . '/var/log/*.log') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_map(
            fn (string $file): array => [
                'path' => str_replace($this->projectDir . '/', '', $file),
                'name' => basename($file),
                'updated_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
                'size_kb' => round(filesize($file) / 1024, 1),
            ],
            array_slice($files, 0, 12),
        );
    }
}
