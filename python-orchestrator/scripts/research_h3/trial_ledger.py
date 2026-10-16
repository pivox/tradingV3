"""Append-only, hash-chained trial ledger (review R1-F12). Research prototype; nothing is wired to it yet.

Why. The development ``TrialLedger`` in ``validation/walkforward.py`` is in memory and voluntary: a team could try
hundreds of configurations and publish only the best. This ledger makes every attempt durable and countable:

- an ATTEMPT is recorded **before** its result exists (pre-registration of the attempt): complete parameter
  document, datasets, model, generator, catalogue, planned tests, protocol reference, code digest, timestamp; the
  ledger assigns its attempt number within the protocol;
- its RESULT is a later, separate entry that names the attempt by sequence and hash: status, and the result
  document when it is legally available (see below);
- every entry is chained: ``entry_hash = sha256(canonical JSON of seq, prev_hash, kind, recorded_ms, body)``;
  ``verify`` recomputes the chain and refuses any rewrite, truncation, reordering, insertion or foreign file;
- a published result quotes a ``PublicationClaim`` (ledger head, attempt counts of its protocol);
  ``verify_publication`` checks the claim against the ledger, so a result cannot be published without disclosing
  how many attempts its protocol consumed.

Rules on data labels (H3-DATA-ELIGIBILITY.md). SYNTHETIC and DEVELOPMENT attempts are free but counted. An attempt
on VALIDATION, HOLDOUT or PROSPECTIVE data needs a FROZEN protocol and is allowed once per (protocol id, dataset id);
its result can be COMPLETED or FAILED (technical, with a reason) but never ABANDONED, so a bad confirmatory read
cannot be withdrawn. A COMPLETED result must carry its result document ("legally available" = the attempt's protocol
allows the read; a DRAFT protocol can only have read synthetic or development data).

Files. One JSON object per line, UTF-8, canonical JSON (sorted keys, no whitespace, no floats: numbers that are not
integers are strings). The file name must end with ``.h3trials.jsonl`` and its first line is a GENESIS entry: the
ledger never appends to a file it did not create, so it cannot write into a historical registry. Writes append one
line, flush and fsync, after re-verifying that the file on disk is exactly the chain already known.
"""
from __future__ import annotations

import enum
import json
import os
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .contracts import DataLabel, ProtocolRef, ProtocolStatus, canonical_json, check_id, check_sha, sha256_text

ENTRY_SCHEMA = 'h3.trial_ledger_entry/2'
SUFFIX = '.h3trials.jsonl'
CONFIRMATORY = (DataLabel.VALIDATION, DataLabel.HOLDOUT, DataLabel.PROSPECTIVE)
TRIAL_KINDS = ('generator', 'feature_set', 'model', 'policy', 'threshold', 'experiment', 'control')


class LedgerError(ValueError):
    """The ledger or an operation on it is invalid. The message is a stable reason code."""


class EntryKind(str, enum.Enum):
    GENESIS = 'genesis'
    ATTEMPT = 'attempt'
    RESULT = 'result'


class TrialStatus(str, enum.Enum):
    COMPLETED = 'completed'
    FAILED = 'failed'          # technical failure, with a reason code
    ABANDONED = 'abandoned'    # stopped before reading the result (never on confirmatory data)


# ---------------------------------------------------------------------------------------------------- documents

def _refuse_float(_: str) -> Any:
    raise LedgerError('ledger_float_forbidden')


def _check_document(value: Any) -> None:
    """Language-neutral JSON only: dict with str keys, list, str, int, bool, None. No float (hash stability)."""
    if isinstance(value, float):
        raise LedgerError('ledger_float_forbidden')
    if isinstance(value, dict):
        for key, item in value.items():
            if not isinstance(key, str):
                raise LedgerError('ledger_document_invalid')
            _check_document(item)
    elif isinstance(value, list):
        for item in value:
            _check_document(item)
    elif not (value is None or isinstance(value, (str, int, bool))):
        raise LedgerError('ledger_document_invalid')


def document_fingerprint(document: Any) -> str:
    _check_document(document)
    return sha256_text(canonical_json(document))


def _entry_hash(seq: int, prev_hash: str | None, kind: str, recorded_ms: int, body: Mapping[str, Any]) -> str:
    return sha256_text(canonical_json({'body': body, 'kind': kind, 'prev_hash': prev_hash,
                                       'recorded_ms': recorded_ms, 'schema': ENTRY_SCHEMA, 'seq': seq}))


