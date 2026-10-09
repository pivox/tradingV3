import json
import os
from decimal import Decimal as D
from pathlib import Path

import pytest

from app.backtesting.research import campaign_evidence as e


def test_private_immutable_files_bounded_sinks_and_replay(tmp_path):
    source = tmp_path/'source'
    source.mkdir()
    out = tmp_path/'output'
    with e.Evidence(out, (source,), max_bytes=100000, min_free_bytes=0) as evidence:
        manifest = evidence.json('manifest.json', {'schema_version':'synthetic'})
        evidence.emit('cashflows', {'plan_hash':'one','kind':'entry_fee','amount_quote':D('-.05')})
        evidence.emit('cashflows', {'plan_hash':'one','kind':'gross_pnl','amount_quote':D('1.25')})
        evidence.emit('cashflows', {'plan_hash':'one','kind':'target_fee','amount_quote':D('-.10')})
        evidence.emit('trades', {'plan_hash':'one','gross_pnl_quote':D('1.25'),
            'net_pnl_quote':D('1.10'),'fees_quote':D('.15'),'funding_quote':D('0'),
            'spread_quote':D('0'),'slippage_quote':D('0')})
        files = evidence.finish_ledgers()
        result = e.reconcile(out, {'wallet_quote':D('100001.10'), 'net_pnl_quote':D('1.10'),'trades':1})
        assert result['gross_pnl_quote'] == D('1.25')
        assert result['fees_quote'] == D('.15')
        assert files['trades.ndjson']['count'] == 1
        assert len(manifest) == 64
        with pytest.raises(e.EvidenceError): evidence.json('manifest.json', {})
    assert out.stat().st_mode & 0o777 == 0o700
    for path in out.iterdir(): assert path.stat().st_mode & 0o777 == 0o600


def test_failure_preserves_partial_records(tmp_path):
    out = tmp_path/'run'
    with pytest.raises(RuntimeError):
        with e.Evidence(out, (), max_bytes=10000,min_free_bytes=0) as evidence:
            evidence.emit('events', {'kind':'before-failure'})
            raise RuntimeError('aborted')
    assert (out/'events.partial.ndjson').read_text()
    assert not (out/'events.ndjson').exists()


@pytest.mark.parametrize('kind',['existing','source','repository','symlink','relative','missingparent'])
def test_output_path_protection(tmp_path, kind):
    source = tmp_path/'source'
    source.mkdir()
    out = tmp_path/'run'
    if kind == 'existing': out.mkdir()
    if kind == 'source': out = source/'run'
    if kind == 'repository': (tmp_path/'.git').mkdir()
    if kind == 'symlink':
        out.symlink_to(source, target_is_directory=True)
    if kind == 'relative': out = Path('relative')
    if kind == 'missingparent': out = tmp_path/'absent'/'run'
    with pytest.raises(e.EvidenceError): e.Evidence(out,(source,),max_bytes=10000,min_free_bytes=0)


def test_disk_limits_and_unsafe_artifact_names(tmp_path):
    with e.Evidence(tmp_path/'cap',(),max_bytes=10,min_free_bytes=0) as evidence:
        with pytest.raises(e.EvidenceError): evidence.json('manifest.json', {'long':'x'*20})
        with pytest.raises(e.EvidenceError): evidence.json('../escape',{})
        with pytest.raises(e.EvidenceError): evidence.emit('unknown',{})
    with e.Evidence(tmp_path/'free',(),max_bytes=10000,min_free_bytes=10**30) as evidence:
        with pytest.raises(e.EvidenceError): evidence.json('manifest.json',{})


@pytest.mark.parametrize('mutation', ['wallet','net','trades','trade_net','orphan','duplicate','sequence','unknown_kind'])
def test_independent_reconciliation_rejects_tampering(tmp_path,mutation):
    out = tmp_path/'run'
    with e.Evidence(out,(),max_bytes=10000,min_free_bytes=0) as evidence:
        evidence.emit('cashflows', {'plan_hash':'one','kind':'gross_pnl' if mutation != 'unknown_kind' else 'invented','amount_quote':D('1')})
        trade = {'plan_hash':'one','gross_pnl_quote':'1','net_pnl_quote':'1',
            'fees_quote':'0','funding_quote':'0','spread_quote':'0','slippage_quote':'0'}
        if mutation == 'trade_net': trade['net_pnl_quote'] = '2'
        if mutation != 'orphan': evidence.emit('trades',trade)
        if mutation == 'duplicate': evidence.emit('trades',trade)
        evidence.finish_ledgers()
    summary = {'wallet_quote':'100001','net_pnl_quote':'1','trades':1}
    if mutation in ('wallet','net'): summary[mutation+'_quote' if mutation == 'wallet' else 'net_pnl_quote'] = '0'
    if mutation == 'trades': summary['trades'] = 2
    if mutation == 'sequence':
        path = out/'trades.ndjson'
        row = json.loads(path.read_text())
        row['ledger_sequence'] = 0
        path.write_text(json.dumps(row)+'\n')
    with pytest.raises(e.EvidenceError): e.reconcile(out,summary)


