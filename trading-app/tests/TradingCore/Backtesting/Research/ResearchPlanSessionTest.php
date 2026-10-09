<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Research\ResearchPlanSession;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\TestCase;

final class ResearchPlanSessionTest extends TestCase
{
    public function testOpenAppendCloseBindsLocalBaselineAndArtifactReference(): void
    {
        [$open, $signal, $portfolio] = self::frames();
        $session = new ResearchPlanSession(new EffectiveTradingConfigResolver());
        $opened = $session->open($open);
        self::assertSame('research-plan-opened.v1', $opened['schema_version']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $opened['research_code_hash']);
        $result = $session->append(['schema_version' => 'research-plan-signal.v1', 'session_id' => 'plan-reference',
            'signal_index' => 2, 'signal' => $signal, 'portfolio' => $portfolio]);
        self::assertSame('research-plan.v1', $result['schema_version']);
        self::assertSame(2, $result['signal_index']);
        self::assertSame(str_repeat('b', 64), $result['signal_output_sha256']);
        $closed = $session->close(['schema_version' => 'research-plan-close.v1', 'session_id' => 'plan-reference', 'expected_signals' => 1]);
        self::assertSame('complete', $closed['completion']);
        self::assertSame(1, $closed['planned']);
    }

    public function testRejectsBaselineFromAnotherAppDirectory(): void
    {
        [$open] = self::frames();
        $open['baseline']['snapshot_hash'] = 'sha256:' . str_repeat('f', 64);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_plan_baseline_mismatch');
        (new ResearchPlanSession(new EffectiveTradingConfigResolver()))->open($open);
    }

    public function testEndBoundarySignalIsOnlyDiagnostic(): void
    {
        [$open, $signal, $portfolio] = self::frames();
        $signal['evaluated_ms'] = $open['source_runs'][0]['end_ms'];
        $signal['evaluated_at'] = gmdate('Y-m-d\TH:i:s', intdiv($signal['evaluated_ms'], 1000)) . '.000000Z';
        $signal['trace']['evaluated_at'] = $signal['evaluated_at'];
        $signal['trace_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($signal['trace']);
        foreach (['1m' => 60000, '5m' => 300000, '15m' => 900000] as $tf => $step) {
            $signal['contexts'][$tf]['available_ms'] = $signal['evaluated_ms'];
            $signal['contexts'][$tf]['open_ms'] = $signal['evaluated_ms'] - $step;
        }
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($signal, ['result_hash' => true]));
        $portfolio['as_of_ms'] = $signal['evaluated_ms'];
        $portfolio['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($portfolio, ['portfolio_hash' => true]));
        $session = new ResearchPlanSession(new EffectiveTradingConfigResolver());
        $session->open($open);
        $result = $session->append(['schema_version' => 'research-plan-signal.v1', 'session_id' => 'plan-reference',
            'signal_index' => 2, 'signal' => $signal, 'portfolio' => $portfolio]);
        self::assertSame('research-plan-rejection.v1', $result['schema_version']);
        self::assertSame('research_signal_outside_score_window', $result['reason_code']);
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} */
    public static function frames(): array
    {
        [, $signal, $portfolio] = ResearchPlanBuilderTest::fixture();
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'local', 'long', ShadowExecutionCapability::Backtest,
        ));
        $setup = (new SetupContractLoader())->load('day_trading.trend_continuation.long', '1.1.0');
        $fileHashes = [];
        foreach ($snapshot->toArray()['ordered_files'] as $file) {
            $fileHashes[$file] = 'sha256:' . hash_file('sha256', $file);
        }
        $catalogPath = dirname(__DIR__, 4) . '/' . $setup->toArray()['data_condition_contract']['condition_catalog_hash']['source'];
        $fileHashes[$catalogPath] = 'sha256:' . hash_file('sha256', $catalogPath);
        $baseline = ['config_hash' => $snapshot->configHash, 'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'snapshot_hash' => $snapshot->toArray()['snapshot_hash'], 'setup_hash' => $setup->stableHash(),
            'file_hashes' => $fileHashes, 'mode_risk' => $snapshot->payload()['mode']['risk']];
        $signal['baseline'] = $baseline;
        $signal['trace']['setup_hash'] = $setup->stableHash();
        $signal['trace_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($signal['trace']);
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($signal, ['result_hash' => true]));
        $variantHash = \App\TradingCore\Backtesting\Research\ResearchVariant::select('baseline', [],
            \App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy::fromSnapshot($snapshot),
            $setup->stableHash(), (string) $snapshot->conditionCatalogHash)->hash;
        $open = ['schema_version' => 'research-plan-open.v1', 'session_id' => 'plan-reference',
            'baseline' => $baseline,
            'source_runs' => [['symbol' => 'BTCUSDT', 'source' => $signal['source'],
                'signal_session_id' => 'reference-BTCUSDT', 'signal_run_id' => 'run-reference-BTCUSDT',
                'signal_output_sha256' => str_repeat('b', 64), 'start_ms' => $signal['evaluated_ms'] - 900000,
                'score_start_ms' => $signal['evaluated_ms'] - 900000, 'end_ms' => $signal['evaluated_ms'] + 900000]],
            'variant' => ['schema_version' => 'research-variant-selection.v1', 'id' => 'baseline', 'diff' => [], 'variant_hash' => $variantHash],
            'instrument_assumptions' => ResearchAssumptionsTest::instruments(),
            'cost_assumptions' => ResearchAssumptionsTest::costs(), 'cost_profile' => 'baseline',
            'expected_signals' => 1];
        return [$open, $signal, $portfolio];
    }
}
