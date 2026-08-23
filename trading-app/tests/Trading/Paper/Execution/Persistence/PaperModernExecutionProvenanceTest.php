<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperExecutionProvenance::class)]
#[CoversClass(PaperExecutionCell::class)]
#[CoversClass(PaperModernStrategyIdentity::class)]
final class PaperModernExecutionProvenanceTest extends TestCase
{
    private const SNAPSHOT_ID = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testModernCellProvenanceRoundTripsEveryExactIdentityDimension(): void
    {
        $cell = self::modernCell();
        $identity = $cell->modernIdentity;
        self::assertNotNull($identity);

        $provenance = $cell->provenance(PaperProfileEligibility::REFERENCE_ONLY);

        self::assertSame([
            'paper_network' => 'testnet',
            'market_data_venue' => 'hyperliquid',
            'paper_execution_cell_id' => $cell->id,
            'configuration_snapshot_id' => self::SNAPSHOT_ID,
            'paper_eligibility' => 'reference_only',
            'strategy_profile' => 'micro_scalping',
            'run_id' => 'modern-run-001',
            'exchange' => 'fake',
            'mode_id' => 'micro_scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'micro_scalping.momentum_ofi.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
            'config_hash' => $identity->configHash,
            'condition_catalog_hash' => $identity->conditionCatalogHash,
        ], $provenance);
        self::assertSame($provenance, PaperExecutionProvenance::validate($provenance));
    }

    public function testModernBaselineEligibleProvenanceRoundTripsWithoutChangingIdentity(): void
    {
        $cell = self::modernCell();
        $provenance = $cell->provenance(PaperProfileEligibility::BASELINE_ELIGIBLE);

        self::assertSame('baseline_eligible', $provenance['paper_eligibility']);
        self::assertSame($cell->id, $provenance['paper_execution_cell_id']);
        self::assertSame($provenance, PaperExecutionProvenance::validate($provenance));
    }

    public function testLegacyProvenanceRemainsByteCompatible(): void
    {
        $cell = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            'scalper_micro',
            'legacy-run-001',
        );

        self::assertSame([
            'paper_network' => 'testnet',
            'market_data_venue' => 'hyperliquid',
            'paper_execution_cell_id' => $cell->id,
            'configuration_snapshot_id' => self::SNAPSHOT_ID,
            'paper_eligibility' => 'reference_only',
            'strategy_profile' => 'scalper_micro',
            'run_id' => 'legacy-run-001',
            'exchange' => 'fake',
        ], $cell->provenance(PaperProfileEligibility::REFERENCE_ONLY));
    }

    public function testIncompleteMixedReorderedOrConflictingModernProvenanceFailsClosed(): void
    {
        $valid = self::modernCell()->provenance(PaperProfileEligibility::REFERENCE_ONLY);
        $reordered = $valid;
        ksort($reordered);
        $cases = [
            array_diff_key($valid, ['setup_version' => true]),
            array_replace($valid, ['unexpected' => 'value']),
            array_replace($valid, ['mode_id' => 'day_trading']),
            array_replace($valid, ['side' => 'LONG']),
            array_replace($valid, ['config_hash' => 'sha256:' . str_repeat('b', 64)]),
            $reordered,
        ];
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            'regular',
            'legacy-run-001',
        )->provenance(PaperProfileEligibility::REFERENCE_ONLY);
        $cases[] = $legacy + ['mode_id' => 'day_trading'];

        foreach ($cases as $case) {
            try {
                PaperExecutionProvenance::validate($case);
                self::fail('Invalid modern Paper provenance was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_execution_provenance_invalid', $exception->getMessage());
            }
        }
    }

    public function testExtractionRejectsEveryUnambiguousPartialModernIdentityMarker(): void
    {
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            'regular',
            'legacy-run-001',
        )->provenance(PaperProfileEligibility::REFERENCE_ONLY);

        foreach (['mode_id', 'mode_version', 'setup_id', 'setup_version', 'condition_catalog_hash'] as $marker) {
            try {
                PaperExecutionProvenance::extract($legacy + [$marker => 'partial-modern-value']);
                self::fail(sprintf('Partial modern marker %s was silently discarded.', $marker));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_execution_provenance_invalid', $exception->getMessage());
            }
        }
    }

    public function testExtractionPreservesLegacyPayloadsWithGenericSideAndConfigHashFields(): void
    {
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            'regular',
            'legacy-run-001',
        )->provenance(PaperProfileEligibility::REFERENCE_ONLY);

        self::assertSame($legacy, PaperExecutionProvenance::extract($legacy + [
            'side' => 1,
            'config_hash' => self::SNAPSHOT_ID,
        ]));
    }

    private static function modernCell(): PaperExecutionCell
    {
        $conditionHash = 'sha256:' . str_repeat('c', 64);
        $payload = ['decision' => ['enabled' => true]];
        $layers = [];
        foreach (['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'] as $type) {
            $layers[] = ['type' => $type, 'name' => $type, 'path' => $type . '.yaml', 'required' => true];
        }
        $snapshot = new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest(
                'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0',
                'hyperliquid', 'testnet', 'long', ShadowExecutionCapability::Paper,
            ),
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $conditionHash),
            $conditionHash,
            $layers,
            ['decision.enabled' => $layers[0]],
        );
        $identity = PaperModernStrategyIdentity::fromResolvedSnapshot(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $snapshot,
        );

        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            $identity,
            'modern-run-001',
        );
    }
}