def test_empty_ledger_and_interleaved_positions(tmp_path):
    out = tmp_path/'empty'
    with e.Evidence(out,(),max_bytes=10000,min_free_bytes=0) as evidence:
        evidence.finish_ledgers()
    assert e.reconcile(out, {'wallet_quote':'100000','net_pnl_quote':'0','trades':0})['trades'] == 0
    out = tmp_path/'interleaved'
    with e.Evidence(out,(),max_bytes=10000,min_free_bytes=0) as evidence:
        for name in ('one','two'):
            evidence.emit('cashflows',{'plan_hash':name,'kind':'funding','amount_quote':D('.1')})
        for name in ('two','one'):
            evidence.emit('trades',{'plan_hash':name,'net_pnl_quote':'.1','gross_pnl_quote':'0',
                'fees_quote':'0','funding_quote':'.1','spread_quote':'0','slippage_quote':'0'})
        evidence.finish_ledgers()
    assert e.reconcile(out,{'wallet_quote':'100000.2','net_pnl_quote':'.2','trades':2})['funding_quote'] == D('.2')


def test_nonfinite_decimal_unsupported_value_and_bad_limits(tmp_path):
    with pytest.raises(e.EvidenceError): e.encoded({'amount':D('NaN')})
    with pytest.raises(TypeError): e.encoded({'amount':object()})
    for cap,reserve in ((0,0),(1,-1),(True,0)):
        with pytest.raises(e.EvidenceError): e.Evidence(tmp_path/'bad',(),max_bytes=cap,min_free_bytes=reserve)


def test_sequence_ownership_line_cap_and_finished_publication(tmp_path):
    with e.Evidence(tmp_path/'run',(),max_bytes=10*e.MAX_LINE,min_free_bytes=0) as evidence:
        with pytest.raises(e.EvidenceError): evidence.emit('events',{'ledger_sequence':0})
        with pytest.raises(e.EvidenceError): evidence.emit('events',{'large':'x'*e.MAX_LINE})
        evidence.finish_ledgers()
        with pytest.raises(e.EvidenceError): evidence.finish_ledgers()
        with pytest.raises(e.EvidenceError): evidence.emit('events',{})


@pytest.mark.parametrize('row',[b'{}',b'{"ledger_sequence":true}\n',b'{"ledger_sequence":0}\n{"ledger_sequence":0}\n'])
def test_replay_framing_and_physical_sequence(row,tmp_path):
    path = tmp_path/'ledger'
    path.write_bytes(row)
    with pytest.raises(e.EvidenceError): list(e._rows(path))


def test_replay_costs_plan_identity_and_concurrency(tmp_path):
    for kind in ('spread','slippage'):
        out = tmp_path/kind
        with e.Evidence(out,(),max_bytes=10000,min_free_bytes=0) as evidence:
            evidence.emit('cashflows',{'plan_hash':'one','kind':'entry_'+kind,'amount_quote':'-.1'})
            evidence.emit('trades',{'plan_hash':'one','gross_pnl_quote':'0','net_pnl_quote':'-.1',
                'fees_quote':'0','funding_quote':'0','spread_quote':'.1' if kind == 'spread' else '0',
                'slippage_quote':'.1' if kind == 'slippage' else '0'})
            evidence.finish_ledgers()
        assert e.reconcile(out,{'wallet_quote':'99999.9','net_pnl_quote':'-.1','trades':1})[kind+'_quote'] == D('.1')
    for mutation in ('identity','credit','concurrency'):
        out = tmp_path/mutation
        with e.Evidence(out,(),max_bytes=10000,min_free_bytes=0) as evidence:
            for index in range(5 if mutation == 'concurrency' else 1):
                evidence.emit('cashflows',{'plan_hash':None if mutation == 'identity' else str(index),
                    'kind':'entry_fee','amount_quote':'.1' if mutation == 'credit' else '-.1'})
            evidence.finish_ledgers()
        with pytest.raises(e.EvidenceError): e.reconcile(out,{'wallet_quote':'100000','net_pnl_quote':'0','trades':0})
