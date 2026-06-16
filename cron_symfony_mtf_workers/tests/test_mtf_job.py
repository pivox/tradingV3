"""Tests for the MtfJob payload, focused on the PR13 idempotency_key addition."""

from models.mtf_job import MtfJob


def test_from_dict_reads_idempotency_key():
    job = MtfJob.from_dict({"url": "http://x", "idempotency_key": "dash:tgt:2026-06-16T00:00:00+00:00"})

    assert job.idempotency_key == "dash:tgt:2026-06-16T00:00:00+00:00"


def test_from_dict_defaults_idempotency_key_to_none():
    assert MtfJob.from_dict({"url": "http://x"}).idempotency_key is None


def test_payload_includes_idempotency_key_when_set():
    job = MtfJob.from_dict({"url": "http://x", "exchange": "okx", "idempotency_key": "d:t:ts"})

    assert job.payload()["idempotency_key"] == "d:t:ts"


def test_payload_omits_idempotency_key_when_absent():
    # Legacy schedules without an idempotency_key keep the exact same payload as before.
    payload = MtfJob.from_dict({"url": "http://x", "exchange": "bitmart"}).payload()

    assert "idempotency_key" not in payload
    assert payload["exchange"] == "bitmart"
