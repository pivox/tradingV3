"""Append-only, hash-chained H3 decision journal (``contracts.DecisionAudit``), one chain per experiment and variant.

Each record carries its sequence number and the fingerprint of the previous record, so any edit, deletion,
insertion or reordering breaks ``verify()``. The journal implements ``contracts.AuditSink`` (``append``) and adds
``record`` (build and append the next record) and a JSON-lines round trip (``dump_jsonl`` / ``load_jsonl``).

Subjects that are not shared contracts (orders, fills, exits, refusals of the simulator) are fingerprinted from
their canonical JSON with a private schema id ``h3.integration.<kind>/1``.

The chain proves internal consistency only. It is not a signature: anyone who can rewrite the whole file can rebuild
a valid chain. Anchoring ``head_fingerprint()`` outside the journal (report, commit) is what makes it tamper-evident.
"""
from __future__ import annotations

import json
from collections.abc import Mapping
from typing import Any

from ..contracts import (
    AuditEvent, ContractError, DecisionAudit, Header, ModelRef, PolicyRef, Variant, canonical_json, check_id,
    sha256_text,
)

PRIVATE_SCHEMA_PREFIX = 'h3.integration.'


def private_subject(kind: str, document: Mapping[str, Any]) -> tuple[str, str]:
    """(schema id, fingerprint) of a non-contract subject given as a JSON-ready mapping."""
    schema = check_id(PRIVATE_SCHEMA_PREFIX + kind + '/1')
    return schema, sha256_text(canonical_json({**document, 'schema': schema}))


class AuditJournal:
    """In-memory append-only journal for one (experiment, variant). Records are immutable contract values."""

    def __init__(self, experiment_id: str, variant: Variant | None) -> None:
        self.experiment_id = check_id(experiment_id)
        self.variant = variant
        self._records: list[DecisionAudit] = []

    @property
    def records(self) -> tuple[DecisionAudit, ...]:
        return tuple(self._records)

    def __len__(self) -> int:
        return len(self._records)

    def head_fingerprint(self) -> str | None:
        return self._records[-1].fingerprint() if self._records else None

    def _audit_id(self, sequence: int) -> str:
        return 'aud-' + (self.variant.value if self.variant is not None else 'x') + '-' + format(sequence, '08d')

    def append(self, record: DecisionAudit) -> None:
        """AuditSink: accept only the exact next record of this chain."""
        if record.experiment_id != self.experiment_id or record.variant != self.variant:
            raise ContractError('h3_audit_scope_mismatch')
        if record.sequence != len(self._records) or record.previous_audit_fingerprint != self.head_fingerprint():
            raise ContractError('h3_audit_append_out_of_order')
        if record.audit_id != self._audit_id(record.sequence):
            raise ContractError('h3_audit_id_invalid')
        self._records.append(record)

    def record(self, *, header: Header, event: AuditEvent, subject_schema: str, subject_id: str,
               subject_fingerprint: str, model: ModelRef | None = None, policy: PolicyRef | None = None,
               reasons: tuple[str, ...] = ()) -> DecisionAudit:
        sequence = len(self._records)
        audit = DecisionAudit(header=header, audit_id=self._audit_id(sequence), sequence=sequence,
                              experiment_id=self.experiment_id, variant=self.variant, event=event,
                              subject_schema=subject_schema, subject_id=subject_id,
                              subject_fingerprint=subject_fingerprint,
                              previous_audit_fingerprint=self.head_fingerprint(), model=model, policy=policy,
                              reasons=tuple(dict.fromkeys(reasons)))
        self.append(audit)
        return audit

    def record_contract(self, *, header: Header, event: AuditEvent, subject: Any, subject_id: str,
                        model: ModelRef | None = None, policy: PolicyRef | None = None,
                        reasons: tuple[str, ...] = ()) -> DecisionAudit:
        """Record a shared-contract subject (its own schema id and fingerprint)."""
        return self.record(header=header, event=event, subject_schema=subject.SCHEMA, subject_id=subject_id,
                           subject_fingerprint=subject.fingerprint(), model=model, policy=policy, reasons=reasons)

    def record_private(self, *, header: Header, event: AuditEvent, kind: str, document: Mapping[str, Any],
                       subject_id: str, policy: PolicyRef | None = None,
                       reasons: tuple[str, ...] = ()) -> DecisionAudit:
        schema, fingerprint = private_subject(kind, document)
        return self.record(header=header, event=event, subject_schema=schema, subject_id=subject_id,
                           subject_fingerprint=fingerprint, policy=policy, reasons=reasons)

    def verify(self) -> tuple[str, ...]:
        """Reason codes of every chain violation; empty when the chain is intact."""
        return verify_chain(self._records, self.experiment_id, self.variant)

    def dump_jsonl(self) -> str:
        return ''.join(record.canonical_json() + '\n' for record in self._records)

    @classmethod
    def load_jsonl(cls, text: str, experiment_id: str, variant: Variant | None) -> tuple[AuditJournal, tuple[str, ...]]:
        """Rebuild a journal from JSON lines without trusting it: returns the journal of the records that parse and
        the chain violations found (``verify`` of the parsed sequence, plus parse failures)."""
        journal = cls(experiment_id, variant)
        problems: list[str] = []
        parsed: list[DecisionAudit] = []
        for line in text.splitlines():
            if not line.strip():
                continue
            try:
                parsed.append(DecisionAudit.from_json(line))
            except (ContractError, ValueError, json.JSONDecodeError):
                problems.append('h3_audit_record_unparseable')
        problems.extend(verify_chain(parsed, experiment_id, variant))
        journal._records = parsed
        return journal, tuple(dict.fromkeys(problems))


def verify_chain(records: list[DecisionAudit] | tuple[DecisionAudit, ...], experiment_id: str,
                 variant: Variant | None) -> tuple[str, ...]:
    problems: list[str] = []
    previous: str | None = None
    for index, record in enumerate(records):
        if record.experiment_id != experiment_id or record.variant != variant:
            problems.append('h3_audit_scope_mismatch')
        if record.sequence != index:
            problems.append('h3_audit_sequence_broken')
        if record.previous_audit_fingerprint != previous:
            problems.append('h3_audit_chain_broken')
        previous = record.fingerprint()
    return tuple(dict.fromkeys(problems))
