<?php

declare(strict_types=1);

namespace App\Tests\Runtime\Audit;

use App\Runtime\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditLoggerTest extends TestCase
{
    private AuditLogger $auditLogger;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->auditLogger = new AuditLogger($this->logger);
    }

    public function testLogAction(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'TEST_ACTION' &&
                       $data['entity'] === 'TEST_ENTITY' &&
                       $data['entity_id'] === '123' &&
                       $data['user_id'] === 'user123' &&
                       $data['ip_address'] === '192.168.1.1' &&
                       $data['data'] === ['key' => 'value'];
            }));

        $this->auditLogger->logAction(
            'TEST_ACTION',
            'TEST_ENTITY',
            '123',
            ['key' => 'value'],
            'user123',
            '192.168.1.1'
        );
    }

    public function testLogCreate(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'CREATE' &&
                       $data['entity'] === 'TEST_ENTITY' &&
                       $data['entity_id'] === '123';
            }));

        $this->auditLogger->logCreate('TEST_ENTITY', '123', ['key' => 'value'], 'user123');
    }

    public function testLogUpdate(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'UPDATE' &&
                       $data['entity'] === 'TEST_ENTITY' &&
                       $data['entity_id'] === '123' &&
                       isset($data['data']['old_data']) &&
                       isset($data['data']['new_data']);
            }));

        $this->auditLogger->logUpdate(
            'TEST_ENTITY',
            '123',
            ['old' => 'value'],
            ['new' => 'value'],
            'user123'
        );
    }

    public function testLogDelete(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'DELETE' &&
                       $data['entity'] === 'TEST_ENTITY' &&
                       $data['entity_id'] === '123';
            }));

        $this->auditLogger->logDelete('TEST_ENTITY', '123', ['key' => 'value'], 'user123');
    }

    public function testLogRead(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'READ' &&
                       $data['entity'] === 'TEST_ENTITY' &&
                       $data['entity_id'] === '123';
            }));

        $this->auditLogger->logRead('TEST_ENTITY', '123', ['key' => 'value'], 'user123');
    }

    public function testLogTradingAction(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'BUY' &&
                       $data['entity'] === 'TRADING' &&
                       $data['entity_id'] === 'order123' &&
                       $data['data']['symbol'] === 'BTCUSDT' &&
                       $data['data']['quantity'] === 0.001 &&
                       $data['data']['price'] === 50000.0;
            }));

        $this->auditLogger->logTradingAction(
            'BUY',
            'BTCUSDT',
            0.001,
            50000.0,
            'order123',
            'user123'
        );
    }

    public function testLogError(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'ERROR' &&
                       $data['entity'] === 'SYSTEM' &&
                       $data['entity_id'] === null &&
                       $data['data']['error'] === 'Test error' &&
                       $data['data']['context'] === ['key' => 'value'];
            }));

        $this->auditLogger->logError('Test error', ['key' => 'value'], 'user123');
    }

    public function testLogUserAccess(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'LOGIN' &&
                       $data['entity'] === 'USER_ACCESS' &&
                       $data['entity_id'] === 'user123' &&
                       $data['ip_address'] === '192.168.1.1';
            }));

        $this->auditLogger->logUserAccess('LOGIN', 'user123', '192.168.1.1');
    }

    public function testLogConfigChange(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'CONFIG_CHANGE' &&
                       $data['entity'] === 'CONFIGURATION' &&
                       $data['entity_id'] === 'test_config' &&
                       $data['data']['old_value'] === 'old' &&
                       $data['data']['new_value'] === 'new';
            }));

        $this->auditLogger->logConfigChange('test_config', 'old', 'new', 'user123');
    }

    public function testLogSecurityEvent(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Action d\'audit', $this->callback(function ($data) {
                return $data['action'] === 'SECURITY' &&
                       $data['entity'] === 'SECURITY_EVENT' &&
                       $data['entity_id'] === null &&
                       $data['data']['event'] === 'LOGIN_FAILED' &&
                       $data['data']['data'] === ['ip' => '192.168.1.1'];
            }));

        $this->auditLogger->logSecurityEvent('LOGIN_FAILED', ['ip' => '192.168.1.1'], 'user123');
    }

    public function testGetAuditLogs(): void
    {
        $result = $this->auditLogger->getAuditLogs('TEST_ENTITY', '123', 100);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAuditStats(): void
    {
        $result = $this->auditLogger->getAuditStats();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_actions', $result);
        $this->assertArrayHasKey('actions_by_type', $result);
        $this->assertArrayHasKey('actions_by_entity', $result);
        $this->assertArrayHasKey('recent_actions', $result);
    }
}
