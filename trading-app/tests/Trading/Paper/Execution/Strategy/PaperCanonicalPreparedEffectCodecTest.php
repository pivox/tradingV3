<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodec;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperCanonicalPreparedEffect::class)]
#[CoversClass(PaperCanonicalPreparedEffectCodec::class)]
final class PaperCanonicalPreparedEffectCodecTest extends TestCase
{
    public function testCanonicalPreparedEffectRoundTripsEveryAuthenticatedBoundary(): void
    {
        $effect = self::fixture();
        $codec = new PaperCanonicalPreparedEffectCodec();

        $encoded = $codec->encode($effect);
        $decoded = $codec->decode($encoded);

        self::assertSame('paper-canonical-prepared-effect.v1', $encoded['schema_version']);
        self::assertSame($effect->plan->toArray(), $decoded->plan->toArray());
        self::assertSame($effect->admissionProof->toArray(), $decoded->admissionProof->toArray());
        self::assertSame($effect->reservation->stateHash, $decoded->reservation->stateHash);
        self::assertSame($effect->lineage->toArray(), $decoded->lineage->toArray());
        self::assertSame($effect->decisionKey, $decoded->decisionKey);
        self::assertSame($effect->executionTimeframe, $decoded->executionTimeframe);
        self::assertSame($effect->orderIntentIdentity, $decoded->orderIntentIdentity);
        self::assertSame($effect->provenance, $decoded->provenance);
    }

    public function testTamperedOrCrossBoundCanonicalPreparedEffectFailsWithOneStableReason(): void
    {
        $encoded = (new PaperCanonicalPreparedEffectCodec())->encode(self::fixture());
        $reordered = $encoded;
        $reordered['payload'] = array_reverse($reordered['payload'], true);
        self::rehash($reordered);
        $cases = [
            array_replace($encoded, ['schema_version' => 'paper-canonical-prepared-effect.v2']),
            array_replace($encoded, ['payload_checksum' => str_repeat('0', 64)]),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['decision_key'] = 'another-decision';
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['execution_timeframe'] = '1m';
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['cell_provenance']['run_id'] = 'another-run';
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['plan']['exchange'] = 'okx';
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['admission_proof']['policy']['max_concurrent_positions'] = 99;
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['admission_proof']['scope']['account_id'] = 'paper:cell:v2:' . str_repeat('f', 64);
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['admission_proof']['scope']['network'] = 'mainnet';
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['lineage']['unexpected'] = true;
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['lineage'] = array_reverse($payload['lineage'], true);
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['order_intent_identity']['order_intent_id'] = 0;
            }),
            self::mutate($encoded, static function (array &$payload): void {
                $payload['unexpected'] = true;
            }),
            $reordered,
        ];

        foreach ($cases as $case) {
            try {
                (new PaperCanonicalPreparedEffectCodec())->decode($case);
                self::fail('Tampered canonical Paper effect was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_canonical_prepared_effect_payload_invalid', $exception->getMessage());
            }
        }
    }

    public function testCanonicalPreparedEffectHasNoLegacyTradeEntryDependency(): void
    {
        foreach ([PaperCanonicalPreparedEffect::class, PaperCanonicalPreparedEffectCodec::class] as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($path);
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString('PreparedTradeEntry', $source);
            self::assertStringNotContainsString('OrderPlanModel', $source);
        }
    }

    public static function fixture(): PaperCanonicalPreparedEffect
    {
        $effective = self::effectiveConfig();
        $identity = PaperModernStrategyIdentity::fromResolvedSnapshot(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $effective,
        );
        $cell = PaperExecutionCell::createModern(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('a', 64),
            $identity,
            'paper-modern-run-001',
        );
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $executionPolicy = (new CanonicalExecutionPolicyCompiler())->compile($effective);
        $components = CanonicalOrderPlanPipelineFixture::accepted(
            executionPolicy: $executionPolicy,
            exchange: 'hyperliquid',
            environment: 'testnet',
        );
        $components['orderBook'] = new CanonicalOrderBookSnapshot(
            'hyperliquid',
            'testnet',
            'BTCUSDT',
            'perpetual',
            'order_book',
            100.095,
            100.105,
            0.9990009990004878,
            new \DateTimeImmutable('2026-08-10T11:59:45+00:00'),
            'sha256:' . str_repeat('b', 64),
        );
        $plan = (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
        $scope = new CanonicalPortfolioScope(
            'testnet',
            'hyperliquid',
            'testnet',
            $cell->accountNamespace,
            'scalping',
            'USDT',
        );
        $portfolio = new CanonicalPortfolioSnapshot(
            $scope,
            'paper_replay',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            0.0,
            0.0,
            0,
            0,
            0.0,
            0.0,
            0.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );
        $decisionKey = 'paper-modern-decision-001';
        $portfolioPolicy = CanonicalPortfolioPolicy::fromSnapshot($effective);
        $admission = new CanonicalPortfolioAdmissionRequest(
            $portfolioPolicy,
            $plan,
            $scope,
            $portfolio,
            $decisionKey,
        );
        $reservation = CanonicalPortfolioReservation::open(
            (new CanonicalPortfolioAdmissionEngine($clock))->admit($admission),
            $plan,
        );
        $proof = CanonicalPortfolioAdmissionProof::fromReservation($admission, $reservation);
        $lineage = self::lineage($effective, $decisionKey);

        return new PaperCanonicalPreparedEffect(
            $plan,
            $proof,
            $reservation,
            $lineage,
            $decisionKey,
            '5m',
            ['client_order_id' => 'paper-modern-cid-001', 'order_intent_id' => 42],
            $cell->provenance(PaperProfileEligibility::REFERENCE_ONLY),
        );
    }

    private static function effectiveConfig(): EffectiveTradingConfigSnapshot
    {
        return (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping',
            '1.1.0',
            'scalping.trend_continuation.long',
            '1.1.0',
            'hyperliquid',
            'testnet',
            'long',
            ShadowExecutionCapability::Paper,
        ));
    }

    private static function lineage(EffectiveTradingConfigSnapshot $snapshot, string $decisionKey): LineageContext
    {
        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'paper-modern-run-001',
            'orchestration_set_id' => 'paper-modern-set-001',
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'scalping.trend_continuation.long',
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => 'LONG',
            'exchange' => 'hyperliquid',
            'environment' => 'testnet',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_id' => '018f5f6d-8f4a-7abc-8def-0123456789ab',
            'decision_key' => $decisionKey,
            'dry_run' => true,
            'effective_config_reference' => 'effective-config-snapshot:' . $snapshot->toArray()['snapshot_hash'],
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
    }

    /** @param array<string, mixed> $encoded */
    private static function rehash(array &$encoded): void
    {
        $encoded['payload_checksum'] = hash('sha256', CanonicalJson::encode($encoded['payload']));
    }

    /**
     * @param array<string, mixed> $encoded
     * @param callable(array<string, mixed>&): void $mutation
     * @return array<string, mixed>
     */
    private static function mutate(array $encoded, callable $mutation): array
    {
        $mutation($encoded['payload']);
        self::rehash($encoded);

        return $encoded;
    }
}
