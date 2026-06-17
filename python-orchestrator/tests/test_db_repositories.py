"""Tests round-trip de la couche DB sur SQLite in-memory (DB-001)."""

from __future__ import annotations

from app.db import repositories as repo
from app.db.models import Dashboard, OrchestrationSet, Run, RunSet


def _make_dashboard(session) -> Dashboard:
    dashboard = Dashboard(name="dash_a", enabled=True, description="demo")
    session.add(dashboard)
    session.flush()
    return dashboard


def test_dashboard_round_trip(db_session):
    dashboard = _make_dashboard(db_session)
    db_session.commit()

    assert repo.get_dashboard(db_session, dashboard.id) is not None
    assert repo.get_dashboard_by_name(db_session, "dash_a").id == dashboard.id
    assert repo.get_dashboard(db_session, 999999) is None


def test_list_active_sets_excludes_disabled_and_sorts_by_priority(db_session):
    dashboard = _make_dashboard(db_session)
    db_session.add_all(
        [
            OrchestrationSet(dashboard_id=dashboard.id, set_id="low", exchange="fake", priority=1, enabled=True),
            OrchestrationSet(dashboard_id=dashboard.id, set_id="high", exchange="fake", priority=10, enabled=True),
            OrchestrationSet(dashboard_id=dashboard.id, set_id="off", exchange="fake", priority=99, enabled=False),
        ]
    )
    db_session.commit()

    active = repo.list_active_sets(db_session, dashboard.id)
    ids = [s.set_id for s in active]
    assert ids == ["high", "low"]  # désactivé exclu, tri priorité desc
    assert all(s.enabled for s in active)


def test_symbols_jsonb_round_trip(db_session):
    dashboard = _make_dashboard(db_session)
    a_set = OrchestrationSet(
        dashboard_id=dashboard.id,
        set_id="btc_eth",
        exchange="fake",
        symbols=["BTCUSDT", "ETHUSDT"],
        payload={"action": "mtf_run", "dry_run": True},
    )
    db_session.add(a_set)
    db_session.commit()
    db_session.expire_all()

    reloaded = db_session.get(OrchestrationSet, a_set.id)
    assert reloaded.symbols == ["BTCUSDT", "ETHUSDT"]
    assert reloaded.payload == {"action": "mtf_run", "dry_run": True}


def test_record_run_and_run_set_then_cascade(db_session):
    dashboard = _make_dashboard(db_session)
    db_session.commit()

    run = repo.record_run(
        db_session,
        Run(
            run_id="run_dashA_20260617T083000Z",
            dashboard_id=dashboard.id,
            ok=False,
            status="partial_failure",
            total_calls=2,
            success_count=1,
            failed_count=1,
            last_json={"ok": False, "summary": {"total_calls": 2}},
        ),
    )
    repo.record_run_set(
        db_session,
        RunSet(
            run_id=run.run_id,
            set_id="fake_regular_demo_btc",
            payload_sent={"dry_run": True},
            response_json={"status": "success"},
            ok=True,
            duration_ms=42,
        ),
    )
    db_session.commit()

    assert repo.get_run(db_session, run.run_id).last_json["ok"] is False
    assert db_session.query(RunSet).filter_by(run_id=run.run_id).count() == 1

    # ON DELETE CASCADE : supprimer le run supprime ses run_sets.
    db_session.delete(repo.get_run(db_session, run.run_id))
    db_session.commit()
    assert db_session.query(RunSet).filter_by(run_id=run.run_id).count() == 0


def test_record_run_idempotent_by_run_id(db_session):
    """Deux appels avec le même run_id mettent à jour, sans doublon."""
    repo.record_run(db_session, Run(run_id="run_1", ok=False, status="failed", total_calls=1))
    repo.record_run(db_session, Run(run_id="run_1", ok=True, status="success", total_calls=3))
    db_session.commit()

    assert db_session.query(Run).count() == 1
    run = repo.get_run(db_session, "run_1")
    assert run.ok is True and run.status == "success" and run.total_calls == 3


def test_record_run_idempotent_by_idempotency_key(db_session):
    """Un retry avec une nouvelle run_id mais la même idempotency_key réutilise le run."""
    repo.record_run(
        db_session,
        Run(run_id="run_a", status="partial_failure", ok=False, idempotency_key="key-42"),
    )
    db_session.commit()

    # Retry : run_id différent, même clé → pas d'IntegrityError, mise à jour.
    repo.record_run(
        db_session,
        Run(run_id="run_b", status="success", ok=True, idempotency_key="key-42"),
    )
    db_session.commit()

    assert db_session.query(Run).count() == 1
    run = repo.get_run_by_idempotency_key(db_session, "key-42")
    assert run.run_id == "run_a"  # l'existant est conservé
    assert run.ok is True and run.status == "success"


def test_record_run_partial_update_preserves_context(db_session):
    """Une mise à jour partielle par run_id ne doit pas effacer idempotency_key / dashboard_id."""
    dashboard = _make_dashboard(db_session)
    repo.record_run(
        db_session,
        Run(
            run_id="run_p",
            dashboard_id=dashboard.id,
            status="partial_failure",
            ok=False,
            idempotency_key="key-99",
        ),
    )
    db_session.commit()

    # Update de statut final : ne fournit ni idempotency_key ni dashboard_id.
    repo.record_run(
        db_session,
        Run(run_id="run_p", status="success", ok=True, total_calls=5, success_count=5),
    )
    db_session.commit()
    db_session.expire_all()

    run = repo.get_run(db_session, "run_p")
    assert run.status == "success" and run.total_calls == 5
    # Champs de contexte préservés → l'idempotence reste résoluble.
    assert run.idempotency_key == "key-99"
    assert run.dashboard_id == dashboard.id


def test_record_run_set_upsert_same_run_set(db_session):
    """Deux appels sur le même (run_id, set_id) mettent à jour le dernier résultat."""
    repo.record_run(db_session, Run(run_id="run_u", status="success", ok=True))
    repo.record_run_set(
        db_session,
        RunSet(run_id="run_u", set_id="s1", ok=False, error="boom", duration_ms=10),
    )
    db_session.commit()

    repo.record_run_set(
        db_session,
        RunSet(run_id="run_u", set_id="s1", ok=True, response_json={"status": "ok"}, duration_ms=20),
    )
    db_session.commit()

    rows = db_session.query(RunSet).filter_by(run_id="run_u", set_id="s1").all()
    assert len(rows) == 1
    assert rows[0].ok is True
    assert rows[0].duration_ms == 20
    assert rows[0].response_json == {"status": "ok"}
    # L'ancienne erreur d'un échec précédent doit être effacée (champ nullable).
    assert rows[0].error is None


def test_dashboard_delete_sets_run_dashboard_null(db_session):
    dashboard = _make_dashboard(db_session)
    db_session.commit()
    repo.record_run(
        db_session,
        Run(run_id="run_x", dashboard_id=dashboard.id, ok=True, status="success"),
    )
    db_session.commit()

    db_session.delete(db_session.get(Dashboard, dashboard.id))
    db_session.commit()
    db_session.expire_all()

    # SET NULL : le run survit, son dashboard_id est nul.
    assert repo.get_run(db_session, "run_x").dashboard_id is None
