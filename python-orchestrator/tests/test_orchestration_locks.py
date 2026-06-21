"""Tests de la couche locks d'orchestration (SAFE-001).

Couvre les helpers ``app/db/repositories.py`` sur SQLite in-memory :

- clé canonique d'exclusion mutuelle (``build_lock_key``) ;
- acquisition atomique « tout ou rien » par set (``acquire_set_locks``) ;
- reclaim d'un lock expiré (TTL dépassé) ;
- libération par ``run_id`` (``release_locks``) et purge des expirés.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

from sqlalchemy import select

from app.db import repositories as repo
from app.db.models import OrchestrationLock


def _lock(run_id: str, symbol: str, *, now: datetime, ttl: int = 1800,
          profile: str = "regular", exchange: str = "bitmart",
          market_type: str = "perpetual") -> OrchestrationLock:
    return OrchestrationLock(
        lock_key=repo.build_lock_key(profile, exchange, market_type, symbol),
        mtf_profile=profile,
        exchange=exchange,
        market_type=market_type,
        symbol=symbol,
        run_id=run_id,
        acquired_at=now,
        expires_at=now + timedelta(seconds=ttl),
    )


def test_build_lock_key_is_canonical_and_stable():
    key = repo.build_lock_key("regular", "bitmart", "perpetual", "BTCUSDT")
    assert key == "regular|bitmart|perpetual|BTCUSDT"
    # Déterministe : mêmes composantes => même clé.
    assert key == repo.build_lock_key(" regular ", " bitmart ", " perpetual ", " BTCUSDT ")
    # Le symbole/profil discriminent la clé (pas de collision).
    assert key != repo.build_lock_key("scalper", "bitmart", "perpetual", "BTCUSDT")
    assert key != repo.build_lock_key("regular", "bitmart", "perpetual", "ETHUSDT")


def test_acquire_set_locks_success_then_present(db_session):
    now = datetime.now(timezone.utc)
    locks = [_lock("run1", "BTCUSDT", now=now), _lock("run1", "ETHUSDT", now=now)]

    conflict = repo.acquire_set_locks(db_session, locks, now)
    db_session.commit()

    assert conflict is None
    rows = db_session.scalars(select(OrchestrationLock)).all()
    assert {r.symbol for r in rows} == {"BTCUSDT", "ETHUSDT"}
    assert all(r.run_id == "run1" for r in rows)


def test_acquire_set_locks_conflict_when_held_by_active_run(db_session):
    now = datetime.now(timezone.utc)
    # run1 détient BTCUSDT.
    assert repo.acquire_set_locks(db_session, [_lock("run1", "BTCUSDT", now=now)], now) is None
    db_session.commit()

    # run2 tente BTCUSDT => conflit, lock_key + titulaire remontés.
    conflict = repo.acquire_set_locks(db_session, [_lock("run2", "BTCUSDT", now=now)], now)
    assert conflict is not None
    key, holder = conflict
    assert key == repo.build_lock_key("regular", "bitmart", "perpetual", "BTCUSDT")
    assert holder == "run1"


def test_acquire_set_locks_all_or_nothing_releases_partial(db_session):
    now = datetime.now(timezone.utc)
    # run1 détient ETHUSDT.
    assert repo.acquire_set_locks(db_session, [_lock("run1", "ETHUSDT", now=now)], now) is None
    db_session.commit()

    # run2 veut [BTCUSDT, ETHUSDT] : BTCUSDT libre, ETHUSDT bloqué => tout ou rien.
    conflict = repo.acquire_set_locks(
        db_session,
        [_lock("run2", "BTCUSDT", now=now), _lock("run2", "ETHUSDT", now=now)],
        now,
    )
    db_session.commit()

    assert conflict is not None
    # Aucun lock résiduel de run2 (BTCUSDT pris puis relâché).
    run2_rows = db_session.scalars(
        select(OrchestrationLock).where(OrchestrationLock.run_id == "run2")
    ).all()
    assert run2_rows == []
    # ETHUSDT reste à run1.
    eth = db_session.scalar(
        select(OrchestrationLock).where(OrchestrationLock.symbol == "ETHUSDT")
    )
    assert eth.run_id == "run1"


def test_expired_lock_is_reclaimed(db_session):
    now = datetime.now(timezone.utc)
    # run1 détient un lock DÉJÀ expiré (acquis dans le passé).
    past = now - timedelta(seconds=3600)
    db_session.add(_lock("run1", "BTCUSDT", now=past, ttl=10))  # expires_at < now
    db_session.commit()

    # run2 acquiert : le lock expiré est reclaim, acquisition réussie.
    conflict = repo.acquire_set_locks(db_session, [_lock("run2", "BTCUSDT", now=now)], now)
    db_session.commit()

    assert conflict is None
    row = db_session.scalar(select(OrchestrationLock).where(OrchestrationLock.symbol == "BTCUSDT"))
    assert row.run_id == "run2"


def test_release_locks_by_run_id(db_session):
    now = datetime.now(timezone.utc)
    repo.acquire_set_locks(
        db_session,
        [_lock("run1", "BTCUSDT", now=now), _lock("run1", "ETHUSDT", now=now)],
        now,
    )
    repo.acquire_set_locks(db_session, [_lock("run2", "XRPUSDT", now=now)], now)
    db_session.commit()

    # Libère uniquement BTCUSDT de run1 (restriction par lock_keys).
    repo.release_locks(
        db_session,
        run_id="run1",
        lock_keys=[repo.build_lock_key("regular", "bitmart", "perpetual", "BTCUSDT")],
    )
    db_session.commit()
    remaining = {r.symbol for r in db_session.scalars(select(OrchestrationLock)).all()}
    assert remaining == {"ETHUSDT", "XRPUSDT"}

    # Libère tout le reste de run1.
    repo.release_locks(db_session, run_id="run1")
    db_session.commit()
    remaining = {r.symbol for r in db_session.scalars(select(OrchestrationLock)).all()}
    assert remaining == {"XRPUSDT"}  # XRPUSDT (run2) intact


def test_purge_expired_locks(db_session):
    now = datetime.now(timezone.utc)
    db_session.add(_lock("dead", "BTCUSDT", now=now - timedelta(hours=2), ttl=10))  # expiré
    db_session.add(_lock("alive", "ETHUSDT", now=now))  # actif
    db_session.commit()

    removed = repo.purge_expired_locks(db_session, now)
    db_session.commit()

    assert removed == 1
    remaining = {r.symbol for r in db_session.scalars(select(OrchestrationLock)).all()}
    assert remaining == {"ETHUSDT"}
