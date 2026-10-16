"""H3 trial ledger (review R1-F12): append-only, hash-chained, attempts pre-registered before results.

Every ledger is written under pytest's tmp_path; no existing registry is touched. Synthetic values only.
Run: python -m pytest --noconftest tests/test_research_h3_trial_ledger.py
"""
from __future__ import annotations

import dataclasses
import json

import pytest

from scripts.research_h3 import contracts as c
from scripts.research_h3 import synthetic as s
from scripts.research_h3 import trial_ledger as tl

FROZEN = dataclasses.replace(s.DRAFT_PROTOCOL, protocol_id='h3a-proto', status=c.ProtocolStatus.FROZEN)
SYN = tl.DatasetUse('synthetic-fixture', None, c.DataLabel.SYNTHETIC)
HOLD = tl.DatasetUse('okx-holdout', c.sha256_text('okx-holdout'), c.DataLabel.HOLDOUT)
T = s.T0_MS


def new(tmp_path, name='h3a.h3trials.jsonl'):
    return tl.TrialLedger.create(tmp_path / name, ledger_id='h3a-dev', purpose='synthetic test', recorded_ms=T)


def attempt(ledger, trial_id, *, protocol=s.DRAFT_PROTOCOL, datasets=(SYN,), params=None, at=T + 1, **kw):
    values = dict(trial_id=trial_id, kind='model', protocol=protocol,
                  parameters=params or {'ridge_lambda': '0.1', 'features': ['ret_24h']}, datasets=datasets,
                  model_fingerprint=c.sha256_text('model'), generator_fingerprint=c.sha256_text('gen'),
                  catalogue_fingerprint=c.sha256_text('cat'), tests=['S1'], code_digest=c.sha256_text('code'),
                  recorded_ms=at)
    values.update(kw)
    return ledger.record_attempt(**values)


def test_attempts_are_chained_numbered_and_verified(tmp_path):
    ledger = new(tmp_path)
    a1 = attempt(ledger, 't-1')
    a2 = attempt(ledger, 't-2', params={'ridge_lambda': '1'})
    assert (a1.body['attempt_number'], a2.body['attempt_number']) == (1, 2)
    assert a2.prev_hash == a1.entry_hash and ledger.attempts('h3-draft') == 2
    ledger.record_result(trial_id='t-1', status=tl.TrialStatus.COMPLETED, result={'rank_ic': '0.01'}, reason=None,
                         recorded_ms=T + 5)
    state = tl.verify(ledger.path)
    assert [e.kind for e in state.entries] == [tl.EntryKind.GENESIS, tl.EntryKind.ATTEMPT, tl.EntryKind.ATTEMPT,
                                               tl.EntryKind.RESULT]
    reopened = tl.TrialLedger.open(ledger.path)
    assert reopened.head == ledger.head and reopened.attempts('h3-draft') == 2


def test_entries_record_complete_parameters_and_identities(tmp_path):
    ledger = new(tmp_path)
    entry = attempt(ledger, 't-1')
    body = entry.body
    assert body['parameters_fingerprint'] == tl.document_fingerprint(body['parameters'])
    for key in ('datasets', 'data_label', 'model_fingerprint', 'generator_fingerprint', 'catalogue_fingerprint',
                'tests', 'code_digest', 'protocol', 'attempt_number'):
        assert key in body
    with pytest.raises(tl.LedgerError, match='ledger_float_forbidden'):
        attempt(ledger, 't-2', params={'ridge_lambda': 0.1})
    with pytest.raises(tl.LedgerError, match='ledger_tests_required'):
        attempt(ledger, 't-3', tests=[])
    with pytest.raises(tl.LedgerError, match='ledger_real_dataset_needs_sha256'):
        attempt(ledger, 't-4', datasets=(tl.DatasetUse('okx-dev', None, c.DataLabel.DEVELOPMENT),))
    with pytest.raises(tl.LedgerError, match='ledger_trial_kind_invalid'):
        attempt(ledger, 't-5', kind='lucky_guess')
    with pytest.raises(tl.LedgerError, match='ledger_trial_duplicate'):
        attempt(ledger, 't-1')
    assert tl.verify(ledger.path).head.entry_hash == entry.entry_hash   # refused appends wrote nothing


