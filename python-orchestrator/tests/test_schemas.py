import pytest
from pydantic import ValidationError

from app.schemas import OrchestratorSet


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
