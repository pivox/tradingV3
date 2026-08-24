<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyInput;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyInputAssemblerInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceUnavailable;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparation;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationResult;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyRuntimeInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodec;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeOutcome;
use App\TradingCore\Shadow\ShadowRuntimeRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalStrategyPreparation::class)]
#[CoversClass(PaperCanonicalStrategyPreparationResult::class)]
final class PaperCanonicalStrategyPreparationTest extends TestCase
{
    private const SOURCE_BUILD_VERSION = 'paper-dataset-recorder.v2';

    public function testMissingEvidenceProducesNoDecisionWithoutRunningCanonicalRuntime(): void
    {
        $assembler = new RecordingCanonicalInputAssembler(null);
        $runtime = new RecordingCanonicalStrategyRuntime(self::noTradeOutcome());

        $result = (new PaperCanonicalStrategyPreparation($assembler, $runtime))->prepareFor(
            self::cell(),
            self::event(),
            'paper-dataset-001',
            str_repeat('a', 64),
            self::SOURCE_BUILD_VERSION,
        );

        self::assertSame('missing_evidence', $result->status);
        self::assertSame('paper_strategy_input_unavailable', $result->reasonCode);
        self::assertNull($result->decision);
        self::assertSame(0, $runtime->calls);
        self::assertSame('paper-dataset-001', $assembler->sourceDatasetId);
        self::assertSame(str_repeat('a', 64), $assembler->sourceEventsFileSha256);
        self::assertSame(self::SOURCE_BUILD_VERSION, $assembler->sourceBuildVersion);
    }

    public function testNoTradeOutcomeProducesNoPaperDecisionAndUsesExactCellPolicy(): void
    {
        $runtime = new RecordingCanonicalStrategyRuntime(self::noTradeOutcome());
        $preparation = new PaperCanonicalStrategyPreparation(
            new RecordingCanonicalInputAssembler(self::input()),
            $runtime,
        );

        $result = $preparation->prepareFor(
            self::cell(),
            self::event(),
            'paper-dataset-001',
            str_repeat('a', 64),
            self::SOURCE_BUILD_VERSION,
        );
        self::assertSame('no_trade', $result->status);
        self::assertSame('paper_canonical_strategy_setup_filter_failed', $result->reasonCode);
        self::assertNull($result->decision);
        self::assertSame(1, $runtime->calls);
        self::assertNotNull($runtime->policy);
        self::assertSame([[
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'scalping.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
        ]], $runtime->policy->identities);
        self::assertTrue($runtime->policy->requiresCanonicalOrderBook);
        self::assertFalse($runtime->policy->requiresCanonicalMicrostructure);
    }

    public function testExactEvidenceFailureIsPreservedWithoutRunningCanonicalRuntime(): void
    {
        $assembler = new class implements PaperCanonicalStrategyInputAssemblerInterface {
            public function assemble(
                PaperExecutionCell $cell,
                PaperMarketEvent $event,
                string $sourceDatasetId,
                string $sourceEventsFileSha256,
                string $sourceBuildVersion,
            ): ?PaperCanonicalStrategyInput {
                throw PaperCanonicalStrategyEvidenceUnavailable::orderBook();
            }
        };
        $runtime = new RecordingCanonicalStrategyRuntime(self::noTradeOutcome());

        $result = (new PaperCanonicalStrategyPreparation($assembler, $runtime))->prepareFor(
            self::cell(),
            self::event(),
            'paper-dataset-001',
            str_repeat('a', 64),
            self::SOURCE_BUILD_VERSION,
        );

        self::assertSame('missing_evidence', $result->status);
        self::assertSame('paper_order_book_unavailable', $result->reasonCode);
        self::assertNull($result->decision);
        self::assertSame(0, $runtime->calls);
    }

    public function testPlannedOutcomeBecomesVerifiedPaperDecision(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $legacyProof = CanonicalPortfolioAdmissionProof::fromRequest(
            new CanonicalPortfolioAdmissionRequest(
                $effect->admissionProof->policy,
                $effect->plan,
                $effect->admissionProof->scope,
                $effect->admissionProof->snapshot,
                $effect->decisionKey,
            ),
        );
        $outcome = new ShadowRuntimeOutcome(
            'planned',
            'paper_canonical_strategy_planned',
            $effect->lineage,
            $effect->plan,
            $effect->reservation,
            ['admission_proof' => $legacyProof->toArray()],
        );

        $result = (new PaperCanonicalStrategyPreparation(
            new RecordingCanonicalInputAssembler(self::input()),
            new RecordingCanonicalStrategyRuntime($outcome),
        ))->prepareFor(self::cell(), self::event(), 'paper-dataset-001', str_repeat('a', 64), self::SOURCE_BUILD_VERSION);

        self::assertSame('planned', $result->status);
        self::assertSame('paper_canonical_strategy_planned', $result->reasonCode);
        $decision = $result->decision;
        self::assertNotNull($decision);
        self::assertSame($effect->plan->planHash, $decision->plan->planHash);
        self::assertSame($effect->reservation->stateHash, $decision->reservation->stateHash);
        self::assertSame($effect->admissionProof->toArray(), $decision->admissionProof->toArray());
        self::assertSame('canonical-portfolio-admission-proof.v2', $decision->admissionProof->toArray()['schema']);
        self::assertSame($effect->lineage->toArray(), $decision->lineage->toArray());
        self::assertSame($effect->decisionKey, $decision->decisionKey);
        self::assertSame('5m', $decision->executionTimeframe);
        $codec = new PaperCanonicalPreparedEffectCodec();
        $prepared = $decision->prepare(
            ['client_order_id' => 'paper-preparation-review-cid', 'order_intent_id' => 77],
            $effect->provenance,
        );
        self::assertSame(
            'canonical-portfolio-admission-proof.v2',
            $codec->decode($codec->encode($prepared))->admissionProof->toArray()['schema'],
        );
    }

