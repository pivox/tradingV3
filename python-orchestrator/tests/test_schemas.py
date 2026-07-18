from datetime import datetime

import pytest
from pydantic import ValidationError

from app.schemas import (
    Action,
    Environment,
    Exchange,
    MarketType,
    MtfProfile,
    OrchestratorSet,
    SetCreate,
    SetRead,
    assert_set_persistable,
)


def test_okx_live_is_forbidden():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="okx", dry_run=False)


def test_hyperliquid_live_is_forbidden():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="hyperliquid", dry_run=False)


def test_okx_dry_run_is_allowed():
    s = OrchestratorSet(set_id="x", exchange="okx", dry_run=True)
    assert s.dry_run is True


def test_bitmart_live_is_allowed():
    s = OrchestratorSet(set_id="x", exchange="bitmart", dry_run=False)
    assert s.dry_run is False


# --- assert_set_persistable ⇆ assess_live (SAFE-003) ------------------------
#
# La persistance d'un set live n'est autorisée que si le runner l'exécuterait
# (mêmes gardes via `live_guard.assess_live`). On vérifie ici la cohérence
# persistance ↔ runtime en pilotant l'interrupteur d'activation par l'env.


def _persist(exchange="bitmart", dry_run=False):
    assert_set_persistable(
        dry_run=dry_run,
        symbols=["BTCUSDT"],
        contracts_limit=None,
        exchange=exchange,
        market_type="perpetual",
        environment="mainnet",
    )


def test_persist_live_refused_by_default(monkeypatch):
    # Interrupteur OFF (config livrée) : aucun set live persistable, comme avant SAFE-003.
    monkeypatch.delenv("ORCHESTRATION_LIVE_ENABLED", raising=False)
    monkeypatch.delenv("ORCHESTRATION_LIVE_EXCHANGES", raising=False)
    with pytest.raises(ValueError):
        _persist(exchange="bitmart", dry_run=False)


def test_persist_dry_run_allowed_by_default(monkeypatch):
    monkeypatch.delenv("ORCHESTRATION_LIVE_ENABLED", raising=False)
    monkeypatch.delenv("ORCHESTRATION_LIVE_EXCHANGES", raising=False)
    _persist(exchange="bitmart", dry_run=True)  # ne lève pas


def test_persist_live_allowed_when_switch_on_and_allowlisted(monkeypatch):
    # Interrupteur ON + bitmart allow-listé ⇒ assess_live autorise ⇒ persistance OK.
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "true")
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "bitmart")
    _persist(exchange="bitmart", dry_run=False)  # ne lève pas


def test_persist_live_okx_refused_even_when_switch_on(monkeypatch):
    # Bannissement permanent : OKX live refusé même interrupteur ON + allow-listé.
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "true")
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "okx,bitmart")
    with pytest.raises(ValueError):
        _persist(exchange="okx", dry_run=False)


def test_persist_live_refused_when_exchange_not_allowlisted(monkeypatch):
    monkeypatch.setenv("ORCHESTRATION_LIVE_ENABLED", "true")
    monkeypatch.setenv("ORCHESTRATION_LIVE_EXCHANGES", "fake")
    with pytest.raises(ValueError):
        _persist(exchange="bitmart", dry_run=False)


def test_unknown_exchange_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="binance")


def test_unknown_profile_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="fake", mtf_profile="hyper_scalp")


def test_recipe_functional_error_profile_is_allowed_only_for_fake_demo_dry_run():
    safe = SetCreate(
        set_id="recipe_r5",
        exchange="fake",
        environment="demo",
        dry_run=True,
        mtf_profile="recipe_functional_error",
        symbols=["BTCUSDT"],
    )
    assert safe.mtf_profile is MtfProfile.RECIPE_FUNCTIONAL_ERROR

    unsafe_overrides = (
        {"exchange": "bitmart"},
        {"environment": "mainnet"},
        {"dry_run": False},
    )
    for override in unsafe_overrides:
        payload = {
            "set_id": "recipe_r5",
            "exchange": "fake",
            "environment": "demo",
            "dry_run": True,
            "mtf_profile": "recipe_functional_error",
            "symbols": ["BTCUSDT"],
            **override,
        }
        with pytest.raises(ValidationError):
            SetCreate(**payload)


def test_workers_upper_bound_is_enforced():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="fake", workers=2)


def test_workers_zero_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="fake", workers=0)


def test_symbols_are_immutable():
    a_set = OrchestratorSet(set_id="x", exchange="fake", symbols=["BTCUSDT"])
    # Tuple immuable : pas de .append possible, et l'affectation est bloquée (frozen).
    assert isinstance(a_set.symbols, tuple)
    with pytest.raises(AttributeError):
        a_set.symbols.append("ETHUSDT")  # type: ignore[attr-defined]


# --- SetRead.effective_payload (PY-007) -------------------------------------


def _set_read(**kwargs) -> SetRead:
    base = dict(
        id=1,
        dashboard_id=1,
        set_id="s1",
        enabled=True,
        action=Action.MTF_RUN,
        exchange=Exchange.BITMART,
        market_type=MarketType.PERPETUAL,
        mtf_profile=MtfProfile.SCALPER_MICRO,
        environment=Environment.DEMO,
        dry_run=True,
        workers=1,
        sync_tables=False,
        symbols=["BTCUSDT", "ETHUSDT"],
        contracts_limit=None,
        priority=0,
        payload=None,
        created_at=datetime(2026, 1, 1),
        updated_at=datetime(2026, 1, 1),
    )
    base.update(kwargs)
    return SetRead(**base)


def test_set_read_exposes_effective_payload_when_materialized():
    # Le champ calculé reflète le payload /api/mtf/run effectif (enums déballés en
    # chaînes, sync_tables/process_tp_sl forcés false), sérialisé dans la réponse.
    dumped = _set_read().model_dump()
    assert dumped["effective_payload"] == {
        "dry_run": True,
        "workers": 1,
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "sync_tables": False,
        "process_tp_sl": False,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "config_hash": "sha256:feb32cc0bf6491ed5f7a551ae53ec5b8db234fdaa692f108583792d91c9aea3f",
    }


def test_set_read_effective_payload_null_when_not_materialized():
    # Sélection capée pas encore résolue (symbols vide) ou symbols blancs => null :
    # le front en déduit « set non matérialisé ».
    assert _set_read(symbols=[], contracts_limit=5).effective_payload is None
    assert _set_read(symbols=[" ", "\t"]).effective_payload is None
