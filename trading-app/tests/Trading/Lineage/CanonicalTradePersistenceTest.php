<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Service\OrderIntentManager;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderIntent::class)]
#[CoversClass(TradeLifecycleEvent::class)]
#[CoversClass(OrderIntentManager::class)]
final class CanonicalTradePersistenceTest extends TestCase
{
    public function testIntentAndLifecycleKeepSeparateSameSymbolContracts(): void
    {
        $long = $this->identity('scalping.trend_continuation.long', 'LONG', 'a');
        $pullback = $this->identity('scalping.pullback.long', 'LONG', 'c');

        $intentA = (new OrderIntent())->applyLineageContext($long);
        $intentB = (new OrderIntent())->applyLineageContext($pullback);
        $eventA = (new TradeLifecycleEvent('BTCUSDT', 'order_submitted'))->applyLineageContext($long);
        $eventB = (new TradeLifecycleEvent('BTCUSDT', 'order_submitted'))->applyLineageContext($pullback);

        self::assertSame('scalping.trend_continuation.long', $intentA->getSetupId());
        self::assertSame('scalping.pullback.long', $intentB->getSetupId());
        self::assertSame($long->decisionId, $eventA->getDecisionId());
        self::assertSame($pullback->decisionId, $eventB->getDecisionId());
        self::assertNotSame($eventA->getConfigHash(), $eventB->getConfigHash());
    }

    public function testLegacyRowsRemainExplicitlyIncomplete(): void
    {
        self::assertFalse((new OrderIntent())->hasCompleteCanonicalIdentity());
        self::assertFalse((new TradeLifecycleEvent('BTCUSDT', 'legacy'))->hasCompleteCanonicalIdentity());
    }

    public function testRetryRejectsChangedFinalOrderDimensions(): void
    {
        $context = $this->identity('scalping.trend_continuation.long', 'LONG', 'a');
        $manager = (new \ReflectionClass(OrderIntentManager::class))->newInstanceWithoutConstructor();
        $params = [
            'exchange' => 'fake', 'market_type' => 'perpetual', 'symbol' => 'BTCUSDT',
            'side' => 1, 'type' => 'limit', 'open_type' => 'isolated', 'leverage' => 5,
            'position_mode' => 'one_way', 'price' => '100.25', 'size' => 10,
            'client_order_id' => 'client-a', 'preset_mode' => 'none',
            'preset_stop_loss_price' => '99.00',
        ];
        $build = new \ReflectionMethod(OrderIntentManager::class, 'buildIntent');
        /** @var OrderIntent $existing */
        $existing = $build->invoke($manager, $params, ['price' => '100.25'], ['source' => 'test'], $context);
        $existing->setSize(11);

        $assert = new \ReflectionMethod(OrderIntentManager::class, 'assertReplayIdentity');
        $this->expectExceptionMessage('canonical_identity_mismatch:size');
        $assert->invoke($manager, $existing, $context, $params, ['price' => '100.25'], ['source' => 'test']);
    }

    public function testRetryRejectsChangedEffectiveSnapshot(): void
    {
        $context = $this->identity('scalping.trend_continuation.long', 'LONG', 'a');
        $changed = $this->identity('scalping.trend_continuation.long', 'LONG', 'z');
        $manager = (new \ReflectionClass(OrderIntentManager::class))->newInstanceWithoutConstructor();
        $params = ['exchange' => 'fake', 'market_type' => 'perpetual', 'symbol' => 'BTCUSDT', 'side' => 1, 'type' => 'market', 'size' => 1];
        $build = new \ReflectionMethod(OrderIntentManager::class, 'buildIntent');
        /** @var OrderIntent $existing */
        $existing = $build->invoke($manager, $params, [], null, $context);

        $assert = new \ReflectionMethod(OrderIntentManager::class, 'assertReplayIdentity');
        $this->expectExceptionMessage('canonical_identity_mismatch:config_hash');
        $assert->invoke($manager, $existing, $changed, $params, [], null);
    }

    private function identity(string $setup, string $side, string $hash): LineageContext
    {
        $config = ['fixture' => $hash, 'trade_entry' => ['defaults' => [], 'entry' => [], 'risk' => [], 'leverage' => [], 'decision' => [], 'fees' => []]];
        $catalogHash = 'sha256:' . str_repeat('b', 64);
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, $catalogHash);
        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator', 'orchestration_run_id' => 'run-' . $hash,
            'correlation_run_id' => 'corr-' . $hash, 'orchestration_set_id' => 'set-' . $hash,
            'mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => $setup,
            'setup_version' => '1.0.0', 'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash, 'side' => $side,
            'exchange' => 'fake', 'market_type' => 'perpetual', 'symbol' => 'BTCUSDT',
            'effective_config_reference' => 'cfg://scalping/1.0.0',
            'effective_config_snapshot' => [
                'request' => [
                    'mode_id' => 'scalping', 'mode_version' => '1.0.0',
                    'setup_id' => $setup, 'setup_version' => '1.0.0',
                    'exchange' => 'fake', 'environment' => 'test', 'side' => strtolower($side),
                ],
                'config' => $config,
                'config_hash' => $configHash,
                'condition_catalog_hash' => $catalogHash,
                'executable' => true,
                'blockers' => [],
            ],
            'decision_id' => $hash === 'a' ? '018f47a2-4f42-7e1b-8d3a-4dc9571bb11b' : '018f47a2-4f42-7e1b-8d3a-4dc9571bb22c',
            'decision_key' => 'decision-' . $hash,
            'intent_id' => 'intent-' . $hash,
            'position_id' => 'position-' . $hash,
            'trade_id' => 'trade-' . $hash,
        ]);
    }
}