    public function testMicroScalpingRequiresBookAndAuthenticatedMicrostructure(): void
    {
        $runtime = new RecordingCanonicalStrategyRuntime(self::noTradeOutcome());
        (new PaperCanonicalStrategyPreparation(
            new RecordingCanonicalInputAssembler(self::input()),
            $runtime,
        ))->prepareFor(
            self::cell('micro_scalping', 'micro_scalping.momentum_ofi.short', 'short'),
            self::event(),
            'paper-dataset-001',
            str_repeat('a', 64),
            self::SOURCE_BUILD_VERSION,
        );

        self::assertNotNull($runtime->policy);
        self::assertTrue($runtime->policy->requiresCanonicalOrderBook);
        self::assertTrue($runtime->policy->requiresCanonicalMicrostructure);
        self::assertSame('micro_scalping.momentum_ofi.short', $runtime->policy->identities[0]['setup_id']);
        self::assertSame('short', $runtime->policy->identities[0]['side']);
    }

    public function testMalformedPlannedOutcomeFailsClosed(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $outcome = new ShadowRuntimeOutcome(
            'planned',
            'paper_canonical_strategy_planned',
            $effect->lineage,
            $effect->plan,
            $effect->reservation,
            [],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_outcome_invalid');
        (new PaperCanonicalStrategyPreparation(
            new RecordingCanonicalInputAssembler(self::input()),
            new RecordingCanonicalStrategyRuntime($outcome),
        ))->prepareFor(self::cell(), self::event(), 'paper-dataset-001', str_repeat('a', 64), self::SOURCE_BUILD_VERSION);
    }

    public function testPreparationResultRejectsUnboundedReasonCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_preparation_result_invalid');

        PaperCanonicalStrategyPreparationResult::missingEvidence('Not Canonical');
    }

    private static function input(): PaperCanonicalStrategyInput
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $reflection = new \ReflectionClass(ShadowRuntimeRequest::class);
        /** @var ShadowRuntimeRequest $request */
        $request = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('lineage')->setValue($request, $effect->lineage);
        $reflection->getProperty('decisionKey')->setValue($request, $effect->decisionKey);
        $reflection->getProperty('portfolioScope')->setValue($request, $effect->admissionProof->scope);
        $reflection->getProperty('portfolioSnapshot')->setValue($request, $effect->admissionProof->snapshot);

        return new PaperCanonicalStrategyInput($request, '5m');
    }

    private static function noTradeOutcome(): ShadowRuntimeOutcome
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();

        return new ShadowRuntimeOutcome(
            'no_trade',
            'paper_canonical_strategy_setup_filter_failed',
            $effect->lineage,
            null,
            null,
            [],
        );
    }

    private static function cell(
        string $modeId = 'scalping',
        string $setupId = 'scalping.trend_continuation.long',
        string $side = 'long',
    ): PaperExecutionCell
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();

        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $effect->provenance['configuration_snapshot_id'],
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                $modeId,
                $effect->provenance['mode_version'],
                $setupId,
                $effect->provenance['setup_version'],
                $side,
                $effect->provenance['config_hash'],
                $effect->provenance['condition_catalog_hash'],
            ),
            $effect->provenance['run_id'],
        );
    }

    private static function event(): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_5M,
            new \DateTimeImmutable('2026-08-10T12:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            '1',
            ['confirmed' => true],
        );
    }
}

final class RecordingCanonicalInputAssembler implements PaperCanonicalStrategyInputAssemblerInterface
{
    public ?string $sourceDatasetId = null;
    public ?string $sourceEventsFileSha256 = null;
    public ?string $sourceBuildVersion = null;

    public function __construct(private readonly ?PaperCanonicalStrategyInput $input)
    {
    }

    public function assemble(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        string $sourceDatasetId,
        string $sourceEventsFileSha256,
        string $sourceBuildVersion,
    ): ?PaperCanonicalStrategyInput {
        $this->sourceDatasetId = $sourceDatasetId;
        $this->sourceEventsFileSha256 = $sourceEventsFileSha256;
        $this->sourceBuildVersion = $sourceBuildVersion;

        return $this->input;
    }
}

final class RecordingCanonicalStrategyRuntime implements PaperCanonicalStrategyRuntimeInterface
{
    public int $calls = 0;
    public ?ShadowRuntimeIdentityPolicy $policy = null;

    public function __construct(private readonly ShadowRuntimeOutcome $outcome)
    {
    }

    public function run(
        ShadowRuntimeRequest $request,
        ShadowRuntimeIdentityPolicy $policy,
    ): ShadowRuntimeOutcome {
        ++$this->calls;
        $this->policy = $policy;

        return $this->outcome;
    }
}