def test_results_follow_their_attempt(tmp_path):
    ledger = new(tmp_path)
    with pytest.raises(tl.LedgerError, match='ledger_result_without_attempt'):
        ledger.record_result(trial_id='nope', status=tl.TrialStatus.COMPLETED, result={}, reason=None,
                             recorded_ms=T + 1)
    attempt(ledger, 't-1', at=T + 10)
    with pytest.raises(tl.LedgerError, match='ledger_time_not_monotonic'):
        ledger.record_result(trial_id='t-1', status=tl.TrialStatus.COMPLETED, result={}, reason=None,
                             recorded_ms=T + 9)
    with pytest.raises(tl.LedgerError, match='ledger_completed_needs_result'):
        ledger.record_result(trial_id='t-1', status=tl.TrialStatus.COMPLETED, result=None, reason=None,
                             recorded_ms=T + 11)
    with pytest.raises(tl.LedgerError, match='ledger_result_reason_required'):
        ledger.record_result(trial_id='t-1', status=tl.TrialStatus.ABANDONED, result=None, reason=None,
                             recorded_ms=T + 11)
    ledger.record_result(trial_id='t-1', status=tl.TrialStatus.ABANDONED, result=None, reason='owner_stop',
                         recorded_ms=T + 11)
    with pytest.raises(tl.LedgerError, match='ledger_result_duplicate'):
        ledger.record_result(trial_id='t-1', status=tl.TrialStatus.COMPLETED, result={}, reason=None,
                             recorded_ms=T + 12)


def test_confirmatory_data_needs_frozen_protocol_once_and_cannot_be_abandoned(tmp_path):
    ledger = new(tmp_path)
    with pytest.raises(tl.LedgerError, match='ledger_confirmatory_needs_frozen_protocol'):
        attempt(ledger, 't-h0', datasets=(SYN, HOLD))
    entry = attempt(ledger, 't-h1', protocol=FROZEN, datasets=(SYN, HOLD))
    assert entry.body['data_label'] == 'holdout'
    with pytest.raises(tl.LedgerError, match='ledger_confirmatory_data_already_read'):
        attempt(ledger, 't-h2', protocol=dataclasses.replace(FROZEN, protocol_version='0.2.0'), datasets=(HOLD,))
    with pytest.raises(tl.LedgerError, match='ledger_confirmatory_cannot_be_abandoned'):
        ledger.record_result(trial_id='t-h1', status=tl.TrialStatus.ABANDONED, result=None, reason='bad_luck',
                             recorded_ms=T + 2)
    ledger.record_result(trial_id='t-h1', status=tl.TrialStatus.COMPLETED, result={'verdict': 'FAIL'},
                         reason=None, recorded_ms=T + 2)


def _rewrite(path, mutate):
    lines = path.read_text().splitlines(keepends=True)
    path.write_text(''.join(mutate(lines)))


def _three(tmp_path):
    ledger = new(tmp_path)
    for i in range(3):
        attempt(ledger, f't-{i}', at=T + 1 + i)
    return ledger


@pytest.mark.parametrize('mutate, code', [
    (lambda ls: ls[:-1], None),                                            # truncation
    (lambda ls: [ls[0], ls[2], ls[1], ls[3]], 'ledger_sequence_broken'),   # reordering
    (lambda ls: ls[:2] + ls[3:], 'ledger_sequence_broken'),                # deletion in the middle
    (lambda ls: [ls[0], ls[1].replace('0.1', '0.2'), ls[2], ls[3]], 'ledger_entry_hash_mismatch'),  # rewrite
    (lambda ls: ls + [ls[-1]], 'ledger_sequence_broken'),                  # replay of an entry
    (lambda ls: [ls[0], ls[1], ls[2], ls[3][:-1]], 'ledger_truncated_line'),
])
def test_tampering_is_detected_and_appends_are_refused(tmp_path, mutate, code):
    ledger = _three(tmp_path)
    _rewrite(ledger.path, mutate)
    if code is None:   # a clean truncation verifies on its own; the writer still detects it
        assert len(tl.verify(ledger.path).entries) == 3
        with pytest.raises(tl.LedgerError, match='ledger_changed_on_disk'):
            attempt(ledger, 't-new', at=T + 10)
        return
    with pytest.raises(tl.LedgerError, match=code):
        tl.verify(ledger.path)
    with pytest.raises(tl.LedgerError):
        attempt(ledger, 't-new', at=T + 10)