@dataclass(frozen=True)
class Entry:
    seq: int
    prev_hash: str | None
    kind: EntryKind
    recorded_ms: int
    body: dict[str, Any]
    entry_hash: str

    def to_line(self) -> str:
        return canonical_json({'body': self.body, 'entry_hash': self.entry_hash, 'kind': self.kind.value,
                               'prev_hash': self.prev_hash, 'recorded_ms': self.recorded_ms,
                               'schema': ENTRY_SCHEMA, 'seq': self.seq})


@dataclass(frozen=True)
class DatasetUse:
    """A dataset an attempt reads: id, content hash (None only for synthetic data) and label."""

    dataset_id: str
    dataset_sha256: str | None
    label: DataLabel

    def to_document(self) -> dict[str, Any]:
        check_id(self.dataset_id, 'ledger_dataset_invalid')
        if self.dataset_sha256 is not None:
            check_sha(self.dataset_sha256, 'ledger_dataset_invalid')
        elif self.label is not DataLabel.SYNTHETIC:
            raise LedgerError('ledger_real_dataset_needs_sha256')
        return {'dataset_id': self.dataset_id, 'dataset_sha256': self.dataset_sha256, 'label': self.label.value}


@dataclass(frozen=True)
class PublicationClaim:
    """What a published result must quote: the ledger head at publication and its protocol's attempt counts."""

    ledger_id: str
    trial_id: str
    head_seq: int
    head_hash: str
    protocol_id: str
    attempts: int
    results_by_status: tuple[tuple[str, int], ...]
    pending: int

    def to_document(self) -> dict[str, Any]:
        return {'ledger_id': self.ledger_id, 'trial_id': self.trial_id, 'head_seq': self.head_seq,
                'head_hash': self.head_hash, 'protocol_id': self.protocol_id, 'attempts': self.attempts,
                'results_by_status': [list(p) for p in self.results_by_status], 'pending': self.pending}


# ------------------------------------------------------------------------------------------------------ replay

