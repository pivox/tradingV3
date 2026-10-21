"""Event study of the clock / calendar windows (G5) on the 1-minute X-Perp history (Gate 1) or on the
capture tape (Gate 2), driven entirely by a frozen JSON specification — nothing here is searched.

    python -m scripts.research_lp.cli g5-eval --tape DIR --spec g5-gate1.json --out-json R.json --out-md R.md \\
        [--expect-spec-sha256 HEX] [--expect-code-digest HEX]

**The specification is strict (`g5_eval/2`).** Every key of the document, at every level, must be known:
an unknown key is refused (`SpecError`), never ignored. `requires` lists the capabilities the spec relies
on; each must be implemented here (`CAPABILITIES`) and every capability the spec actually uses must be
listed, so no other version of this evaluator can run it under another meaning. Thresholds, signs,
windows, controls, costs, seeds and the economic gate all come from the spec; the command line exposes
none of them. Implemented (`LP-G5-DRAFT-PREREGISTRATION.md` §2.3 (B) and the CTO review of 2026-10-21):

- (i) `price_source` (`last` | `mark`) is mandatory: one reference price, no fallback; a return whose ends
  do not share the same source would be excluded and counted (`mixed_source`); the other price is
  descriptive (`r_alt_bp`). On the history `last` = `candles_1m.close`, `mark` = `mark_1m.close`; on the
  capture `last` = the minute's last print, `mark` = its last mark. No forward fill on the history.
- (ii) a sign or condition on `funding_rate` reads the **realized** rate settled at h only (a row without
  `realizedRate` never supplies a rate: the settlement's rate is unknown, counted), with a dead zone
  (`dead_zone: [low, high]`, `eps`: +1 above high + eps, −1 below low − eps, excluded inside); the
  `funding` generator keeps a settlement only when the previous and next `fundingTime` gaps equal
  `interval_hours` ± `interval_tolerance_min` (others counted: `interval_other`, `interval_unknown`).
- (iii) `premium_wavg`: P̂(t) = Σ k·π(t−n+k) / Σ k over the n minutes ending at t (weights 1…n, the
  shape of the official formula), π = `premium_mark_index` on the history ((mark − index) / index) or
  `premium_okx` on the capture (the funding channel's `premium`): two distinct fields, each refused on
  the other source (M2). It is a `sign` or a `control_sign` (the sign at the controls of a type whose
  event sign reads `funding_rate`, which does not exist there); concordance κ at the events (C6) and the
  3 × 3 classification table are reported.
- (iv) controls of a type on the days its instrument has ≥ 1 event of that type (`same_days_as_events`),
  at minute `control_minute_of_hour` (default 0) of the fixed `hours` and of hours drawn per day
  (`minute_zero_only`; seed per type and day), ≥ `exclusion_min` from the union of every settlement of
  the instrument (all intervals), every instant of every type (including `evaluate: false` filters) and
  the assumed settlement hours outside the funding coverage (`funding_outside_coverage`); the distance
  is taken to the control instant or to its whole window (`exclusion_on`).
- (v) the unit is the **instant**: per instant, the mean over the instruments present; n = unique
  instants; `min_instants` applies to n; instruments per instant reported.
- (vi) `execution_role` per type (`maker_maker`, `taker_maker`): fees at the role, adverse selection per
  maker leg, slippage, all in the net; `cost_stress_multiplier` multiplies every cost term.
- (vii) frozen date lists on `us_open` / `fixed_time`: `exclude_dates` (no session: NYSE holidays) and `skip_dates` (a session the type
  does not trade: CPI / Employment Situation days) — both give no instant; skipped days stay candidate days of the economic reading.
- (viii) verdict: C1 D ≥ threshold and LB90(D) > 0; C2 event net > 0; C3 C2 at stressed costs; C4 D > 0 in
  both calendar halves; C5 LB90(D) > 0 without the best 5 % event instants; C6 κ ≥ `concordance_min`
  (types with a `control_sign`). `INCONCLUSIVE` (device defect, before any other verdict): C6, proxy
  coverage, funding coverage, fetch errors (§4.5 thresholds from `quality`); `NOT_INFORMATIVE` below
  `min_instants` / `min_days`; FAIL annotated « effet exclu » (UB90 < threshold) or « non détecté ».
- (ix) economic reading per type (`GATES` §1): ≤ `max_fills_per_instant` fills per instant in instrument-id
  order, `order_notional_usd`, fills per candidate day (days with a generated instant, plus the `skip_dates`
  sessions) × `days_per_year` of the type; plafond_USD_an point / LB90 / UB90 against
  `s_eco_usd_per_year` → « PASS, poursuivi » / « PASS, non poursuivi (sous S_éco) » / « FAIL ».
- (x) capture (Gate 2): simulated entry on the real book — post-only at the own touch decided at
  h + arm_min and resting until h + rest_until_min (`maker_maker`), or taker at the opposite touch plus
  slippage (`taker_maker`) —, passive exit at the opposite touch from h + to_min until h + exit_until_min,
  remainder taker; a fill within `funding_fill_grace_min` after a settlement crosses it (`sim_net_bp`).
- (xii) `requires` and the strict key whitelist.
- **No look-ahead (B2).** Every field a condition or a sign reads must be observed at or before the decision
  `h + arm_min` (`funding_rate` is observed at h − 1: "The fee assessment at 16:00 will use the funding rate
  calculated at 15:59"). A later observation is refused unless declared by name in `declared_lookahead`
  (`{"field": "sign" | "condition" | "control_sign", "reason": "…"}`), copied into the JSON and the Markdown.
- **Coverage (M1).** `restrict_to_coverage` bounds a type's events and controls to the D_cov of the named
  history streams (from the fetch manifest: `end_reason`, `coverage_start_us`; else the oldest row read). It is
  mandatory on the history for the streams a type reads: `funding_hist` for a `funding_rate` sign / condition,
  `mark_1m` and `index_1m` for `premium_mark_index` (sign, condition or control sign);
  a window crossing a settlement whose rate is unknown is excluded and counted (`funding_unknown`; the
  settlement of exactly END_EXCLUSIVE is never known: `funding_unknown_end_edge`).
- Quality (§4.5): unconfirmed candles (`confirm = 0`) dropped and counted (`unconfirmed_dropped`); an
  instrument missing more than `max_missing_window_share` of its event-window minutes is excluded from the
  type; optional `listTime + days_after_list` eligibility and zero-volume exclusion.

Statistics: D = mean(event instants) − mean(control instants); bootstrap over blocks of calendar days
(events and controls of a block resampled together; LB90 / UB90, one-sided p = P*(D* ≤ 0), `GATES` §2.2).
Holm (α from the spec) over the evaluated `primary` types, a `NOT_INFORMATIVE` or `INCONCLUSIVE` type
counting p = 1; `holm_local_confirmed` = local PASS surviving this protocol's family (not `PASS_PROGRAMME`,
which is the programme-level status of `GATES` §2.3: `programme.m_max` reports its immediate test).

Guards: the spec window may not touch the quarantine [2026-10-14, 2026-11-02) (`g5_fetch.END_EXCLUSIVE`,
`xperp_capture.QUARANTINE_FLOOR`); a history read drops any row at or after END_EXCLUSIVE; the spec's
sha256 and the code digest are recorded and checked when expected. Sums use `math.fsum`.
"""

from __future__ import annotations

import argparse
import json
import math
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import events as ev
from . import g5_fetch, lp_sim, tape
from . import xperp_capture as xc

EVALUATOR_VERSION = "g5_eval/2"
MINUTE_US = 60 * tape.SECOND_US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * tape.SECOND_US
QUARANTINE_START_US = g5_fetch.END_EXCLUSIVE_US  # 2026-10-14T00:00:00Z
QUARANTINE_END_US = xc.quarantine_floor_us()  # 2026-11-02T00:00:00Z
PAD_DAYS = 1  # read before the window: the premium average looks back up to 480 minutes and windows start before h
FUNDING_RATE_OBSERVED_MIN = -1  # funding page EEE l.258–270: the rate of the 16:00 assessment is calculated at 15:59
PREMIUM_FFILL_MIN = 5
PRICE_CODE = {"last": 1, "mark": 2}
ROLES = ("maker_maker", "taker_maker")
VERDICTS = ("PASS", "FAIL", "NOT_INFORMATIVE", "INCONCLUSIVE")
APPROX_MEASURES = ("r_bp", "net_bp", "net_bp_stress", "net_bp_as_sensitivity", "r_alt_bp")
SIM_MEASURES = ("sim_net_bp", "sim_net_bp_stress")
GENERATORS = ("funding", "fixed_time", "us_open", "explicit")
SIGN_KINDS = ("constant", "field_sign", "return_sign", "premium_wavg")
PREMIUM_FIELDS = {"premium_mark_index": "history", "premium_okx": "capture"}
CONDITION_FIELDS = ("price_return", "premium_mark_index", "premium_okx", "funding_rate")
CONDITION_OPS = {">": np.greater, "<": np.less, ">=": np.greater_equal, "<=": np.less_equal, "abs>": lambda a, b: np.abs(a) > b, "abs<": lambda a, b: np.abs(a) < b}
LOOKAHEAD_PATHS = ("condition", "sign", "control_sign")
SIM_STATUS = ("filled", "unfilled", "stale", "crossed", "skipped_size", "funding_unknown", "no_exit_quote", "unavailable")

# The versioned whitelist (xii). A spec names the capabilities it relies on in `requires`; an unknown name is refused, and so is
# a spec using a capability it does not declare.
CAPABILITIES: dict[str, str] = {
    "price_source_last": "(B) i: mandatory price_source, one source, no fallback, mixed-source returns excluded and counted",
    "funding_rate_sign_dead_zone": "(B) ii: sign / condition on the realized funding rate with a dead zone; predicted rates never used",
    "interval_8h_filter": "(B) ii: funding generator keeps settlements whose previous and next gaps equal interval_hours ± tolerance",
    "control_sign_premium_wavg": "(B) iii: premium_wavg sign (weights 1..n, coverage floor, thresholds) as sign or control_sign; κ (C6)",
    "controls_same_days_minute_zero": "(B) iv: controls on the type's event days, at a fixed minute of drawn hours; union exclusion",
    "controls_minute_of_hour": "per-type minute of the hour of the controls (quant review B2)",
    "controls_window_exclusion": "controls.exclusion_on = window: the whole control window kept away from every excluded instant",
    "n_unit_instant": "(B) v: unit = instant, mean over instruments present, n = unique instants",
    "execution_role_costs": "(B) vi: execution_role, adverse selection per maker leg, slippage, stress on every cost term",
    "us_open_exclude_dates": "(B) vii: frozen exclude_dates lists",
    "verdict_c2_c6": "(B) viii: C1-C6, INCONCLUSIVE, FAIL annotation",
    "days_per_year": "(B) ix: economic reading with days_per_year per type and S_eco",
    "gate2_lp_sim_roles": "(B) x: capture simulation, maker or taker entry, passive exit then taker, funding fill grace",
    "declared_lookahead": "B2: a field observed after arm_min only through a named declaration",
    "premium_named_fields": "M2: premium_mark_index (history) and premium_okx (capture) are distinct fields",
    "coverage_restriction": "M1: restrict_to_coverage (D_cov of the named streams), funding_unknown, assumed hours outside coverage",
    "quality_rules": "§4.5: missing-window share, proxy share, fetch error share, funding_unknown share",
    "eligibility_days_after_list": "§3.2 (i): instrument eligible from listTime + days (quant review B3)",
    "zero_volume_exclusion": "quant review M3: exclude an observation whose arming, window or sign candle has vol = 0",
    "programme_family": "GATES §2.3: PASS_PROGRAMME immediate test p <= alpha / m_max",
}
ALWAYS_USED = ("price_source_last", "n_unit_instant", "execution_role_costs", "verdict_c2_c6", "days_per_year", "quality_rules")
RENAMED_KEYS = {
    "fee_stress_multiplier": "cost_stress_multiplier (the stress multiplies every cost term, not the fees only)",
    "premium": "premium_mark_index (history: (mark - index) / index) or premium_okx (capture: the funding channel's premium)",
    "mid_return": "price_return (the return of the reference price price_source)",
}


class SpecError(ValueError):
    """The specification is incomplete, inconsistent or asks for something this evaluator does not implement; nothing is evaluated."""


def _json_default(value):
    if isinstance(value, np.integer):
        return int(value)
    if isinstance(value, np.floating):
        return None if np.isnan(value) else float(value)
    if isinstance(value, np.ndarray):
        return value.tolist()
    if isinstance(value, (set, frozenset, tuple)):
        return sorted(value) if isinstance(value, (set, frozenset)) else list(value)
    if isinstance(value, float) and math.isnan(value):
        return None
    raise TypeError(f"not serialisable: {type(value)}")


def _finite(value) -> float | None:
    try:
        value = float(value)
    except (TypeError, ValueError):
        return None
    return value if math.isfinite(value) else None


def _fmean(values) -> float | None:
    values = [float(v) for v in np.asarray(values, dtype=np.float64).ravel().tolist() if math.isfinite(v)]
    return math.fsum(values) / len(values) if values else None


# ----------------------------------------------------------------------------- specification: strict reading


@dataclass(frozen=True)
class EventSpec:
    type: str
    primary: bool
    evaluate: bool
    generator: dict
    condition: dict | None = None
    sign: dict | None = None
    control_sign: dict | None = None
    from_min: int = 0
    to_min: int = 0
    arm_min: int = 0
    rest_until_min: int = 0
    exit_until_min: int | None = None
    execution_role: str | None = None
    days_per_year: float | None = None
    restrict_to_coverage: tuple = ()
    control_minute_of_hour: int = 0
    declared_lookahead: tuple = ()
    observed_min: dict = field(default_factory=dict)  # path → minute (relative to h) at which the field is observed
    description: str = ""

    @property
    def price_offsets(self) -> tuple[int, ...]:
        """Minute offsets (relative to h) where the reference price must exist: arming, window ends, sign and condition windows."""
        offsets = {self.arm_min, self.from_min, self.to_min}
        if self.sign and self.sign["kind"] == "return_sign":
            offsets |= {self.sign["from_min"], self.sign["to_min"]}
        if self.condition and self.condition["field"] == "price_return":
            offsets |= {self.condition["from_min"], self.condition["to_min"]}
        return tuple(sorted(offsets))

    @property
    def lo_min(self) -> int:
        lows = list(self.price_offsets)
        for block in (self.sign, self.condition):
            if block and "at_min" in block and block.get("field") != "funding_rate":
                lows.append(int(block["at_min"]))
        return min(lows)

    @property
    def wavg(self) -> dict | None:
        """The premium_wavg block the type relies on (its sign, else its control sign)."""
        if self.sign and self.sign["kind"] == "premium_wavg":
            return self.sign
        return self.control_sign


@dataclass(frozen=True)
class Spec:
    spec_id: str
    source: str
    requires: tuple
    price_source: str
    start: str  # YYYY-MM-DD
    end_exclusive: str  # YYYY-MM-DD
    alpha: float
    threshold_bp: float
    min_instants: int
    min_days: int
    primary_measure: str
    resamples: int
    seed: int
    block_days: int
    execution: dict
    controls: dict
    quality: dict
    economic: dict
    eligibility_days: float | None
    assumed_hours: tuple | None
    programme_m_max: int | None
    events: tuple
    used: tuple
    raw: dict = field(default_factory=dict, compare=False)

    @property
    def dates(self) -> list[str]:
        last = tape.date_of_day(tape.day_of_date(self.end_exclusive) - 1)
        return tape.parse_dates(f"{self.start}..{last}")

    @property
    def start_us(self) -> int:
        return tape.day_of_date(self.start) * DAY_US

    @property
    def end_us(self) -> int:
        return tape.day_of_date(self.end_exclusive) * DAY_US

    @property
    def pad_start_us(self) -> int:
        pad = self.start_us - PAD_DAYS * DAY_US
        return max(pad, QUARANTINE_END_US) if self.source == "capture" else pad

    @property
    def simulated(self) -> bool:
        return self.source == "capture" and self.execution.get("sim") is not None

    @property
    def measures(self) -> tuple[str, ...]:
        return APPROX_MEASURES + (SIM_MEASURES if self.simulated else ())

    @property
    def net_measure(self) -> str:
        return "sim_net_bp" if self.primary_measure == "sim_net_bp" else "net_bp"

    @property
    def stress_measure(self) -> str:
        return "sim_net_bp_stress" if self.primary_measure == "sim_net_bp" else "net_bp_stress"

    @property
    def evaluated(self) -> tuple:
        return tuple(e for e in self.events if e.evaluate)


def _check_keys(document, where: str, required=(), optional=()) -> dict:
    if not isinstance(document, dict):
        raise SpecError(f"{where} must be an object")
    allowed = set(required) | set(optional)
    unknown = sorted(k for k in document if k not in allowed)
    if unknown:
        hints = [f"{k!r} -> {RENAMED_KEYS[k]}" for k in unknown if k in RENAMED_KEYS]
        raise SpecError(f"{where}: unknown key(s) {unknown}; {EVALUATOR_VERSION} refuses what it does not implement" + (f" (renamed: {'; '.join(hints)})" if hints else ""))
    missing = [k for k in required if k not in document]
    if missing:
        raise SpecError(f"{where}: missing key(s) {missing}")
    return document


def _int(document: dict, key: str, where: str, lo: int | None = None, hi: int | None = None) -> int:
    value = document[key]
    if isinstance(value, bool) or not isinstance(value, int):
        raise SpecError(f"{where}.{key}: expected an integer, got {value!r}")
    if (lo is not None and value < lo) or (hi is not None and value > hi):
        raise SpecError(f"{where}.{key}: {value} outside [{lo}, {hi}]")
    return int(value)


def _num(document: dict, key: str, where: str, lo: float | None = None, hi: float | None = None, lo_open: bool = False) -> float:
    value = document[key]
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
        raise SpecError(f"{where}.{key}: expected a finite number, got {value!r}")
    value = float(value)
    if lo is not None and (value < lo or (lo_open and value == lo)):
        raise SpecError(f"{where}.{key}: {value} must be {'>' if lo_open else '>='} {lo}")
    if hi is not None and value > hi:
        raise SpecError(f"{where}.{key}: {value} must be <= {hi}")
    return value


