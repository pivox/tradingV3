<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Research\ResearchCostAssumptions;
use App\TradingCore\Backtesting\Research\ResearchInstrumentAssumptions;
use App\TradingCore\Backtesting\Research\ResearchPlanBuilder;
use App\TradingCore\Backtesting\Research\ResearchPlanSession;
use App\TradingCore\Backtesting\Research\ResearchProfile;
use App\TradingCore\Backtesting\Research\ResearchSignalSession;
use App\TradingCore\Backtesting\Research\ResearchVariant;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\NetRCostMath;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchProfile::class)]
#[CoversClass(ResearchSignalSession::class)]
#[CoversClass(ResearchPlanBuilder::class)]
#[CoversClass(ResearchPlanSession::class)]
#[CoversClass(ResearchVariant::class)]
final class ResearchSwingProfileTest extends TestCase
{
    private const EVAL = 1672660800000; // 2023-01-02 12:00 UTC, a 4h boundary
    private const FOUR_HOURS = 14_400_000;

    public function testProfilesAreAClosedCatalogueWithAnImplicitDefault(): void
    {
        $default = ResearchProfile::fromFrame(['schema_version' => 'research-signal-open.v1']);
        self::assertTrue($default->isDefault());
        self::assertSame(900_000, $default->evaluationStepMs);
        self::assertSame('last_closed_15m_close', $default->candidatePriceSource());
        self::assertFalse($default->oneActivePlanPerSymbol);

        foreach (['swing.long' => ['swing.breakout.long', 'long'], 'swing.short' => ['swing.breakout.short', 'short']] as $id => [$setupId, $side]) {
            $profile = ResearchProfile::fromFrame(['profile' => $id]);
            self::assertSame(['swing', '1.0.0', $setupId, '1.0.0', $side],
                [$profile->modeId, $profile->modeVersion, $profile->setupId, $profile->setupVersion, $profile->side]);
            self::assertSame(self::FOUR_HOURS, $profile->evaluationStepMs);
            self::assertSame('last_closed_4h_close', $profile->candidatePriceSource());
            self::assertSame([3600, 3660, 1_209_600, 10],
                [$profile->entryTtlSeconds, $profile->cancelAfterSeconds, $profile->holdingWindowSeconds, $profile->maxConcurrentPositions]);
            self::assertSame([1.0, 100_000.0], [$profile->dailyLossRate, $profile->dailyLossAbsoluteQuote]);
            self::assertTrue($profile->oneActivePlanPerSymbol);
            self::assertSame(['fake', 'local', $side], [$profile->request()->exchange, $profile->request()->environment, $profile->request()->side]);
        }
        // An explicit day_trading profile would fork the frozen day_trading frame shape.
        foreach (['day_trading', 'swing', 'swing.LONG', 1, null] as $invalid) {
            try {
                ResearchProfile::fromFrame(['profile' => $invalid]);
                self::fail('Accepted profile ' . var_export($invalid, true));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('research_profile_invalid', $exception->getMessage());
            }
        }
    }

    public function testSwingHasOnlyItsContractGeometryAsVariant(): void
    {
        foreach ([ResearchProfile::SWING_LONG, ResearchProfile::SWING_SHORT] as $id) {
            $profile = ResearchProfile::fromId($id);
            $snapshot = (new EffectiveTradingConfigResolver())->resolve($profile->request());
            $policy = CanonicalExecutionPolicy::fromSnapshot($snapshot);
            $setupHash = (string) $snapshot->payload()['setup']['contract_hash'];
            $catalogHash = (string) $snapshot->conditionCatalogHash;

            $catalog = ResearchVariant::catalog($policy, $setupHash, $catalogHash, $profile);
            self::assertSame(['baseline'], array_map(static fn (ResearchVariant $variant): string => $variant->id, $catalog));
            self::assertSame(['close', '4h', 0.30], [$catalog[0]->entryZone->anchorSource, $catalog[0]->entryZone->anchorTimeframe, $catalog[0]->entryZone->atrMultiplier]);
            self::assertSame(['4h', 3.0, 5.0], [$catalog[0]->stop->timeframe, $catalog[0]->stop->atrMultiplier, $catalog[0]->targets[0]->riskMultiple]);
            foreach ([['stop_200', ['stop_atr_multiplier' => 2.0], null, 'research_variant_invalid'],
                ['baseline', [], ResearchProfile::fromId(ResearchProfile::DAY_TRADING), 'research_baseline_geometry_mismatch']] as [$variantId, $diff, $otherProfile, $reason]) {
                try {
                    ResearchVariant::select($variantId, $diff, $policy, $setupHash, $catalogHash, $otherProfile ?? $profile);
                    self::fail('Accepted ' . $variantId);
                } catch (\InvalidArgumentException $exception) {
                    self::assertSame($reason, $exception->getMessage());
                }
            }
        }
    }

