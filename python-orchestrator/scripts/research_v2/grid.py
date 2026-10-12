"""Configuration grid of the v2 screen (PREREGISTRATION.md, sections 3 and 4)."""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass
from pathlib import Path

from .setups import CATALOGUE, CATALOGUE_BY_ID, SetupSpec

HOUR_MS = 3_600_000
DAY_MS = 24 * HOUR_MS

STOPS_ATR = {"4h": (2.0, 3.0), "15m": (1.5, 2.5)}
TTL_MS = {"4h": 60 * 60_000, "15m": 15 * 60_000}
HORIZON_MS = {("trend", "4h"): 14 * DAY_MS, ("trend", "15m"): 24 * HOUR_MS, ("reversion", "4h"): 5 * DAY_MS, ("reversion", "15m"): 8 * HOUR_MS}
TRAILING_ATR = 3.0


@dataclass(frozen=True)
class ExitVariant:
    id: str
    stop_atr: float
    target_r: float | None
    trailing_atr: float | None
    indicator_exit: bool
    horizon_ms: int
    ttl_ms: int


def exit_variants(exit_family: str, cadence: str) -> tuple[ExitVariant, ...]:
    horizon = HORIZON_MS[(exit_family, cadence)]
    ttl = TTL_MS[cadence]
    variants = []
    for stop in STOPS_ATR[cadence]:
        label = f"S{stop:g}"
        if exit_family == "trend":
            variants.append(ExitVariant(f"{label}.T3", stop, 3.0, None, False, horizon, ttl))
            variants.append(ExitVariant(f"{label}.T5", stop, 5.0, None, False, horizon, ttl))
            variants.append(ExitVariant(f"{label}.TR{TRAILING_ATR:g}", stop, None, TRAILING_ATR, False, horizon, ttl))
        elif exit_family == "reversion":
            variants.append(ExitVariant(f"{label}.X", stop, None, None, True, horizon, ttl))
            variants.append(ExitVariant(f"{label}.T2", stop, 2.0, None, False, horizon, ttl))
        else:
            raise ValueError(f"unknown exit family {exit_family!r}")
    return tuple(variants)


@dataclass(frozen=True)
class Configuration:
    id: str
    setup_id: str
    cadence: str
    side: int
    variant: ExitVariant
    filter_id: str | None = None  # second-pass filter (E1, E2.US, E2.ASIA, E3, E4) or None

    @property
    def setup(self) -> SetupSpec:
        return CATALOGUE_BY_ID[self.setup_id]

    @property
    def family(self) -> str:
        return self.setup.family

    @property
    def side_name(self) -> str:
        return "long" if self.side == 1 else "short"

    @property
    def base_id(self) -> str:
        return configuration_id(self.setup_id, self.cadence, self.side, self.variant.id)

    def to_json(self) -> dict:
        return {"id": self.id, "setup_id": self.setup_id, "family": self.family, "cadence": self.cadence, "side": self.side_name, "variant": asdict(self.variant), "filter": self.filter_id}


def configuration_id(setup_id: str, cadence: str, side: int, variant_id: str, filter_id: str | None = None) -> str:
    base = f"{setup_id}|{cadence}|{'long' if side == 1 else 'short'}|{variant_id}"
    return f"{base}|{filter_id}" if filter_id else base


def build_grid(setups: tuple[SetupSpec, ...] = CATALOGUE) -> tuple[Configuration, ...]:
    configurations = []
    for spec in setups:
        for cadence in spec.cadences:
            for side in (1, -1):
                for variant in exit_variants(spec.exit_family, cadence):
                    configurations.append(Configuration(configuration_id(spec.id, cadence, side, variant.id), spec.id, cadence, side, variant))
    return tuple(configurations)


def parse_configuration(identifier: str) -> Configuration:
    parts = identifier.split("|")
    if len(parts) == 4:
        setup_id, cadence, side_name, variant_id = parts
        filter_id = None
    elif len(parts) == 5:
        setup_id, cadence, side_name, variant_id, filter_id = parts
        from .filters import FILTER_IDS

        if filter_id not in FILTER_IDS:
            raise KeyError(identifier)
    else:
        raise KeyError(identifier)
    spec = CATALOGUE_BY_ID[setup_id]
    if side_name not in ("long", "short"):
        raise KeyError(identifier)
    side = 1 if side_name == "long" else -1
    for variant in exit_variants(spec.exit_family, cadence):
        if variant.id == variant_id:
            return Configuration(identifier, setup_id, cadence, side, variant, filter_id)
    raise KeyError(identifier)


def write_grid(path: Path, configurations: tuple[Configuration, ...]) -> dict:
    by_family: dict[str, int] = {}
    by_cadence: dict[str, int] = {}
    for configuration in configurations:
        by_family[configuration.family] = by_family.get(configuration.family, 0) + 1
        by_cadence[configuration.cadence] = by_cadence.get(configuration.cadence, 0) + 1
    document = {
        "schema": "research-v2-grid.v1",
        "configurations": [configuration.to_json() for configuration in configurations],
        "count": len(configurations),
        "by_family": by_family,
        "by_cadence": by_cadence,
        "setups": [{"id": spec.id, "family": spec.family, "cadences": list(spec.cadences), "description": spec.description, "params": spec.params} for spec in CATALOGUE],
    }
    path.write_text(json.dumps(document, indent=2, sort_keys=True) + "\n")
    return document
