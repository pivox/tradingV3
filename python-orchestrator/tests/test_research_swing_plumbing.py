"""Swing research cells through the Python signal and plan plumbing (swing campaign PR 3).

Day_trading keeps its frozen call shapes and frames; these tests cover the profile paths.
"""
from __future__ import annotations

import copy
import json
import shutil
import subprocess
import sys
from dataclasses import replace
from datetime import datetime, timezone
from decimal import Decimal as D
from pathlib import Path

import pytest

from app.backtesting.research import campaign, plans, signals
from app.backtesting.research.portfolio_simulator import (SWING_PROFILE, Candle, CostAssumptions,
    FundingCoverage, PortfolioSimulator, RunAssumptions, Signal, canonical_hash)
from app.backtesting.research.signal_sources import (DAY_TRADING, FOUR_HOURS_MS, RESEARCH_PROFILES,
    SignalError, _expected_ticks, research_profile, select_sources)
from app.modern_trading_contracts import (CanonicalEffectiveConfigSnapshot, calculate_config_hash,
    calculate_snapshot_hash)
from tests.test_research_plans import WORKER, opening
from tests.test_research_signals import END, FAKE_WORKER, START, _worker_evidence, dataset

SWING_LONG, SWING_SHORT = RESEARCH_PROFILES['swing.long'], RESEARCH_PROFILES['swing.short']


def _utc(text: str) -> datetime:
    return datetime.fromisoformat(text.replace('Z', '+00:00'))


def swing_evidence(app_dir: Path, profile=SWING_SHORT) -> dict:
    """The fake worker's day_trading evidence, rebound to a swing cell."""
    evidence = _worker_evidence(app_dir)
    snapshot = evidence['snapshot']
    execution = profile.execution()
    snapshot['request'] = execution
    snapshot['config']['mode'].update({'mode_id': 'swing', 'mode_version': '1.0.0'})
    snapshot['config']['setup'].update({'setup_id': execution['setup_id'], 'setup_version': '1.0.0',
                                        'side': execution['side']})
    snapshot['config_hash'] = calculate_config_hash(snapshot['config'], snapshot['condition_catalog_hash'])
    snapshot['snapshot_hash'] = calculate_snapshot_hash(snapshot)
    CanonicalEffectiveConfigSnapshot.model_validate(snapshot)
    evidence['baseline'].update({'config_hash': snapshot['config_hash'], 'snapshot_hash': snapshot['snapshot_hash']})
    evidence['execution'] = execution
    return evidence


def swing_worker(app_dir: Path, mode: str, profile=SWING_SHORT) -> tuple[str, ...]:
    path = app_dir / 'fake-swing-worker-evidence.json'
    path.write_text(json.dumps(swing_evidence(app_dir, profile)))
    return (sys.executable, '-c', FAKE_WORKER, mode, str(path))


# ------------------------------------------------------------------------------- signals
def test_profiles_mirror_the_php_research_cells() -> None:
    assert DAY_TRADING.is_default and DAY_TRADING.wire is None and DAY_TRADING.step_ms == 900000
    for profile, side in ((SWING_LONG, 'long'), (SWING_SHORT, 'short')):
        assert profile.wire == profile.id and profile.step_ms == FOUR_HOURS_MS
        assert profile.execution() == {'mode_id': 'swing', 'mode_version': '1.0.0',
            'setup_id': f'swing.breakout.{side}', 'setup_version': '1.0.0', 'exchange': 'fake',
            'environment': 'local', 'side': side, 'execution_capability': 'backtest'}
    with pytest.raises(SignalError, match='unknown research profile'):
        research_profile('swing')


def test_expected_ticks_follow_the_profile_cadence() -> None:
    start, score, end = _utc('2022-11-01T00:00:00Z'), _utc('2023-01-01T00:00:00Z'), _utc('2023-01-02T00:00:00Z')
    # 372 4h closes in (start, end]: 249 before the 250-bar window is full, 116 before score, 7 scored.
    assert _expected_ticks(start, end, score, FOUR_HOURS_MS) == (7, 249, 116)
    evaluated, warmup, before = _expected_ticks(start, end, score)
    assert evaluated + warmup + before == 62 * 96
    with pytest.raises(SignalError, match='cadence'):
        _expected_ticks(start, end, score, 3600000)