    public function testLongSignalsEvaluateEveryFourHourCloseWithTheBreakoutChronologyProof(): void
    {
        [$opened, $results, $summary] = self::stream(ResearchProfile::SWING_LONG, 0.001, 60_240);

        self::assertSame(['swing', 'swing.breakout.long', 'long'],
            [$opened['execution']['mode_id'], $opened['execution']['setup_id'], $opened['execution']['side']]);
        self::assertCount(2, $results);
        self::assertSame(self::FOUR_HOURS, $results[1]['evaluated_ms'] - $results[0]['evaluated_ms']);
        foreach ($results as $result) {
            self::assertSame(0, $result['evaluated_ms'] % self::FOUR_HOURS);
            // Without the series timestamps the strict evaluator fails the breakout as unproven chronology.
            self::assertTrue($result['passed'], (string) $result['reason_code']);
            self::assertSame('setup_rules_passed', $result['reason_code']);
            self::assertSame('4h', $result['trace']['execution_timeframe']);
            foreach ($result['contexts'] as $context) {
                self::assertSame($result['evaluated_ms'], $context['available_ms']);
            }
        }
        self::assertSame(2, $summary['evaluated_ticks']);
        self::assertSame(249, $summary['warmup_unavailable']);
    }

    public function testShortSignalsMirrorTheLongRuleAndNeitherSideFiresAgainstTheTrend(): void
    {
        [, $short] = self::stream(ResearchProfile::SWING_SHORT, -0.001, 60_001);
        self::assertCount(1, $short);
        self::assertTrue($short[0]['passed'], (string) $short[0]['reason_code']);
        self::assertSame('short', $short[0]['execution']['side']);

        [, $longInDowntrend] = self::stream(ResearchProfile::SWING_LONG, -0.001, 60_001);
        [, $shortInUptrend] = self::stream(ResearchProfile::SWING_SHORT, 0.001, 60_001);
        self::assertFalse($longInDowntrend[0]['passed']);
        self::assertFalse($shortInUptrend[0]['passed']);
    }

    /** @return iterable<string, array{string}> */
    public static function swingProfiles(): iterable
    {
        yield 'long' => [ResearchProfile::SWING_LONG];
        yield 'short' => [ResearchProfile::SWING_SHORT];
    }

    #[DataProvider('swingProfiles')]
    public function testSwingPlanUsesTheFourHourCloseAndTheFrozenGeometry(string $profileId): void
    {
        [$builder, $signal, $portfolio] = self::planFixture($profileId);
        $plan = $builder->build($signal, 3, $portfolio);
        $long = $profileId === ResearchProfile::SWING_LONG;

        self::assertSame('research-plan.v1', $plan['schema_version'], (string) ($plan['reason_code'] ?? ''));
        self::assertSame($long ? 'long' : 'short', $plan['side']);
        self::assertSame('last_closed_4h_close', $plan['candidate_price_source']);
        self::assertSame(100.2, $plan['entry_price']);
        self::assertSame($long ? 97.2 : 103.2, $plan['stop_price']);
        self::assertSame($long ? 115.2 : 85.2, $plan['targets'][0]['price']);
        self::assertSame([3600, 3660], [$plan['entry_ttl_seconds'], $plan['cancel_after_seconds']]);
        self::assertSame(self::EVAL + 1_209_600_000, $plan['holding_deadline_ms']);
        self::assertSame(42, $plan['cost_model']['funding_intervals_provisioned']);
        self::assertLessThanOrEqual(250.0, $plan['position_notional_quote']);
        self::assertGreaterThanOrEqual(1.3, $plan['targets'][0]['net_r']);
    }