class _State:
    """Chain state rebuilt from entries; every rule is checked here, for appends and for verification alike."""

    def __init__(self) -> None:
        self.entries: list[Entry] = []
        self.ledger_id: str | None = None
        self.attempts: dict[str, Entry] = {}
        self.results: dict[str, Entry] = {}
        self.attempts_per_protocol: dict[str, int] = {}
        self.confirmatory_reads: set[tuple[str, str]] = set()

    @property
    def head(self) -> Entry | None:
        return self.entries[-1] if self.entries else None

    def apply(self, entry: Entry) -> None:
        head = self.head
        if entry.seq != len(self.entries):
            raise LedgerError('ledger_sequence_broken')
        if entry.prev_hash != (head.entry_hash if head else None):
            raise LedgerError('ledger_chain_broken')
        if isinstance(entry.recorded_ms, bool) or not isinstance(entry.recorded_ms, int) or entry.recorded_ms < 0:
            raise LedgerError('ledger_time_invalid')
        if entry.entry_hash != _entry_hash(entry.seq, entry.prev_hash, entry.kind.value, entry.recorded_ms,
                                           entry.body):
            raise LedgerError('ledger_entry_hash_mismatch')
        if head is not None and entry.recorded_ms < head.recorded_ms:
            raise LedgerError('ledger_time_not_monotonic')
        if (entry.seq == 0) != (entry.kind is EntryKind.GENESIS):
            raise LedgerError('ledger_genesis_invalid')
        handler = {EntryKind.GENESIS: self._genesis, EntryKind.ATTEMPT: self._attempt,
                   EntryKind.RESULT: self._result}[entry.kind]
        try:
            handler(entry)
        except LedgerError:
            raise
        except (KeyError, TypeError, ValueError) as error:   # ContractError is a ValueError
            raise LedgerError(f'ledger_{entry.kind.value}_invalid') from error
        self.entries.append(entry)

    def _genesis(self, entry: Entry) -> None:
        body = entry.body
        if set(body) != {'ledger_id', 'purpose'}:
            raise LedgerError('ledger_genesis_invalid')
        self.ledger_id = check_id(body['ledger_id'], 'ledger_genesis_invalid')

    def _attempt(self, entry: Entry) -> None:
        b = entry.body
        expected = {'trial_id', 'kind', 'protocol', 'attempt_number', 'parameters', 'parameters_fingerprint',
                    'datasets', 'data_label', 'model_fingerprint', 'generator_fingerprint',
                    'catalogue_fingerprint', 'tests', 'code_digest'}
        if set(b) != expected:
            raise LedgerError('ledger_attempt_fields_invalid')
        trial_id = check_id(b['trial_id'], 'ledger_trial_id_invalid')
        if trial_id in self.attempts:
            raise LedgerError('ledger_trial_duplicate')
        if b['kind'] not in TRIAL_KINDS:
            raise LedgerError('ledger_trial_kind_invalid')
        protocol = ProtocolRef.from_wire(b['protocol'])
        if document_fingerprint(b['parameters']) != b['parameters_fingerprint']:
            raise LedgerError('ledger_parameters_fingerprint_mismatch')
        for key in ('model_fingerprint', 'generator_fingerprint', 'catalogue_fingerprint'):
            if b[key] is not None:
                check_sha(b[key], 'ledger_fingerprint_invalid')
        check_sha(b['code_digest'], 'ledger_fingerprint_invalid')
        if not isinstance(b['tests'], list) or not b['tests'] or any(not isinstance(t, str) for t in b['tests']):
            raise LedgerError('ledger_tests_required')
        datasets = b['datasets']
        if not isinstance(datasets, list) or not datasets:
            raise LedgerError('ledger_dataset_required')
        labels = []
        for d in datasets:
            use = DatasetUse(d['dataset_id'], d['dataset_sha256'], DataLabel(d['label']))
            if use.to_document() != d:
                raise LedgerError('ledger_dataset_invalid')
            labels.append(use.label)
        label = DataLabel(b['data_label'])
        if label is not _most_confirmatory(labels):
            raise LedgerError('ledger_data_label_mismatch')
        number = self.attempts_per_protocol.get(protocol.protocol_id, 0) + 1
        if b['attempt_number'] != number:
            raise LedgerError('ledger_attempt_number_mismatch')
        if label in CONFIRMATORY:
            if protocol.status is not ProtocolStatus.FROZEN:
                raise LedgerError('ledger_confirmatory_needs_frozen_protocol')
            reads = {(protocol.protocol_id, d['dataset_id']) for d in datasets
                     if DataLabel(d['label']) in CONFIRMATORY}
            if reads & self.confirmatory_reads:
                raise LedgerError('ledger_confirmatory_data_already_read')
            self.confirmatory_reads |= reads
        self.attempts_per_protocol[protocol.protocol_id] = number
        self.attempts[trial_id] = entry

    def _result(self, entry: Entry) -> None:
        b = entry.body
        if set(b) != {'trial_id', 'attempt_seq', 'attempt_hash', 'status', 'reason', 'result',
                      'result_fingerprint'}:
            raise LedgerError('ledger_result_fields_invalid')
        attempt = self.attempts.get(b['trial_id'])
        if attempt is None:
            raise LedgerError('ledger_result_without_attempt')
        if b['trial_id'] in self.results:
            raise LedgerError('ledger_result_duplicate')
        if b['attempt_seq'] != attempt.seq or b['attempt_hash'] != attempt.entry_hash:
            raise LedgerError('ledger_result_attempt_mismatch')
        status = TrialStatus(b['status'])
        confirmatory = DataLabel(attempt.body['data_label']) in CONFIRMATORY
        if status is TrialStatus.ABANDONED and confirmatory:
            raise LedgerError('ledger_confirmatory_cannot_be_abandoned')
        if status is TrialStatus.COMPLETED:
            if b['result'] is None or b['reason'] is not None:
                raise LedgerError('ledger_completed_needs_result')
        elif not isinstance(b['reason'], str) or not b['reason']:
            raise LedgerError('ledger_result_reason_required')
        if b['result'] is None:
            if b['result_fingerprint'] is not None:
                raise LedgerError('ledger_result_fingerprint_mismatch')
        elif document_fingerprint(b['result']) != b['result_fingerprint']:
            raise LedgerError('ledger_result_fingerprint_mismatch')
        self.results[b['trial_id']] = entry


def _most_confirmatory(labels: Sequence[DataLabel]) -> DataLabel:
    order = (DataLabel.SYNTHETIC, DataLabel.DEVELOPMENT, DataLabel.VALIDATION, DataLabel.HOLDOUT,
             DataLabel.PROSPECTIVE)
    return max(labels, key=order.index)


