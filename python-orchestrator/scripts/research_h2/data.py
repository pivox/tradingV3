"""1-minute candles, hourly bars, daily quote volumes and bounded-delay price lookups (§2-§5).

Two archives are read, both OKX official 1-minute last-price candles:

- the 20 programme bases: raw zips of the H2 data step, converted in memory by `research_swing2.okx.convert_candles`
  (the same column rule as every OKX campaign);
- every other family: the swing v2 monthly archive, already converted to npz by `research_swing2.okx_universe`.

No file whose period is after `LAST_PERIOD` is opened, and candles opening at or after `DATA_END_MS` are dropped.
Times: `ts_s` is the candle's open time (s); a candle closes at `ts_s + 60`.
"""

from __future__ import annotations

import io
import re
import zipfile
from dataclasses import dataclass
from pathlib import Path

import numpy as np

from ..research_swing2.okx import convert_candles
from . import protocol as pr

PERIOD = re.compile(r"(\d{4}-\d{2}(?:-\d{2})?)\.(?:zip|npz)$")
COLUMNS = ("ts_s", "close", "volume", "base_volume", "contracts")


def period_of(path: Path) -> str:
    match = PERIOD.search(Path(path).name)
    if match is None:
        raise ValueError(f"no period in {path}")
    return match.group(1)


def readable(path: Path) -> bool:
    """A period is compared on its first 10 characters (`YYYY-MM` months sort before their days)."""
    return period_of(path) <= pr.LAST_PERIOD


@dataclass(frozen=True)
class Sources:
    h2_tape: Path
    swing_tape: Path

    def programme(self) -> list[str]:
        root = self.h2_tape / "zips"
        return sorted(p.name for p in root.iterdir() if p.is_dir()) if root.exists() else []

    def swing(self) -> list[str]:
        root = self.swing_tape / "klines"
        return sorted(p.name for p in root.iterdir() if p.is_dir()) if root.exists() else []

    def files(self, inst: str) -> list[Path]:
        zips = self.h2_tape / "zips" / inst
        if zips.is_dir():
            paths = sorted(zips.glob(f"{inst}-candlesticks-*.zip"), key=lambda p: p.name)
        else:
            paths = sorted((self.swing_tape / "klines" / inst).glob(f"{inst}-1m-*.npz"), key=lambda p: p.name)
        return [p for p in paths if readable(p)]


def _read(path: Path) -> dict[str, np.ndarray]:
    if path.suffix == ".zip":
        with zipfile.ZipFile(path) as archive:
            members = archive.namelist()
            if len(members) != 1:
                raise ValueError(f"{path}: expected one CSV, found {members}")
            arrays = convert_candles(archive.read(members[0]))
    else:
        with np.load(path) as data:
            arrays = {k: data[k] for k in data.files}
    size = arrays["ts_s"].size
    return {k: np.asarray(arrays[k], dtype=np.int64 if k == "ts_s" else np.float64) if k in arrays else np.full(size, np.nan)
            for k in COLUMNS}