    public function testShortReservesTheSameFundingProvisionAsALongAtTheNegatedRate(): void
    {
        $plans = [];
        foreach ([ResearchProfile::SWING_LONG, ResearchProfile::SWING_SHORT] as $profileId) {
            [$builder, $signal, $portfolio] = self::planFixture($profileId);
            $plans[$profileId] = $builder->build($signal, 3, $portfolio);
        }
        $long = $plans[ResearchProfile::SWING_LONG];
        $short = $plans[ResearchProfile::SWING_SHORT];
        $model = $short['cost_model'];

        // The plan records the cost assumption itself; only the arithmetic sees the adverse sign.
        self::assertSame(0.0001, $model['funding_provision_rate']);
        $provision = $short['entry_price'] * $short['quantity'] * $short['instrument_math']['contract_size'] * 0.0001 * 42;
        self::assertEqualsWithDelta($provision, $short['risk_components_quote']['funding_provision'], 1e-9);
        self::assertSame($long['risk_components_quote']['funding_provision'], $short['risk_components_quote']['funding_provision']);
        $risk = NetRCostMath::risk('short', $short['entry_price'], $short['stop_price'], $short['quantity'],
            $short['instrument_math']['contract_size'], $model['entry_fee_rate'], $model['stop_fee_rate'],
            $model['entry_spread_rate'], $model['stop_spread_rate'], $model['entry_slippage_rate'], $model['stop_slippage_rate'],
            -$model['funding_provision_rate'], $model['funding_intervals_provisioned']);
        self::assertSame($risk['net_risk']->toFloat(), $short['risk_components_quote']['total_stop_loss']);
        $target = NetRCostMath::target($short['entry_price'], $short['targets'][0]['price'], $short['quantity'],
            $short['instrument_math']['contract_size'], $model['target_fee_rate'], $model['target_spread_rate'],
            $model['target_slippage_rate'], $risk);
        self::assertSame($target['net_r']->toFloat(), $short['targets'][0]['net_r']);
    }

    public function testOneActivePlanPerPairIsRejectedBeforeConcurrency(): void
    {
        [$builder, $signal, $portfolio] = self::planFixture(ResearchProfile::SWING_LONG);
        $active = self::portfolio($portfolio, ['BTCUSDT', 'ETHUSDT'], 1, 1);
        $rejection = $builder->build($signal, 3, $active);
        self::assertSame('research_portfolio_symbol_active', $rejection['reason_code']);
        self::assertSame($active['portfolio_hash'], $rejection['portfolio_hash']);

        $others = array_values(array_diff(ResearchInstrumentAssumptions::SYMBOLS, ['BTCUSDT']));
        sort($others, SORT_STRING);
        $nine = self::portfolio($portfolio, $others, 5, 4);
        self::assertSame('research-plan.v1', $builder->build($signal, 3, $nine)['schema_version']);

        // A full portfolio holding the pair is refused for the pair, not for the limit (PortfolioSimulator order).
        $all = ResearchInstrumentAssumptions::SYMBOLS;
        sort($all, SORT_STRING);
        $full = self::portfolio($portfolio, $all, 6, 4);
        self::assertSame('research_portfolio_symbol_active', $builder->build($signal, 3, $full)['reason_code']);
        // The daily cap is checked first.
        $capped = self::portfolio(['reserved_risk_quote' => 99_999.9] + $portfolio, ['BTCUSDT', 'ETHUSDT'], 1, 1);
        self::assertSame('research_portfolio_daily_loss_exceeded', $builder->build($signal, 3, $capped)['reason_code']);
    }

