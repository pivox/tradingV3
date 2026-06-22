"""Vecteurs partagés de l'identifiant de corrélation canonique (OBS-003).

Lit ``tests/fixtures/run_correlation_vectors.json`` (racine du dépôt), le MÊME
fichier que le test PHP ``RunCorrelationIdTest`` : Python et PHP doivent produire
exactement le même résultat pour chaque vecteur (identifiant court conservé, ≤64
conservé, long/non-sûr haché, collisions de préfixe évitées, chaîne vide rejetée).
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from app.services.correlation import CORRELATION_ID_MAX_LEN, canonical_correlation_id

# python-orchestrator/tests/ -> racine du dépôt -> tests/fixtures/...
_FIXTURE = (
    Path(__file__).resolve().parents[2]
    / "tests"
    / "fixtures"
    / "run_correlation_vectors.json"
)


def _load_fixture() -> dict:
    return json.loads(_FIXTURE.read_text(encoding="utf-8"))


def test_fixture_is_present_and_shared():
    """Le fichier de vecteurs partagé existe et déclare la règle attendue."""
    data = _load_fixture()
    assert data["max_len"] == CORRELATION_ID_MAX_LEN
    assert data["empty_must_raise"] is True
    assert data["vectors"], "au moins un vecteur attendu"


@pytest.mark.parametrize("vector", _load_fixture()["vectors"], ids=lambda v: v["name"])
def test_canonical_matches_shared_vectors(vector):
    """Chaque vecteur partagé produit l'``expected`` (identique côté PHP)."""
    result = canonical_correlation_id(vector["input"])
    assert result == vector["expected"]

    if vector["transform"] == "identity":
        assert result == vector["input"]
        assert len(result) <= CORRELATION_ID_MAX_LEN
    else:
        assert vector["transform"] == "sha256"
        # Hash hex minuscule de 64 caractères, jamais une troncature du préfixe.
        assert len(result) == 64
        assert result != vector["input"][:64]


def test_empty_string_is_rejected():
    """Une chaîne vide n'est jamais corrélée (un run a toujours un id)."""
    with pytest.raises(ValueError):
        canonical_correlation_id("")


def test_prefix_collisions_do_not_collide():
    """Deux longs ``run_id`` au même préfixe 64 donnent deux identifiants distincts.

    C'est l'invariant central : une troncature ``run_id[:64]`` les confondrait.
    """
    data = _load_fixture()
    by_name = {v["name"]: v for v in data["vectors"]}
    a = by_name["prefix_collision_a"]
    b = by_name["prefix_collision_b"]

    assert a["input"][:64] == b["input"][:64], "les inputs partagent bien le préfixe 64"
    assert canonical_correlation_id(a["input"]) != canonical_correlation_id(b["input"])