def test_first_evaluable_tick_and_session_identity_follow_the_profile(tmp_path: Path) -> None:
    root = dataset(tmp_path / 'dataset')
    selection = select_sources(root, START, END, START, 'BTCUSDT')
    late = replace(selection, score_start=_utc('2023-03-01T01:05:00Z'))
    assert signals._first_evaluable_ms(late) == 1677633300000  # 01:05 rounds up to 01:15
    assert signals._first_evaluable_ms(replace(late, step_ms=FOUR_HOURS_MS)) == 1677643200000  # 04:00
    digest = 'f' * 64
    assert signals.signal_session_id('BTCUSDT', digest) == f'research-BTCUSDT-{"f" * 20}'
    assert signals.signal_session_id('BTCUSDT', digest, SWING_LONG) == f'research-BTCUSDT-swing.long-{"f" * 20}'


def test_swing_open_frame_names_its_profile_and_requires_its_cadence(tmp_path: Path) -> None:
    root = dataset(tmp_path / 'dataset')
    day = select_sources(root, START, END, START, 'BTCUSDT')
    swing = select_sources(root, START, END, START, 'BTCUSDT', step=FOUR_HOURS_MS)
    assert (swing.step_ms, swing.dataset_sha256) == (FOUR_HOURS_MS, day.dataset_sha256)
    default_open = json.loads(next(signals._frames(day, root, 'session')))
    swing_open = json.loads(next(signals._frames(swing, root, 'session', SWING_LONG)))
    assert 'profile' not in default_open
    assert swing_open == {**default_open, 'profile': 'swing.long'}
    with pytest.raises(SignalError, match='cadence'):
        next(signals._frames(day, root, 'session', SWING_LONG))


def test_opened_execution_identity_is_exact_per_profile(tmp_path: Path) -> None:
    root = dataset(tmp_path / 'dataset')
    selection = select_sources(root, START, END, START, 'BTCUSDT', step=FOUR_HOURS_MS)
    evidence = swing_evidence(tmp_path)
    frame = {'schema_version': 'research-signal-opened.v1', 'session_id': 'session',
             'source': {'dataset_id': 'research-' + selection.dataset_sha256[:24],
                        'dataset_sha256': selection.dataset_sha256, 'source_venue': 'binance_usdm',
                        'source_network': 'mainnet', 'market_type': 'perpetual'},
             'execution': evidence['execution'], 'baseline': evidence['baseline'],
             'effective_config_snapshot': evidence['snapshot'], 'indicator_engine_version': 'php_fallback_v1'}
    signals._validate_opened(frame, 'session', selection, tmp_path, SWING_SHORT)
    for wrong in (DAY_TRADING, SWING_LONG):
        with pytest.raises(SignalError, match='identity'):
            signals._validate_opened(frame, 'session', selection, tmp_path, wrong)


