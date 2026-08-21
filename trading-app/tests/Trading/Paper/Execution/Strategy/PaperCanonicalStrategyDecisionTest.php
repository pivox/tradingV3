<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyDecision;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalStrategyDecision::class)]
final class PaperCanonicalStrategyDecisionTest extends TestCase
{
    public function testDecisionRebuildsAValidatedPreparedEffectWithDurableIdentity(): void
    {
        $fixture = PaperCanonicalPreparedEffectCodecTest::fixture();
        $prepared = PaperCanonicalStrategyDecision::fromPreparedEffect($fixture)->prepare(
            ['client_order_id' => 'paper-modern-cid-durable', 'order_intent_id' => 99],
            $fixture->provenance,
        );

        self::assertSame($fixture->plan->toArray(), $prepared->plan->toArray());
        self::assertSame($fixture->admissionProof->toArray(), $prepared->admissionProof->toArray());
        self::assertSame($fixture->reservation->stateHash, $prepared->reservation->stateHash);
        self::assertSame($fixture->lineage->toArray(), $prepared->lineage->toArray());
        self::assertSame(
            ['client_order_id' => 'paper-modern-cid-durable', 'order_intent_id' => 99],
            $prepared->orderIntentIdentity,
        );
    }

    public function testCanonicalStrategyBoundaryHasNoLegacyTradeEntryDependency(): void
    {
        foreach ([PaperCanonicalStrategyDecision::class, PaperCanonicalStrategyPreparationInterface::class] as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($path);
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString('PreparedTradeEntry', $source);
            self::assertStringNotContainsString('OrderPlanModel', $source);
        }
    }
}