def _parse_line(line: str) -> Entry:
    try:
        raw = json.loads(line, parse_float=_refuse_float)
    except json.JSONDecodeError as error:
        raise LedgerError('ledger_line_invalid') from error
    if not isinstance(raw, dict) or raw.get('schema') != ENTRY_SCHEMA or set(raw) != {
            'body', 'entry_hash', 'kind', 'prev_hash', 'recorded_ms', 'schema', 'seq'}:
        raise LedgerError('ledger_foreign_or_invalid_line')
    try:
        kind = EntryKind(raw['kind'])
    except ValueError as error:
        raise LedgerError('ledger_foreign_or_invalid_line') from error
    if not isinstance(raw['body'], dict) or isinstance(raw['seq'], bool) or not isinstance(raw['seq'], int):
        raise LedgerError('ledger_foreign_or_invalid_line')
    entry = Entry(seq=raw['seq'], prev_hash=raw['prev_hash'], kind=kind, recorded_ms=raw['recorded_ms'],
                  body=raw['body'], entry_hash=raw['entry_hash'])
    if entry.to_line() != line:
        raise LedgerError('ledger_line_not_canonical')
    return entry


def _check_path(path: Path) -> None:
    if not path.name.endswith(SUFFIX):
        raise LedgerError('ledger_path_suffix_invalid')


def verify(path: str | os.PathLike[str]) -> _State:
    """Rebuild and check the whole chain. Raises ``LedgerError`` with the first violated rule."""
    file = Path(path)
    _check_path(file)
    data = file.read_bytes()
    if not data:
        raise LedgerError('ledger_empty')
    if not data.endswith(b'\n'):
        raise LedgerError('ledger_truncated_line')
    state = _State()
    try:
        text = data.decode('utf-8')
    except UnicodeDecodeError as error:
        raise LedgerError('ledger_line_invalid') from error
    for line in text.split('\n')[:-1]:
        state.apply(_parse_line(line))
    return state


# ------------------------------------------------------------------------------------------------------ ledger

class TrialLedger:
    """A ledger file opened for appending. Use ``create`` for a new file and ``open`` for an existing one."""

    def __init__(self, path: Path, state: _State) -> None:
        self.path = path
        self._state = state
        self._size = path.stat().st_size

    @classmethod
    def create(cls, path: str | os.PathLike[str], *, ledger_id: str, purpose: str, recorded_ms: int) -> TrialLedger:
        file = Path(path)
        _check_path(file)
        if file.exists():
            raise LedgerError('ledger_already_exists')
        body = {'ledger_id': check_id(ledger_id, 'ledger_genesis_invalid'), 'purpose': purpose}
        entry = Entry(0, None, EntryKind.GENESIS, recorded_ms, body,
                      _entry_hash(0, None, EntryKind.GENESIS.value, recorded_ms, body))
        state = _State()
        state.apply(entry)
        with open(file, 'x', encoding='utf-8') as handle:   # 'x': never overwrite
            handle.write(entry.to_line() + '\n')
            handle.flush()
            os.fsync(handle.fileno())
        return cls(file, state)

    @classmethod
    def open(cls, path: str | os.PathLike[str]) -> TrialLedger:
        file = Path(path)
        return cls(file, verify(file))

    # ---------------------------------------------------------------------------------------------- reading
    @property
    def ledger_id(self) -> str:
        assert self._state.ledger_id is not None
        return self._state.ledger_id

    @property
    def head(self) -> tuple[int, str]:
        head = self._state.head
        assert head is not None
        return head.seq, head.entry_hash

    def entries(self) -> tuple[Entry, ...]:
        return tuple(self._state.entries)

    def attempts(self, protocol_id: str) -> int:
        return self._state.attempts_per_protocol.get(protocol_id, 0)

    # ---------------------------------------------------------------------------------------------- writing
    def _append(self, kind: EntryKind, body: dict[str, Any], recorded_ms: int) -> Entry:
        # The file on disk must still be exactly the chain we know: refuse rewrites, truncation and appends by
        # anyone else, then apply every rule to the new entry before writing it.
        on_disk = verify(self.path)
        if [e.entry_hash for e in on_disk.entries] != [e.entry_hash for e in self._state.entries] \
                or self.path.stat().st_size != self._size:
            raise LedgerError('ledger_changed_on_disk')
        _check_document(body)
        head = self._state.head
        assert head is not None
        seq, prev = head.seq + 1, head.entry_hash
        entry = Entry(seq, prev, kind, recorded_ms, body, _entry_hash(seq, prev, kind.value, recorded_ms, body))
        self._state.apply(entry)
        with open(self.path, 'a', encoding='utf-8') as handle:
            handle.write(entry.to_line() + '\n')
            handle.flush()
            os.fsync(handle.fileno())
        self._size = self.path.stat().st_size
        return entry

    def record_attempt(self, *, trial_id: str, kind: str, protocol: ProtocolRef, parameters: Mapping[str, Any],
                       datasets: Sequence[DatasetUse], model_fingerprint: str | None,
                       generator_fingerprint: str | None, catalogue_fingerprint: str | None,
                       tests: Sequence[str], code_digest: str, recorded_ms: int) -> Entry:
        """Pre-register one attempt, before any result exists. Returns the chained entry."""
        if not datasets:
            raise LedgerError('ledger_dataset_required')
        documents = [d.to_document() for d in datasets]
        params = dict(parameters)
        body = {'trial_id': trial_id, 'kind': kind, 'protocol': protocol.to_wire(),
                'attempt_number': self.attempts(protocol.protocol_id) + 1, 'parameters': params,
                'parameters_fingerprint': document_fingerprint(params), 'datasets': documents,
                'data_label': _most_confirmatory([d.label for d in datasets]).value,
                'model_fingerprint': model_fingerprint, 'generator_fingerprint': generator_fingerprint,
                'catalogue_fingerprint': catalogue_fingerprint, 'tests': list(tests), 'code_digest': code_digest}
        return self._append(EntryKind.ATTEMPT, body, recorded_ms)

    def record_result(self, *, trial_id: str, status: TrialStatus, result: Mapping[str, Any] | None,
                      reason: str | None, recorded_ms: int) -> Entry:
        attempt = self._state.attempts.get(trial_id)
        if attempt is None:
            raise LedgerError('ledger_result_without_attempt')
        document = None if result is None else dict(result)
        body = {'trial_id': trial_id, 'attempt_seq': attempt.seq, 'attempt_hash': attempt.entry_hash,
                'status': status.value, 'reason': reason, 'result': document,
                'result_fingerprint': None if document is None else document_fingerprint(document)}
        return self._append(EntryKind.RESULT, body, recorded_ms)

    # ------------------------------------------------------------------------------------------ publication
    def publication_claim(self, trial_id: str) -> PublicationClaim:
        """The claim a publication of ``trial_id``'s result must quote (the trial must have a COMPLETED result)."""
        result = self._state.results.get(trial_id)
        if result is None or result.body['status'] != TrialStatus.COMPLETED.value:
            raise LedgerError('ledger_publication_without_completed_result')
        return _claim(self._state, self.ledger_id, trial_id, len(self._state.entries) - 1)