def test_swing_run_streams_the_profile_and_reports_it(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    root = dataset(tmp_path / 'dataset')
    selection = select_sources(root, START, END, START, 'BTCUSDT', step=FOUR_HOURS_MS)
    calls = []

    def selected(*args, **kwargs):
        calls.append(kwargs)
        return replace(selection, expected_evaluations=1)

    monkeypatch.setattr(signals, 'select_sources', selected)
    monkeypatch.setattr(signals, '_first_evaluable_ms', lambda unused: 1672531260000)
    out = tmp_path / 'out'
    report = signals.run_signals(root, out, tmp_path, START, END, START, ('BTCUSDT',),
                                 worker_argv=swing_worker(tmp_path, 'scored'), profile='swing.short')

    assert calls == [{'step': FOUR_HOURS_MS}]
    assert report['profile'] == 'swing.short'
    assert report['symbols']['BTCUSDT']['execution'] == SWING_SHORT.execution()
    assert report['symbols']['BTCUSDT']['scored_passed_rules'] == 1
    result = json.loads((out / 'BTCUSDT.results.ndjson').read_text())
    assert result['session_id'] == signals.signal_session_id('BTCUSDT', selection.dataset_sha256, SWING_SHORT)
    assert json.loads((out / 'report.json').read_text())['profile'] == 'swing.short'


def test_cli_passes_a_profile_only_when_one_is_chosen(monkeypatch: pytest.MonkeyPatch) -> None:
    received = []

    def run(*args, **kwargs):
        received.append(kwargs)
        return {'status': 'complete', 'symbols': {'BTCUSDT': {}}}

    monkeypatch.setattr(signals, 'run_signals', run)
    base = ['--dataset-root', '/d', '--output-root', '/o', '--app-dir', '/a', '--start', START,
            '--end', END, '--score-start', START, '--symbols', 'BTCUSDT']
    assert signals.main(base) == 0
    assert signals.main([*base, '--profile', 'swing.long']) == 0
    assert received == [{}, {'profile': 'swing.long'}]
    with pytest.raises(SystemExit):
        signals.main([*base, '--profile', 'swing'])


# --------------------------------------------------------------------------------- plans
def test_swing_variant_catalogue_is_the_frozen_contract_geometry() -> None:
    baseline = opening()['baseline']
    assert plans.variant_catalogue('swing.long') == {'baseline': {}}
    assert plans.variant_catalogue() is plans.VARIANT_DIFFS
    assert plans.variant_selection('baseline', baseline, 'swing.short') == plans.variant_selection('baseline', baseline)
    with pytest.raises(plans.PlanError, match='variant'):
        plans.variant_selection('stop_200', baseline, 'swing.long')
    with pytest.raises(plans.PlanError, match='profile'):
        plans.variant_catalogue('swing')


def test_plan_worker_binds_the_profile_and_the_plan_side(tmp_path: Path) -> None:
    frame = {**opening(), 'profile': 'swing'}
    with pytest.raises(plans.PlanError, match='profile'):
        plans.PlanWorker(tmp_path, frame, worker_argv=(sys.executable, '-c', WORKER, 'plan'))
    frame['profile'] = 'swing.long'
    with plans.PlanWorker(tmp_path, frame, worker_argv=(sys.executable, '-c', WORKER, 'plan'),
                          wall_timeout=2, io_timeout=.5) as worker:
        assert worker.profile is SWING_LONG
        signal = Signal(7, {'symbol': 'BTCUSDT', 'result_hash': 'sha256:' + 'a' * 64,
                            'evaluated_ms': START_MS, 'passed': True})
        # The fake planner returns a plan without `side`, which a swing session must refuse.
        with pytest.raises(plans.PlanError, match='side'):
            worker.build(signal, {'portfolio_hash': 'sha256:' + 'a' * 64})


START_MS = 1672531200000


@pytest.fixture(scope='module')
def swing_short_opening():
    php = shutil.which('php')
    app = Path(__file__).resolve().parents[2] / 'trading-app'
    if php is None or not (app / 'vendor/autoload.php').is_file():
        pytest.skip('actual Symfony handshake requires PHP and locally installed vendor')
    f = opening()
    run = f['source_runs'][0]
    tick = 1672660800000  # 2023-01-02T12:00Z, a 4h boundary
    run.update({'start_ms': tick - FOUR_HOURS_MS, 'score_start_ms': tick - FOUR_HOURS_MS, 'end_ms': tick + 3 * 86400000})
    frame = {'schema_version': 'research-signal-open.v1', 'session_id': run['signal_session_id'],
             'profile': 'swing.short', **run['source'], 'symbol': run['symbol'], 'start_ms': run['start_ms'],
             'end_ms': run['end_ms'], 'score_start_ms': run['score_start_ms']}
    raw = json.dumps(frame) + '\n' + json.dumps({'schema_version': 'research-signal-close.v1',
                                                 'session_id': run['signal_session_id']}) + '\n'
    result = subprocess.run((php, '-d', 'display_errors=stderr', str(app / 'bin/console'),
        'app:research:signals', '--no-interaction'), input=raw.encode(), capture_output=True,
        env=plans.worker_environment(), cwd=app, timeout=30)
    acknowledgement = json.loads(result.stdout.splitlines()[0])
    assert acknowledgement['execution'] == SWING_SHORT.execution()
    f.update(profile='swing.short', baseline=acknowledgement['baseline'])
    f['variant'] = plans.variant_selection('baseline', f['baseline'], 'swing.short')
    return app, f, tick


def test_actual_php_swing_short_plan_is_admitted_and_settled_by_the_swing_kernel(swing_short_opening) -> None:
    from app.backtesting.research.campaign import _kernel_instruments
    app, f, tick = swing_short_opening
    f = copy.deepcopy(f)
    f['expected_signals'] = 1
    run = f['source_runs'][0]
    b = f['baseline']
    trace = {'schema_version': 'canonical-setup-rule-runtime.v1', 'mode_id': 'swing', 'mode_version': '1.0.0',
             'setup_id': 'swing.breakout.short', 'setup_version': '1.0.0', 'setup_hash': b['setup_hash'],
             'catalog_hash': b['condition_catalog_hash'], 'config_hash': b['config_hash'], 'side': 'short',
             'execution_timeframe': '4h', 'mandatory_confirmations': ['4h'],
             'evaluated_at': '2023-01-02T12:00:00.000000Z'}
    contexts = {tf: {'open_ms': tick - step, 'available_ms': tick, 'research_window_hash': 'sha256:' + 'c' * 64}
                for tf, step in signals.TIMEFRAME_MS.items()}
    contexts['4h'].update(close=100.0, atr=1.0)
    signal = {'schema_version': 'research-signal-result.v1', 'session_id': run['signal_session_id'],
              'source': run['source'], 'execution': SWING_SHORT.execution(), 'baseline': b, 'symbol': 'BTCUSDT',
              'evaluated_ms': tick, 'evaluated_at': '2023-01-02T12:00:00.000000Z', 'passed': True,
              'reason_code': 'setup_rules_passed', 'numeric_context_hash': 'sha256:' + 'd' * 64,
              'contexts': contexts, 'trace_hash': canonical_hash(trace), 'verdicts': [], 'trace': trace}
    signal['result_hash'] = canonical_hash(signal)
    with plans.PlanWorker(app, f, wall_timeout=60, io_timeout=30) as worker:
        identity = {'base_setup_hash': b['setup_hash'], 'base_config_hash': b['config_hash'],
            'base_catalog_hash': b['condition_catalog_hash'], 'base_snapshot_hash': b['snapshot_hash'],
            'variant_id': 'baseline', 'variant_hash': f['variant']['variant_hash'],
            'instrument_assumptions_hash': f['instrument_assumptions']['manifest_hash'],
            'cost_assumptions_hash': f['cost_assumptions']['assumption_hash'],
            'research_code_hash': worker.opened['research_code_hash']}
        source = {**{k: run['source'][k] for k in ('dataset_id', 'dataset_sha256')},
                  **{k: run[k] for k in ('signal_run_id', 'signal_output_sha256')}}
        assumptions = RunAssumptions(tick, tick + 180000, 'training', ('BTCUSDT',),
            _kernel_instruments(f['instrument_assumptions'], ('BTCUSDT',)),
            CostAssumptions(D('.0002'), D('.0005'), D('.0005'), *(D(0) for _ in range(6)), D('.0001'), 28800, 42),
            tuple(identity.items()), (('BTCUSDT', tuple(source.items())),),
            FundingCoverage('verified_complete', 'sha256:' + 'a' * 64, (('BTCUSDT', 0),)),
            'adverse_possible_credit_certain.v1', 'last_known_close.v1', kernel_profile=SWING_PROFILE)
        ledger = {key: [] for key in ('events', 'trades', 'cashflows', 'rejections')}
        sim = PortfolioSimulator(assumptions, worker.build, event_sink=ledger['events'].append,
            trade_sink=ledger['trades'].append, cashflow_sink=ledger['cashflows'].append,
            rejection_sink=ledger['rejections'].append)

        def bar(t, high, low):
            return (Candle('BTCUSDT', t, D('100'), D(high), D(low), D('100')),)

        sim.prime(bar(tick - 60000, '100.5', '99.5'), signals=(Signal(0, signal),))
        assert sim.admitted_plans == 1, ledger['rejections']
        sim.advance(bar(tick, '100.5', '99.5'))  # the sell limit at 100.0 fills on the high
        sim.advance(bar(tick + 60000, '100.2', '84'))  # the 5R target at 85.0 fills on the low
        sim.advance(bar(tick + 120000, '100.5', '99.5'))
        summary = sim.finish()
        assert summary['trades'] == 1 and ledger['trades'][0]['side'] == 'short'
        assert summary['net_pnl_quote'] > 0
        assert worker.close()['planned'] == 1


# ------------------------------------------------------------------------------ campaign
def _snapshot(time_stop: str) -> dict:
    return {'config': {'exchange': {'fees': {'maker_rate': .0002, 'taker_rate': .0005}, 'funding': {'interval': 'PT8H'}},
        'setup': {'ast': {'execution': {'time_stop': {'value': time_stop},
            'targets': {'value': [{'id': 'tp1', 'risk_multiple': 5.0, 'liquidity_role': 'taker'}]},
            'cost_contract': {'value': {'entry_liquidity_role': 'maker', 'stop_liquidity_role': 'taker',
                                        'funding_interval_seconds': 28800}}}}}}}


def test_kernel_costs_provision_every_funding_interval_of_the_holding_window() -> None:
    rates = dict.fromkeys(('entry_spread_rate', 'stop_spread_rate', 'target_spread_rate', 'entry_slippage_rate',
                           'stop_slippage_rate', 'target_slippage_rate', 'funding_provision_rate'), .0001)
    assert campaign._kernel_costs(_snapshot('PT8H'), rates).funding_intervals_provisioned == 1
    assert campaign._kernel_costs(_snapshot('PT336H'), rates).funding_intervals_provisioned == 42
    with pytest.raises(campaign.CampaignError, match='holding'):
        campaign._kernel_costs(_snapshot('PT2H'), rates)
