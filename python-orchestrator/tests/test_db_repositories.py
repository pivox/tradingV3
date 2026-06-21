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


def test_record_run_insert_race_falls_back_to_existing(db_session, monkeypatch):
    """Course concurrente : si le lookup rate puis l'insert entre en conflit sur
    `idempotency_key`, la violation est rattrapée et la ligne gagnante renvoyée."""
    repo.record_run(
        db_session,
        Run(run_id="winner", status="success", ok=True, idempotency_key="race"),
    )
    db_session.commit()

    real_resolve = repo._resolve_existing_run
    calls = {"n": 0}

    def flaky_resolve(session, run):
        calls["n"] += 1
        if calls["n"] == 1:
            return None  # simule un lookup qui rate avant le commit concurrent
        return real_resolve(session, run)

    monkeypatch.setattr(repo, "_resolve_existing_run", flaky_resolve)

    # Nouveau run_id, même clé → insert → IntegrityError → rattrapage + reload.
    result = repo.record_run(
        db_session,
        Run(run_id="loser", status="failed", ok=False, idempotency_key="race"),
    )
    db_session.commit()

    assert result.run_id == "winner"            # ligne gagnante récupérée
    assert db_session.query(Run).count() == 1   # pas de doublon créé


def test_claim_run_creates_then_yields_active_then_reclaims(db_session):
    """claim_run (SAFE-002) : crée, cède sur un claim actif, reprend un claim inactif."""
    none = lambda _r: None  # noqa: E731
    in_flight = lambda _r: "in_flight"  # noqa: E731

    # 1) Aucune ligne → création (claim obtenu, yield_reason=None).
    run, reason = repo.claim_run(
        db_session, Run(run_id="c1", idempotency_key="k1", ok=False, status="running"),
        classify=none,
    )
    db_session.commit()
    assert reason is None and run.run_id == "c1"

    # 2) Ligne classée à céder (in_flight) → on s'efface, aucun écrasement.
    _, reason2 = repo.claim_run(
        db_session,
        Run(run_id="c1", idempotency_key="k1", ok=False, status="running", total_calls=7),
        classify=in_flight,
    )
    assert reason2 == "in_flight"
    assert repo.get_run(db_session, "c1").total_calls == 0  # ligne intacte

    # 3) Ligne non cédée (terminal/périmé) → reprise (yield_reason=None, mise à jour).
    _, reason3 = repo.claim_run(
        db_session,
        Run(run_id="c1", idempotency_key="k1", ok=False, status="running", total_calls=9),
        classify=none,
    )
    db_session.commit()
    assert reason3 is None
    assert repo.get_run(db_session, "c1").total_calls == 9


def test_claim_run_rereads_fresh_state_under_lock(db_session):
    """P1 (atomicité) : le read-modify-write se fait sous ``FOR UPDATE`` +
    ``populate_existing``, donc ``classify`` est ré-évalué sur l'état FRAIS, pas sur
    une copie périmée de l'identity map. Une ligne devenue active entre-temps fait
    céder la reprise (yield_reason='in_flight') au lieu d'écraser le gagnant."""
    from datetime import datetime, timedelta, timezone

    from sqlalchemy import text

    now = datetime(2026, 6, 21, 12, 0, 0, tzinfo=timezone.utc)
    # Ligne running PÉRIMÉE, puis chargée dans la session (identity map = périmé).
    db_session.add(
        Run(run_id="r", idempotency_key="k", ok=False, status="running",
            expires_at=now - timedelta(seconds=1))
    )
    db_session.commit()
    stale = repo.get_run(db_session, "r")  # instance périmée en identity map
    assert stale is not None

    # Un gagnant concurrent rend la ligne ACTIVE (expiry future), écrit hors ORM pour
    # que l'identity map reste périmée tant qu'on ne relit pas sous verrou.
    db_session.execute(
        text("UPDATE orchestration.runs SET expires_at = :e WHERE run_id = 'r'"),
        {"e": now + timedelta(hours=1)},
    )

    def classify(r):
        if r.status == "running" and r.expires_at is not None:
            exp = r.expires_at if r.expires_at.tzinfo else r.expires_at.replace(tzinfo=timezone.utc)
            if exp > now:
                return "in_flight"
        return None

    _, reason = repo.claim_run(
        db_session,
        Run(run_id="r", idempotency_key="k", ok=False, status="running",
            expires_at=now + timedelta(seconds=10)),
        classify=classify,
    )
    # populate_existing a relu l'expiry FUTURE → classify cède → on n'écrase pas.
    assert reason == "in_flight"


