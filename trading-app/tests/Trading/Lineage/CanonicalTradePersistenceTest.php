<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderIntent::class)]
#[CoversClass(TradeLifecycleEvent::class)]
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

    private function identity(string $setup, string $side, string $hash): LineageContext
    {
        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator', 'orchestration_run_id' => 'run-' . $hash,
            'correlation_run_id' => 'corr-' . $hash, 'orchestration_set_id' => 'set-' . $hash,
            'mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => $setup,
            'setup_version' => '1.0.0', 'config_hash' => 'sha256:' . str_repeat($hash, 64),
            'condition_catalog_hash' => 'sha256:' . str_repeat('b', 64), 'side' => $side,
            'exchange' => 'fake', 'market_type' => 'perpetual', 'symbol' => 'BTCUSDT',
            'effective_config_reference' => 'cfg://scalping/1.0.0',
            'effective_config_snapshot' => [
                'request' => [
                    'mode_id' => 'scalping', 'mode_version' => '1.0.0',
                    'setup_id' => $setup, 'setup_version' => '1.0.0',
                    'exchange' => 'fake', 'environment' => 'test', 'side' => strtolower($side),
                ],
                'config_hash' => 'sha256:' . str_repeat($hash, 64),
                'condition_catalog_hash' => 'sha256:' . str_repeat('b', 64),
                'executable' => true,
            ],
            'decision_id' => $hash === 'a' ? '018f47a2-4f42-7e1b-8d3a-4dc9571bb11b' : '018f47a2-4f42-7e1b-8d3a-4dc9571bb22c',
            'decision_key' => 'decision-' . $hash,
        ]);
    }
}