def load_minutes(sources: Sources, inst: str) -> dict[str, np.ndarray]:
    """All readable candles of one instrument, sorted by open time, duplicates removed (first file wins), cut at the
    data end."""
    parts = [_read(p) for p in sources.files(inst)]
    if not parts:
        return {k: np.zeros(0, dtype=np.int64 if k == "ts_s" else np.float64) for k in COLUMNS}
    data = {k: np.concatenate([p[k] for p in parts]) for k in COLUMNS}
    order = np.argsort(data["ts_s"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(data["ts_s"].size, dtype=bool)
    keep[1:] = data["ts_s"][1:] != data["ts_s"][:-1]
    keep &= data["ts_s"] * 1000 < pr.DATA_END_MS
    keep &= np.isfinite(data["close"]) & (data["close"] > 0)
    return {k: v[keep] for k, v in data.items()}


def n_hours() -> int:
    return (pr.DATA_END_MS - pr.GRID_START_MS) // pr.HOUR_MS


def bar_close_ms(b: int | np.ndarray) -> int | np.ndarray:
    """Close time of hourly bar b (bar b covers open times [grid + b h, grid + (b + 1) h))."""
    return pr.GRID_START_MS + (np.asarray(b) + 1) * pr.HOUR_MS if isinstance(b, np.ndarray) else pr.GRID_START_MS + (b + 1) * pr.HOUR_MS


def bar_of_close(ms: int) -> int:
    if (ms - pr.GRID_START_MS) % pr.HOUR_MS:
        raise ValueError("not an hour close")
    return (ms - pr.GRID_START_MS) // pr.HOUR_MS - 1


def hourly_bars(ts_s: np.ndarray, close: np.ndarray, hours: int | None = None, grid_start_ms: int | None = None) -> tuple[np.ndarray, np.ndarray]:
    """Hourly closes on the grid: the close of the last minute of the hour, NaN unless at least `BAR_MIN_MINUTES` of
    the hour's 60 minutes exist. Returns (closes, minute counts)."""
    hours = n_hours() if hours is None else hours
    start_s = (pr.GRID_START_MS if grid_start_ms is None else grid_start_ms) // 1000
    b = (np.asarray(ts_s, dtype=np.int64) - start_s) // 3600
    inside = (b >= 0) & (b < hours)
    b, values = b[inside], np.asarray(close, dtype=np.float64)[inside]
    counts = np.bincount(b, minlength=hours)[:hours]
    out = np.full(hours, np.nan)
    if b.size:
        ends = np.concatenate((np.flatnonzero(np.diff(b) != 0), [b.size - 1]))
        out[b[ends]] = values[ends]
    out[counts < pr.BAR_MIN_MINUTES] = np.nan
    return out, counts


def daily_quote_volume(data: dict[str, np.ndarray], ct_val: float | None = None) -> tuple[dict[str, list], float | None]:
    """Daily quote volume per UTC day, the rule of `research_swing2.okx_universe.volumes`: per minute the archived
    quote volume, else base volume x close, else contracts x contract value x close. The contract value is `ct_val`
    when given, else inferred as the median of quote volume / (contracts x close) over the minutes carrying both (no
    metadata request is made). Returns ({day: [volume, minutes, minutes without a volume]}, inferred value)."""
    both = np.isfinite(data["volume"]) & np.isfinite(data["contracts"]) & (data["contracts"] > 0)
    inferred = float(np.median(data["volume"][both] / (data["contracts"][both] * data["close"][both]))) if both.any() else None
    value = ct_val if ct_val is not None else inferred
    quote = np.where(np.isfinite(data["volume"]), data["volume"],
                     np.where(np.isfinite(data["base_volume"]), data["base_volume"] * data["close"],
                              data["contracts"] * (value if value is not None else np.nan) * data["close"]))
    days = (data["ts_s"] // 86_400).astype(np.int64)
    out: dict[str, list] = {}
    for day in np.unique(days):
        mask = days == day
        values = quote[mask]
        out[str(int(day))] = [float(np.nansum(values)), int(mask.sum()), int(np.isnan(values).sum())]
    return out, inferred


class Prices:
    """1-minute closes of one instrument indexed by close time (ms), for bounded-delay lookups."""

    def __init__(self, ts_s: np.ndarray, close: np.ndarray):
        self.close_ms = (np.asarray(ts_s, dtype=np.int64) + 60) * 1000
        self.close = np.asarray(close, dtype=np.float64)

    def first_in(self, start_ms: np.ndarray, max_delay_ms: int) -> tuple[np.ndarray, np.ndarray]:
        """Per target: the first close at or after `start` and no later than `start + max_delay` (NaN otherwise)."""
        start_ms = np.asarray(start_ms, dtype=np.int64)
        idx = np.searchsorted(self.close_ms, start_ms, side="left")
        ok = idx < self.close_ms.size
        safe = np.where(ok, idx, 0)
        ok &= self.close_ms[safe] <= start_ms + max_delay_ms if self.close_ms.size else False
        price = np.where(ok, self.close[safe] if self.close.size else np.nan, np.nan)
        when = np.where(ok, self.close_ms[safe] if self.close_ms.size else -1, -1)
        return price, when

    def last_between(self, after_ms: np.ndarray, before_ms: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
        """Per target: the last close strictly after `after` and strictly before `before` (NaN otherwise)."""
        idx = np.searchsorted(self.close_ms, np.asarray(before_ms, dtype=np.int64), side="left") - 1
        ok = idx >= 0
        safe = np.where(ok, idx, 0)
        if self.close_ms.size:
            ok &= self.close_ms[safe] > np.asarray(after_ms, dtype=np.int64)
        else:
            ok &= False
        price = np.where(ok, self.close[safe] if self.close.size else np.nan, np.nan)
        when = np.where(ok, self.close_ms[safe] if self.close_ms.size else -1, -1)
        return price, when


def entry_exit(prices: Prices, decision_ms: np.ndarray, horizon_h: int, truncate: bool) -> dict[str, np.ndarray]:
    """Entry: the first close in [t + 5 min, t + 5 min + 15 min]. Exit: the first close in [T, T + 60 min] with
    T = t + 5 min + h; when there is none and `truncate`, the last close after the entry and before T (flagged
    `truncated`: a delisting or a gap). The log return is NaN when either price is missing."""
    decision_ms = np.asarray(decision_ms, dtype=np.int64)
    t_e = decision_ms + pr.LATENCY_MIN * pr.MINUTE_MS
    entry, entry_ms = prices.first_in(t_e, pr.ENTRY_MAX_DELAY_MIN * pr.MINUTE_MS)
    target = t_e + horizon_h * pr.HOUR_MS
    exit_, exit_ms = prices.first_in(target, pr.EXIT_MAX_DELAY_MIN * pr.MINUTE_MS)
    truncated = np.zeros(decision_ms.size, dtype=bool)
    if truncate:
        missing = np.isnan(exit_) & np.isfinite(entry)
        if missing.any():
            last, last_ms = prices.last_between(entry_ms, target)
            use = missing & np.isfinite(last)
            exit_ = np.where(use, last, exit_)
            exit_ms = np.where(use, last_ms, exit_ms)
            truncated = use
    with np.errstate(invalid="ignore", divide="ignore"):
        ret = np.log(exit_ / entry)
    return {"ret": ret, "entry": entry, "exit": exit_, "entry_ms": entry_ms, "exit_ms": exit_ms, "truncated": truncated}
