<?php

declare(strict_types=1);

namespace App\Tests\Runtime\Concurrency;

use App\Runtime\Concurrency\FeatureSwitch;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeatureSwitchTest extends TestCase
{
    private FeatureSwitch $featureSwitch;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->featureSwitch = new FeatureSwitch($this->logger);
    }

    public function testEnable(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Commutateur activé', $this->callback(function ($data) {
                return $data['switch'] === 'test_switch' &&
                       $data['reason'] === 'test reason';
            }));

        $this->featureSwitch->enable('test_switch', 'test reason');
        $this->assertTrue($this->featureSwitch->isEnabled('test_switch'));
    }

    public function testDisable(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Commutateur désactivé', $this->callback(function ($data) {
                return $data['switch'] === 'test_switch' &&
                       $data['reason'] === 'test reason';
            }));

        $this->featureSwitch->disable('test_switch', 'test reason');
        $this->assertTrue($this->featureSwitch->isDisabled('test_switch'));
    }

    public function testIsEnabled(): void
    {
        $this->featureSwitch->enable('test_switch');
        $this->assertTrue($this->featureSwitch->isEnabled('test_switch'));
        $this->assertFalse($this->featureSwitch->isEnabled('unknown_switch'));
    }

    public function testIsDisabled(): void
    {
        $this->featureSwitch->disable('test_switch');
        $this->assertTrue($this->featureSwitch->isDisabled('test_switch'));
        $this->assertFalse($this->featureSwitch->isDisabled('unknown_switch'));
    }

    public function testToggle(): void
    {
        // Test toggle from disabled to enabled
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Commutateur activé', $this->isType('array'));

        $result = $this->featureSwitch->toggle('test_switch', 'test reason');
        $this->assertTrue($result);
        $this->assertTrue($this->featureSwitch->isEnabled('test_switch'));

        // Test toggle from enabled to disabled
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Commutateur désactivé', $this->isType('array'));

        $result = $this->featureSwitch->toggle('test_switch', 'test reason');
        $this->assertFalse($result);
        $this->assertTrue($this->featureSwitch->isDisabled('test_switch'));
    }

    public function testSetDefaultState(): void
    {
        $this->featureSwitch->setDefaultState('test_switch', true);
        $this->assertTrue($this->featureSwitch->isEnabled('test_switch'));

        $this->featureSwitch->setDefaultState('test_switch2', false);
        $this->assertTrue($this->featureSwitch->isDisabled('test_switch2'));
    }

    public function testReset(): void
    {
        $this->featureSwitch->setDefaultState('test_switch', false);
        $this->featureSwitch->enable('test_switch');
        $this->assertTrue($this->featureSwitch->isEnabled('test_switch'));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Commutateur réinitialisé', $this->isType('array'));

        $this->featureSwitch->reset('test_switch');
        $this->assertTrue($this->featureSwitch->isDisabled('test_switch'));
    }

    public function testResetAll(): void
    {
        $this->featureSwitch->enable('switch1');
        $this->featureSwitch->enable('switch2');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Tous les commutateurs ont été réinitialisés');

        $this->featureSwitch->resetAll();
        $this->assertTrue($this->featureSwitch->isDisabled('switch1'));
        $this->assertTrue($this->featureSwitch->isDisabled('switch2'));
    }

    public function testGetState(): void
    {
        $this->assertNull($this->featureSwitch->getState('unknown_switch'));

        $this->featureSwitch->enable('test_switch');
        $this->assertTrue($this->featureSwitch->getState('test_switch'));

        $this->featureSwitch->disable('test_switch');
        $this->assertFalse($this->featureSwitch->getState('test_switch'));
    }

    public function testGetAllSwitches(): void
    {
        $this->featureSwitch->enable('switch1');
        $this->featureSwitch->setDefaultState('switch2', true);

        $switches = $this->featureSwitch->getAllSwitches();

        $this->assertArrayHasKey('switch1', $switches);
        $this->assertArrayHasKey('switch2', $switches);
        $this->assertTrue($switches['switch1']['enabled']);
        $this->assertFalse($switches['switch1']['is_default']);
        $this->assertTrue($switches['switch2']['enabled']);
        $this->assertTrue($switches['switch2']['is_default']);
    }

    public function testExecuteIfEnabled(): void
    {
        $this->featureSwitch->enable('test_switch');
        
        $callback = $this->createMock(\stdClass::class);
        $callback->expects($this->once())
            ->method('__invoke')
            ->willReturn('success');

        $result = $this->featureSwitch->executeIfEnabled('test_switch', $callback, 'default');
        $this->assertEquals('success', $result);
    }

    public function testExecuteIfEnabledWithDisabledSwitch(): void
    {
        $this->featureSwitch->disable('test_switch');
        
        $callback = $this->createMock(\stdClass::class);
        $callback->expects($this->never())
            ->method('__invoke');

        $result = $this->featureSwitch->executeIfEnabled('test_switch', $callback, 'default');
        $this->assertEquals('default', $result);
    }

    public function testExecuteIfDisabled(): void
    {
        $this->featureSwitch->disable('test_switch');
        
        $callback = $this->createMock(\stdClass::class);
        $callback->expects($this->once())
            ->method('__invoke')
            ->willReturn('success');

        $result = $this->featureSwitch->executeIfDisabled('test_switch', $callback, 'default');
        $this->assertEquals('success', $result);
    }

    public function testExecuteIfDisabledWithEnabledSwitch(): void
    {
        $this->featureSwitch->enable('test_switch');
        
        $callback = $this->createMock(\stdClass::class);
        $callback->expects($this->never())
            ->method('__invoke');

        $result = $this->featureSwitch->executeIfDisabled('test_switch', $callback, 'default');
        $this->assertEquals('default', $result);
    }

    public function testGetStats(): void
    {
        $this->featureSwitch->enable('switch1');
        $this->featureSwitch->disable('switch2');
        $this->featureSwitch->setDefaultState('switch3', true);

        $stats = $this->featureSwitch->getStats();

        $this->assertArrayHasKey('total_switches', $stats);
        $this->assertArrayHasKey('enabled', $stats);
        $this->assertArrayHasKey('disabled', $stats);
        $this->assertArrayHasKey('switches', $stats);
        $this->assertIsArray($stats['switches']);
    }
}