    public function testSwingPortfolioViewMustCarryExactSortedActiveSymbols(): void
    {
        [$builder, $signal, $portfolio] = self::planFixture(ResearchProfile::SWING_SHORT);
        $missing = $portfolio;
        unset($missing['active_symbols']);
        $missing['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($missing, ['portfolio_hash' => true]));
        foreach ([
            'missing' => $missing,
            'unsorted' => self::portfolio($portfolio, ['ETHUSDT', 'ADAUSDT'], 2, 0),
            'count' => self::portfolio($portfolio, ['ETHUSDT'], 2, 0),
            'duplicate' => self::portfolio($portfolio, ['ETHUSDT', 'ETHUSDT'], 2, 0),
            'unknown' => self::portfolio($portfolio, ['SHIBUSDT'], 1, 0),
        ] as $label => $view) {
            try {
                $builder->build($signal, 3, $view);
                self::fail('Accepted portfolio view: ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('research_portfolio_view_invalid', $exception->getMessage(), $label);
            }
        }
    }

    public function testSwingBuilderRejectsDayTradingCadenceTraceAndIdentity(): void
    {
        [$builder, $signal, $portfolio] = self::planFixture(ResearchProfile::SWING_LONG);
        $mutations = [];
        $mutations['quarter-hour decision'] = self::rehash($signal, static function (array &$s): void {
            $s['evaluated_ms'] += 900_000;
            $s['evaluated_at'] = ResearchSignalSession::instant($s['evaluated_ms']);
        });
        $mutations['other side'] = self::rehash($signal, static function (array &$s): void { $s['execution']['side'] = 'short'; });
        $mutations['day trading trace'] = self::rehash($signal, static function (array &$s): void {
            $s['trace']['execution_timeframe'] = '15m';
            $s['trace_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($s['trace']);
        });
        // Every timeframe must close at the 4h decision.
        foreach (['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000, '4h' => self::FOUR_HOURS] as $tf => $step) {
            $mutations['stale ' . $tf . ' context'] = self::rehash($signal, static function (array &$s) use ($tf, $step): void {
                $s['contexts'][$tf]['open_ms'] -= $step;
                $s['contexts'][$tf]['available_ms'] -= $step;
            });
        }
        foreach ($mutations as $label => $mutation) {
            try {
                $builder->build($mutation, 3, $portfolio);
                self::fail('Accepted ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertContains($exception->getMessage(), ['research_signal_binding_invalid', 'research_signal_trace_invalid', 'research_signal_context_stale'], $label);
            }
        }
    }

    public function testSwingPlanSessionBindsItsOwnBaselineAndProfile(): void
    {
        [$open, $signal, $portfolio] = self::sessionFrames(ResearchProfile::SWING_SHORT);
        $session = new ResearchPlanSession(new EffectiveTradingConfigResolver());
        $opened = $session->open($open);
        self::assertSame('baseline', $opened['variant_id']);
        $plan = $session->append(['schema_version' => 'research-plan-signal.v1', 'session_id' => $open['session_id'],
            'signal_index' => 3, 'signal' => $signal, 'portfolio' => $portfolio]);
        self::assertSame('short', $plan['side']);
        self::assertSame(1, $session->close(['schema_version' => 'research-plan-close.v1', 'session_id' => $open['session_id'], 'expected_signals' => 1])['planned']);

        // A plan session refuses an invalid profile with the signal session's reason code.
        foreach (['day_trading', 'swing', null] as $invalid) {
            try {
                (new ResearchPlanSession(new EffectiveTradingConfigResolver()))->open(['profile' => $invalid] + $open);
                self::fail('Accepted plan profile ' . var_export($invalid, true));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('research_profile_invalid', $exception->getMessage());
            }
        }

        // The same frame without its profile resolves the day_trading baseline and fails closed.
        unset($open['profile']);
        try {
            (new ResearchPlanSession(new EffectiveTradingConfigResolver()))->open($open);
            self::fail('A swing baseline opened a day_trading session.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('research_plan_baseline_mismatch', $exception->getMessage());
        }
    }

    /**
     * @return array{array<string, mixed>, list<array<string, mixed>>, array<string, mixed>}
     */
    private static function stream(string $profile, float $slope, int $minutes): array
    {
        $session = ResearchSignalSessionTest::session();
        $frame = ['profile' => $profile, ...ResearchSignalSessionTest::openFrame('BTCUSDT', $minutes)];
        $opened = $session->open($frame);
        $results = [];
        for ($first = 0; $first < $minutes; $first += ResearchSignalSession::MAX_BATCH) {
            $batch = [];
            for ($minute = $first; $minute < min($minutes, $first + ResearchSignalSession::MAX_BATCH); ++$minute) {
                $batch[] = self::trendCandle($minute, $slope);
            }
            array_push($results, ...$session->appendBatch(['schema_version' => 'research-signal-candles.v1', 'session_id' => $frame['session_id'], 'candles' => $batch]));
        }
        $summary = $session->close(['schema_version' => 'research-signal-close.v1', 'session_id' => $frame['session_id']]);

        return [$opened, $results, $summary];
    }

    /** @return array<string, mixed> */
    private static function trendCandle(int $minute, float $slope): array
    {
        $open = 100.0 + $slope * $minute;
        $close = 100.0 + $slope * ($minute + 1);
        $format = static fn (float $value): string => number_format($value, 6, '.', '');

        return ['symbol' => 'BTCUSDT', 'open_ms' => 1667260800000 + $minute * 60000, 'open' => $format($open),
            'high' => $format(max($open, $close) + 0.0001), 'low' => $format(min($open, $close) - 0.0001),
            'close' => $format($close), 'volume' => '1', 'source_record_id' => hash('sha256', 'trend:' . $minute)];
    }

    /**
     * @param array<string, mixed>|null $baseline
     * @return array{ResearchPlanBuilder, array<string, mixed>, array<string, mixed>}
     */
    private static function planFixture(string $profileId, ?array $baseline = null): array
    {
        $profile = ResearchProfile::fromId($profileId);
        $snapshot = (new EffectiveTradingConfigResolver())->resolve($profile->request());
        $policy = CanonicalExecutionPolicy::fromSnapshot($snapshot);
        $setupHash = (string) $snapshot->payload()['setup']['contract_hash'];
        $variant = ResearchVariant::select('baseline', [], $policy, $setupHash, (string) $snapshot->conditionCatalogHash, $profile);
        $baseline ??= ['config_hash' => $snapshot->configHash, 'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'snapshot_hash' => $snapshot->toArray()['snapshot_hash'], 'setup_hash' => $setupHash,
            'file_hashes' => [], 'mode_risk' => $snapshot->payload()['mode']['risk']];
        $source = ['dataset_id' => 'reference-BTCUSDT', 'dataset_sha256' => str_repeat('a', 64),
            'source_venue' => 'binance_usdm', 'source_network' => 'mainnet', 'market_type' => 'perpetual'];
        $run = ['symbol' => 'BTCUSDT', 'source' => $source, 'signal_session_id' => 'reference-BTCUSDT',
            'signal_run_id' => 'run-reference-BTCUSDT', 'signal_output_sha256' => str_repeat('b', 64),
            'score_start_ms' => self::EVAL - self::FOUR_HOURS, 'end_ms' => self::EVAL + self::FOUR_HOURS];
        $trace = ['schema_version' => 'canonical-setup-rule-runtime.v1', 'mode_id' => 'swing', 'mode_version' => '1.0.0',
            'setup_id' => $profile->setupId, 'setup_version' => '1.0.0',
            'setup_hash' => $baseline['setup_hash'], 'catalog_hash' => $snapshot->conditionCatalogHash,
            'config_hash' => $snapshot->configHash, 'side' => $profile->side, 'execution_timeframe' => '4h',
            'mandatory_confirmations' => ['4h'], 'evaluated_at' => ResearchSignalSession::instant(self::EVAL)];
        $contexts = [];
        foreach (['1m' => 60000, '5m' => 300000, '15m' => 900000, '1h' => 3600000, '4h' => self::FOUR_HOURS] as $tf => $step) {
            $contexts[$tf] = ['open_ms' => self::EVAL - $step, 'available_ms' => self::EVAL,
                'research_window_hash' => 'sha256:' . str_repeat('c', 64)];
        }
        $contexts['4h']['close'] = 100.2;
        $contexts['4h']['atr'] = 1.0;
        $signal = ['schema_version' => 'research-signal-result.v1', 'session_id' => $run['signal_session_id'],
            'source' => $source, 'execution' => $snapshot->request->toArray(), 'baseline' => $baseline,
            'symbol' => 'BTCUSDT', 'evaluated_ms' => self::EVAL, 'evaluated_at' => ResearchSignalSession::instant(self::EVAL),
            'passed' => true, 'reason_code' => 'setup_rules_passed',
            'numeric_context_hash' => 'sha256:' . str_repeat('d', 64), 'contexts' => $contexts,
            'trace_hash' => CanonicalBacktestRuleEvaluator::canonicalHash($trace), 'verdicts' => [], 'trace' => $trace];
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($signal);
        $portfolio = self::portfolio(['schema_version' => 'research-portfolio-view.v1', 'as_of_ms' => self::EVAL,
            'equity_quote' => 100000.0, 'available_balance_quote' => 100000.0,
            'realized_net_pnl_quote' => 0.0, 'unrealized_net_pnl_quote' => 0.0,
            'open_positions' => 0, 'pending_entries' => 0, 'open_notional_quote' => 0.0,
            'pending_notional_quote' => 0.0, 'reserved_risk_quote' => 0.0,
            'active_signal_hashes' => []], [], 0, 0);
        $builder = new ResearchPlanBuilder($policy, CanonicalPortfolioPolicy::fromSnapshot($snapshot), $variant,
            ResearchInstrumentAssumptions::fromArray(ResearchAssumptionsTest::instruments()),
            ResearchCostAssumptions::fromArray(ResearchAssumptionsTest::costs()), 'baseline', $baseline, $run, null, $profile);

        return [$builder, $signal, $portfolio];
    }

    /**
     * @param array<string, mixed> $view
     * @param list<string> $symbols
     * @return array<string, mixed>
     */
    private static function portfolio(array $view, array $symbols, int $open, int $pending): array
    {
        unset($view['portfolio_hash']);
        $view['active_symbols'] = $symbols;
        $view['open_positions'] = $open;
        $view['pending_entries'] = $pending;
        $view['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($view);

        return $view;
    }

    /**
     * @param array<string, mixed> $signal
     * @param callable(array<string, mixed>&): void $mutate
     * @return array<string, mixed>
     */
    private static function rehash(array $signal, callable $mutate): array
    {
        $mutate($signal);
        unset($signal['result_hash']);
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($signal);

        return $signal;
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} */
    private static function sessionFrames(string $profileId): array
    {
        $profile = ResearchProfile::fromId($profileId);
        $snapshot = (new EffectiveTradingConfigResolver())->resolve($profile->request());
        $setup = (new SetupContractLoader())->load($profile->setupId, $profile->setupVersion);
        $fileHashes = [];
        foreach ($snapshot->toArray()['ordered_files'] as $file) {
            $fileHashes[$file] = 'sha256:' . hash_file('sha256', $file);
        }
        $catalogPath = dirname(__DIR__, 4) . '/' . $setup->toArray()['data_condition_contract']['condition_catalog_hash']['source'];
        $fileHashes[$catalogPath] = 'sha256:' . hash_file('sha256', $catalogPath);
        $baseline = ['config_hash' => $snapshot->configHash, 'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'snapshot_hash' => $snapshot->toArray()['snapshot_hash'], 'setup_hash' => $setup->stableHash(),
            'file_hashes' => $fileHashes, 'mode_risk' => $snapshot->payload()['mode']['risk']];
        [, $signal, $portfolio] = self::planFixture($profileId, $baseline);
        $variant = ResearchVariant::select('baseline', [], CanonicalExecutionPolicy::fromSnapshot($snapshot),
            $setup->stableHash(), (string) $snapshot->conditionCatalogHash, $profile);
        $open = ['schema_version' => 'research-plan-open.v1', 'session_id' => 'plan-swing', 'profile' => $profileId,
            'baseline' => $baseline,
            'source_runs' => [['symbol' => 'BTCUSDT', 'source' => $signal['source'],
                'signal_session_id' => 'reference-BTCUSDT', 'signal_run_id' => 'run-reference-BTCUSDT',
                'signal_output_sha256' => str_repeat('b', 64), 'start_ms' => self::EVAL - self::FOUR_HOURS,
                'score_start_ms' => self::EVAL - self::FOUR_HOURS, 'end_ms' => self::EVAL + self::FOUR_HOURS]],
            'variant' => ['schema_version' => 'research-variant-selection.v1', 'id' => 'baseline', 'diff' => [], 'variant_hash' => $variant->hash],
            'instrument_assumptions' => ResearchAssumptionsTest::instruments(),
            'cost_assumptions' => ResearchAssumptionsTest::costs(), 'cost_profile' => 'baseline',
            'expected_signals' => 1];

        return [$open, $signal, $portfolio];
    }
}
