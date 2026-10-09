<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Research\ResearchCostAssumptions;
use App\TradingCore\Backtesting\Research\ResearchInstrumentAssumptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchInstrumentAssumptions::class)]
#[CoversClass(ResearchCostAssumptions::class)]
final class ResearchAssumptionsTest extends TestCase
{
    /** @var list<string> */
    private const SYMBOLS = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'DOGEUSDT', 'SOLUSDT', 'LTCUSDT', 'LINKUSDT', 'AVAXUSDT'];

    public function testRequiresExactlyTenExplicitResearchInstrumentsAndHash(): void
    {
        $manifest = self::instruments();
        $assumptions = ResearchInstrumentAssumptions::fromArray($manifest);
        self::assertSame(10, count($assumptions->symbols));
        self::assertSame('current_metadata_not_historical', $assumptions->status);
        self::assertSame(0.1, $assumptions->forSymbol('BTCUSDT')['tick_size']);

        unset($manifest['symbols'][0]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_instrument_manifest_invalid');
        ResearchInstrumentAssumptions::fromArray($manifest);
    }

    public function testRejectsTamperedInstrumentManifestHash(): void
    {
        $manifest = self::instruments();
        $manifest['symbols'][0]['tick_size'] = 0.2;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_instrument_hash_invalid');
        ResearchInstrumentAssumptions::fromArray($manifest);
    }

    public function testRejectsMissingMmrProxyEvenWithRecomputedHash(): void
    {
        $manifest = self::instruments();
        unset($manifest['symbols'][0]['mmr_proxy_rate']);
        $manifest['manifest_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($manifest, ['manifest_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_instrument_manifest_invalid');
        ResearchInstrumentAssumptions::fromArray($manifest);
    }

    public function testRejectsNegativeCostRate(): void
    {
        $manifest = self::costs();
        $manifest['profiles']['baseline']['entry_spread_rate'] = -0.1;
        $manifest['assumption_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($manifest, ['assumption_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_cost_assumptions_invalid');
        ResearchCostAssumptions::fromArray($manifest);
    }

    public function testRejectsCostAssumptionsFrozenAfterSourceCutoff(): void
    {
        $manifest = self::costs();
        $manifest['frozen_at'] = '2027-01-01T00:00:00Z';
        $manifest['assumption_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($manifest, ['assumption_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_cost_assumptions_invalid');
        ResearchCostAssumptions::fromArray($manifest);
    }

    public function testCostProfileObjectOrderDoesNotChangeMeaningOrHash(): void
    {
        $manifest = self::costs();
        $expected = ResearchCostAssumptions::fromArray($manifest);
        $manifest['profiles'] = ['adverse' => $manifest['profiles']['adverse'], 'baseline' => $manifest['profiles']['baseline']];
        $actual = ResearchCostAssumptions::fromArray($manifest);
        self::assertSame($expected->hash, $actual->hash);
        self::assertSame($expected->profile('baseline'), $actual->profile('baseline'));
        self::assertSame($expected->profile('adverse'), $actual->profile('adverse'));
    }

    public function testUnknownCostProfileStillRejectsWithRecomputedHash(): void
    {
        $manifest = self::costs();
        $manifest['profiles']['extra'] = $manifest['profiles']['baseline'];
        $manifest['assumption_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($manifest, ['assumption_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_cost_assumptions_invalid');
        ResearchCostAssumptions::fromArray($manifest);
    }

    /** @return array<string, mixed> */
    public static function instruments(): array
    {
        $symbols = [];
        foreach (self::SYMBOLS as $symbol) {
            $symbols[] = ['symbol' => $symbol, 'tick_size' => 0.1, 'quantity_step' => 0.001,
                'min_quantity' => 0.001, 'max_quantity' => 100.0, 'min_notional' => 5.0,
                'contract_size' => 1.0, 'leverage_cap' => 2.0, 'mmr_proxy_rate' => 0.005,
                'liquidation_fee_rate' => 0.001];
        }
        $manifest = ['schema_version' => 'research-instrument-assumptions.v1',
            'source_url' => 'https://fapi.binance.com/fapi/v1/exchangeInfo',
            'retrieved_at' => '2026-10-09T06:00:00Z', 'raw_sha256' => str_repeat('a', 64),
            'validity_statement' => 'Current public metadata used only as a research assumption; historical 2023-2026 precision is not attested.',
            'assumption_status' => 'current_metadata_not_historical', 'symbols' => $symbols];
        $manifest['manifest_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($manifest);
        return $manifest;
    }

    /** @return array<string, mixed> */
    public static function costs(): array
    {
        $baseline = ['entry_spread_rate' => 0.0001, 'stop_spread_rate' => 0.0001, 'target_spread_rate' => 0.0001,
            'entry_slippage_rate' => 0.0001, 'stop_slippage_rate' => 0.0001, 'target_slippage_rate' => 0.0001,
            'funding_provision_rate' => 0.0001];
        $adverse = array_fill_keys(array_keys($baseline), 0.0005);
        $manifest = ['schema_version' => 'research-cost-assumptions.v1',
            'frozen_at' => '2026-10-09T06:00:00Z', 'fee_basis' => 'fake_local_policy_not_historical_binance',
            'assumption_status' => 'hypothetical_ohlcv_costs', 'units' => 'fraction_of_notional',
            'profiles' => ['baseline' => $baseline, 'adverse' => $adverse]];
        $manifest['assumption_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($manifest);
        return $manifest;
    }
}