def _bool(document: dict, key: str, where: str) -> bool:
    value = document[key]
    if not isinstance(value, bool):
        raise SpecError(f"{where}.{key}: expected true or false, got {value!r}")
    return value


def _str(document: dict, key: str, where: str, choices=None) -> str:
    value = document[key]
    if not isinstance(value, str) or not value.strip():
        raise SpecError(f"{where}.{key}: expected a non-empty string, got {value!r}")
    if choices is not None and value not in choices:
        hint = f" (renamed: {RENAMED_KEYS[value]})" if value in RENAMED_KEYS else ""
        raise SpecError(f"{where}.{key}: {value!r} not in {tuple(choices)}{hint}")
    return value


def _list(document: dict, key: str, where: str) -> list:
    value = document[key]
    if not isinstance(value, list):
        raise SpecError(f"{where}.{key}: expected a list")
    return value


def _parse_hhmm(text: str, where: str) -> int:
    parts = str(text).strip().split(":")
    if len(parts) != 2 or not all(p.isdigit() for p in parts):
        raise SpecError(f"{where}: time {text!r} is not HH:MM")
    hour, minute = int(parts[0]), int(parts[1])
    if hour > 23 or minute > 59:
        raise SpecError(f"{where}: time {text!r} out of range")
    return hour * 60 + minute


def _hours(values: list, where: str) -> tuple[int, ...]:
    out = []
    for value in values:
        if isinstance(value, bool) or not isinstance(value, int) or not 0 <= value <= 23:
            raise SpecError(f"{where}: hours must be integers within 0..23, got {value!r}")
        out.append(int(value))
    if len(set(out)) != len(out):
        raise SpecError(f"{where}: duplicate hours")
    return tuple(out)


def _date_days(values: list, where: str) -> tuple[int, ...]:
    days = []
    for text in values:
        if not isinstance(text, str):
            raise SpecError(f"{where}: dates must be 'YYYY-MM-DD' strings, got {text!r}")
        try:
            datetime.strptime(text, "%Y-%m-%d")
        except ValueError:
            raise SpecError(f"{where}: {text!r} is not a YYYY-MM-DD date") from None
        days.append(tape.day_of_date(text))
    if len(set(days)) != len(days):
        raise SpecError(f"{where}: duplicate dates")
    return tuple(sorted(days))


def _parse_generator(generator, where: str, used: set) -> dict:
    kind = _str(_check_keys(generator, where, ("kind",), ("fallback_hours", "interval_hours", "interval_tolerance_min", "times", "weekdays", "exclude_dates", "skip_dates", "instants")),
                "kind", where, GENERATORS)
    if kind == "funding":
        _check_keys(generator, where, ("kind",), ("fallback_hours", "interval_hours", "interval_tolerance_min"))
        out = {"kind": kind, "fallback_hours": _hours(_list(generator, "fallback_hours", where), f"{where}.fallback_hours") if "fallback_hours" in generator else ()}
        if "interval_hours" in generator:
            if "interval_tolerance_min" not in generator:
                raise SpecError(f"{where}: interval_hours needs interval_tolerance_min")
            out["interval_hours"] = _num(generator, "interval_hours", where, 0.0, 24.0, lo_open=True)
            out["interval_tolerance_min"] = _num(generator, "interval_tolerance_min", where, 0.0, 60.0)
            used.add("interval_8h_filter")
        elif "interval_tolerance_min" in generator:
            raise SpecError(f"{where}: interval_tolerance_min without interval_hours")
        return out
    if kind == "fixed_time":
        _check_keys(generator, where, ("kind", "times"), ("weekdays", "exclude_dates", "skip_dates"))
        minutes = tuple(sorted({_parse_hhmm(t, f"{where}.times") for t in _list(generator, "times", where)}))
        weekdays = _list(generator, "weekdays", where) if "weekdays" in generator else list(range(7))
        if any(isinstance(d, bool) or not isinstance(d, int) or not 0 <= d <= 6 for d in weekdays):
            raise SpecError(f"{where}.weekdays must be integers within 0..6 (Monday = 0)")
        out = {"kind": kind, "minutes": minutes, "weekdays": tuple(sorted(set(weekdays))), "exclude_days": (), "skip_days": ()}
        for key, name in (("exclude_dates", "exclude_days"), ("skip_dates", "skip_days")):
            if key in generator:
                out[name] = _date_days(_list(generator, key, where), f"{where}.{key}")
                used.add("us_open_exclude_dates")
        if set(out["exclude_days"]) & set(out["skip_days"]):
            raise SpecError(f"{where}: a date cannot be both excluded (no session) and skipped (session, not traded)")
        return out
    if kind == "us_open":
        _check_keys(generator, where, ("kind", "exclude_dates"), ("skip_dates",))
        used.add("us_open_exclude_dates")
        out = {"kind": kind, "exclude_days": _date_days(_list(generator, "exclude_dates", where), f"{where}.exclude_dates"),
               "skip_days": _date_days(_list(generator, "skip_dates", where), f"{where}.skip_dates") if "skip_dates" in generator else ()}
        if set(out["exclude_days"]) & set(out["skip_days"]):
            raise SpecError(f"{where}: a date cannot be both excluded (no session) and skipped (session, not traded)")
        return out
    _check_keys(generator, where, ("kind", "instants"))
    instants = []
    for text in _list(generator, "instants", where):
        try:
            instants.append(tape.parse_iso_utc(str(text)))
        except ValueError:
            raise SpecError(f"{where}.instants: {text!r} is not an ISO UTC instant") from None
    return {"kind": kind, "instants_us": tuple(instants)}


def _check_premium_field(name: str, source: str, where: str, used: set) -> None:
    if name not in PREMIUM_FIELDS:
        raise SpecError(f"{where}: {name!r} is not a premium field {tuple(PREMIUM_FIELDS)}")
    if PREMIUM_FIELDS[name] != source:
        raise SpecError(f"{where}: {name!r} exists on the {PREMIUM_FIELDS[name]} only, not on a {source} source (two different quantities; CTO M2)")
    used.add("premium_named_fields")


def _dead_zone(block: dict, where: str) -> tuple[float, float, float]:
    zone = block["dead_zone"]
    if not isinstance(zone, list) or len(zone) != 2 or any(isinstance(v, bool) or not isinstance(v, (int, float)) for v in zone):
        raise SpecError(f"{where}.dead_zone must be [low, high]")
    low, high = float(zone[0]), float(zone[1])
    if low > high:
        raise SpecError(f"{where}.dead_zone: low > high")
    return low, high, _num(block, "eps", where, 0.0)


def _parse_condition(condition, where: str, spec_source: str, used: set) -> tuple[dict | None, int | None]:
    if condition is None:
        return None, None
    if not isinstance(condition, dict):
        raise SpecError(f"{where} must be an object or null")
    if "field" not in condition or "op" not in condition:
        raise SpecError(f"{where}: missing key(s) field / op")
    name = _str(condition, "field", where, CONDITION_FIELDS)
    op = _str(condition, "op", where, tuple(CONDITION_OPS) + ("outside_dead_zone",))
    value_keys = ("low", "high", "eps") if op == "outside_dead_zone" else ("value",)
    if name == "price_return":
        _check_keys(condition, where, ("field", "op", "from_min", "to_min") + value_keys)
        out = {"field": name, "op": op, "from_min": _int(condition, "from_min", where), "to_min": _int(condition, "to_min", where)}
        if out["from_min"] >= out["to_min"]:
            raise SpecError(f"{where}: from_min must be < to_min")
        observed = out["to_min"]
    elif name == "funding_rate":
        _check_keys(condition, where, ("field", "op") + value_keys, ("require_realized",))
        if "require_realized" in condition and _bool(condition, "require_realized", where) is not True:
            raise SpecError(f"{where}.require_realized: only true is implemented (a predicted rate is never used)")
        out = {"field": name, "op": op}
        observed = FUNDING_RATE_OBSERVED_MIN
        used.add("funding_rate_sign_dead_zone")
    else:
        _check_keys(condition, where, ("field", "op", "at_min") + value_keys)
        _check_premium_field(name, spec_source, where, used)
        out = {"field": name, "op": op, "at_min": _int(condition, "at_min", where)}
        observed = out["at_min"]
    if op == "outside_dead_zone":
        low, high = _num(condition, "low", where), _num(condition, "high", where)
        if low > high:
            raise SpecError(f"{where}: low > high")
        out.update({"low": low, "high": high, "eps": _num(condition, "eps", where, 0.0)})
    else:
        out["value"] = _num(condition, "value", where)
    return out, observed


def _parse_wavg(block: dict, where: str, spec_source: str, used: set, control: bool) -> dict:
    required = ("kind", "base", "at_min", "minutes", "min_coverage", "upper", "lower") + (("concordance_min", "concordance_on") if control else ())
    _check_keys(block, where, required, () if control else ("multiplier",))
    base = _str(block, "base", where)
    _check_premium_field(base, spec_source, f"{where}.base", used)
    out = {"kind": "premium_wavg", "base": base, "at_min": _int(block, "at_min", where), "minutes": _int(block, "minutes", where, 1, 10_000),
           "min_coverage": _num(block, "min_coverage", where, 0.0, 1.0, lo_open=True), "upper": _num(block, "upper", where), "lower": _num(block, "lower", where),
           "multiplier": _int(block, "multiplier", where) if "multiplier" in block else 1}
    if out["lower"] > out["upper"]:
        raise SpecError(f"{where}: lower > upper")
    if out["multiplier"] not in (1, -1):
        raise SpecError(f"{where}.multiplier must be +1 or -1")
    if control:
        out["concordance_min"] = _num(block, "concordance_min", where, 0.0, 1.0)
        out["concordance_on"] = _str(block, "concordance_on", where, ("class", "sign"))
    used.add("control_sign_premium_wavg")
    return out


def _parse_sign(sign, where: str, spec_source: str, used: set) -> tuple[dict, int | None]:
    if not isinstance(sign, dict):
        raise SpecError(f"{where} must be an object")
    kind = _str(_check_keys(sign, where, ("kind",), ("value", "field", "at_min", "multiplier", "dead_zone", "eps", "from_min", "to_min", "base", "minutes", "min_coverage", "upper", "lower")),
                "kind", where, SIGN_KINDS)
    if kind == "constant":
        _check_keys(sign, where, ("kind", "value"))
        value = _int(sign, "value", where)
        if value not in (1, -1):
            raise SpecError(f"{where}.value must be +1 or -1")
        return {"kind": kind, "value": value}, None
    if kind == "premium_wavg":
        return _parse_wavg(sign, where, spec_source, used, control=False), int(sign["at_min"])
    multiplier = _int(sign, "multiplier", where) if "multiplier" in sign else 1
    if multiplier not in (1, -1):
        raise SpecError(f"{where}.multiplier must be +1 or -1")
    if kind == "return_sign":
        _check_keys(sign, where, ("kind", "from_min", "to_min"), ("multiplier",))
        out = {"kind": kind, "from_min": _int(sign, "from_min", where), "to_min": _int(sign, "to_min", where), "multiplier": multiplier}
        if out["from_min"] >= out["to_min"]:
            raise SpecError(f"{where}: from_min must be < to_min")
        return out, out["to_min"]
    name = _str(sign, "field", where, ("funding_rate",) + tuple(PREMIUM_FIELDS))
    if name == "funding_rate":
        _check_keys(sign, where, ("kind", "field", "dead_zone", "eps"), ("at_min", "multiplier"))
        if "at_min" in sign and _int(sign, "at_min", where) != 0:
            raise SpecError(f"{where}.at_min: the funding rate is the one settled at h (at_min 0)")
        low, high, eps = _dead_zone(sign, where)
        used.add("funding_rate_sign_dead_zone")
        return {"kind": kind, "field": name, "at_min": 0, "dead_zone": (low, high), "eps": eps, "multiplier": multiplier}, FUNDING_RATE_OBSERVED_MIN
    _check_keys(sign, where, ("kind", "field", "at_min"), ("multiplier", "dead_zone", "eps"))
    _check_premium_field(name, spec_source, where, used)
    out = {"kind": kind, "field": name, "at_min": _int(sign, "at_min", where), "multiplier": multiplier, "dead_zone": None, "eps": 0.0}
    if "dead_zone" in sign:
        if "eps" not in sign:
            raise SpecError(f"{where}: dead_zone needs eps")
        low, high, eps = _dead_zone(sign, where)
        out.update({"dead_zone": (low, high), "eps": eps})
    elif "eps" in sign:
        raise SpecError(f"{where}: eps without dead_zone")
    return out, out["at_min"]


def _parse_lookahead(value, where: str) -> tuple[dict, ...]:
    items = [value] if isinstance(value, dict) else value
    if not isinstance(items, list):
        raise SpecError(f"{where} must be an object {{field, reason}} or a list of them")
    out, seen = [], set()
    for i, item in enumerate(items):
        w = f"{where}[{i}]"
        _check_keys(item, w, ("field", "reason"))
        name = _str(item, "field", w, LOOKAHEAD_PATHS)
        if name in seen:
            raise SpecError(f"{w}: {name} declared twice")
        seen.add(name)
        out.append({"field": name, "reason": _str(item, "reason", w)})
    return tuple(out)


_EVAL_REQUIRED = ("type", "generator", "sign", "window", "arm_min", "rest_until_min", "execution_role", "days_per_year")
_EVAL_OPTIONAL = ("primary", "evaluate", "description", "condition", "control_sign", "exit_until_min", "declared_lookahead", "restrict_to_coverage", "control_minute_of_hour")


def _parse_event(item, index: int, source: str, simulated: bool, used: set) -> EventSpec:
    where = f"events[{index}]"
    if not isinstance(item, dict):
        raise SpecError(f"{where} must be an object")
    evaluate = _bool(item, "evaluate", where) if "evaluate" in item else True
    if not evaluate:
        _check_keys(item, where, ("type", "evaluate", "generator"), ("primary", "description"))
        if "primary" in item and _bool(item, "primary", where):
            raise SpecError(f"{where}: an evaluate:false type cannot be primary")
        etype = _str(item, "type", where)
        return EventSpec(etype, False, False, _parse_generator(item["generator"], f"{where}.generator", used), description=str(item.get("description", "")))
    _check_keys(item, where, _EVAL_REQUIRED, _EVAL_OPTIONAL)
    etype = _str(item, "type", where)
    primary = _bool(item, "primary", where) if "primary" in item else False
    generator = _parse_generator(item["generator"], f"{where}.generator", used)
    condition, cond_obs = _parse_condition(item.get("condition"), f"{where}.condition", source, used)
    sign, sign_obs = _parse_sign(item["sign"], f"{where}.sign", source, used)
    control_sign, cs_obs = None, None
    if item.get("control_sign") is not None:
        block = item["control_sign"]
        if not isinstance(block, dict) or block.get("kind") != "premium_wavg":
            raise SpecError(f"{where}.control_sign: only kind 'premium_wavg' is implemented")
        control_sign = _parse_wavg(block, f"{where}.control_sign", source, used, control=True)
        cs_obs = control_sign["at_min"]
    window = _check_keys(item["window"], f"{where}.window", ("from_min", "to_min"))
    from_min, to_min = _int(window, "from_min", f"{where}.window"), _int(window, "to_min", f"{where}.window")
    if from_min >= to_min:
        raise SpecError(f"{where}.window: from_min must be < to_min")
    arm_min = _int(item, "arm_min", where)
    if arm_min > from_min:
        raise SpecError(f"{where}.arm_min must be <= window.from_min (the order is armed before the window)")
    if arm_min == -1:
        raise SpecError(f"{where}.arm_min = -1 is forbidden (the minute before the instant is the toxic one; use -5 or -2)")
    rest_until_min = _int(item, "rest_until_min", where)
    if rest_until_min <= arm_min or rest_until_min > to_min:
        raise SpecError(f"{where}.rest_until_min must be in (arm_min, to_min]")
    exit_until_min = None
    if simulated:
        if "exit_until_min" not in item:
            raise SpecError(f"{where}: a simulated capture type needs exit_until_min (end of the passive exit; remainder taker)")
        exit_until_min = _int(item, "exit_until_min", where)
        if exit_until_min <= to_min:
            raise SpecError(f"{where}.exit_until_min must be > window.to_min")
    elif "exit_until_min" in item:
        raise SpecError(f"{where}.exit_until_min is a simulated-capture (Gate 2) key; it would be ignored here")
    role = _str(item, "execution_role", where, ROLES)
    days_per_year = _num(item, "days_per_year", where, 0.0, 366.0, lo_open=True)
    coverage = ()
    if "restrict_to_coverage" in item:
        if source != "history":
            raise SpecError(f"{where}.restrict_to_coverage: history only (the capture is continuous by construction)")
        names = _list(item, "restrict_to_coverage", where)
        if not names or any(n not in tape.HISTORY_LAYOUTS for n in names) or len(set(names)) != len(names):
            raise SpecError(f"{where}.restrict_to_coverage must list distinct streams among {tape.HISTORY_LAYOUTS}")
        coverage = tuple(names)
        used.add("coverage_restriction")
    minute = 0
    if "control_minute_of_hour" in item:
        minute = _int(item, "control_minute_of_hour", where, 0, 59)
        used.add("controls_minute_of_hour")
    reads_funding = (condition is not None and condition["field"] == "funding_rate") or sign.get("field") == "funding_rate"
    if reads_funding and generator["kind"] != "funding":
        raise SpecError(f"{where}: funding_rate exists only at settlement instants (generator kind 'funding')")
    if reads_funding and control_sign is None:
        raise SpecError(f"{where}: the sign / condition reads funding_rate, which does not exist at the controls: a control_sign is required")
    if control_sign is not None and not reads_funding:
        raise SpecError(f"{where}: control_sign replaces only a funding_rate sign / condition at the controls; otherwise the same rule applies at the controls")
    if source == "history":
        # M1: a type is bounded to the D_cov of the streams it reads (the funding history keeps three months; mark and index make the proxy).
        needed = set()
        if reads_funding:
            needed.add("funding_hist")
        reads_mark_index = any(b is not None and (b.get("field") == "premium_mark_index" or b.get("base") == "premium_mark_index") for b in (condition, sign, control_sign))
        if reads_mark_index:
            needed |= {"mark_1m", "index_1m"}
        missing_cov = sorted(needed - set(coverage))
        if missing_cov:
            raise SpecError(f"{where}: the type reads {missing_cov} and must be bounded to their coverage: list them in restrict_to_coverage (D_cov, CTO M1)")
    observed = {path: value for path, value in (("condition", cond_obs), ("sign", sign_obs), ("control_sign", cs_obs)) if value is not None}
    late = {path: value for path, value in observed.items() if value > arm_min}
    declared = _parse_lookahead(item["declared_lookahead"], f"{where}.declared_lookahead") if "declared_lookahead" in item else ()
    declared_names = {d["field"] for d in declared}
    for path in sorted(set(late) - declared_names):
        raise SpecError(f"{where}.{path} is observed at h{late[path]:+d}, after the decision at arm_min h{arm_min:+d} (look-ahead): move it at or before arm_min, "
                        f"or declare it by name in declared_lookahead {{field, reason}}")
    for path in sorted(declared_names - set(late)):
        raise SpecError(f"{where}.declared_lookahead names {path!r}, which is not observed after arm_min: nothing to declare")
    if declared:
        used.add("declared_lookahead")
    return EventSpec(etype, primary, True, generator, condition, sign, control_sign, from_min, to_min, arm_min, rest_until_min, exit_until_min, role, days_per_year, coverage, minute,
                     declared, observed, str(item.get("description", "")))


