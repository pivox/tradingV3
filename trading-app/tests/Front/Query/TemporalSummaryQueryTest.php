<?php

declare(strict_types=1);

namespace App\Tests\Front\Query;

use App\Front\Query\TemporalSummaryQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemporalSummaryQuery::class)]
final class TemporalSummaryQueryTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/tradingv3-temporal-test-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/config', 0777, true);

        file_put_contents($this->projectDir . '/config/mtf.yaml', <<<'YAML'
mtf:
  temporal:
    address: '%env(TEMPORAL_ADDRESS)%'
    namespace: '%env(TEMPORAL_NAMESPACE)%'
    task_queue: 'mtf_minute_queue'
    workflow_id: 'mtf-minute-workflow'
YAML);

        file_put_contents($this->projectDir . '/docker-compose.yml', <<<'YAML'
services:
  temporal:
    image: temporalio/auto-setup:1.25
    container_name: temporal_server
    ports:
      - "7233:7233"
    environment:
      - ENABLE_HTTP_API=true
  temporal-ui:
    image: temporalio/ui:2.39.0
    container_name: temporal_ui
    ports:
      - "8233:8080"
    environment:
      - TEMPORAL_ADDRESS=temporal:7233
  cron-symfony-mtf-workers:
    container_name: cron_symfony_mtf_workers
    environment:
      - TEMPORAL_ADDRESS=temporal:7233
      - TASK_QUEUE_NAME=cron_symfony_mtf_workers
      - MTF_WORKERS_COUNT=4
YAML);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/config/mtf.yaml');
        @unlink($this->projectDir . '/docker-compose.yml');
        @rmdir($this->projectDir . '/config');
        @rmdir($this->projectDir);
    }

    public function testSummaryExtractsTemporalConfigurationAndOperations(): void
    {
        $summary = (new TemporalSummaryQuery($this->projectDir))->summary();

        self::assertSame('%env(TEMPORAL_ADDRESS)%', $summary['cluster']['address']);
        self::assertSame('%env(TEMPORAL_NAMESPACE)%', $summary['cluster']['namespace']);
        self::assertSame('mtf_minute_queue', $summary['cluster']['task_queue']);
        self::assertSame('mtf-minute-workflow', $summary['cluster']['workflow_id']);
        self::assertSame('http://localhost:8233', $summary['ui']['url']);

        self::assertArrayHasKey('temporal', $summary['services']);
        self::assertSame('temporal_server', $summary['services']['temporal']['container_name']);
        self::assertSame('temporal_ui', $summary['services']['temporal-ui']['container_name']);

        self::assertContains('mtf_minute_queue', $summary['task_queues']);
        self::assertContains('cron_symfony_mtf_workers', $summary['task_queues']);
        self::assertSame('4', $summary['workers'][0]['workers_count']);

        self::assertNotEmpty($summary['admin_commands']);
        self::assertStringContainsString('temporal operator cluster health', $summary['admin_commands'][0]['command']);
        self::assertStringContainsString('--namespace default', $summary['admin_commands'][2]['command']);
        self::assertStringNotContainsString('%env(TEMPORAL_NAMESPACE)%', $summary['admin_commands'][2]['command']);
    }
}
