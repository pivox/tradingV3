"""Tests de la couche **unique** de garde-fous live (SAFE-003).

``live_guard.assess_live`` est la source de vérité partagée par la persistance
(``schemas.assert_set_persistable``) et le runner (``_dispatch_set``). On vérifie
ici la hiérarchie fail-closed des décisions, indépendamment des couches qui la
consomment.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Tuple

import pytest

from app.services import live_guard
from app.services.live_guard import (
    DRY_RUN,
    LIVE_EXCHANGE_NOT_ALLOWLISTED,
    LIVE_FORBIDDEN_EXCHANGE,
    LIVE_NOT_ENABLED,
    LIVE_OK,
    assess_live,
    is_permanently_forbidden,
)


@dataclass(frozen=True)
class _FakeSettings:
    """Réplique minimale des attributs lus par ``assess_live``."""

    live_enabled: bool = False
    live_exchanges: Tuple[str, ...] = ()


def _assess(exchange, *, dry_run, enabled=False, allow=()):
    return assess_live(
        exchange=exchange,
        market_type="perpetual",
        environment="mainnet",
        dry_run=dry_run,
        settings=_FakeSettings(live_enabled=enabled, live_exchanges=allow),
    )


# --- Dry-run : toujours autorisé -------------------------------------------


def test_dry_run_is_always_allowed_even_for_forbidden_exchange():
    decision = _assess("okx", dry_run=True)
    assert decision.allowed is True
    assert decision.code == DRY_RUN
    assert decision.reason is None


# --- Bannissements permanents (jamais relâchés) -----------------------------


@pytest.mark.parametrize("exchange", ["okx", "hyperliquid", "OKX", " Hyperliquid "])
def test_permanent_forbidden_even_with_switch_on_and_allowlisted(exchange):
    # OKX/Hyperliquid restent interdits MÊME interrupteur ON + exchange allow-listé,
    # et quelle que soit la casse/les espaces d'une ligne ORM hors API.
    decision = _assess(exchange, dry_run=False, enabled=True, allow=("okx", "hyperliquid"))
    assert decision.allowed is False
    assert decision.code == LIVE_FORBIDDEN_EXCHANGE


def test_is_permanently_forbidden_normalizes_case_and_spaces():
    assert is_permanently_forbidden(" OKX ") is True
    assert is_permanently_forbidden("hyperliquid") is True
    assert is_permanently_forbidden("bitmart") is False
    assert is_permanently_forbidden("fake") is False


# --- Interrupteur OFF (défaut) ----------------------------------------------


def test_switch_off_skips_any_live_set():
    decision = _assess("bitmart", dry_run=False, enabled=False, allow=("bitmart",))
    assert decision.allowed is False
    assert decision.code == LIVE_NOT_ENABLED


# --- Interrupteur ON --------------------------------------------------------


def test_switch_on_but_exchange_not_allowlisted_is_refused():
    decision = _assess("bitmart", dry_run=False, enabled=True, allow=())
    assert decision.allowed is False
    assert decision.code == LIVE_EXCHANGE_NOT_ALLOWLISTED


def test_switch_on_and_allowlisted_is_allowed():
    decision = _assess("bitmart", dry_run=False, enabled=True, allow=("bitmart",))
    assert decision.allowed is True
    assert decision.code == LIVE_OK
    assert decision.reason is None


def test_allowlist_match_is_case_insensitive_on_orm_value():
    # Une ligne ORM hors API peut porter `Bitmart` ; l'allow-list normalisée matche.
    decision = _assess(" Bitmart ", dry_run=False, enabled=True, allow=("bitmart",))
    assert decision.allowed is True
    assert decision.code == LIVE_OK


def test_forbidden_takes_precedence_over_allowlist_membership():
    # Même si OKX était (par erreur) dans l'allow-list, le bannissement permanent
    # prime : la garde n'est jamais relâchée.
    decision = _assess("okx", dry_run=False, enabled=True, allow=("okx", "bitmart"))
    assert decision.code == LIVE_FORBIDDEN_EXCHANGE
    assert decision.allowed is False