def test_claim_run_yields_terminal_success_for_replay(db_session):
    """P1 (race winner finalise en succès) : si la ligne verrouillée est un succès
    terminal, claim_run cède ('replay') sans l'écraser — le perdant rejouera au lieu
    de ré-exécuter."""
    db_session.add(
        Run(run_id="s", idempotency_key="ks", ok=True, status="success",
            total_calls=2, success_count=2, last_json={"ok": True})
    )
    db_session.commit()

    def classify(r):
        if r.status in ("success", "partial_failure", "failed") and r.ok:
            return "replay"
        return None

    run, reason = repo.claim_run(
        db_session,
        Run(run_id="s", idempotency_key="ks", ok=False, status="running"),
        classify=classify,
    )
    assert reason == "replay"
    # La ligne de succès n'a PAS été écrasée vers running.
    assert run.status == "success" and run.ok is True
    assert repo.get_run(db_session, "s").status == "success"


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


def test_create_update_delete_dashboard(db_session):
    dashboard = repo.create_dashboard(db_session, name="cfg", description="x")
    db_session.commit()
    assert dashboard.id is not None

    repo.update_dashboard(db_session, dashboard, fields={"enabled": False, "description": "y"})
    db_session.commit()
    reloaded = repo.get_dashboard(db_session, dashboard.id)
    assert reloaded.enabled is False and reloaded.description == "y"

    repo.delete_dashboard(db_session, reloaded)
    db_session.commit()
    assert repo.get_dashboard(db_session, dashboard.id) is None


def test_list_dashboards_sorted_by_name(db_session):
    repo.create_dashboard(db_session, name="zeta")
    repo.create_dashboard(db_session, name="alpha")
    db_session.commit()

    assert [d.name for d in repo.list_dashboards(db_session)] == ["alpha", "zeta"]


def test_create_get_update_delete_set(db_session):
    dashboard = _make_dashboard(db_session)
    db_session.commit()

    a_set = repo.create_set(
        db_session,
        dashboard.id,
        fields={"set_id": "s1", "exchange": "bitmart", "symbols": ["BTCUSDT"], "priority": 3},
    )
    db_session.commit()
    assert repo.get_set(db_session, dashboard.id, "s1").id == a_set.id

    repo.update_set(db_session, a_set, fields={"priority": 9, "enabled": False})
    db_session.commit()
    reloaded = repo.get_set(db_session, dashboard.id, "s1")
    assert reloaded.priority == 9 and reloaded.enabled is False

    repo.delete_set(db_session, reloaded)
    db_session.commit()
    assert repo.get_set(db_session, dashboard.id, "s1") is None


def test_list_sets_enabled_only(db_session):
    dashboard = _make_dashboard(db_session)
    db_session.add_all(
        [
            OrchestrationSet(dashboard_id=dashboard.id, set_id="on", exchange="fake", enabled=True, priority=1),
            OrchestrationSet(dashboard_id=dashboard.id, set_id="off", exchange="fake", enabled=False, priority=5),
        ]
    )
    db_session.commit()

    assert {s.set_id for s in repo.list_sets(db_session, dashboard.id)} == {"on", "off"}
    assert [s.set_id for s in repo.list_sets(db_session, dashboard.id, enabled_only=True)] == ["on"]


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