_TOP_REQUIRED = ("spec_id", "source", "requires", "unit", "window", "price_source", "alpha", "threshold_bp", "min_instants", "min_days", "primary_measure", "bootstrap",
                 "execution", "controls", "quality", "economic", "events")
_TOP_OPTIONAL = ("description", "eligibility", "funding_outside_coverage", "programme")


def parse_spec(document) -> Spec:
    _check_keys(document, "spec", _TOP_REQUIRED, _TOP_OPTIONAL)
    used: set[str] = set(ALWAYS_USED)
    spec_id = _str(document, "spec_id", "spec")
    source = _str(document, "source", "spec", ("history", "capture"))
    if _str(document, "unit", "spec", ("instant",)) != "instant":
        raise SpecError("unit must be 'instant'")
    window = _check_keys(document["window"], "window", ("start", "end_exclusive"))
    start, end_exclusive = _str(window, "start", "window"), _str(window, "end_exclusive", "window")
    for text in (start, end_exclusive):
        _date_days([text], "window")
    if tape.day_of_date(start) >= tape.day_of_date(end_exclusive):
        raise SpecError("window.start must be before window.end_exclusive")
    price_source = _str(document, "price_source", "spec", tuple(PRICE_CODE))
    alpha = _num(document, "alpha", "spec", 0.0, 1.0, lo_open=True)
    if alpha >= 1.0:
        raise SpecError("alpha must be in (0, 1)")
    threshold_bp = _num(document, "threshold_bp", "spec")
    min_instants = _int(document, "min_instants", "spec", 1)
    min_days = _int(document, "min_days", "spec", 1)
    boot = _check_keys(document["bootstrap"], "bootstrap", ("resamples", "seed", "block_days"))
    resamples, seed, block_days = _int(boot, "resamples", "bootstrap", 1), _int(boot, "seed", "bootstrap"), _int(boot, "block_days", "bootstrap", 1)
    ex = _check_keys(document["execution"], "execution", ("maker_bp", "taker_bp", "half_spread_bp", "adverse_selection_bp_per_leg", "slippage_bp", "cost_stress_multiplier"),
                     ("adverse_selection_sensitivity_bp_per_leg", "half_spread_from_book", "sim"))
    execution = {key: _num(ex, key, "execution", 0.0) for key in ("maker_bp", "taker_bp", "half_spread_bp", "adverse_selection_bp_per_leg", "slippage_bp")}
    execution["cost_stress_multiplier"] = _num(ex, "cost_stress_multiplier", "execution", 1.0)
    execution["adverse_selection_sensitivity_bp_per_leg"] = _num(ex, "adverse_selection_sensitivity_bp_per_leg", "execution", 0.0) if "adverse_selection_sensitivity_bp_per_leg" in ex else None
    execution["half_spread_from_book"] = False
    if "half_spread_from_book" in ex:
        if source != "capture":
            raise SpecError("execution.half_spread_from_book: the history carries no book")
        execution["half_spread_from_book"] = _bool(ex, "half_spread_from_book", "execution")
    execution["sim"] = None
    if "sim" in ex:
        if source != "capture":
            raise SpecError("execution.sim: the simulation needs a captured book (source capture)")
        sim = _check_keys(ex["sim"], "execution.sim", ("rule", "latency_ms", "funding_fill_grace_min", "stale_s"))
        execution["sim"] = {"rule": _str(sim, "rule", "execution.sim", lp_sim.RULES), "latency_ms": _num(sim, "latency_ms", "execution.sim", 0.0),
                            "funding_fill_grace_min": _num(sim, "funding_fill_grace_min", "execution.sim", 0.0, 60.0), "stale_s": _num(sim, "stale_s", "execution.sim", 0.0, lo_open=True)}
        used.add("gate2_lp_sim_roles")
    primary_measure = _str(document, "primary_measure", "spec", ("net_bp", "r_bp", "sim_net_bp"))
    if primary_measure == "sim_net_bp" and execution["sim"] is None:
        raise SpecError("primary_measure sim_net_bp needs execution.sim (source capture)")
    ct = _check_keys(document["controls"], "controls", ("hours", "random_per_day", "exclusion_min", "seed", "minute_zero_only", "same_days_as_events"), ("exclusion_on",))
    controls = {"hours": _hours(_list(ct, "hours", "controls"), "controls.hours"), "random_per_day": _int(ct, "random_per_day", "controls", 0, 23),
                "exclusion_min": _int(ct, "exclusion_min", "controls", 0), "seed": _int(ct, "seed", "controls"), "minute_zero_only": _bool(ct, "minute_zero_only", "controls"),
                "same_days_as_events": _bool(ct, "same_days_as_events", "controls"), "exclusion_on": _str(ct, "exclusion_on", "controls", ("instant", "window")) if "exclusion_on" in ct else "instant"}
    if controls["minute_zero_only"] or controls["same_days_as_events"]:
        used.add("controls_same_days_minute_zero")
    if controls["exclusion_on"] == "window":
        used.add("controls_window_exclusion")
    q = _check_keys(document["quality"], "quality", ("max_missing_window_share", "max_proxy_missing_share", "max_fetch_error_share", "max_funding_unknown_share", "exclude_zero_volume"))
    quality = {key: _num(q, key, "quality", 0.0, 1.0) for key in ("max_missing_window_share", "max_proxy_missing_share", "max_fetch_error_share", "max_funding_unknown_share")}
    quality["exclude_zero_volume"] = _bool(q, "exclude_zero_volume", "quality")
    if quality["exclude_zero_volume"]:
        if source != "history":
            raise SpecError("quality.exclude_zero_volume: the per-minute volume exists on the history candles only")
        used.add("zero_volume_exclusion")
    eco = _check_keys(document["economic"], "economic", ("s_eco_usd_per_year", "max_fills_per_instant", "order_notional_usd"))
    economic = {"s_eco_usd_per_year": _num(eco, "s_eco_usd_per_year", "economic", 0.0), "max_fills_per_instant": _int(eco, "max_fills_per_instant", "economic", 1),
                "order_notional_usd": _num(eco, "order_notional_usd", "economic", 0.0, lo_open=True)}
    eligibility_days = None
    if "eligibility" in document:
        el = _check_keys(document["eligibility"], "eligibility", ("days_after_list",))
        eligibility_days = _num(el, "days_after_list", "eligibility", 0.0)
        used.add("eligibility_days_after_list")
    assumed_hours = None
    if "funding_outside_coverage" in document:
        if source != "history":
            raise SpecError("funding_outside_coverage: history only")
        fo = _check_keys(document["funding_outside_coverage"], "funding_outside_coverage", ("assumed_hours",))
        assumed_hours = _hours(_list(fo, "assumed_hours", "funding_outside_coverage"), "funding_outside_coverage.assumed_hours")
        used.add("coverage_restriction")
    programme_m_max = None
    if "programme" in document:
        pr = _check_keys(document["programme"], "programme", ("m_max",))
        programme_m_max = _int(pr, "m_max", "programme", 1)
        used.add("programme_family")
    items = _list(document, "events", "spec")
    if not items:
        raise SpecError("events must not be empty")
    events = tuple(_parse_event(item, i, source, execution["sim"] is not None, used) for i, item in enumerate(items))
    names = [e.type for e in events]
    if len(set(names)) != len(names):
        raise SpecError(f"duplicate event types: {sorted({n for n in names if names.count(n) > 1})}")
    if not any(e.primary and e.evaluate for e in events):
        raise SpecError("at least one evaluated event must be flagged primary (the Holm family)")
    requires = _list(document, "requires", "spec")
    if any(not isinstance(r, str) for r in requires) or len(set(requires)) != len(requires):
        raise SpecError("requires must be a list of distinct capability names")
    unknown = sorted(set(requires) - set(CAPABILITIES))
    if unknown:
        raise SpecError(f"requires {unknown}: not implemented by {EVALUATOR_VERSION} (implemented: {sorted(CAPABILITIES)})")
    undeclared = sorted(used - set(requires))
    if undeclared:
        raise SpecError(f"the spec uses {undeclared} without declaring it in requires (the version lock of (B) xii)")
    return Spec(spec_id, source, tuple(requires), price_source, start, end_exclusive, alpha, threshold_bp, min_instants, min_days, primary_measure, resamples, seed, block_days,
                execution, controls, quality, economic, eligibility_days, assumed_hours, programme_m_max, events, tuple(sorted(used)), raw=document)


def load_spec(path: Path) -> tuple[Spec, str]:
    text = Path(path).read_text()
    try:
        document = json.loads(text)
    except ValueError as error:
        raise SpecError(f"{path}: not JSON ({error})") from None
    return parse_spec(document), g5_fetch.sha256_text(text)


def guard_window(spec: Spec) -> dict:
    """Refuses a window touching the quarantine; a history window must end at or before END_EXCLUSIVE."""
    if spec.start_us < QUARANTINE_END_US and spec.end_us > QUARANTINE_START_US:
        raise SystemExit(f"quarantine: the window {spec.start}..{spec.end_exclusive} touches [{g5_fetch.END_EXCLUSIVE}, {xc.QUARANTINE_FLOOR}); nothing dated there is ever read")
    if spec.source == "history" and spec.end_us > g5_fetch.END_EXCLUSIVE_US:
        raise SystemExit(f"history window must end at or before END_EXCLUSIVE {g5_fetch.END_EXCLUSIVE}")
    if spec.source == "capture" and spec.start_us < QUARANTINE_END_US:
        raise SystemExit(f"a capture window must start at or after the quarantine floor {xc.QUARANTINE_FLOOR}")
    return {"end_exclusive": g5_fetch.END_EXCLUSIVE, "quarantine_floor": xc.QUARANTINE_FLOOR, "window": [spec.start, spec.end_exclusive], "source": spec.source,
            "read_from": tape.iso_utc(spec.pad_start_us)}


# ----------------------------------------------------------------------------- minute series