def _claim(state: _State, ledger_id: str, trial_id: str, head_seq: int) -> PublicationClaim:
    entries = state.entries[:head_seq + 1]
    protocol_id = state.attempts[trial_id].body['protocol']['protocol_id']
    attempts = [e for e in entries if e.kind is EntryKind.ATTEMPT and e.body['protocol']['protocol_id'] == protocol_id]
    ids = {e.body['trial_id'] for e in attempts}
    statuses: dict[str, int] = {}
    for e in entries:
        if e.kind is EntryKind.RESULT and e.body['trial_id'] in ids:
            statuses[e.body['status']] = statuses.get(e.body['status'], 0) + 1
    return PublicationClaim(ledger_id=ledger_id, trial_id=trial_id, head_seq=head_seq,
                            head_hash=entries[-1].entry_hash, protocol_id=protocol_id, attempts=len(attempts),
                            results_by_status=tuple(sorted(statuses.items())),
                            pending=len(attempts) - sum(statuses.values()))


def verify_publication(path: str | os.PathLike[str], claim: PublicationClaim) -> tuple[str, ...]:
    """Reasons the claim does not match the ledger (empty: the publication discloses the ledger faithfully).

    The claimed head must be an entry of the verified chain, the trial's COMPLETED result must precede it, and the
    disclosed attempt counts must be those of the protocol up to that head.
    """
    state = verify(path)
    if claim.ledger_id != state.ledger_id:
        return ('publication_ledger_mismatch',)
    if not 0 <= claim.head_seq < len(state.entries) or state.entries[claim.head_seq].entry_hash != claim.head_hash:
        return ('publication_head_not_in_ledger',)
    result = state.results.get(claim.trial_id)
    if result is None or result.seq > claim.head_seq or result.body['status'] != TrialStatus.COMPLETED.value:
        return ('publication_result_not_before_head',)
    if _claim(state, claim.ledger_id, claim.trial_id, claim.head_seq) != claim:
        return ('publication_counts_not_disclosed',)
    return ()
