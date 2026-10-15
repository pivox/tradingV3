"""Point-in-time monthly universe (§3), built from the downloaded archives only.

The rule of `research_swing2.okx_universe.monthly_universe` with the H2 parameters: on the 1st of each month M, the
30 OKX USDT swaps with the highest median daily quote volume over M-1, among instruments with 1-minute candles since
at least 60 days before the 1st of M and at least 15 "reliable" UTC days in M-1 (a day counts when it has >= 720
minutes and every minute carries a quote volume). Ties: instrument id. Programme bases are not excluded; stablecoins
and gold/fiat tokens are. No metadata endpoint is called: the volumes of the swing v2 families come from the archive's
`daily-quote-volume.json`; those of the programme bases are computed from their zips by `data.daily_quote_volume`
with the inferred contract value.
"""

from __future__ import annotations

import json
import statistics
from collections.abc import Callable, Mapping

import numpy as np

from ..research_swing2.okx_universe import _month_start_ms, _months
from . import data as dt
from . import protocol as pr


def base_of(inst: str) -> str:
    return inst.split("-")[0]


def decision_months() -> list[str]:
    return _months(*pr.UNIVERSE_MONTHS)


def month_of_ms(ms: int) -> str:
    from datetime import datetime, timezone

    return datetime.fromtimestamp(ms / 1000, tz=timezone.utc).strftime("%Y-%m")


def build(first_day: Mapping[str, int], volumes: Mapping[str, Mapping[str, list]], months: list[str] | None = None) -> dict:
    """`first_day`: UTC day index of each instrument's first candle; `volumes`: {inst: {day: [volume, minutes,
    missing]}}. Pure function."""
    months = decision_months() if months is None else months
    out = {}
    for month in months:
        start_day = _month_start_ms(month) // pr.DAY_MS
        prev_start = _month_start_ms(_months("2000-01", month)[-2]) // pr.DAY_MS
        ranking = []
        for inst in sorted(volumes):
            if base_of(inst) in pr.EXCLUDED_BASES:
                continue
            if inst not in first_day or first_day[inst] > start_day - pr.WARMUP_DAYS:
                continue
            values = [v for d, (v, minutes, missing) in volumes[inst].items()
                      if prev_start <= int(d) < start_day and minutes >= pr.VOLUME_DAY_MIN_MINUTES and missing == 0]
            if len(values) < pr.MIN_DAYS_IN_PREVIOUS_MONTH:
                continue
            ranking.append((statistics.median(values), inst, len(values)))
        ranking.sort(key=lambda r: (-r[0], r[1]))
        out[month] = {"universe": sorted(inst for _, inst, _ in ranking[:pr.UNIVERSE_SIZE]), "eligible": len(ranking)}
    return {"rule": {"size": pr.UNIVERSE_SIZE, "warmup_days": pr.WARMUP_DAYS, "min_days_in_previous_month": pr.MIN_DAYS_IN_PREVIOUS_MONTH,
                     "day_min_minutes": pr.VOLUME_DAY_MIN_MINUTES, "excluded_bases": sorted(pr.EXCLUDED_BASES)},
            "months": out, "members": sorted({i for m in out.values() for i in m["universe"]})}


def gather(sources: dt.Sources, guard: Callable[[], None] | None = None,
           on_minutes: Callable[[str, dict], None] | None = None) -> tuple[dict[str, int], dict[str, dict], dict]:
    """First candle day and daily volumes of every archived instrument (volumes of UTC days before the data end).
    `on_minutes(inst, minutes)` sees each programme instrument's candles once (the caller builds its bars)."""
    end_day = pr.DATA_END_MS // pr.DAY_MS
    swing_volumes = json.loads((sources.swing_tape / pr.SWING_VOLUME).read_text())["days"]
    first_day: dict[str, int] = {}
    volumes: dict[str, dict] = {}
    notes: dict[str, dict] = {}
    for inst in sources.swing():
        files = sources.files(inst)
        if not files:
            continue
        with np.load(files[0]) as first:
            stamps = first["ts_s"][:1]
        if stamps.size:
            first_day[inst] = int(stamps[0]) // 86_400
        volumes[inst] = {d: v for d, v in swing_volumes.get(inst, {}).items() if int(d) < end_day}
    for inst in sources.programme():
        if guard is not None:
            guard()
        minutes = dt.load_minutes(sources, inst)
        if minutes["ts_s"].size:
            first_day[inst] = int(minutes["ts_s"][0]) // 86_400
        if on_minutes is not None:
            on_minutes(inst, minutes)
        days, inferred = dt.daily_quote_volume(minutes)
        volumes[inst] = days
        notes[inst] = {"ct_val_inferred": inferred, "minutes": int(minutes["ts_s"].size)}
    return first_day, volumes, notes


def secondary(inst: str) -> bool:
    return base_of(inst) in pr.XPERP_BASES
