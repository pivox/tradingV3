"""Tests de la dépréciation du chemin legacy multi-jobs (CLEAN-001).

Vérifie que :
- le helper ``warn_legacy_deprecation`` émet un ``DeprecationWarning`` pointant
  vers la cible (schedule orchestrateur unique) et renvoie le message ;
- chaque script legacy (``manage_mtf_workers`` / ``manage_scalper_micro`` /
  ``manage_exchange_profile``) émet l'avertissement à son lancement ET continue
  de fonctionner (``main()`` parse les arguments puis délègue à ``asyncio.run``) ;
- le workflow legacy ``CronSymfonyMtfWorkersWorkflow`` journalise un
  avertissement de dépréciation via ``workflow.logger.warning`` au début du run,
  sans rien casser.

Comme le reste de la suite cron, ces tests tournent sans serveur Temporal ni
réseau : ``asyncio.run`` est patché pour ne pas contacter Temporal, et les
primitives ``workflow.*`` sont patchées (pattern ``test_orchestrator_workflow``).
"""

import asyncio
import sys

import pytest

from utils.legacy_deprecation import (
    LEGACY_DEPRECATION_MESSAGE,
    TARGET_SCRIPT,
    legacy_deprecation_message,
    warn_legacy_deprecation,
)


# ---------------------------------------------------------------------------
# Helper de dépréciation
# ---------------------------------------------------------------------------


def test_warn_legacy_deprecation_emits_and_returns_message():
    with pytest.warns(DeprecationWarning) as record:
        message = warn_legacy_deprecation("some_component.py")

    assert len(record) == 1
    emitted = str(record[0].message)
    # Le message nomme le composant, la référence CLEAN-001 et la cible.
    assert emitted == message
    assert "some_component.py" in message
    assert "CLEAN-001" in message
    assert TARGET_SCRIPT in message


def test_legacy_deprecation_message_is_shared_and_prefixed():
    message = legacy_deprecation_message("foo")
    assert message.startswith("foo: ")
    assert LEGACY_DEPRECATION_MESSAGE in message


def test_warn_legacy_deprecation_never_raises_under_error_filter():
    # Garantie « ne lève jamais » même sous -W error::DeprecationWarning
    # (PYTHONWARNINGS=error::DeprecationWarning) : le helper force un filtre
    # local ``always`` pour que le CLI legacy reste fonctionnel.
    import warnings

    with warnings.catch_warnings(record=True) as record:
        warnings.simplefilter("error", DeprecationWarning)
        # Ne doit PAS lever, bien que DeprecationWarning soit configuré en erreur.
        message = warn_legacy_deprecation("legacy_cli.py")

    assert "legacy_cli.py" in message
    # L'avertissement reste émis (visible) malgré le filtre error global.
    assert any(isinstance(w.message, DeprecationWarning) for w in record)


# ---------------------------------------------------------------------------
# Scripts legacy : main() émet l'avertissement ET continue de fonctionner
# ---------------------------------------------------------------------------


def _patch_asyncio_run(monkeypatch, module, sink):
    """Remplace ``asyncio.run`` du module pour ne pas contacter Temporal.

    Ferme la coroutine reçue (évite un RuntimeWarning « never awaited ») et
    enregistre l'appel, prouvant que ``main()`` a bien poursuivi son exécution
    après l'avertissement de dépréciation.
    """

    def fake_run(coro):
        coro.close()
        sink["ran"] = True

    monkeypatch.setattr(module.asyncio, "run", fake_run)


def test_manage_mtf_workers_main_warns_and_continues(monkeypatch):
    import scripts.manage_mtf_workers_schedule as module

    monkeypatch.setattr(sys, "argv", ["manage_mtf_workers_schedule.py", "status"])
    sink = {}
    _patch_asyncio_run(monkeypatch, module, sink)

    with pytest.warns(DeprecationWarning, match="CLEAN-001"):
        module.main()

    # Le legacy reste fonctionnel : main() a parsé les args puis appelé asyncio.run.
    assert sink["ran"] is True


def test_manage_scalper_micro_main_warns_and_continues(monkeypatch):
    import scripts.manage_scalper_micro_schedule as module

    monkeypatch.setattr(sys, "argv", ["manage_scalper_micro_schedule.py", "status"])
    sink = {}
    _patch_asyncio_run(monkeypatch, module, sink)

    with pytest.warns(DeprecationWarning, match="CLEAN-001"):
        module.main()

    assert sink["ran"] is True


def test_manage_exchange_profile_main_warns_and_continues(monkeypatch):
    import scripts.manage_exchange_profile_schedule as module

    monkeypatch.setattr(
        sys,
        "argv",
        ["manage_exchange_profile_schedule.py", "status", "--schedule-id", "cron-mtf-okx-scalper-1m"],
    )
    sink = {}
    _patch_asyncio_run(monkeypatch, module, sink)

    with pytest.warns(DeprecationWarning, match="CLEAN-001"):
        module.main()

    assert sink["ran"] is True


# ---------------------------------------------------------------------------
# Workflow legacy : log de dépréciation déterministe (workflow.logger.warning)
# ---------------------------------------------------------------------------


class _RecordingLogger:
    def __init__(self):
        self.warnings = []
        self.infos = []
        self.errors = []

    def warning(self, msg, *args, **_kwargs):
        self.warnings.append(msg % args if args else msg)

    def info(self, msg, *args, **_kwargs):
        self.infos.append(msg % args if args else msg)

    def error(self, msg, *args, **_kwargs):
        self.errors.append(msg % args if args else msg)


def test_legacy_workflow_logs_deprecation_warning(monkeypatch):
    import workflows.mtf_workers as mtf_workers

    logger = _RecordingLogger()

    async def _fake_execute_activity(name, *, args, start_to_close_timeout):
        # Le legacy continue de tourner : l'activity est appelée normalement.
        return {"summary": "ok"}

    monkeypatch.setattr(mtf_workers.workflow, "logger", logger)
    monkeypatch.setattr(mtf_workers.workflow, "execute_activity", _fake_execute_activity)

    wf = mtf_workers.CronSymfonyMtfWorkersWorkflow()
    asyncio.run(wf.run([{"url": "http://trading-app-nginx:80/api/mtf/run"}]))

    joined = "\n".join(logger.warnings)
    assert "DEPRECATED (CLEAN-001)" in joined
    assert "manage_orchestrator_schedule.py" in joined
    # Le run a bien continué (résumé de l'activity journalisé en info).
    assert any("ok" in info for info in logger.infos)
