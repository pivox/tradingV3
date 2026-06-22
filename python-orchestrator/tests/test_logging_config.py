"""Tests unitaires du logging d'audit JSON line (QA-001, cf. OBS-001).

Couvre le ``JsonLineFormatter`` (branches audit / non-audit / exc_info) et
l'idempotence de ``configure_audit_logging`` (un seul handler, pas de double
émission, propagation coupée). Aucun réseau, aucune DB.
"""

from __future__ import annotations

import json
import logging

import pytest

from app.logging_config import (
    _AUDIT_HANDLER_FLAG,
    JsonLineFormatter,
    configure_audit_logging,
)
from app.services.run_audit import AUDIT_LOGGER_NAME


@pytest.fixture(autouse=True)
def _clean_audit_logger():
    """Retire les handlers d'audit avant/après pour repartir d'un état neutre."""
    logger = logging.getLogger(AUDIT_LOGGER_NAME)
    saved = list(logger.handlers)
    saved_level, saved_prop, saved_disabled = logger.level, logger.propagate, logger.disabled
    for h in list(logger.handlers):
        logger.removeHandler(h)
    yield logger
    for h in list(logger.handlers):
        logger.removeHandler(h)
    for h in saved:
        logger.addHandler(h)
    logger.setLevel(saved_level)
    logger.propagate = saved_prop
    logger.disabled = saved_disabled


def _make_record(**extra) -> logging.LogRecord:
    record = logging.LogRecord(
        name="orchestrator.audit",
        level=logging.INFO,
        pathname=__file__,
        lineno=1,
        msg="raw message",
        args=(),
        exc_info=None,
    )
    for key, value in extra.items():
        setattr(record, key, value)
    return record


def test_formatter_renders_audit_payload() -> None:
    record = _make_record(audit={"event": "run_started", "run_id": "run_42", "n": 3})
    line = JsonLineFormatter().format(record)
    payload = json.loads(line)

    assert payload["event"] == "run_started"
    assert payload["run_id"] == "run_42"
    assert payload["n"] == 3
    assert payload["level"] == "INFO"
    # timestamp ISO 8601 UTC déterministe.
    assert payload["timestamp"].endswith("+00:00")


def test_formatter_non_audit_fallback() -> None:
    # Pas d'attribut `audit` → repli JSON line sur name/message.
    record = _make_record()
    payload = json.loads(JsonLineFormatter().format(record))
    assert payload["event"] == "orchestrator.audit"
    assert payload["message"] == "raw message"


def test_formatter_includes_exc_info() -> None:
    try:
        raise ValueError("boom")
    except ValueError:
        import sys

        record = _make_record(audit={"event": "run_failed"})
        record.exc_info = sys.exc_info()
    payload = json.loads(JsonLineFormatter().format(record))
    assert "exc" in payload
    assert "ValueError" in payload["exc"]


def test_configure_is_idempotent() -> None:
    logger = configure_audit_logging("INFO")
    configure_audit_logging("DEBUG")
    configure_audit_logging("INFO")

    audit_handlers = [
        h for h in logger.handlers if getattr(h, _AUDIT_HANDLER_FLAG, False)
    ]
    # Un seul handler d'audit quel que soit le nombre d'appels.
    assert len(audit_handlers) == 1


def test_configure_sets_level_and_stops_propagation() -> None:
    logger = configure_audit_logging("WARNING")
    assert logger.level == logging.WARNING
    assert logger.propagate is False
    assert logger.disabled is False


def test_no_double_emission(capsys) -> None:
    logger = configure_audit_logging("INFO")
    logger.info("hello", extra={"audit": {"event": "run_started", "run_id": "r1"}})
    out = capsys.readouterr().out
    # Une seule ligne JSON émise (propagate coupé → pas de doublon root/uvicorn).
    lines = [ln for ln in out.splitlines() if ln.strip()]
    assert len(lines) == 1
    assert json.loads(lines[0])["event"] == "run_started"
