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
    SetRead,
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


def test_unknown_exchange_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="binance")


def test_unknown_profile_is_rejected():
    with pytest.raises(ValidationError):
        OrchestratorSet(set_id="x", exchange="fake", mtf_profile="hyper_scalp")


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
    }


def test_set_read_effective_payload_null_when_not_materialized():
    # Sélection capée pas encore résolue (symbols vide) ou symbols blancs => null :
    # le front en déduit « set non matérialisé ».
    assert _set_read(symbols=[], contracts_limit=5).effective_payload is None
    assert _set_read(symbols=[" ", "\t"]).effective_payload is None