def test_rewritten_chain_with_recomputed_hashes_is_detected_by_the_writer(tmp_path):
    ledger = _three(tmp_path)
    forged = tl.TrialLedger.create(tmp_path / 'forged.h3trials.jsonl', ledger_id='h3a-dev', purpose='synthetic test',
                                   recorded_ms=T)
    attempt(forged, 't-0', at=T + 1)       # same history up to t-0, then a different chain
    ledger.path.write_bytes(forged.path.read_bytes())
    with pytest.raises(tl.LedgerError, match='ledger_changed_on_disk'):
        attempt(ledger, 't-new', at=T + 10)


def test_ledger_never_writes_foreign_or_existing_files(tmp_path):
    registry = tmp_path / 'registry.jsonl'
    registry.write_text('{"dataset_id":"x"}\n')
    with pytest.raises(tl.LedgerError, match='ledger_path_suffix_invalid'):
        tl.TrialLedger.create(registry, ledger_id='x', purpose='p', recorded_ms=T)
    with pytest.raises(tl.LedgerError, match='ledger_path_suffix_invalid'):
        tl.TrialLedger.open(registry)
    disguised = tmp_path / 'old.h3trials.jsonl'
    disguised.write_text('{"dataset_id":"x"}\n')
    with pytest.raises(tl.LedgerError, match='ledger_already_exists'):
        tl.TrialLedger.create(disguised, ledger_id='x', purpose='p', recorded_ms=T)
    with pytest.raises(tl.LedgerError, match='ledger_foreign_or_invalid_line'):
        tl.TrialLedger.open(disguised)
    assert registry.read_text() == '{"dataset_id":"x"}\n' and disguised.read_text() == '{"dataset_id":"x"}\n'


def test_publication_must_disclose_every_attempt_of_its_protocol(tmp_path):
    ledger = new(tmp_path)
    for i in range(5):    # a team tries five configurations ...
        attempt(ledger, f't-{i}', params={'ridge_lambda': str(i)}, at=T + 1 + i)
    for i in range(4):
        ledger.record_result(trial_id=f't-{i}', status=tl.TrialStatus.COMPLETED, result={'ic': str(i)}, reason=None,
                             recorded_ms=T + 10 + i)
    claim = ledger.publication_claim('t-3')   # ... and wants to publish the best one
    assert claim.attempts == 5 and claim.pending == 1 and claim.results_by_status == (('completed', 4),)
    assert tl.verify_publication(ledger.path, claim) == ()
    understated = dataclasses.replace(claim, attempts=1)
    assert tl.verify_publication(ledger.path, understated) == ('publication_counts_not_disclosed',)
    assert tl.verify_publication(ledger.path, dataclasses.replace(claim, head_hash=c.sha256_text('x'))) \
        == ('publication_head_not_in_ledger',)
    early = dataclasses.replace(claim, head_seq=6, head_hash=ledger.entries()[6].entry_hash)
    assert tl.verify_publication(ledger.path, early) == ('publication_result_not_before_head',)
    with pytest.raises(tl.LedgerError, match='ledger_publication_without_completed_result'):
        ledger.publication_claim('t-4')
    json.dumps(claim.to_document())   # the claim is JSON-ready for a report


def test_ledger_bytes_are_reproducible(tmp_path):
    first, second = new(tmp_path, 'a.h3trials.jsonl'), new(tmp_path, 'b.h3trials.jsonl')
    for ledger in (first, second):
        attempt(ledger, 't-1')
        ledger.record_result(trial_id='t-1', status=tl.TrialStatus.FAILED, result=None, reason='simulator_error',
                             recorded_ms=T + 2)
    assert first.path.read_bytes() == second.path.read_bytes()
    assert first.head == second.head
