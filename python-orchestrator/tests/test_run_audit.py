"""Tests du module d'audit et de sa configuration logging (OBS-001).

Couvre :
- ``run_audit.emit`` : payload structuré, corrélation ``run_id``, **fail-safe** ;
- ``JsonLineFormatter`` : rendu JSON line déterministe (timestamp ISO UTC) ;
- ``configure_audit_logging`` : handler idempotent, pas de double émission.

Les logs sont capturés via un handler de test attaché au logger d'audit (la
``fixture audit_records``) plutôt qu'en dépendant du format brut.
"""

from __future__ import annotations

import json
import logging

from app import logging_config
from app.logging_config import JsonLineFormatter, configure_audit_logging
from app.services import run_audit
from app.services.run_audit import AUDIT_LOGGER_NAME


# --- emit : payload structuré + corrélation ---------------------------------


def test_emit_builds_structured_correlated_payload(audit_records):
    run_audit.emit(
        run_audit.RUN_STARTED,
        run_id="run_abc",
        dashboard_id=7,
        has_anchor=True,
    )

    assert len(audit_records) == 1
    record = audit_records[0]
    # Attributs directs (accès en test sans parser le JSON).
    assert record.event == "run_started"
    assert record.run_id == "run_abc"
    # Payload complet (event + run_id + champs métier aplatis).
    assert record.audit == {
        "event": "run_started",
        "run_id": "run_abc",
        "dashboard_id": 7,
        "has_anchor": True,
    }


def test_emit_maps_level(audit_records):
    run_audit.emit(run_audit.SET_SKIPPED, run_id="r", level="warning", set_id="s", code="locked")
    assert audit_records[0].levelno == logging.WARNING


def test_emit_unknown_level_falls_back_to_info(audit_records):
    run_audit.emit(run_audit.RUN_FINISHED, run_id="r", level="bogus", status="success")
    assert audit_records[0].levelno == logging.INFO


# --- emit : fail-safe (ne lève jamais) --------------------------------------


def test_emit_never_raises_when_logger_explodes(monkeypatch):
    # Une erreur interne d'audit (handler/logger en échec) ne doit pas propager :
    # on remplace le logger interne par un objet qui lève sur tout appel.
    class _BoomLogger:
        def log(self, *_a, **_k):
            raise RuntimeError("boom log")

        def exception(self, *_a, **_k):
            raise RuntimeError("boom exception")

    monkeypatch.setattr(run_audit, "_logger", _BoomLogger())
    # Aucune exception ne doit remonter (fail-safe).
    run_audit.emit(run_audit.RUN_STARTED, run_id="r", dashboard_id=1)


# --- JsonLineFormatter : JSON line déterministe -----------------------------


def _make_audit_record(audit: dict, level: int = logging.INFO) -> logging.LogRecord:
    record = logging.LogRecord(
        name=AUDIT_LOGGER_NAME,
        level=level,
        pathname=__file__,
        lineno=1,
        msg=audit.get("event", ""),
        args=(),
        exc_info=None,
    )
    record.audit = audit
    return record


def test_json_line_formatter_is_deterministic_json():
    record = _make_audit_record(
        {"event": "run_started", "run_id": "run_x", "dashboard_id": 5, "has_anchor": True}
    )
    line = JsonLineFormatter().format(record)
    data = json.loads(line)  # ligne JSON valide

    assert data["event"] == "run_started"
    assert data["run_id"] == "run_x"
    assert data["dashboard_id"] == 5
    assert data["has_anchor"] is True
    assert data["level"] == "INFO"
    # Timestamp ISO UTC.
    assert data["timestamp"].endswith("+00:00")


def test_json_line_formatter_tolerates_non_serializable_field():
    # default=str : un champ non nativement sérialisable ne fait jamais lever le
    # rendu (fail-safe jusque dans le formatteur).
    record = _make_audit_record({"event": "x", "run_id": "r", "obj": object()})
    data = json.loads(JsonLineFormatter().format(record))
    assert data["event"] == "x"
    assert isinstance(data["obj"], str)


def test_json_line_formatter_falls_back_for_non_audit_record():
    record = logging.LogRecord(
        name="some.logger",
        level=logging.INFO,
        pathname=__file__,
        lineno=1,
        msg="hello %s",
        args=("world",),
        exc_info=None,
    )
    data = json.loads(JsonLineFormatter().format(record))
    assert data["event"] == "some.logger"
    assert data["message"] == "hello world"


# --- configure_audit_logging : idempotent -----------------------------------


def test_configure_audit_logging_is_idempotent():
    logger = logging.getLogger(AUDIT_LOGGER_NAME)
    # Plusieurs appels ne doivent poser qu'UN seul handler d'audit (pas de double
    # émission), quel que soit l'état initial (main.py l'a déjà appelé à l'import).
    configure_audit_logging("INFO")
    configure_audit_logging("DEBUG")
    audit_handlers = [
        h
        for h in logger.handlers
        if getattr(h, logging_config._AUDIT_HANDLER_FLAG, False)
    ]
    assert len(audit_handlers) == 1
    # Propagation coupée : pas de double émission via le root/uvicorn.
    assert logger.propagate is False