@dataclass
class Series:
    inst: str
    grid_us: np.ndarray  # minute boundaries g_k, k = 0..N (from the padded start to the window end)
    price: np.ndarray  # reference price at g_k: close of the candle ending at g_k (history), last value of the minute (capture)
    price_code: np.ndarray  # PRICE_CODE of the value at g_k, 0 when missing
    alt_price: np.ndarray  # the other price, descriptive
    volume: np.ndarray | None  # vol of the last-price candle ending at g_k (history)
    premium: dict  # premium field name → array at g_k
    range_bp: np.ndarray  # (high − low) / close of the reference candle ending at g_k, bp
    funding: lp_sim.FundingSchedule  # settlements with a known (realized / settled) rate
    settlements_all: np.ndarray  # every settlement instant read, rate known or not
    funding_unknown_ts: np.ndarray  # settlement instants whose rate is unknown (no realizedRate / not settled)
    assumed_unknown_ts: np.ndarray  # assumed settlement instants outside the funding coverage (and at / after END_EXCLUSIVE)
    coverage_start_us: dict  # history layout → start of D_cov (None: no coverage)
    eligible_from_us: int | None
    eligibility_known: bool
    window_start_us: int
    half_spread_bp: np.ndarray | None = None
    book: lp_sim.Book | None = None
    prints: lp_sim.Prints | None = None
    inst_spec: lp_sim.InstrumentSpec | None = None
    spec_row: dict | None = None
    coverage: dict = field(default_factory=dict)

    @property
    def size(self) -> int:
        return int(self.grid_us.size)

    def index_of(self, instants_us: np.ndarray) -> np.ndarray:
        return ((np.asarray(instants_us, dtype=np.int64) - int(self.grid_us[0])) // MINUTE_US).astype(np.int64)


def _grid(start_us: int, end_us: int) -> np.ndarray:
    return np.arange(start_us, end_us + MINUTE_US, MINUTE_US, dtype=np.int64)


def _dates_between(start_us: int, end_us: int) -> list[str]:
    first, last = start_us // DAY_US, (end_us - 1) // DAY_US
    return [tape.date_of_day(d) for d in range(int(first), int(last) + 1)]


def _place_closes(grid: np.ndarray, open_ts_us: np.ndarray, values: np.ndarray, target: np.ndarray) -> int:
    """`target[k] = value` for the candle opening at g_{k−1} (ending at g_k); returns how many rows fell outside the grid."""
    k = (open_ts_us - int(grid[0])) // MINUTE_US + 1
    inside = (k >= 0) & (k < grid.size) & ((open_ts_us - int(grid[0])) % MINUTE_US == 0)
    target[k[inside]] = values[inside]
    return int((~inside).sum())


def _drop_after_end(rows: np.ndarray) -> tuple[np.ndarray, int]:
    keep = rows["ts_us"] < g5_fetch.END_EXCLUSIVE_US
    return rows[keep], int((~keep).sum())


def stream_coverage(manifest: dict | None, key: str, layout: str, rows_ts: np.ndarray) -> dict:
    """D_cov start of one (instrument, stream) of a `g5_fetch` tape: the job's floor when it reached it, its `coverage_start_us`
    when the server stopped earlier, the oldest row stored when the job did not end; without a manifest state, the oldest row read."""
    oldest = int(np.min(rows_ts)) if np.asarray(rows_ts).size else None
    state = ((manifest or {}).get("g5_streams") or {}).get(f"{key}:{layout}")
    if state is None:
        return {"start_us": oldest, "source": "rows", "end_reason": None, "truncated": None}
    reason = state.get("end_reason")
    if state.get("complete") and reason == "reached_start":
        start = int(state["start_us"])
    elif state.get("coverage_start_us") is not None:
        start = int(state["coverage_start_us"])
    elif state.get("oldest_ts_us") is not None:
        start = int(state["oldest_ts_us"])
    else:
        start = None
    return {"start_us": start, "source": f"manifest:{reason}", "end_reason": reason, "truncated": state.get("truncated"), "complete": bool(state.get("complete"))}


def _eligibility(row: dict | None, spec: Spec) -> tuple[int | None, bool]:
    if spec.eligibility_days is None:
        return None, True
    listed = xc._i((row or {}).get("listTime"), 0)
    if listed <= 0:
        return None, False
    return listed * 1000 + int(round(spec.eligibility_days * DAY_US)), True


def _assumed_unknown(spec: Spec, funding_start_us: int | None) -> np.ndarray:
    if not spec.assumed_hours:
        return np.zeros(0, dtype=np.int64)
    first, last = spec.pad_start_us // DAY_US, (spec.end_us + DAY_US) // DAY_US
    moments = np.array([d * DAY_US + h * HOUR_US for d in range(int(first), int(last) + 1) for h in spec.assumed_hours], dtype=np.int64)
    unknown = moments >= g5_fetch.END_EXCLUSIVE_US
    unknown |= (moments < funding_start_us) if funding_start_us is not None else True
    return np.unique(moments[unknown])


def load_history_series(tape_dir: Path, inst: str, index_id: str, spec: Spec, manifest: dict | None = None, row: dict | None = None, allow_pending: bool = False) -> Series:
    grid = _grid(spec.pad_start_us, spec.end_us)
    dates = _dates_between(spec.pad_start_us, spec.end_us)
    cov: dict = {"rows_at_or_after_end_dropped": 0, "rows_outside_grid": 0, "unconfirmed_dropped": {}, "confirm_unknown": {}, "index_id": index_id, "minutes": int(grid.size)}

    def candles(layout: str, key: str) -> np.ndarray:
        rows, _ = tape.load_days(tape_dir, layout, key, dates, allow_pending)
        rows, dropped = _drop_after_end(rows)
        cov["rows_at_or_after_end_dropped"] += dropped
        unconfirmed = rows["confirm"] == 0 if rows.size else np.zeros(0, dtype=bool)
        cov["unconfirmed_dropped"][layout] = int(unconfirmed.sum())
        cov["confirm_unknown"][layout] = int((rows["confirm"] < 0).sum()) if rows.size else 0
        return rows[~unconfirmed] if rows.size else rows

    def closes(rows: np.ndarray, column: str = "close") -> np.ndarray:
        out = np.full(grid.size, np.nan)
        if rows.size:
            cov["rows_outside_grid"] += _place_closes(grid, rows["ts_us"].astype(np.int64), rows[column].astype(np.float64), out)
        return out

    last_rows, mark_rows, index_rows = candles("candles_1m", inst), candles("mark_1m", inst), candles("index_1m", index_id)
    last, mark, index_px = closes(last_rows), closes(mark_rows), closes(index_rows)
    volume = closes(last_rows, "vol")
    ref_rows = last_rows if spec.price_source == "last" else mark_rows
    range_bp = np.full(grid.size, np.nan)
    if ref_rows.size:
        with np.errstate(divide="ignore", invalid="ignore"):
            rng = ((ref_rows["high"] - ref_rows["low"]) / ref_rows["close"] * 1e4).astype(np.float64)
        _place_closes(grid, ref_rows["ts_us"].astype(np.int64), rng, range_bp)
    price, alt = (last, mark) if spec.price_source == "last" else (mark, last)
    code = np.where(np.isfinite(price), PRICE_CODE[spec.price_source], 0).astype(np.int8)
    with np.errstate(divide="ignore", invalid="ignore"):
        premium = (mark - index_px) / index_px
    cov.update({"price_source": spec.price_source, "price_minutes": int(np.isfinite(price).sum()), "alt_price_minutes": int(np.isfinite(alt).sum()),
                "premium_mark_index_minutes": int(np.isfinite(premium).sum()), "zero_volume_minutes": int((volume == 0).sum())})
    funding_dates = _dates_between(spec.pad_start_us, min(spec.end_us + DAY_US, g5_fetch.END_EXCLUSIVE_US))
    frows, _ = tape.load_days(tape_dir, "funding_hist", inst, funding_dates, allow_pending)
    frows, dropped = _drop_after_end(frows)
    cov["rows_at_or_after_end_dropped"] += dropped
    ts = frows["ts_us"].astype(np.int64) if frows.size else np.zeros(0, dtype=np.int64)
    realized = frows["realized_rate"].astype(np.float64) if frows.size else np.zeros(0)
    known = np.isfinite(realized)
    funding = lp_sim.FundingSchedule(ts[known], realized[known], source="funding_hist realizedRate (predicted fundingRate never used)")
    cov.update({"funding_rows": int(frows.size), "funding_rows_without_realized": int((~known).sum()), "funding_realized_share": float(known.mean()) if known.size else None})
    coverage = {"candles_1m": stream_coverage(manifest, inst, "candles_1m", last_rows["ts_us"] if last_rows.size else np.zeros(0)),
                "mark_1m": stream_coverage(manifest, inst, "mark_1m", mark_rows["ts_us"] if mark_rows.size else np.zeros(0)),
                "index_1m": stream_coverage(manifest, index_id, "index_1m", index_rows["ts_us"] if index_rows.size else np.zeros(0)),
                "funding_hist": stream_coverage(manifest, inst, "funding_hist", ts)}
    cov["streams"] = {k: {**v, "start": tape.iso_utc(v["start_us"]) if v["start_us"] is not None else None} for k, v in coverage.items()}
    starts = {k: v["start_us"] for k, v in coverage.items()}
    eligible_from, eligibility_known = _eligibility(row, spec)
    cov["eligible_from"] = tape.iso_utc(eligible_from) if eligible_from is not None else (None if eligibility_known else "UNKNOWN (no listTime): excluded")
    return Series(inst, grid, price, code, alt, volume, {"premium_mark_index": premium}, range_bp, funding, np.unique(ts), np.unique(ts[~known]),
                  _assumed_unknown(spec, starts["funding_hist"]), starts, eligible_from, eligibility_known, spec.start_us, spec_row=row, coverage=cov)


def load_capture_series(tape_dir: Path, inst: str, spec: Spec, row: dict | None = None, allow_pending: bool = False) -> Series:
    grid = _grid(spec.pad_start_us, spec.end_us)
    dates = _dates_between(spec.pad_start_us, spec.end_us)
    cov: dict = {"minutes": int(grid.size), "price_source": spec.price_source}

    def last_per_minute(ts_us: np.ndarray, values: np.ndarray, ffill_min: int = 0) -> np.ndarray:
        out = np.full(grid.size, np.nan)
        if ts_us.size == 0:
            return out
        order = np.argsort(ts_us, kind="stable")
        ts_us, values = ts_us[order], values[order]
        idx = np.searchsorted(ts_us, grid, side="right") - 1
        valid = idx >= 0
        age = np.where(valid, grid - ts_us[np.where(valid, idx, 0)], np.iinfo(np.int64).max)
        ok = valid & (age <= (ffill_min + 1) * MINUTE_US)
        out[ok] = values[idx[ok]]
        return out

    mark_rows, _ = tape.load_days(tape_dir, "mark", inst, dates, allow_pending)
    mark = last_per_minute(mark_rows["ts_us"].astype(np.int64), mark_rows["mark_px"].astype(np.float64)) if mark_rows.size else np.full(grid.size, np.nan)
    trade_rows, _ = tape.load_days(tape_dir, "trades", inst, dates, allow_pending)
    last = last_per_minute(trade_rows["ts_us"].astype(np.int64), trade_rows["price"].astype(np.float64)) if trade_rows.size else np.full(grid.size, np.nan)
    prints = lp_sim.Prints.from_rows(trade_rows) if trade_rows.size else None
    price, alt = (last, mark) if spec.price_source == "last" else (mark, last)
    code = np.where(np.isfinite(price), PRICE_CODE[spec.price_source], 0).astype(np.int8)
    funding_rows, _ = tape.load_days(tape_dir, "funding", inst, dates, allow_pending)
    premium = np.full(grid.size, np.nan)
    settled_ts, settled_rate, moments = [], [], set()
    if funding_rows.size:
        premium = last_per_minute(funding_rows["ts_us"].astype(np.int64), funding_rows["premium"].astype(np.float64), ffill_min=PREMIUM_FFILL_MIN)
        rates: dict[int, float] = {}
        for item in funding_rows:
            for column in ("funding_time_us", "next_funding_time_us"):
                if int(item[column]) > 0:
                    moments.add(int(item[column]))
            if int(item["sett_state"]) == 1 and math.isfinite(float(item["sett_funding_rate"])) and int(item["funding_time_us"]) > 0:
                rates[int(item["funding_time_us"])] = float(item["sett_funding_rate"])
        settled_ts, settled_rate = sorted(rates), [rates[m] for m in sorted(rates)]
    funding = lp_sim.FundingSchedule(np.array(settled_ts, dtype=np.int64), np.array(settled_rate, dtype=np.float64), source="capture settled rate (sett_state = settled)")
    all_ts = np.array(sorted(moments), dtype=np.int64)
    unknown = np.setdiff1d(all_ts, np.array(settled_ts, dtype=np.int64)) if all_ts.size else np.zeros(0, dtype=np.int64)
    book_rows, _ = tape.load_days(tape_dir, "books5", inst, dates, allow_pending)
    half_spread, book = None, None
    if book_rows.size:
        book = lp_sim.Book.from_rows(book_rows)
        with np.errstate(divide="ignore", invalid="ignore"):
            hs = (book.ask - book.bid) / (book.ask + book.bid) * 1e4
        half_spread = last_per_minute(book.ts, hs.astype(np.float64))
    cov.update({"price_minutes": int(np.isfinite(price).sum()), "alt_price_minutes": int(np.isfinite(alt).sum()), "premium_okx_minutes": int(np.isfinite(premium).sum()),
                "funding_rows": int(funding_rows.size), "settlements": int(all_ts.size), "settlements_unknown_rate": int(unknown.size), "book_rows": int(book_rows.size),
                "trade_rows": int(trade_rows.size)})
    inst_spec = None
    if row and row.get("tickSz") not in (None, "") and row.get("ctVal") not in (None, ""):
        inst_spec = lp_sim.InstrumentSpec.from_okx_row(row)
    eligible_from, eligibility_known = _eligibility(row, spec)
    return Series(inst, grid, price, code, alt, None, {"premium_okx": premium}, np.full(grid.size, np.nan), funding, all_ts, unknown, np.zeros(0, dtype=np.int64), {},
                  eligible_from, eligibility_known, spec.start_us, half_spread_bp=half_spread, book=book, prints=prints, inst_spec=inst_spec, spec_row=row, coverage=cov)


# ----------------------------------------------------------------------------- instants


def generate_instants(gen: dict, series: Series, spec: Spec) -> tuple[np.ndarray, str, dict]:
    """Minute-aligned instants (µs) of one generator inside the window, the source actually used, and its diagnostics."""
    kind = gen["kind"]
    start_us, end_us = spec.start_us, spec.end_us
    diag: dict = {}
    if kind == "funding":
        all_ts = np.unique(series.settlements_all)
        moments = all_ts[(all_ts >= start_us) & (all_ts < end_us)]
        diag["misaligned"] = int((moments % MINUTE_US != 0).sum())
        moments = moments[moments % MINUTE_US == 0]
        if moments.size == 0 and gen.get("fallback_hours"):
            gen = {"kind": "fixed_time", "minutes": tuple(h * 60 for h in gen["fallback_hours"]), "weekdays": tuple(range(7)), "exclude_days": (), "skip_days": ()}
            kind, source = "fixed_time", "fixed_hours_fallback"
        else:
            diag["settlements"] = int(moments.size)
            if "interval_hours" in gen and moments.size:
                pos = np.searchsorted(all_ts, moments)
                prev_gap = np.where(pos > 0, moments - all_ts[np.maximum(pos - 1, 0)], -1)
                next_gap = np.where(pos + 1 < all_ts.size, all_ts[np.minimum(pos + 1, all_ts.size - 1)] - moments, -1)
                target, tol = gen["interval_hours"] * HOUR_US, gen["interval_tolerance_min"] * MINUTE_US
                prev_ok = (prev_gap >= 0) & (np.abs(prev_gap - target) <= tol)
                next_ok = (next_gap >= 0) & (np.abs(next_gap - target) <= tol)
                other = ((prev_gap >= 0) & ~prev_ok) | ((next_gap >= 0) & ~next_ok)
                keep = prev_ok & next_ok
                diag.update({"interval_kept": int(keep.sum()), "interval_other": int(other.sum()), "interval_unknown": int((~keep & ~other).sum()),
                             "interval_rule": f"{gen['interval_hours']:g} h ± {gen['interval_tolerance_min']:g} min to the previous and the next fundingTime (the next one is read from the following row)"})
                moments = moments[keep]
            return moments, "instrument_settlements", diag
    else:
        source = kind
    if kind in ("fixed_time", "us_open"):
        # `exclude_dates`: no session that day (a holiday): neither an instant nor a day of the economic reading.
        # `skip_dates`: the session exists but the type does not trade it (CPI / Employment Situation for T05): no instant, but a
        # candidate day of the economic reading (its fills / day are per session day, the unit of `days_per_year`).
        weekdays = set(gen["weekdays"]) if kind == "fixed_time" else {0, 1, 2, 3, 4}
        excluded, skips = set(gen.get("exclude_days", ())), set(gen.get("skip_days", ()))
        items, skipped_items, n_excluded = [], [], 0
        for date in spec.dates:
            day = tape.day_of_date(date)
            calendar = datetime.strptime(date, "%Y-%m-%d").replace(tzinfo=timezone.utc)
            if calendar.weekday() not in weekdays:
                continue
            if day in excluded:
                n_excluded += 1
                continue
            minutes = gen["minutes"] if kind == "fixed_time" else (ev.us_open_utc_minutes(calendar.date()),)
            moments = [day * DAY_US + m * MINUTE_US for m in minutes]
            (skipped_items if day in skips else items).extend(moments)
        diag.update({"excluded_dates": n_excluded, "skipped_dates": len({t // DAY_US for t in skipped_items}), "skipped_instants": sorted(set(skipped_items))})
        return np.array(sorted(set(items)), dtype=np.int64), (source if kind == "fixed_time" else "us_open_dst_aware"), diag
    moments = (np.array(gen["instants_us"], dtype=np.int64) // MINUTE_US) * MINUTE_US
    moments = moments[(moments >= start_us) & (moments < end_us)]
    return np.unique(moments), "explicit_list", diag


def restrict(instants: np.ndarray, series: Series, espec: EventSpec, spec: Spec) -> tuple[np.ndarray, dict]:
    """Instants of a type that the eligibility rule and the type's coverage restriction admit (events and controls alike)."""
    instants = np.asarray(instants, dtype=np.int64)
    keep = np.ones(instants.size, dtype=bool)
    counts: dict = {"generated": int(instants.size)}
    if spec.eligibility_days is not None:
        if not series.eligibility_known:
            counts["eligibility_unknown"] = int(keep.sum())
            keep[:] = False
        else:
            early = keep & (instants < int(series.eligible_from_us))
            counts["before_eligibility"] = int(early.sum())
            keep &= ~early
    for layout in espec.restrict_to_coverage:
        start = series.coverage_start_us.get(layout)
        if start is None:
            counts[f"no_coverage_{layout}"] = int(keep.sum())
            keep[:] = False
        else:
            early = keep & (instants < int(start))
            counts[f"before_coverage_{layout}"] = int(early.sum())
            keep &= ~early
    counts["kept"] = int(keep.sum())
    return instants[keep], counts


def _near(union: np.ndarray, cands: np.ndarray, lo_us: int, hi_us: int, excl_us: int) -> np.ndarray:
    """True where an excluded instant lies within [c + lo − excl, c + hi + excl]."""
    if union.size == 0 or cands.size == 0:
        return np.zeros(cands.size, dtype=bool)
    left = np.searchsorted(union, cands + lo_us - excl_us, side="left")
    right = np.searchsorted(union, cands + hi_us + excl_us, side="right")
    return right > left


def control_instants(series: Series, spec: Spec, espec: EventSpec, type_index: int, union_us: np.ndarray, event_days=None) -> tuple[np.ndarray, dict]:
    """Controls of one type on one instrument: at minute `control_minute_of_hour` of the fixed hours and of `random_per_day`
    hours drawn per day (seed per type and day, the same for every instrument), on the type's event days when
    `same_days_as_events`, admitted by the type's eligibility / coverage restriction, and away from every excluded instant."""
    ct = spec.controls
    excl = int(ct["exclusion_min"]) * MINUTE_US
    union = np.unique(np.asarray(union_us, dtype=np.int64))
    lo, hi = (espec.lo_min * MINUTE_US, espec.to_min * MINUTE_US) if ct["exclusion_on"] == "window" else (0, 0)
    minute = int(espec.control_minute_of_hour) * MINUTE_US
    if ct["same_days_as_events"]:
        days = sorted({int(d) for d in np.asarray(event_days if event_days is not None else [], dtype=np.int64).tolist()})
    else:
        days = [tape.day_of_date(d) for d in spec.dates]
    first_day, end_day = spec.start_us // DAY_US, spec.end_us // DAY_US
    fixed_hours = set(ct["hours"])
    others = [h for h in range(24) if h not in fixed_hours]
    fixed, random = [], []
    diag = {"days": len(days), "near_excluded": 0, "restricted": 0}
    for day in days:
        if not first_day <= day < end_day:
            continue
        midnight = day * DAY_US
        rng = np.random.default_rng([int(ct["seed"]), int(type_index), int(day)])
        cands_fixed = np.array([midnight + h * HOUR_US + minute for h in ct["hours"]], dtype=np.int64)
        if ct["minute_zero_only"]:
            order = rng.permutation(len(others)) if others else np.zeros(0, dtype=np.int64)
            cands_random = np.array([midnight + others[i] * HOUR_US + minute for i in order], dtype=np.int64)
        else:  # any minute of the day (not the pre-registered rule; kept for descriptive specs)
            cands_random = midnight + rng.integers(0, tape.DAY_S // 60, size=4 * ct["random_per_day"] + 8) * MINUTE_US
        fixed_today: set[int] = set()
        for cands, out, limit in ((cands_fixed, fixed, None), (cands_random, random, ct["random_per_day"])):
            if cands.size == 0 or (limit is not None and limit <= 0):
                continue
            near = _near(union, cands, lo, hi, excl)
            diag["near_excluded"] += int(near.sum())
            cands = cands[~near]
            admitted, _ = restrict(cands, series, espec, spec)
            diag["restricted"] += int(cands.size - admitted.size)
            if limit is None:
                fixed_today = set(admitted.tolist())
            else:
                taken: list[int] = []
                for c in admitted.tolist():
                    if c not in fixed_today and c not in taken:
                        taken.append(c)
                    if len(taken) >= limit:
                        break
                admitted = np.array(taken, dtype=np.int64)
            out.extend(admitted.tolist())
    fixed_arr, random_arr = np.array(fixed, dtype=np.int64), np.array(random, dtype=np.int64)
    both = np.unique(np.concatenate([fixed_arr, random_arr]))
    diag.update({"fixed": int(fixed_arr.size), "random": int(random_arr.size), "hours": list(ct["hours"]), "random_per_day": ct["random_per_day"], "minute_of_hour": espec.control_minute_of_hour,
                 "exclusion_min": ct["exclusion_min"], "exclusion_on": ct["exclusion_on"], "seed": ct["seed"], "same_days_as_events": ct["same_days_as_events"]})
    return both, diag


# ----------------------------------------------------------------------------- measures


def _log_return_bp(series: Series, k_from: np.ndarray, k_to: np.ndarray, prices: np.ndarray | None = None) -> np.ndarray:
    prices = series.price if prices is None else prices
    n = series.size
    ok = (k_from >= 0) & (k_to >= 0) & (k_from < n) & (k_to < n)
    out = np.full(k_from.size, np.nan)
    a = prices[np.where(ok, k_from, 0)]
    b = prices[np.where(ok, k_to, 0)]
    with np.errstate(divide="ignore", invalid="ignore"):
        out[ok] = (np.log(b) - np.log(a))[ok] * 1e4
    return out


def _settlement_rate(series: Series, instants: np.ndarray) -> np.ndarray:
    """The known (realized / settled) rate of the settlement at each instant, NaN when there is none or its rate is unknown."""
    out = np.full(instants.size, np.nan)
    ts = series.funding.ts_us
    if ts.size == 0 or instants.size == 0:
        return out
    pos = np.searchsorted(ts, instants)
    hit = pos < ts.size
    hit[hit] = ts[pos[hit]] == instants[hit]
    out[hit] = series.funding.rate[pos[hit]]
    return out


def premium_wavg_at(series: Series, k: np.ndarray, block: dict) -> np.ndarray:
    """P̂ at boundaries k: Σ j·π(k − n + j) / Σ j over the present minutes of the n ending at k (weights 1…n), NaN when fewer
    than `min_coverage` × n minutes are present or the look-back leaves the grid."""
    base = series.premium.get(block["base"])
    out = np.full(np.asarray(k).size, np.nan)
    if base is None:
        return out
    n = int(block["minutes"])
    weights = np.arange(1, n + 1, dtype=np.float64)
    for i, kk in enumerate(np.asarray(k, dtype=np.int64).tolist()):
        lo = kk - n + 1
        if lo < 0 or kk >= base.size:
            continue
        window = base[lo: kk + 1]
        ok = np.isfinite(window)
        if ok.sum() < block["min_coverage"] * n:
            continue
        out[i] = math.fsum((weights[ok] * window[ok]).tolist()) / math.fsum(weights[ok].tolist())
    return out


def _classify(values: np.ndarray, high: float, low: float, eps: float = 0.0) -> np.ndarray:
    """+1 above high + eps, −1 below low − eps, 0 inside the dead zone or unknown."""
    out = np.zeros(values.size, dtype=np.int8)
    finite = np.isfinite(values)
    out[finite & (values > high + eps)] = 1
    out[finite & (values < low - eps)] = -1
    return out


def _condition(series: Series, k0: np.ndarray, instants: np.ndarray, cond: dict) -> tuple[np.ndarray, np.ndarray]:
    """`(passes, known)`: the condition at each instant and whether its field was available."""
    if cond["field"] == "price_return":
        values = _log_return_bp(series, k0 + cond["from_min"], k0 + cond["to_min"])
    elif cond["field"] == "funding_rate":
        values = _settlement_rate(series, instants)
    else:
        base = series.premium.get(cond["field"])
        k = k0 + cond["at_min"]
        values = np.full(k0.size, np.nan)
        if base is not None:
            ok = (k >= 0) & (k < base.size)
            values[ok] = base[k[ok]]
    known = np.isfinite(values)
    passes = np.zeros(k0.size, dtype=bool)
    if cond["op"] == "outside_dead_zone":
        passes[known] = (values[known] > cond["high"] + cond["eps"]) | (values[known] < cond["low"] - cond["eps"])
    else:
        passes[known] = CONDITION_OPS[cond["op"]](values[known], float(cond["value"]))
    return passes, known


def _sign(series: Series, k0: np.ndarray, instants: np.ndarray, sign: dict) -> tuple[np.ndarray, np.ndarray]:
    """`(sign, known)`: −1 / 0 / +1 per instant (0 = excluded) and whether the field it reads was available."""
    if sign["kind"] == "constant":
        return np.full(k0.size, int(sign["value"]), dtype=np.int8), np.ones(k0.size, dtype=bool)
    if sign["kind"] == "return_sign":
        values = _log_return_bp(series, k0 + sign["from_min"], k0 + sign["to_min"])
        return (np.sign(np.nan_to_num(values, nan=0.0)) * sign["multiplier"]).astype(np.int8), np.isfinite(values)
    if sign["kind"] == "premium_wavg":
        values = premium_wavg_at(series, k0 + sign["at_min"], sign)
        return (_classify(values, sign["upper"], sign["lower"]) * sign.get("multiplier", 1)).astype(np.int8), np.isfinite(values)
    if sign["field"] == "funding_rate":
        values = _settlement_rate(series, instants)
    else:
        base = series.premium.get(sign["field"])
        k = k0 + sign["at_min"]
        values = np.full(k0.size, np.nan)
        if base is not None:
            ok = (k >= 0) & (k < base.size)
            values[ok] = base[k[ok]]
    if sign.get("dead_zone") is not None:
        low, high = sign["dead_zone"]
        cls = _classify(values, high, low, sign["eps"])
    else:
        cls = np.sign(np.nan_to_num(values, nan=0.0)).astype(np.int8)
    return (cls * sign["multiplier"]).astype(np.int8), np.isfinite(values)


def cost_terms(espec: EventSpec, spec: Spec) -> dict:
    """Round-trip cost terms of the type's execution role (bp): fees at the role, adverse selection on each maker leg,
    slippage; `stress` multiplies every term; `sensitivity` uses the sensitivity value of the adverse selection."""
    ex = spec.execution
    if espec.execution_role == "maker_maker":
        fees, maker_legs = 2.0 * ex["maker_bp"], 2
    else:
        fees, maker_legs = ex["taker_bp"] + ex["maker_bp"], 1
    adverse = maker_legs * ex["adverse_selection_bp_per_leg"]
    total = math.fsum([fees, adverse, ex["slippage_bp"]])
    sens = ex["adverse_selection_sensitivity_bp_per_leg"]
    return {"role": espec.execution_role, "fees_bp": fees, "adverse_selection_bp": adverse, "maker_legs": maker_legs, "slippage_bp": ex["slippage_bp"], "total_bp": total,
            "stress_multiplier": ex["cost_stress_multiplier"], "stress_total_bp": total * ex["cost_stress_multiplier"],
            "sensitivity_total_bp": math.fsum([fees, maker_legs * sens, ex["slippage_bp"]]) if sens is not None else None}


def _crossed_count(moments: np.ndarray, open_us: np.ndarray, close_us: np.ndarray) -> np.ndarray:
    """Number of `moments` T with open < T ≤ close, per window."""
    if moments.size == 0:
        return np.zeros(np.asarray(open_us).size, dtype=np.int64)
    return np.searchsorted(moments, close_us, side="right") - np.searchsorted(moments, open_us, side="right")


def measure(series: Series, instants_us: np.ndarray, espec: EventSpec, spec: Spec, role: str = "event") -> dict:
    """Per-observation measures (arrays aligned on the kept instants) and the exclusion counts, each observation counted in
    the first rule that excludes it. At the controls of a type with a `control_sign`, the control sign replaces the funding
    sign and the funding condition (fields that only exist at settlements); every other rule is identical."""
    instants = np.asarray(instants_us, dtype=np.int64)
    counts = {key: 0 for key in ("instants", "misaligned", "out_of_range", "missing_price", "mixed_source", "zero_volume", "condition_unknown", "condition_failed",
                                 "sign_unknown", "zero_sign", "funding_unknown", "funding_unknown_end_edge", "kept", "funding_not_observed", "funding_end_edge_not_observed")}
    counts["instants"] = int(instants.size)
    aligned = instants % MINUTE_US == 0
    counts["misaligned"] = int((~aligned).sum())
    instants = instants[aligned]
    k0 = series.index_of(instants)
    n = series.size
    alive = (k0 + espec.lo_min >= 0) & (k0 + espec.to_min < n)
    counts["out_of_range"] = int((~alive).sum())
    offsets = espec.price_offsets
    present = np.ones(instants.size, dtype=bool)
    codes = []
    for off in offsets:
        k = np.clip(k0 + off, 0, n - 1)
        present &= np.isfinite(series.price[k]) & (series.price_code[k] > 0)
        codes.append(series.price_code[k])
    missing = alive & ~present
    counts["missing_price"] = int(missing.sum())
    alive &= present
    same = np.all(np.vstack(codes) == codes[0], axis=0) if codes else np.ones(instants.size, dtype=bool)
    counts["mixed_source"] = int((alive & ~same).sum())
    alive &= same
    if spec.quality["exclude_zero_volume"] and series.volume is not None:
        zero = np.zeros(instants.size, dtype=bool)
        for off in offsets:
            zero |= series.volume[np.clip(k0 + off, 0, n - 1)] == 0
        counts["zero_volume"] = int((alive & zero).sum())
        alive &= ~zero
    control_side = role == "control" and espec.control_sign is not None
    condition = espec.condition
    if condition is not None and not (control_side and condition["field"] == "funding_rate"):
        passes, known = _condition(series, k0, instants, condition)
        counts["condition_unknown"] = int((alive & ~known).sum())
        alive &= known
        counts["condition_failed"] = int((alive & ~passes).sum())
        alive &= passes
    sign, sign_known = _sign(series, k0, instants, espec.control_sign if control_side else espec.sign)
    counts["sign_unknown"] = int((alive & ~sign_known).sum())
    alive &= sign_known
    counts["zero_sign"] = int((alive & (sign == 0)).sum())
    alive &= sign != 0
    open_us = instants + espec.arm_min * MINUTE_US
    close_us = instants + espec.to_min * MINUTE_US
    unknown_rate = _crossed_count(series.funding_unknown_ts, open_us, close_us) > 0
    assumed = _crossed_count(series.assumed_unknown_ts, open_us, close_us) > 0
    at_end = close_us >= g5_fetch.END_EXCLUSIVE_US  # the settlement of exactly END_EXCLUSIVE is never known (m4)
    unknown = unknown_rate | assumed
    counts["funding_unknown"] = int((alive & unknown).sum())
    counts["funding_unknown_end_edge"] = int((alive & at_end & assumed).sum())
    alive &= ~unknown
    fund_start = series.coverage_start_us.get("funding_hist") if series.coverage_start_us else None
    if spec.source == "history" and not spec.assumed_hours:
        # Without assumed hours a window outside the funding coverage has F = 0 assumed, not observed: kept and counted.
        outside = (open_us < fund_start) if fund_start is not None else np.ones(instants.size, dtype=bool)
        counts["funding_not_observed"] = int((alive & outside).sum())
        counts["funding_end_edge_not_observed"] = int((alive & at_end).sum())
    keep = alive
    instants, k0, sign = instants[keep], k0[keep], sign[keep].astype(np.float64)
    counts["kept"] = int(instants.size)
    out: dict = {"instants_us": instants, "inst": series.inst, "sign": sign.astype(np.int8), "counts": counts}
    out["r_bp"] = _log_return_bp(series, k0 + espec.from_min, k0 + espec.to_min) * sign
    out["r_alt_bp"] = _log_return_bp(series, k0 + espec.from_min, k0 + espec.to_min, series.alt_price) * sign
    price_arm, price_to = series.price[np.clip(k0 + espec.arm_min, 0, n - 1)], series.price[np.clip(k0 + espec.to_min, 0, n - 1)]
    hs = spec.execution["half_spread_bp"] / 1e4
    if spec.execution["half_spread_from_book"] and series.half_spread_bp is not None:
        observed = series.half_spread_bp[np.clip(k0 + espec.arm_min, 0, n - 1)] / 1e4
        hs = np.where(np.isfinite(observed), observed, hs)
    entry = price_arm * (1.0 - sign * hs) if espec.execution_role == "maker_maker" else price_arm * (1.0 + sign * hs)
    exit_px = price_to * (1.0 + sign * hs)
    with np.errstate(divide="ignore", invalid="ignore"):
        rt = sign * (exit_px - entry) / entry * 1e4
    costs = cost_terms(espec, spec)
    funding_bp = np.zeros(instants.size)
    settlements = np.zeros(instants.size, dtype=np.int32)
    for i in range(instants.size):
        count, rate_sum = series.funding.crossed(int(instants[i]) + espec.arm_min * MINUTE_US, int(instants[i]) + espec.to_min * MINUTE_US)
        settlements[i] = count
        funding_bp[i] = -sign[i] * rate_sum * 1e4
    rt_list, f_list = rt.tolist(), funding_bp.tolist()
    out["rt_bp"] = rt
    out["funding_bp"] = funding_bp
    out["settlements"] = settlements
    out["net_bp"] = np.array([math.fsum([a, -costs["total_bp"], b]) for a, b in zip(rt_list, f_list)], dtype=np.float64)
    out["net_bp_stress"] = np.array([math.fsum([a, -costs["stress_total_bp"], b]) for a, b in zip(rt_list, f_list)], dtype=np.float64)
    sens = costs["sensitivity_total_bp"]
    out["net_bp_as_sensitivity"] = np.array([math.fsum([a, -sens, b]) for a, b in zip(rt_list, f_list)], dtype=np.float64) if sens is not None else np.full(instants.size, np.nan)
    out["funding_rate_at"] = _settlement_rate(series, instants)
    out["costs"] = costs
    out["execution"] = "approx_price_half_spread_from_book" if spec.execution["half_spread_from_book"] and series.half_spread_bp is not None else "approx_price_half_spread"
    if role == "event" and espec.control_sign is not None:
        values = premium_wavg_at(series, k0 + espec.control_sign["at_min"], espec.control_sign)
        out["proxy_value"] = values
        out["proxy_class"] = _classify(values, espec.control_sign["upper"], espec.control_sign["lower"])
    if spec.simulated:
        sim, status = simulate_clock_scalp(series, instants, sign.astype(np.int8), espec, spec)
        out.update(sim)
        out["sim_status"] = status
    return out


def simulate_clock_scalp(series: Series, instants: np.ndarray, signs: np.ndarray, espec: EventSpec, spec: Spec) -> tuple[dict, dict]:
    """(B) x — capture only, on the real book and prints (`lp_sim` primitives, rule from the spec): entry decided at h + arm_min,
    post-only at the own touch resting until h + rest_until_min (`maker_maker`) or taker at the opposite touch after the
    latency (`taker_maker`, plus slippage); passive exit at the opposite touch from h + to_min until h + exit_until_min,
    remainder taker; funding of the settlements in (first fill − grace, exit] (a fill within the grace after a settlement
    crosses it, "may take up to a minute"); an unfilled entry is no observation (counted). Returns NaN where not filled."""
    n_obs = int(np.asarray(instants).size)
    out = {"sim_net_bp": np.full(n_obs, np.nan), "sim_net_bp_stress": np.full(n_obs, np.nan), "sim_rt_bp": np.full(n_obs, np.nan), "sim_fee_bp": np.full(n_obs, np.nan),
           "sim_funding_bp": np.full(n_obs, np.nan)}
    status = {key: 0 for key in SIM_STATUS}
    book, prints, ispec = series.book, series.prints, series.inst_spec
    if book is None or prints is None or ispec is None or book.size == 0:
        status["unavailable"] = n_obs
        return out, status
    sim, ex = spec.execution["sim"], spec.execution
    latency = int(round(sim["latency_ms"] * 1000))
    stale = int(round(sim["stale_s"] * tape.SECOND_US))
    grace = int(round(sim["funding_fill_grace_min"] * MINUTE_US))
    tol = ispec.tick * 1e-6
    slip = ex["slippage_bp"]
    notional = spec.economic["order_notional_usd"]
    last_ts = int(book.ts[-1])
    for i in range(n_obs):
        h, s = int(instants[i]), int(signs[i])
        d = h + espec.arm_min * MINUTE_US
        a = d + latency
        k = book.index_at(d)
        if k < 0 or d - int(book.ts[k]) > stale:
            status["stale"] += 1
            continue
        bid, ask = float(book.bid[k]), float(book.ask[k])
        if not (math.isfinite(bid) and math.isfinite(ask) and 0 < bid < ask):
            status["stale"] += 1
            continue
        ka = book.index_at(a)
        if ka < 0:
            status["stale"] += 1
            continue
        if espec.execution_role == "maker_maker":
            price = round((bid if s > 0 else ask) / ispec.tick) * ispec.tick
            size = ispec.size_for_notional(notional, price)
            if size <= 0:
                status["skipped_size"] += 1
                continue
            if (s > 0 and price >= float(book.ask[ka]) - tol) or (s < 0 and price <= float(book.bid[ka]) + tol):
                status["crossed"] += 1
                continue
            first_fill, fill_ts, filled = lp_sim._fill(book, prints, s, price, size, a, h + espec.rest_until_min * MINUTE_US, book.displayed(s, price, ka, tol), sim["rule"], tol)
            if filled <= 0:
                status["unfilled"] += 1
                continue
            tau = fill_ts if fill_ts >= 0 else h + espec.rest_until_min * MINUTE_US
            entry_px, entry_fee, entry_slip = price, ex["maker_bp"], 0.0
        else:
            price = float(book.ask[ka]) if s > 0 else float(book.bid[ka])
            size = ispec.size_for_notional(notional, price)
            if size <= 0:
                status["skipped_size"] += 1
                continue
            first_fill, tau, filled = a, a, size
            entry_px, entry_fee, entry_slip = price, ex["taker_bp"], slip
        ex_end = min(h + int(espec.exit_until_min) * MINUTE_US, last_ts)
        ex_start = min(max(tau + latency, h + espec.to_min * MINUTE_US), ex_end)
        ke = book.index_at(ex_start)
        if ke < 0:
            status["no_exit_quote"] += 1
            continue
        ex_bid, ex_ask = float(book.bid[ke]), float(book.ask[ke])
        exit_price = round((ex_ask if s > 0 else ex_bid) / ispec.tick) * ispec.tick
        _, ex_fill_ts, ex_filled = lp_sim._fill(book, prints, -s, exit_price, filled, ex_start, ex_end, book.displayed(-s, exit_price, ke, tol), sim["rule"], tol)
        ex_filled = max(0.0, min(float(ex_filled), float(filled)))
        if ex_fill_ts >= 0:
            exit_ts, exit_px, exit_fee, exit_slip = int(ex_fill_ts), exit_price, ex["maker_bp"], 0.0
        else:
            kl = book.index_at(ex_end)
            taker_px = float(book.bid[kl]) if s > 0 else float(book.ask[kl])
            rest = float(filled) - ex_filled
            exit_px = (ex_filled * exit_price + rest * taker_px) / float(filled)
            exit_fee = (ex_filled * ex["maker_bp"] + rest * ex["taker_bp"]) / float(filled)
            exit_slip = slip * rest / float(filled)
            exit_ts = ex_end
        open_f, close_f = int(first_fill) - grace, int(exit_ts)
        if _crossed_count(series.funding_unknown_ts, np.array([open_f]), np.array([close_f]))[0] > 0:
            status["funding_unknown"] += 1
            continue
        _, rate_sum = series.funding.crossed(open_f, close_f)
        funding_bp = -s * rate_sum * 1e4
        rt = s * (exit_px - entry_px) / entry_px * 1e4
        fees, slip_cost = entry_fee + exit_fee, entry_slip + exit_slip
        out["sim_rt_bp"][i], out["sim_fee_bp"][i], out["sim_funding_bp"][i] = rt, fees, funding_bp
        out["sim_net_bp"][i] = math.fsum([rt, -fees, -slip_cost, funding_bp])
        out["sim_net_bp_stress"][i] = math.fsum([rt, -ex["cost_stress_multiplier"] * (fees + slip_cost), funding_bp])
        status["filled"] += 1
    return out, status


# ----------------------------------------------------------------------------- statistics


def day_block_bootstrap(ev_values, ev_days, ct_values=None, ct_days=None, resamples: int = 1000, seed: int = 20261102, block_days: int = 1) -> dict:
    """Mean of the events (minus the mean of the controls when given), resampling calendar-day blocks with replacement —
    events and controls of a block move together. Returns the point, LB90 / UB90 and the one-sided p = P*(D* ≤ 0)."""
    ev_values = np.asarray(ev_values, dtype=np.float64)
    ev_days = np.asarray(ev_days, dtype=np.int64) // max(1, int(block_days))
    keep = np.isfinite(ev_values)
    ev_values, ev_days = ev_values[keep], ev_days[keep]
    with_control = ct_values is not None and ct_days is not None
    if with_control:
        ct_values = np.asarray(ct_values, dtype=np.float64)
        ct_days = np.asarray(ct_days, dtype=np.int64) // max(1, int(block_days))
        keep = np.isfinite(ct_values)
        ct_values, ct_days = ct_values[keep], ct_days[keep]
    out = {"n": int(ev_values.size), "n_control": int(ct_values.size) if with_control else None, "point": None, "event_mean": None, "control_mean": None, "event_median": None,
           "lb90": None, "ub90": None, "p_one_sided": None, "blocks": 0, "resamples": int(resamples), "block_days": int(block_days)}
    if ev_values.size == 0 or (with_control and ct_values.size == 0):
        return out
    ev_mean = math.fsum(ev_values.tolist()) / ev_values.size
    out["event_mean"] = ev_mean
    out["event_median"] = float(np.median(ev_values))
    ct_mean = math.fsum(ct_values.tolist()) / ct_values.size if with_control else 0.0
    out["control_mean"] = ct_mean if with_control else None
    out["point"] = ev_mean - ct_mean
    blocks = np.unique(np.concatenate([ev_days, ct_days]) if with_control else ev_days)
    out["blocks"] = int(blocks.size)
    if blocks.size < 2:
        out["lb90"], out["ub90"], out["p_one_sided"] = out["point"], out["point"], (1.0 if out["point"] <= 0 else None)
        return out
    ev_idx = np.searchsorted(blocks, ev_days)
    ev_sum = np.bincount(ev_idx, weights=ev_values, minlength=blocks.size)
    ev_cnt = np.bincount(ev_idx, minlength=blocks.size).astype(np.float64)
    rng = np.random.default_rng(int(seed))
    draws = rng.integers(0, blocks.size, size=(int(resamples), blocks.size))
    picked = np.zeros((int(resamples), blocks.size), dtype=np.float64)  # how often each block is drawn in each resample
    rows = np.arange(int(resamples))
    for j in range(blocks.size):
        picked[rows, draws[:, j]] += 1.0  # one draw per resample and column: no repeated index within an assignment
    e_cnt = picked @ ev_cnt
    with np.errstate(divide="ignore", invalid="ignore"):
        stats = (picked @ ev_sum) / e_cnt
        if with_control:
            ct_idx = np.searchsorted(blocks, ct_days)
            ct_sum = np.bincount(ct_idx, weights=ct_values, minlength=blocks.size)
            ct_cnt = np.bincount(ct_idx, minlength=blocks.size).astype(np.float64)
            c_cnt = picked @ ct_cnt
            stats = stats - (picked @ ct_sum) / c_cnt
            stats[c_cnt <= 0] = np.nan
    stats[e_cnt <= 0] = np.nan
    stats = stats[np.isfinite(stats)]
    if stats.size == 0:
        return out
    out["lb90"] = float(np.quantile(stats, 0.10))
    out["ub90"] = float(np.quantile(stats, 0.90))
    out["p_one_sided"] = float(np.mean(stats <= 0.0))
    out["resamples_effective"] = int(stats.size)
    return out


def holm(pvalues: dict, alpha: float) -> dict:
    """Step-down Holm at level `alpha` (one-sided p-values); a missing p counts as 1 and never passes. Returns, per test,
    its rank, the threshold it faced and whether it is confirmed; the walk stops at the first failure."""
    items = sorted(((1.0 if p is None or not math.isfinite(p) else float(p)), name) for name, p in pvalues.items())
    m = len(items)
    out: dict = {"alpha": alpha, "m": m, "tests": {}, "confirmed": []}
    failed = False
    for j, (p, name) in enumerate(items, start=1):
        threshold = alpha / (m - j + 1)
        passed = (not failed) and p <= threshold
        if not passed:
            failed = True
        out["tests"][name] = {"p": p, "rank": j, "threshold": threshold, "confirmed": passed}
        if passed:
            out["confirmed"].append(name)
    return out


def instant_means(parts: list[dict], measure_name: str) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """(B) v — `(instants, values, instruments)`: per unique instant, the fsum mean of the measure over the instruments present."""
    ts = [np.asarray(p["instants_us"], dtype=np.int64) for p in parts if p["instants_us"].size]
    vals = [np.asarray(p[measure_name], dtype=np.float64) for p in parts if p["instants_us"].size]
    if not ts:
        return np.zeros(0, dtype=np.int64), np.zeros(0), np.zeros(0, dtype=np.int64)
    ts_all, v_all = np.concatenate(ts), np.concatenate(vals)
    ok = np.isfinite(v_all)
    ts_all, v_all = ts_all[ok], v_all[ok]
    if ts_all.size == 0:
        return np.zeros(0, dtype=np.int64), np.zeros(0), np.zeros(0, dtype=np.int64)
    order = np.argsort(ts_all, kind="stable")
    ts_all, v_all = ts_all[order], v_all[order]
    uniq, first, counts = np.unique(ts_all, return_index=True, return_counts=True)
    values = np.array([math.fsum(v_all[f: f + c].tolist()) / c for f, c in zip(first.tolist(), counts.tolist())], dtype=np.float64)
    return uniq, values, counts.astype(np.int64)


def _stat(ev_ts, ev_v, ct_ts=None, ct_v=None, spec: Spec | None = None) -> dict:
    if ct_ts is None:
        return day_block_bootstrap(ev_v, ev_ts // DAY_US, None, None, spec.resamples, spec.seed, spec.block_days)
    return day_block_bootstrap(ev_v, ev_ts // DAY_US, ct_v, ct_ts // DAY_US, spec.resamples, spec.seed, spec.block_days)


def criteria_for(spec: Spec, espec: EventSpec, inst_level: dict, kappa: dict | None) -> tuple[dict, dict]:
    """C1–C6 of `LP-G5` §7.5 at the instant level. `inst_level[m] = (ev_ts, ev_v, ct_ts, ct_v)`."""
    ev_ts, ev_v, ct_ts, ct_v = inst_level[spec.primary_measure]
    diff = _stat(ev_ts, ev_v, ct_ts, ct_v, spec)
    point, lb90 = diff.get("point"), diff.get("lb90")
    c1 = point is not None and lb90 is not None and point >= spec.threshold_bp and lb90 > 0
    net_mean = _fmean(inst_level[spec.net_measure][1])
    stress_mean = _fmean(inst_level[spec.stress_measure][1])
    halves = {}
    c4 = False
    if ev_ts.size:
        days = ev_ts // DAY_US
        cut = (int(days.min()) + int(days.max())) // 2
        for label, sel, c_sel in (("first_half", days <= cut, (ct_ts // DAY_US) <= cut), ("second_half", days > cut, (ct_ts // DAY_US) > cut)):
            e_mean, c_mean = _fmean(ev_v[sel]), _fmean(ct_v[c_sel])
            halves[label] = {"n": int(sel.sum()), "n_control": int(c_sel.sum()), "point": (e_mean - c_mean) if e_mean is not None and c_mean is not None else None}
        c4 = all(h["point"] is not None and h["point"] > 0 for h in halves.values()) and len(halves) == 2
        halves["cut_day"] = tape.date_of_day(cut)
    trimmed = None
    c5 = False
    if ev_v.size:
        drop = int(math.ceil(0.05 * ev_v.size))
        order = np.argsort(ev_v, kind="stable")
        keep = np.sort(order[: ev_v.size - drop])
        trimmed = _stat(ev_ts[keep], ev_v[keep], ct_ts, ct_v, spec)
        trimmed["dropped_best"] = drop
        c5 = trimmed.get("lb90") is not None and trimmed["lb90"] > 0
    out = {"C1": {"pass": bool(c1), "point": point, "lb90": lb90, "ub90": diff.get("ub90"), "threshold_bp": spec.threshold_bp, "p_one_sided": diff.get("p_one_sided")},
           "C2": {"pass": net_mean is not None and net_mean > 0, "event_net_mean": net_mean, "measure": spec.net_measure},
           "C3": {"pass": stress_mean is not None and stress_mean > 0, "event_net_stress_mean": stress_mean, "measure": spec.stress_measure, "multiplier": spec.execution["cost_stress_multiplier"]},
           "C4": {"pass": bool(c4), "halves": halves},
           "C5": {"pass": bool(c5), "lb90_without_best_5pct": trimmed.get("lb90") if trimmed else None, "dropped_best": trimmed.get("dropped_best") if trimmed else 0}}
    if espec.control_sign is not None:
        value = None if kappa is None else kappa.get("kappa")
        out["C6"] = {"pass": value is not None and value >= espec.control_sign["concordance_min"], "kappa": value, "concordance_min": espec.control_sign["concordance_min"],
                     "on": espec.control_sign["concordance_on"]}
    else:
        out["C6"] = {"pass": True, "applies": False}
    return out, diff


def verdict_for(spec: Spec, n_instants: int, n_days: int, diff: dict, criteria: dict, device: list[str]) -> tuple[str, list[str], str | None]:
    """INCONCLUSIVE (device defect) → NOT_INFORMATIVE (power gate) → PASS (C1–C5, C6 being a device test) → FAIL."""
    if device:
        return "INCONCLUSIVE", device, None
    reasons = []
    if n_instants < spec.min_instants:
        reasons.append(f"n={n_instants} < min_instants={spec.min_instants}")
    if n_days < spec.min_days:
        reasons.append(f"days={n_days} < min_days={spec.min_days}")
    if diff.get("point") is None or diff.get("lb90") is None:
        reasons.append("no statistic")
    if reasons:
        return "NOT_INFORMATIVE", reasons, None
    failed = [name for name in ("C1", "C2", "C3", "C4", "C5") if not criteria[name]["pass"]]
    if not failed:
        return "PASS", [f"C1 point={diff['point']:.2f} >= {spec.threshold_bp:g} bp and lb90={diff['lb90']:.2f} > 0", "C2-C5 pass"], None
    ub90 = diff.get("ub90")
    annotation = "effet exclu" if ub90 is not None and ub90 < spec.threshold_bp else "non détecté"
    return "FAIL", [f"{name} fails" for name in failed], annotation


def economic_reading(parts: list[dict], measure_name: str, espec: EventSpec, spec: Spec, candidate_days: int, verdict: str) -> dict:
    """(B) ix — portfolio reading (`GATES` §1, §4): at each event instant at most `max_fills_per_instant` fills, admitted in
    instrument-id order; plafond_USD_an = fills/day × notional × net / 1e4 × days_per_year (point, LB90, UB90 of the
    admitted fills' net, day-block bootstrap); « poursuivi » ⇔ point ≥ S_éco and (UB90 ≥ 2 S_éco or LB90 > 0)."""
    eco = spec.economic
    by_instant: dict[int, list[tuple[str, float]]] = {}
    for p in parts:
        for t, v in zip(np.asarray(p["instants_us"]).tolist(), np.asarray(p[measure_name], dtype=np.float64).tolist()):
            if math.isfinite(v):
                by_instant.setdefault(int(t), []).append((str(p["inst"]), float(v)))
    fills_ts, fills_v = [], []
    for t in sorted(by_instant):
        for _, v in sorted(by_instant[t])[: eco["max_fills_per_instant"]]:
            fills_ts.append(t)
            fills_v.append(v)
    out = {"measure": measure_name, "max_fills_per_instant": eco["max_fills_per_instant"], "order_notional_usd": eco["order_notional_usd"], "days_per_year": espec.days_per_year,
           "candidate_days": int(candidate_days), "s_eco_usd_per_year": eco["s_eco_usd_per_year"], "fills": len(fills_v), "fills_uncapped": sum(len(v) for v in by_instant.values())}
    if not fills_v or candidate_days <= 0:
        out.update({"fills_per_day": 0.0, "usd_per_year_point": None, "usd_per_year_lb90": None, "usd_per_year_ub90": None, "pursued": False,
                    "reading": "FAIL" if verdict == "FAIL" else verdict})
        return out
    stat = day_block_bootstrap(np.array(fills_v), np.array(fills_ts, dtype=np.int64) // DAY_US, None, None, spec.resamples, spec.seed, spec.block_days)
    per_day = len(fills_v) / candidate_days

    def usd(value):
        return None if value is None else per_day * eco["order_notional_usd"] * float(value) / 1e4 * espec.days_per_year

    point, lb90, ub90 = usd(stat["event_mean"]), usd(stat["lb90"]), usd(stat["ub90"])
    s_eco = eco["s_eco_usd_per_year"]
    pursued = point is not None and point >= s_eco and ((ub90 is not None and ub90 >= 2 * s_eco) or (lb90 is not None and lb90 > 0))
    if verdict == "PASS":
        reading = "PASS, poursuivi" if pursued else "PASS, non poursuivi (sous S_éco)"
    else:
        reading = "FAIL" if verdict == "FAIL" else verdict
    out.update({"fills_per_day": per_day, "net_bp_mean": stat["event_mean"], "net_bp_lb90": stat["lb90"], "net_bp_ub90": stat["ub90"], "usd_per_year_point": point,
                "usd_per_year_lb90": lb90, "usd_per_year_ub90": ub90, "pursued": bool(pursued), "reading": reading,
                "note": "lecture portefeuille : 1×, aucun impact de marché supposé ; net = aller-retour au rôle déclaré, coûts du spec, funding réel"})
    return out


# ----------------------------------------------------------------------------- descriptive reports


def descriptive_reports(series_by_inst: dict, window_start_us: int | None = None) -> dict:
    """Minute-0 effect, hourly and weekday seasonality of the 1-minute |return| of the reference price and of the candle range,
    spread seasonality when a book exists, funding intervals. Pooled over instruments, window part only; no verdict."""
    abs_ret, minute_of_hour, hour_of_day, weekday, ranges, spreads, spread_hours = [], [], [], [], [], [], []
    funding_intervals: dict = {}
    for inst, s in series_by_inst.items():
        start = int(window_start_us if window_start_us is not None else s.window_start_us)
        k_first = max(0, int((start - int(s.grid_us[0])) // MINUTE_US))
        grid, price = s.grid_us[k_first:], s.price[k_first:]
        if grid.size > 1:
            with np.errstate(divide="ignore", invalid="ignore"):
                r = np.abs(np.diff(np.log(price))) * 1e4
            ends = grid[1:]
            ok = np.isfinite(r)
            abs_ret.append(r[ok])
            minute_of_hour.append(((ends[ok] // MINUTE_US) % 60).astype(np.int64))
            hour_of_day.append(((ends[ok] // tape.SECOND_US) % tape.DAY_S // 3600).astype(np.int64))
            weekday.append(((ends[ok] // DAY_US + 3) % 7).astype(np.int64))  # 1970-01-01 was a Thursday: Monday = 0
            ranges.append(s.range_bp[k_first:][1:][ok])
        if s.half_spread_bp is not None:
            hs = s.half_spread_bp[k_first:]
            ok = np.isfinite(hs)
            spreads.append(hs[ok])
            spread_hours.append(((grid[ok] // tape.SECOND_US) % tape.DAY_S // 3600).astype(np.int64))
        settled = s.settlements_all[s.settlements_all >= start]
        if settled.size > 1:
            diffs_h = np.diff(settled) / HOUR_US
            values, counts = np.unique(np.round(diffs_h, 2), return_counts=True)
            rates = s.funding.rate[s.funding.ts_us >= start]
            funding_intervals[inst] = {"settlements": int(settled.size), "interval_h_histogram": {f"{v:g}": int(c) for v, c in zip(values.tolist(), counts.tolist())},
                                       "rate_known": int(rates.size), "rate_mean": _fmean(rates), "rate_abs_mean": _fmean(np.abs(rates)), "source": s.funding.source}
    out: dict = {"note": "descriptif, aucun verdict ; ces tables larges sont un crible de fait pour les types du Gate 2 : GATES §9.7 interdit de les promouvoir en test",
                 "funding_intervals": funding_intervals}
    if not abs_ret:
        return out
    r, moh, hod, wd, rg = (np.concatenate(x) for x in (abs_ret, minute_of_hour, hour_of_day, weekday, ranges))

    def by(keys: np.ndarray, size: int, labels) -> dict:
        table = {}
        for k in range(size):
            sel = keys == k
            if not sel.any():
                continue
            entry = {"n": int(sel.sum()), "abs_return_bp_mean": _fmean(r[sel])}
            if np.isfinite(rg[sel]).any():
                entry["range_bp_mean"] = _fmean(rg[sel])
            table[str(labels(k))] = entry
        return table

    minute0, others = r[moh == 1], r[moh != 1]  # the minute starting at hh:00 ends at hh:01
    m0, mo = _fmean(minute0), _fmean(others)
    out["minute_zero"] = {"abs_return_bp_mean_minute_0": m0, "abs_return_bp_mean_other_minutes": mo, "ratio": (m0 / mo) if m0 is not None and mo else None,
                          "n_minute_0": int(minute0.size), "by_minute_of_hour": by(moh, 60, lambda k: f"{(k - 1) % 60:02d}")}
    out["hourly_seasonality"] = {"overall_abs_return_bp_mean": _fmean(r), "by_hour_utc": by(hod, 24, lambda k: f"{k:02d}")}
    out["weekday_seasonality"] = {"by_weekday": by(wd, 7, lambda k: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"][k])}
    if spreads:
        sp, sh = np.concatenate(spreads), np.concatenate(spread_hours)
        out["spread_seasonality"] = {"half_spread_bp_mean": _fmean(sp), "by_hour_utc": {f"{h:02d}": {"n": int((sh == h).sum()), "half_spread_bp_mean": _fmean(sp[sh == h])}
                                                                                       for h in range(24) if (sh == h).any()}}
    else:
        out["spread_seasonality"] = "UNAVAILABLE: the 1-minute history carries no book; measured on the capture only"
    return out


# ----------------------------------------------------------------------------- the study


def _month_of(day: int) -> str:
    return tape.date_of_day(int(day))[:7]


def _proxy_share_missing(series: Series, block: dict, event_days: np.ndarray) -> tuple[int, int]:
    """Instrument-days (days with ≥ 1 event) whose base premium covers fewer than `min_coverage` of the day's minutes."""
    base = series.premium.get(block["base"])
    days = np.unique(np.asarray(event_days, dtype=np.int64))
    if base is None or days.size == 0:
        return int(days.size), int(days.size)
    k_day = (series.grid_us[1:] - MINUTE_US) // DAY_US  # the day of the candle ending at g_k
    first = int(k_day[0])
    idx = (k_day - first).astype(np.int64)
    present = np.bincount(idx, weights=np.isfinite(base[1:]).astype(np.float64))
    total = np.bincount(idx).astype(np.float64)
    pos = days - first
    inside = (pos >= 0) & (pos < total.size)
    share = np.zeros(days.size)
    share[inside] = present[pos[inside]] / np.maximum(total[pos[inside]], 1.0)
    return int((share < block["min_coverage"]).sum()), int(days.size)


def _kappa(event_parts: list[dict], block: dict) -> dict | None:
    signs = np.concatenate([p["sign"] for p in event_parts if "proxy_class" in p and p["sign"].size]) if event_parts else np.zeros(0)
    if signs.size == 0:
        return None
    values = np.concatenate([p["proxy_value"] for p in event_parts if "proxy_class" in p and p["sign"].size])
    classes = np.concatenate([p["proxy_class"] for p in event_parts if "proxy_class" in p and p["sign"].size])
    computable = np.isfinite(values)
    if block["concordance_on"] == "class":
        agree = (classes == signs) & computable
    else:
        agree = (np.sign(np.nan_to_num(values, nan=0.0)).astype(np.int8) == signs) & computable
    return {"kappa": float(agree.sum() / signs.size), "observations": int(signs.size), "proxy_not_computable": int((~computable).sum()), "on": block["concordance_on"],
            "unit": "instrument-instant (the proxy classifies one instrument)", "note": "a non-computable proxy counts as a disagreement"}


def _proxy_table(series_by_inst: dict, restricted: dict, espec: EventSpec) -> dict:
    """Descriptive 3 × 3 table at the settlements of the type: class of the realized rate (dead zone of the sign) × class of P̂."""
    block = espec.control_sign
    if block is None or espec.sign.get("field") != "funding_rate":
        return {}
    low, high = espec.sign["dead_zone"]
    table = {f"{r}|{p}": 0 for r in (-1, 0, 1) for p in (-1, 0, 1)}
    unknown = 0
    for inst, s in series_by_inst.items():
        instants = restricted.get(inst, np.zeros(0, dtype=np.int64))
        if instants.size == 0:
            continue
        rate = _settlement_rate(s, instants)
        r_cls = _classify(rate, high, low, espec.sign["eps"])
        values = premium_wavg_at(s, s.index_of(instants) + block["at_min"], block)
        p_cls = _classify(values, block["upper"], block["lower"])
        for rc, pc, rv, pv in zip(r_cls.tolist(), p_cls.tolist(), rate.tolist(), values.tolist()):
            if not (math.isfinite(rv) and math.isfinite(pv)):
                unknown += 1
                continue
            table[f"{rc}|{pc}"] += 1
    outside_r = sum(v for k, v in table.items() if not k.startswith("0|"))
    inside_r = sum(v for k, v in table.items() if k.startswith("0|"))
    sens = sum(v for k, v in table.items() if not k.startswith("0|") and not k.endswith("|0")) / outside_r if outside_r else None
    false_pos = sum(v for k, v in table.items() if k.startswith("0|") and not k.endswith("|0")) / inside_r if inside_r else None
    return {"realized_class|proxy_class": table, "unknown": unknown, "sensitivity_outside": sens, "false_positive_inside_dead_zone": false_pos,
            "note": "descriptif (relecture quant B1) : part des règlements hors zone morte que le proxy classe hors zone, et part des règlements en zone morte qu'il classe hors zone"}


def fetch_quality(manifest: dict | None, spec: Spec) -> dict:
    """§4.5 — share of (instrument, stream) jobs of the fetch manifest that ended in error (`errors`, `malformed_page`, `error`,
    `max_requests_reached`, or not complete)."""
    if spec.source != "history":
        return {"jobs": 0, "error_share": 0.0, "note": "capture tape: no fetch manifest"}
    states = ((manifest or {}).get("g5_streams") or {})
    if not states:
        return {"jobs": 0, "error_share": 0.0, "note": "no g5_streams in the manifest (not fetched by g5_fetch, or synthetic)"}
    bad = sorted(key for key, s in states.items() if s.get("errors") or s.get("end_reason") in ("malformed_page", "error", "max_requests_reached") or not s.get("complete"))
    reasons: dict[str, int] = {}
    for s in states.values():
        reasons[str(s.get("end_reason"))] = reasons.get(str(s.get("end_reason")), 0) + 1
    return {"jobs": len(states), "jobs_in_error": len(bad), "error_share": len(bad) / len(states), "jobs_in_error_keys": bad[:50], "end_reasons": reasons,
            "truncated": sorted(k for k, s in states.items() if s.get("truncated"))}


def exclusion_union(series: Series, raw_by_type: dict, extra=()) -> np.ndarray:
    """(B) iv — the instants every control keeps away from: every settlement of the instrument (all intervals, rate known or
    not, floored to the minute), the assumed settlement hours outside the funding coverage, every instant of every type
    (including the `evaluate: false` filters) and `extra` (the clock instants of the `skip_dates` sessions: still an open)."""
    parts = [(np.asarray(series.settlements_all, dtype=np.int64) // MINUTE_US) * MINUTE_US, np.asarray(series.assumed_unknown_ts, dtype=np.int64),
             *[np.asarray(v, dtype=np.int64) for v in raw_by_type.values()], np.asarray(list(extra), dtype=np.int64)]
    return np.unique(np.concatenate(parts))


def run_study(series_by_inst: dict, spec: Spec, fetch: dict | None = None) -> dict:
    """Every evaluated type on every instrument: instants, restrictions, controls, measures, instant-level statistics,
    criteria, verdicts, economic reading, Holm."""
    fetch = fetch or {"jobs": 0, "error_share": 0.0}
    measures = spec.measures
    raw: dict[str, dict[str, np.ndarray]] = {}
    gen_diag: dict[str, dict[str, dict]] = {}
    union: dict[str, np.ndarray] = {}
    for inst, s in series_by_inst.items():
        raw[inst], gen_diag[inst] = {}, {}
        for espec in spec.events:
            instants, source, diag = generate_instants(espec.generator, s, spec)
            raw[inst][espec.type] = instants
            gen_diag[inst][espec.type] = {"source": source, **diag}
        union[inst] = exclusion_union(s, raw[inst], [t for d in gen_diag[inst].values() for t in d.get("skipped_instants", [])])
    global_device = []
    if fetch.get("error_share", 0.0) > spec.quality["max_fetch_error_share"]:
        global_device.append(f"fetch: {fetch.get('jobs_in_error')} of {fetch.get('jobs')} (instrument, stream) jobs in error > max_fetch_error_share={spec.quality['max_fetch_error_share']:g} (§4.5)")
    types_out: dict[str, dict] = {}
    pvalues: dict[str, float | None] = {}
    for t_index, espec in enumerate(spec.events):
        if not espec.evaluate:
            types_out[espec.type] = {"evaluate": False, "description": espec.description, "generator": espec.generator, "role": "exclusion union of the controls only",
                                     "instants": {inst: int(raw[inst][espec.type].size) for inst in raw}}
            continue
        ev_parts, ct_parts, by_inst = [], [], {}
        restricted_all: dict[str, np.ndarray] = {}
        totals = {"event": {}, "control": {}, "restriction": {}, "sim_event": {}, "sim_control": {}}
        excluded_instruments = {"missing_window": [], "no_proxy": []}
        proxy_missing, proxy_days = 0, 0
        candidate_days: set[int] = set()
        costs = cost_terms(espec, spec)
        for inst, s in series_by_inst.items():
            events, rcounts = restrict(raw[inst][espec.type], s, espec, spec)
            for key, value in rcounts.items():
                totals["restriction"][key] = totals["restriction"].get(key, 0) + int(value)
            restricted_all[inst] = events
            entry = {"generator": {k: v for k, v in gen_diag[inst][espec.type].items() if k != "skipped_instants"}, "restriction": rcounts}
            # §4.5: an instrument missing more than the declared share of its event-window minutes leaves the type.
            k0 = s.index_of(events)
            offsets = np.arange(min(espec.lo_min, espec.arm_min), espec.to_min + 1)
            if events.size:
                ks = (k0[:, None] + offsets[None, :]).ravel()
                inside = (ks >= 0) & (ks < s.size)
                missing_share = float((~np.isfinite(s.price[ks[inside]])).mean()) if inside.any() else 1.0
            else:
                missing_share = 0.0
            entry["missing_window_share"] = missing_share
            if events.size and missing_share > spec.quality["max_missing_window_share"]:
                excluded_instruments["missing_window"].append(inst)
                entry["excluded"] = f"missing_window_share={missing_share:.3f} > {spec.quality['max_missing_window_share']:g}"
                by_inst[inst] = entry
                continue
            block = espec.wavg
            if block is not None:
                miss, days = _proxy_share_missing(s, block, events // DAY_US)
                proxy_missing += miss
                proxy_days += days
                if events.size and not np.isfinite(s.premium.get(block["base"], np.zeros(0))).any():
                    excluded_instruments["no_proxy"].append(inst)
                    entry["excluded"] = "no proxy: the base premium is absent (mark or index missing)"
                    by_inst[inst] = entry
                    continue
            candidate_days |= set((events // DAY_US).tolist())
            skipped = np.array(gen_diag[inst][espec.type].get("skipped_instants", []), dtype=np.int64)
            if skipped.size:
                candidate_days |= set((restrict(skipped, s, espec, spec)[0] // DAY_US).tolist())
            controls, cdiag = control_instants(s, spec, espec, t_index, union[inst], events // DAY_US)
            e = measure(s, events, espec, spec, "event")
            c = measure(s, controls, espec, spec, "control")
            ev_parts.append(e)
            ct_parts.append(c)
            for side, m in (("event", e), ("control", c)):
                for key, value in m["counts"].items():
                    totals[side][key] = totals[side].get(key, 0) + int(value)
                for key, value in m.get("sim_status", {}).items():
                    totals[f"sim_{side}"][key] = totals[f"sim_{side}"].get(key, 0) + int(value)
            entry.update({"n_observations": int(e["counts"]["kept"]), "n_control": int(c["counts"]["kept"]), "controls": cdiag, "exclusions_event": e["counts"],
                          "net_bp_mean": _fmean(e["net_bp"]), "control_net_bp_mean": _fmean(c["net_bp"])})
            by_inst[inst] = entry
        inst_level = {}
        stats = {}
        for m in measures:
            ev_ts, ev_v, ev_n = instant_means(ev_parts, m)
            ct_ts, ct_v, _ = instant_means(ct_parts, m)
            inst_level[m] = (ev_ts, ev_v, ct_ts, ct_v)
            stats[m] = {"event": _stat(ev_ts, ev_v, spec=spec), "diff_vs_control": _stat(ev_ts, ev_v, ct_ts, ct_v, spec), "n_instants": int(ev_ts.size), "n_control_instants": int(ct_ts.size)}
        p_ts, p_v, p_n = instant_means(ev_parts, spec.primary_measure)
        n_instants, n_days = int(p_ts.size), int(np.unique(p_ts // DAY_US).size)
        kappa = _kappa(ev_parts, espec.control_sign) if espec.control_sign is not None else None
        criteria, diff = criteria_for(spec, espec, inst_level, kappa)
        device = list(global_device)
        if espec.wavg is not None:
            share = proxy_missing / proxy_days if proxy_days else 1.0
            if share > spec.quality["max_proxy_missing_share"]:
                device.append(f"proxy: {proxy_missing} of {proxy_days} instrument-days without the premium proxy > max_proxy_missing_share={spec.quality['max_proxy_missing_share']:g} (§4.5)")
        unknown, kept = totals["event"].get("funding_unknown", 0), totals["event"].get("kept", 0)
        funding_unknown_share = unknown / (unknown + kept) if (unknown + kept) else 0.0
        if funding_unknown_share > spec.quality["max_funding_unknown_share"]:
            device.append(f"funding: {unknown} event windows cross a settlement of unknown rate ({funding_unknown_share:.3f} > max_funding_unknown_share={spec.quality['max_funding_unknown_share']:g})")
        if espec.control_sign is not None and n_instants and not criteria["C6"]["pass"]:
            device.append(f"C6: κ={criteria['C6']['kappa']} < concordance_min={espec.control_sign['concordance_min']:g} (the proxy does not follow the contractual premium)")
        verdict, reasons, annotation = verdict_for(spec, n_instants, n_days, diff, criteria, device)
        if espec.primary:
            pvalues[espec.type] = diff.get("p_one_sided") if verdict in ("PASS", "FAIL") else None
        gate = "G5_GATE1_" if spec.source == "history" else "G5_GATE2_"
        economic = economic_reading(ev_parts, spec.net_measure, espec, spec, len(candidate_days), verdict)
        months = {}
        ev_ts, ev_v, ct_ts, ct_v = inst_level[spec.primary_measure]
        for month in sorted({_month_of(d) for d in (ev_ts // DAY_US).tolist()}):
            sel = np.array([_month_of(d) == month for d in (ev_ts // DAY_US).tolist()])
            c_sel = np.array([_month_of(d) == month for d in (ct_ts // DAY_US).tolist()], dtype=bool) if ct_ts.size else np.zeros(0, dtype=bool)
            e_mean, c_mean = _fmean(ev_v[sel]), _fmean(ct_v[c_sel]) if c_sel.size else None
            months[month] = {"n": int(sel.sum()), "n_control": int(c_sel.sum()), "event_mean": e_mean, "control_mean": c_mean,
                             "point": (e_mean - c_mean) if e_mean is not None and c_mean is not None else None}
        signs = np.concatenate([p["sign"] for p in ev_parts]) if ev_parts else np.zeros(0, dtype=np.int8)
        pooled = {m: np.concatenate([p[m] for p in ev_parts]) if ev_parts else np.zeros(0) for m in ("r_bp", "net_bp")}
        rate_at = np.concatenate([p["funding_rate_at"] for p in ev_parts]) if ev_parts else np.zeros(0)
        by_sign = {label: {"observations": int(sel.sum()), "r_bp_mean": _fmean(pooled["r_bp"][sel]), "net_bp_mean": _fmean(pooled["net_bp"][sel])}
                   for label, sel in (("long", signs > 0), ("short", signs < 0)) if sel.any()}
        by_funding_sign = {label: {"observations": int(sel.sum()), "r_bp_mean": _fmean(pooled["r_bp"][sel]), "net_bp_mean": _fmean(pooled["net_bp"][sel])}
                           for label, sel in (("rate_positive", rate_at > 0), ("rate_negative", rate_at < 0), ("rate_unknown_or_none", ~np.isfinite(rate_at))) if sel.any()}
        funding_bp = np.concatenate([p["funding_bp"] for p in ev_parts]) if ev_parts else np.zeros(0)
        settle = np.concatenate([p["settlements"] for p in ev_parts]) if ev_parts else np.zeros(0)
        sim_block = None
        if spec.simulated:
            sim_block = {"status_event": totals["sim_event"], "status_control": totals["sim_control"], "rule": spec.execution["sim"]["rule"], "role": espec.execution_role,
                         "funding_fill_grace_min": spec.execution["sim"]["funding_fill_grace_min"], "exit_until_min": espec.exit_until_min}
        types_out[espec.type] = {
            "evaluate": True, "primary": espec.primary, "description": espec.description, "generator": espec.generator, "condition": espec.condition, "sign": espec.sign,
            "control_sign": espec.control_sign, "window_min": [espec.from_min, espec.to_min], "arm_min": espec.arm_min, "rest_until_min": espec.rest_until_min,
            "exit_until_min": espec.exit_until_min, "execution_role": espec.execution_role, "costs_bp": costs, "days_per_year": espec.days_per_year,
            "observed_min": espec.observed_min, "declared_lookahead": list(espec.declared_lookahead), "restrict_to_coverage": list(espec.restrict_to_coverage),
            "control_minute_of_hour": espec.control_minute_of_hour, "execution": sorted({p["execution"] for p in ev_parts}) if ev_parts else [],
            "n_instants": n_instants, "n_days": n_days, "n_observations": int(totals["event"].get("kept", 0)), "n_control_instants": int(inst_level[spec.primary_measure][2].size),
            "instruments_per_instant": {"min": int(p_n.min()) if p_n.size else None, "median": float(np.median(p_n)) if p_n.size else None, "max": int(p_n.max()) if p_n.size else None,
                                        "share_below_5": float(np.mean(p_n < 5)) if p_n.size else None},
            "exclusions": {"event": totals["event"], "control": totals["control"], "restriction": totals["restriction"], "instruments": excluded_instruments,
                           "funding_unknown_share": funding_unknown_share, "proxy_missing_instrument_days": [proxy_missing, proxy_days] if espec.wavg is not None else None},
            "settlements_crossed_share": float(np.mean(settle > 0)) if settle.size else None, "funding_bp_mean": _fmean(funding_bp),
            "statistics_bp": stats, "primary_measure": spec.primary_measure, "primary_statistic": diff, "criteria": criteria, "kappa": kappa,
            "proxy_table": _proxy_table(series_by_inst, restricted_all, espec), "verdict": verdict, "verdict_label": gate + verdict, "verdict_reasons": reasons,
            "fail_annotation": annotation, "reading": economic["reading"], "economic": economic, "lp_sim": sim_block,
            "robustness": {"by_month": months, "by_sign": by_sign, "by_funding_sign": by_funding_sign,
                           "alt_price": {"measure": "r_alt_bp", "price": "mark" if spec.price_source == "last" else "last", "diff": stats["r_alt_bp"]["diff_vs_control"]},
                           "as_sensitivity": stats["net_bp_as_sensitivity"]["event"], "note": "descriptif : aucune p-value ni verdict"},
            "by_instrument": by_inst,
        }
    family = holm(pvalues, spec.alpha)
    for name, result in types_out.items():
        if not result.get("evaluate"):
            continue
        test = family["tests"].get(name)
        result["holm"] = test
        result["holm_local_confirmed"] = bool(test and test["confirmed"] and result["verdict"] == "PASS")
        p = result["primary_statistic"].get("p_one_sided")
        result["programme_immediate"] = (bool(result["verdict"] == "PASS" and p is not None and p <= spec.alpha / spec.programme_m_max) if spec.programme_m_max else None)
    evaluated = {n: r for n, r in types_out.items() if r.get("evaluate")}
    summary = {"types": len(evaluated), "primary_types": sum(1 for e in spec.evaluated if e.primary), "pass_local": [n for n, r in evaluated.items() if r["verdict"] == "PASS"],
               "holm_local_confirmed": [n for n, r in evaluated.items() if r["holm_local_confirmed"]],
               "programme_immediate": [n for n, r in evaluated.items() if r.get("programme_immediate")] if spec.programme_m_max else None,
               "fail": [n for n, r in evaluated.items() if r["verdict"] == "FAIL"], "not_informative": [n for n, r in evaluated.items() if r["verdict"] == "NOT_INFORMATIVE"],
               "inconclusive": [n for n, r in evaluated.items() if r["verdict"] == "INCONCLUSIVE"], "readings": {n: r["reading"] for n, r in evaluated.items()},
               "global_device_defects": global_device}
    return {"types": types_out, "holm": family, "summary": summary, "fetch_quality": fetch}


# ----------------------------------------------------------------------------- reports


def _fmt(value, digits: int = 2, signed: bool = True) -> str:
    if value is None:
        return "—"
    return format(float(value), f"+.{digits}f" if signed else f".{digits}f")


def render_markdown(document: dict) -> str:
    spec = document["spec"]
    lines = [f"# G5 — étude d'événements `{spec['spec_id']}` ({document['source']})", "",
             "> **Rapports descriptifs larges** (minute de l'heure, heure, jour, mois, instrument) : sur une lecture unique, ils forment un crible de fait "
             "pour les types du Gate 2. `GATES` §9.7 interdit de promouvoir l'un d'eux en test, de baisser un seuil ou d'allonger une fenêtre après cette lecture.", "",
             f"Généré le {document['generated_at']} par `{document['evaluator']}` ; spec sha256 `{spec['sha256']}` ; digest du code `{document.get('code_digest')}` ; "
             f"fenêtre {spec['window'][0]} → {spec['window'][1]} (exclu) ; prix de référence `{spec['price_source']}` ; instruments : {len(document['instruments'])} ; "
             f"mesure primaire `{spec['primary_measure']}` ; seuil {spec['threshold_bp']:g} pb ; α = {spec['alpha']:g} (Holm sur les types primaires évalués) ; "
             f"unité : l'instant ; capacités : {', '.join(spec['requires'])}.", "",
             "Aucun paramètre n'a été recherché : signes, fenêtres, conditions, contrôles, coûts et seuils viennent de la spécification gelée.", "",
             "## Verdicts par type", "",
             "| type | primaire | n instants | jours | n contrôles | D (pb) | LB90 | UB90 | p | C1 | C2 | C3 | C4 | C5 | C6 | verdict | lecture | Holm |",
             "|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|"]

    def mark(c: dict) -> str:
        if c.get("applies") is False:
            return "n/a"
        return "oui" if c.get("pass") else "non"

    for name, r in document["types"].items():
        if not r.get("evaluate"):
            continue
        p, c = r["primary_statistic"], r["criteria"]
        holm_text = "confirmé" if r["holm_local_confirmed"] else ("—" if not r.get("holm") else "non")
        lines.append(f"| `{name}` | {'oui' if r['primary'] else 'non'} | {r['n_instants']} | {r['n_days']} | {r['n_control_instants']} | {_fmt(p['point'])} | {_fmt(p['lb90'])} | "
                     f"{_fmt(p['ub90'])} | {_fmt(p['p_one_sided'], 3, signed=False)} | {mark(c['C1'])} | {mark(c['C2'])} | {mark(c['C3'])} | {mark(c['C4'])} | {mark(c['C5'])} | "
                     f"{mark(c['C6'])} | **{r['verdict_label']}**{(' (' + r['fail_annotation'] + ')') if r['fail_annotation'] else ''} | {r['reading']} | {holm_text} |")
    summary = document["summary"]
    lines += ["", f"PASS local : {summary['pass_local'] or 'aucun'} ; confirmés par Holm dans ce protocole : {summary['holm_local_confirmed'] or 'aucun'} ; "
              f"FAIL : {summary['fail'] or 'aucun'} ; NOT_INFORMATIVE : {summary['not_informative'] or 'aucun'} ; INCONCLUSIVE : {summary['inconclusive'] or 'aucun'}."]
    if summary.get("programme_immediate") is not None:
        lines.append(f"`PASS_PROGRAMME` immédiat (p ≤ α / m_max, `GATES` §2.3) : {summary['programme_immediate'] or 'aucun'}.")
    lines += ["", "La confirmation Holm locale n'est pas `PASS_PROGRAMME` : ce statut se lit au niveau de la famille du programme (`GATES` §2.3).", ""]
    for name, r in document["types"].items():
        if not r.get("evaluate"):
            lines += [f"## `{name}` (filtre, non évalué)", "", f"{r.get('description') or ''}".strip(), "", f"- Instants par instrument : {json.dumps(r['instants'])}.", ""]
            continue
        lines += [f"## `{name}`", "", f"{r['description'] or ''}".strip(), "",
                  f"- Fenêtre [h{r['window_min'][0]:+d} ; h{r['window_min'][1]:+d}] min, armement h{r['arm_min']:+d}, repos jusqu'à h{r['rest_until_min']:+d} ; rôle `{r['execution_role']}` ; "
                  f"coûts {json.dumps(r['costs_bp'])} ; jours/an {r['days_per_year']:g}.",
                  f"- Signe : `{json.dumps(r['sign'], default=_json_default)}` ; condition : `{json.dumps(r['condition'], default=_json_default)}` ; signe de contrôle : "
                  f"`{json.dumps(r['control_sign'], default=_json_default)}` ; générateur : `{json.dumps(r['generator'], default=_json_default)}`.",
                  f"- Minutes d'observation : {json.dumps(r['observed_min'])} ; regard en avant déclaré : {json.dumps(r['declared_lookahead'], ensure_ascii=False) if r['declared_lookahead'] else 'aucun'}.",
                  f"- Couverture imposée : {r['restrict_to_coverage'] or 'aucune'} ; minute des contrôles : {r['control_minute_of_hour']:02d}.",
                  f"- Verdict **{r['verdict_label']}** : {' ; '.join(r['verdict_reasons'])}{(' — FAIL ' + r['fail_annotation']) if r['fail_annotation'] else ''}. Lecture : **{r['reading']}**.",
                  f"- Critères : {json.dumps(r['criteria'], default=_json_default, ensure_ascii=False)}.",
                  f"- κ : {json.dumps(r['kappa'], default=_json_default)} ; table proxy : {json.dumps(r['proxy_table'], default=_json_default, ensure_ascii=False)}.",
                  f"- Instruments par instant : {json.dumps(r['instruments_per_instant'])} ; observations (instrument-instants) : {r['n_observations']}.",
                  f"- Exclusions : {json.dumps(r['exclusions'], default=_json_default)}.",
                  f"- Règlements traversés : part {r['settlements_crossed_share']} ; funding moyen {r['funding_bp_mean']} pb.", ""]
        lines += ["| mesure | n instants | événement (moy.) | contrôle (moy.) | D | LB90 | UB90 | p |", "|---|---|---|---|---|---|---|---|"]
        for m, st in r["statistics_bp"].items():
            d = st["diff_vs_control"]
            lines.append(f"| `{m}` | {st['n_instants']} | {_fmt(d['event_mean'])} | {_fmt(d['control_mean'])} | {_fmt(d['point'])} | {_fmt(d['lb90'])} | {_fmt(d['ub90'])} | "
                         f"{_fmt(d['p_one_sided'], 3, signed=False)} |")
        lines += ["", f"Lecture économique : {json.dumps(r['economic'], default=_json_default, ensure_ascii=False)}", "",
                  f"Descriptif (sans p-value ni verdict) : {json.dumps(r['robustness'], default=_json_default, ensure_ascii=False)}", ""]
        if r.get("lp_sim"):
            lines += [f"Simulation (Gate 2) : {json.dumps(r['lp_sim'], default=_json_default)}", ""]
    desc = document.get("descriptive", {})
    lines += ["## Rapports non décisifs", "", f"_{desc.get('note', '')}_", ""]
    if "minute_zero" in desc:
        mz = desc["minute_zero"]
        lines.append(f"- Minute 0 : |r| moyen {mz['abs_return_bp_mean_minute_0']} pb contre {mz['abs_return_bp_mean_other_minutes']} pb les autres minutes (ratio {mz['ratio']}, n = {mz['n_minute_0']}).")
    if "hourly_seasonality" in desc:
        lines.append(f"- Saisonnalité horaire (|r| 1 min, pb) : {json.dumps({h: v['abs_return_bp_mean'] for h, v in desc['hourly_seasonality']['by_hour_utc'].items()})}.")
    if "weekday_seasonality" in desc:
        lines.append(f"- Jour de semaine (|r| 1 min, pb) : {json.dumps({d: v['abs_return_bp_mean'] for d, v in desc['weekday_seasonality']['by_weekday'].items()})}.")
    lines.append(f"- Spread : {json.dumps(desc.get('spread_seasonality'), default=_json_default)[:600]}.")
    lines.append(f"- Intervalles de funding : {json.dumps(desc.get('funding_intervals'), default=_json_default)[:1200]}.")
    lines += ["", "## Couverture et qualité", "", f"Fetch : {json.dumps(document.get('fetch_quality'), default=_json_default)[:1500]}.", "",
              f"Instantané des métadonnées : {json.dumps(document.get('instruments_snapshot'), default=_json_default, ensure_ascii=False)}.", "",
              "| instrument | minutes | prix | autre prix | prime | funding rows | sans taux réalisé | non confirmées rejetées | lignes ≥ END rejetées | début D_cov funding |",
              "|---|---|---|---|---|---|---|---|---|---|"]
    for inst, cov in document["coverage"].items():
        premium = cov.get("premium_mark_index_minutes", cov.get("premium_okx_minutes"))
        streams = cov.get("streams", {})
        fund = (streams.get("funding_hist") or {}).get("start")
        lines.append(f"| `{inst}` | {cov.get('minutes')} | {cov.get('price_minutes')} | {cov.get('alt_price_minutes')} | {premium} | {cov.get('funding_rows')} | "
                     f"{cov.get('funding_rows_without_realized', cov.get('settlements_unknown_rate'))} | {json.dumps(cov.get('unconfirmed_dropped', {}))} | "
                     f"{cov.get('rows_at_or_after_end_dropped', 0)} | {fund} |")
    lines += ["", f"Garde : {json.dumps(document['guard'])}.", ""]
    return "\n".join(lines)


# ----------------------------------------------------------------------------- command line


def add_parser(sub) -> None:
    parser = sub.add_parser("g5-eval", help="G5 event study driven by a frozen JSON spec (history layouts or capture tape); JSON + Markdown reports", allow_abbrev=False)
    parser.add_argument("--tape", required=True)
    parser.add_argument("--spec", required=True, help="frozen specification (types, signs, windows, conditions, controls, costs, thresholds); strict g5_eval/2 format")
    parser.add_argument("--instruments-file", default="", help="contract metadata (default <tape>/instruments-futures.json)")
    parser.add_argument("--inst", default="", help="comma-separated instrument ids (default: every instrument of the tape)")
    parser.add_argument("--expect-spec-sha256", default="", help="refuse to run when the spec file's sha256 differs")
    parser.add_argument("--expect-code-digest", default="", help="refuse to run when cli.code_digest() (sha256 of the research_lp modules) differs")
    parser.add_argument("--allow-partial", action="store_true", help="read pending segments of unfinalized days")
    parser.add_argument("--out-json", default="")
    parser.add_argument("--out-md", default="")
    parser.set_defaults(func=command_eval)


def _instrument_rows(args: argparse.Namespace) -> tuple[dict, str | None, dict | None]:
    path = Path(args.instruments_file) if args.instruments_file else Path(args.tape) / "instruments-futures.json"
    if not path.exists():
        return {}, None, None
    document = json.loads(path.read_text())
    items = document.get("instruments", document.get("data", [])) if isinstance(document, dict) else document
    snapshot = document.get("snapshot") if isinstance(document, dict) else None
    return {str(row["instId"]): row for row in items if isinstance(row, dict) and row.get("instId")}, str(path), snapshot


def command_eval(args: argparse.Namespace) -> int:
    from . import cli  # lazy: cli imports this module

    started = time.time()
    try:
        spec, spec_sha = load_spec(Path(args.spec))
    except SpecError as error:
        raise SystemExit(f"spec error: {error}") from None
    if args.expect_spec_sha256 and args.expect_spec_sha256.lower() != spec_sha:
        raise SystemExit(f"spec sha256 mismatch: file {spec_sha}, expected {args.expect_spec_sha256}")
    code_digest = cli.code_digest()
    if args.expect_code_digest and args.expect_code_digest.lower() != code_digest:
        raise SystemExit(f"code digest mismatch: {code_digest}, expected {args.expect_code_digest} (the frozen code is not the one running; nothing is read)")
    guard = guard_window(spec)
    manifest = tape.read_manifest(Path(args.tape))
    if manifest is not None and manifest.get("mode") == "counting":
        raise SystemExit(f"{args.tape}: a counting tape holds no value")
    if spec.source == "history" and (manifest is None or manifest.get("kind") != g5_fetch.MANIFEST_KIND):
        raise SystemExit(f"{args.tape}: not a {g5_fetch.MANIFEST_KIND} tape (source history reads the g5_fetch layouts only)")
    if spec.source == "capture" and manifest is not None and manifest.get("kind") == g5_fetch.MANIFEST_KIND:
        raise SystemExit(f"{args.tape}: a 1-minute history is not a capture tape")
    rows, rows_path, snapshot = _instrument_rows(args)
    price_layout = ("candles_1m" if spec.price_source == "last" else "mark_1m") if spec.source == "history" else ("trades" if spec.price_source == "last" else "mark")
    instruments = [s for s in args.inst.split(",") if s] or tape.instruments_in(Path(args.tape), price_layout)
    if not instruments:
        raise SystemExit(f"no instrument under {args.tape}/{price_layout}")
    series_by_inst: dict[str, Series] = {}
    for inst in instruments:
        row = rows.get(inst) or None
        if spec.source == "history":
            index_id = str((row or {}).get("indexId") or "") or g5_fetch.index_id_of({"instId": inst, "uly": (row or {}).get("uly")})
            series = load_history_series(Path(args.tape), inst, index_id, spec, manifest=manifest, row=row, allow_pending=args.allow_partial)
        else:
            series = load_capture_series(Path(args.tape), inst, spec, row=row, allow_pending=args.allow_partial)
        if series.coverage.get("price_minutes", 0) == 0:
            series.coverage["skipped"] = "no reference price"
        series_by_inst[inst] = series
    usable = {inst: s for inst, s in series_by_inst.items() if s.coverage.get("price_minutes", 0) > 0}
    fetch = fetch_quality(manifest, spec)
    if usable:
        study = run_study(usable, spec, fetch)
    else:
        study = {"types": {}, "holm": holm({}, spec.alpha), "fetch_quality": fetch,
                 "summary": {"types": 0, "primary_types": 0, "pass_local": [], "holm_local_confirmed": [], "programme_immediate": None, "fail": [], "not_informative": [],
                             "inconclusive": [], "readings": {}, "global_device_defects": []}}
    document = {"kind": "lp-g5-eval", "evaluator": EVALUATOR_VERSION, "generated_at": tape.iso_now(), "source": spec.source, "tape": str(args.tape), "guard": guard,
                "spec": {"path": str(args.spec), "sha256": spec_sha, "spec_id": spec.spec_id, "window": [spec.start, spec.end_exclusive], "alpha": spec.alpha, "threshold_bp": spec.threshold_bp,
                         "min_instants": spec.min_instants, "min_days": spec.min_days, "primary_measure": spec.primary_measure, "price_source": spec.price_source,
                         "requires": list(spec.requires), "capabilities_used": list(spec.used), "document": spec.raw},
                "instruments": list(series_by_inst), "instruments_file": rows_path, "instruments_snapshot": snapshot,
                "coverage": {inst: s.coverage for inst, s in series_by_inst.items()}, **study, "descriptive": descriptive_reports(usable, spec.start_us),
                "code": cli.code_identity(), "code_digest": code_digest, "elapsed_seconds": round(time.time() - started, 1)}
    if args.out_json:
        path = Path(args.out_json)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(document, indent=1, sort_keys=True, default=_json_default) + "\n")
    if args.out_md:
        path = Path(args.out_md)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(render_markdown(json.loads(json.dumps(document, default=_json_default))))
    print(json.dumps({"spec_id": spec.spec_id, "spec_sha256": spec_sha, "code_digest": code_digest, "instruments": len(usable), "summary": document["summary"],
                      "elapsed_seconds": document["elapsed_seconds"]}, default=_json_default))
    return 0
