"""Outcome module (§5-§8): reads the event file only after checking its sha256, measures every outcome and evaluates
Gate 1.

Prices: the entry is the first 1-minute close in [t + 5 min, t + 20 min]; the exit the first close in [T, T + 60 min]
with T = t + 5 min + h. A coin's own exit falls back to its last close before T when there is none (delisting or gap,
counted as `truncated`). The market return over [t_e, T] is the equal-weight mean, over the other members of the
decision month M(t), of their log return between the same two bounded closes (no fallback), with at least
MIN_MARKET_MEMBERS members; for the BTC sensitivity it is BTC's return. When the coin's exit is truncated at a close
time T' < T, the market (and BTC) is measured to T' instead (exit = first close in [T', T' + 60 min]), so the residual
compares the same interval. β is the one frozen at t.
"""

from __future__ import annotations

import hashlib
import json
import math
from collections import defaultdict
from collections.abc import Callable, Mapping
from pathlib import Path

import numpy as np

from . import data as dt
from . import protocol as pr
from . import stats as st

OUTCOMES_FILE = "h2-outcomes.json"
RESULT_FILE = "h2-gate1-result.json"


class OrderError(SystemExit):
    pass


def read_events(path: Path, expected_sha256: str) -> dict:
    """The event file, refused unless its sha256 equals `expected_sha256` and the hash written beside it."""
    path = Path(path)
    if not expected_sha256:
        raise OrderError("outcomes: no event-file hash given — the event module must run first")
    blob = path.read_bytes()
    actual = hashlib.sha256(blob).hexdigest()
    beside = Path(str(path) + ".sha256")
    if not beside.exists():
        raise OrderError("outcomes: the event file has no .sha256 beside it")
    written = beside.read_text().split()[0]
    if actual != expected_sha256 or written != expected_sha256:
        raise OrderError(f"outcomes: event file sha256 {actual} differs from the expected {expected_sha256} (beside: {written})")
    return json.loads(blob)


def _sign(e: float) -> float:
    return 1.0 if e > 0 else -1.0


def _queries(document: Mapping) -> tuple[dict[str, set], dict[str, set], dict[str, list[str]]]:
    """Own-price decision times per instrument, market decision times per month, members per month."""
    own: dict[str, set] = defaultdict(set)
    market: dict[str, set] = defaultdict(set)
    members = {m: list(v) for m, v in document["universe"]["months"].items()}
    for record in document["events"]:
        own[record["inst"]].add(record["decision_ms"])
        market[record["month"]].add(record["decision_ms"])
        for bar in record["c1"]["bars"]:
            own[record["inst"]].add(int(dt.bar_close_ms(bar)))
        if record["c2"] is not None:
            own[record["c2"]["inst"]].add(record["c2"]["decision_ms"])
            market[record["c2"]["month"]].add(record["c2"]["decision_ms"])
    for name, rows in document["sensitivities"].items():
        for record in rows or []:
            own[record["inst"]].add(record["decision_ms"])
            if name == "btc_market":
                own[pr.BTC].add(record["decision_ms"])
            else:
                market[record["month"]].add(record["decision_ms"])
    return own, market, members


class Measured:
    """Own returns (with truncation) and market matrices (without), per horizon; and the market measured to the
    actual exit time of the truncated own returns."""

    def __init__(self):
        self.own: dict[tuple[str, int, int], tuple[float, bool, int]] = {}
        self.btc_plain: dict[tuple[int, int], float] = {}
        self.btc_truncated: dict[tuple[int, int], float] = {}
        self.market: dict[str, dict] = {}
        self.market_truncated: dict[str, dict] = {}

    def own_return(self, inst: str, decision_ms: int, horizon: int) -> tuple[float, bool]:
        ret, cut, _ = self.own.get((inst, decision_ms, horizon), (math.nan, False, -1))
        return ret, cut

    def own_exit_ms(self, inst: str, decision_ms: int, horizon: int) -> int | None:
        """The actual exit close time of a truncated own return, None otherwise."""
        _, cut, exit_ms = self.own.get((inst, decision_ms, horizon), (math.nan, False, -1))
        return int(exit_ms) if cut else None

    def btc_return(self, decision_ms: int, horizon: int, exit_ms: int | None = None) -> float:
        if exit_ms is not None:
            return self.btc_truncated.get((decision_ms, exit_ms), math.nan)
        return self.btc_plain.get((decision_ms, horizon), math.nan)

    def market_return(self, month: str, decision_ms: int, horizon: int, exclude: str, exit_ms: int | None = None) -> tuple[float, int]:
        if exit_ms is not None:
            entry = self.market_truncated[month]
            values = entry["returns"][:, entry["column"][(decision_ms, exit_ms)]]
        else:
            entry = self.market[month]
            values = entry["returns"][horizon][:, entry["column"][decision_ms]]
        keep = np.isfinite(values) & (np.array(entry["members"]) != exclude)
        count = int(keep.sum())
        return (float(values[keep].mean()) if count >= pr.MIN_MARKET_MEMBERS else math.nan), count


def measure(document: Mapping, load: Callable[[str], dict], guard: Callable[[], None] | None = None) -> Measured:
    """`load(inst)` returns that instrument's minutes (`data.load_minutes`). Each instrument is loaded once."""
    own, market, members = _queries(document)
    measured = Measured()
    for month, times in market.items():
        ordered = sorted(times)
        measured.market[month] = {"members": members[month], "times": ordered, "column": {t: k for k, t in enumerate(ordered)},
                                  "returns": {h: np.full((len(members[month]), len(ordered)), np.nan) for h in pr.HORIZONS_H}}
    needed = sorted(set(own) | {inst for month in market for inst in members[month]})
    for inst in needed:
        if guard is not None:
            guard()
        minutes = load(inst)
        prices = dt.Prices(minutes["ts_s"], minutes["close"])
        if inst in own:
            times = np.array(sorted(own[inst]), dtype=np.int64)
            for h in pr.HORIZONS_H:
                result = dt.entry_exit(prices, times, h, truncate=True)
                for t, r, cut, when in zip(times, result["ret"], result["truncated"], result["exit_ms"]):
                    measured.own[(inst, int(t), h)] = (float(r), bool(cut), int(when))
                if inst == pr.BTC:
                    plain = dt.entry_exit(prices, times, h, truncate=False)
                    for t, r in zip(times, plain["ret"]):
                        measured.btc_plain[(int(t), h)] = float(r)
        for month, entry in measured.market.items():
            if inst not in entry["members"]:
                continue
            row = entry["members"].index(inst)
            times = np.array(entry["times"], dtype=np.int64)
            for h in pr.HORIZONS_H:
                entry["returns"][h][row] = dt.entry_exit(prices, times, h, truncate=False)["ret"]
    _measure_truncated(document, measured, members, load, guard)
    return measured


def _measure_truncated(document: Mapping, measured: Measured, members: Mapping[str, list[str]], load: Callable[[str], dict],
                       guard: Callable[[], None] | None) -> None:
    """Second pass, only when some own return was truncated: the market (or BTC) from the same entry to the coin's
    actual exit close time."""
    queries: dict[str, set] = defaultdict(set)
    btc_queries: set = set()

    def note(inst: str, decision_ms: int, month: str, btc: bool = False) -> None:
        for h in pr.HORIZONS_H:
            exit_ms = measured.own_exit_ms(inst, decision_ms, h)
            if exit_ms is not None:
                (btc_queries if btc else queries[month]).add((decision_ms, exit_ms))

    for record in document["events"]:
        note(record["inst"], record["decision_ms"], record["month"])
        if record["c2"] is not None:
            note(record["c2"]["inst"], record["c2"]["decision_ms"], record["c2"]["month"])
    for name, rows in document["sensitivities"].items():
        for record in rows or []:
            note(record["inst"], record["decision_ms"], record["month"], btc=name == "btc_market")
    for month, pairs in queries.items():
        ordered = sorted(pairs)
        measured.market_truncated[month] = {"members": members[month], "column": {p: k for k, p in enumerate(ordered)}, "pairs": ordered,
                                            "returns": np.full((len(members[month]), len(ordered)), np.nan)}
    needed = sorted({inst for month in queries for inst in members[month]} | ({pr.BTC} if btc_queries else set()))
    for inst in needed:
        if guard is not None:
            guard()
        minutes = load(inst)
        prices = dt.Prices(minutes["ts_s"], minutes["close"])
        for month, entry in measured.market_truncated.items():
            if inst not in entry["members"]:
                continue
            times = np.array([p[0] for p in entry["pairs"]], dtype=np.int64)
            targets = np.array([p[1] for p in entry["pairs"]], dtype=np.int64)
            entry["returns"][entry["members"].index(inst)] = dt.market_leg(prices, times, targets)
        if inst == pr.BTC and btc_queries:
            pairs = sorted(btc_queries)
            values = dt.market_leg(prices, np.array([p[0] for p in pairs], dtype=np.int64), np.array([p[1] for p in pairs], dtype=np.int64))
            measured.btc_truncated.update({p: float(v) for p, v in zip(pairs, values)})


def _finite(value: float) -> float | None:
    return float(value) if value is not None and math.isfinite(value) else None


def outcomes(document: Mapping, measured: Measured) -> dict:
    rows, counts = [], defaultdict(int)
    for record in document["events"]:
        s = _sign(record["e"])
        row = {"id": record["id"], "inst": record["inst"], "base": record["base"], "decision_ms": record["decision_ms"],
               "month": record["month"], "half": record["half"], "side": record["side"], "secondary": record["secondary"], "h": {}}
        for h in pr.HORIZONS_H:
            raw, cut = measured.own_return(record["inst"], record["decision_ms"], h)
            market, members = measured.market_return(record["month"], record["decision_ms"], h, record["inst"],
                                                     measured.own_exit_ms(record["inst"], record["decision_ms"], h))
            y_raw = -s * raw
            y = -s * (raw - record["beta"] * market)
            c1 = [-s * measured.own_return(record["inst"], int(dt.bar_close_ms(b)), h)[0] for b in record["c1"]["bars"]]
            c2 = c2_market = None
            if record["c2"] is not None:
                other = record["c2"]
                raw_c, _ = measured.own_return(other["inst"], other["decision_ms"], h)
                market_c, _ = measured.market_return(other["month"], other["decision_ms"], h, other["inst"],
                                                     measured.own_exit_ms(other["inst"], other["decision_ms"], h))
                c2 = -s * raw_c
                c2_market = -s * other["beta"] * market_c
            row["h"][str(h)] = {"y": _finite(y), "y_raw": _finite(y_raw), "market_part": _finite(-s * record["beta"] * market),
                                "truncated": cut, "market_members": members, "c1": [v for v in (_finite(x) for x in c1) if v is not None],
                                "c2": _finite(c2) if c2 is not None else None, "c2_market_part": _finite(c2_market) if c2_market is not None else None}
            if h == pr.PRIMARY_HORIZON_H:
                counts["own_return_undefined"] += not math.isfinite(raw)
                counts["market_return_undefined"] += math.isfinite(raw) and not math.isfinite(market)
                counts["truncated"] += cut
                counts["c1_controls_without_outcome"] += sum(1 for x in c1 if not math.isfinite(x))
                counts["c2_control_without_outcome"] += record["c2"] is not None and c2 is not None and not math.isfinite(c2)
        rows.append(row)
    sensitivity_rows = {}
    for name, records in document["sensitivities"].items():
        out = []
        for record in records or []:
            s = _sign(record["e"])
            entry = {"base": record["base"], "month": record["month"], "half": record["half"], "side": record["side"], "h": {}}
            for h in pr.HORIZONS_H:
                raw, _ = measured.own_return(record["inst"], record["decision_ms"], h)
                exit_ms = measured.own_exit_ms(record["inst"], record["decision_ms"], h)
                if name == "btc_market":
                    market = measured.btc_return(record["decision_ms"], h, exit_ms)
                else:
                    market, _ = measured.market_return(record["month"], record["decision_ms"], h, record["inst"], exit_ms)
                entry["h"][str(h)] = {"y": _finite(-s * (raw - record["beta"] * market)), "y_raw": _finite(-s * raw)}
            out.append(entry)
        sensitivity_rows[name] = out
    return {"schema": "h2-gate1-outcomes.v1", "events": rows, "sensitivities": sensitivity_rows, "counts_primary_horizon": dict(counts)}


def _observations(rows, horizon: int, keep=lambda r: True) -> list[dict]:
    out = []
    for row in rows:
        if not keep(row):
            continue
        cell = row["h"][str(horizon)]
        out.append({"month": row["month"], "half": row["half"], "base": row["base"], "y": cell["y"], "y_raw": cell["y_raw"],
                    "c1": cell.get("c1", []), "c2": cell.get("c2")})
    return out


def _decomposition(rows, horizon: int) -> dict:
    """Each split is computed on one sample: the events with both y_raw and a market part (G1 sample), and among their
    matched C2 controls those with both a raw outcome and a market part. The C2 mean over every matched control with
    an outcome (the S2 term) is reported beside it."""
    cells = [row["h"][str(horizon)] for row in rows
             if row["h"][str(horizon)]["y"] is not None and row["h"][str(horizon)]["y_raw"] is not None]
    pairs = [(c["c2"], c["c2_market_part"]) for c in cells if c["c2"] is not None and c["c2_market_part"] is not None]
    mean = st._mean
    events_raw = mean([c["y_raw"] for c in cells])
    events_market = mean([c["market_part"] for c in cells])
    c2_raw = mean([a for a, _ in pairs])
    c2_market = mean([b for _, b in pairs])
    return {"events": len(cells), "events_y_raw": events_raw, "events_market_part": events_market,
            "events_residual_part": (events_raw - events_market) if cells else None,
            "c2_controls": len(pairs), "c2_y_raw": c2_raw, "c2_market_part": c2_market,
            "c2_residual_part": (c2_raw - c2_market) if pairs else None,
            "c2_y_raw_all_matched": mean([c["c2"] for c in cells if c["c2"] is not None])}


def _reported_only(result: dict) -> dict:
    for key in ("s1", "s1_events", "ci_s1", "c2_matched", "c2_match_rate", "s2", "ci_s2", "statistical_blocker", "g4_ci_lower_bound_positive"):
        result.pop(key, None)
    result["gates"] = {k: v for k, v in result["gates"].items() if k not in ("G4", "G5")}
    result["failed_gates"] = [k for k in result["failed_gates"] if k not in ("G4", "G5")]
    result["verdict"] = "REPORTED_ONLY"
    result["fail_due_to_G4_alone"] = None
    return result


def evaluate(measured_outcomes: Mapping, draws: int = pr.BOOTSTRAP_DRAWS) -> dict:
    rows = measured_outcomes["events"]
    primary = st.evaluate(_observations(rows, pr.PRIMARY_HORIZON_H), draws=draws)
    report = {"schema": "h2-gate1-result.v1", "verdict": primary["verdict"], "fail_due_to_G4_alone": primary["fail_due_to_G4_alone"],
              "primary": primary, "sides": {}, "horizons": {}, "secondary_universe": {}, "sensitivities": {},
              "c2_decomposition": {str(h): _decomposition(rows, h) for h in pr.HORIZONS_H},
              "counts_primary_horizon": measured_outcomes["counts_primary_horizon"]}
    for side in ("long", "short"):
        result = st.evaluate(_observations(rows, pr.PRIMARY_HORIZON_H, lambda r, s=side: r["side"] == s), draws=draws)
        result["excluded_from_gate2"] = result["mean_y"] is None or result["mean_y"] <= 0
        report["sides"][side] = result
    for h in pr.HORIZONS_H:
        if h != pr.PRIMARY_HORIZON_H:
            report["horizons"][str(h)] = st.evaluate(_observations(rows, h), draws=draws)
    report["secondary_universe"]["pooled"] = st.evaluate(_observations(rows, pr.PRIMARY_HORIZON_H, lambda r: r["secondary"]), draws=draws)
    for side in ("long", "short"):
        report["secondary_universe"][side] = st.evaluate(
            _observations(rows, pr.PRIMARY_HORIZON_H, lambda r, s=side: r["secondary"] and r["side"] == s), draws=draws)
    for name, srows in measured_outcomes["sensitivities"].items():
        report["sensitivities"][name] = _reported_only(st.evaluate(_observations(srows, pr.PRIMARY_HORIZON_H), draws=draws))
    return report


def _write_exclusive(path: Path, document: Mapping) -> str:
    blob = (json.dumps(document, sort_keys=True, indent=1, allow_nan=True) + "\n").encode()
    with open(path, "xb") as handle:
        handle.write(blob)
    return hashlib.sha256(blob).hexdigest()


def run(events_path: Path, expected_sha256: str, sources: dt.Sources, out: Path, guard: Callable[[], None] | None = None) -> dict:
    document = read_events(events_path, expected_sha256)
    measured = measure(document, lambda inst: dt.load_minutes(sources, inst), guard)
    measured_outcomes = outcomes(document, measured)
    measured_outcomes["events_sha256"] = expected_sha256
    _write_exclusive(Path(out) / OUTCOMES_FILE, measured_outcomes)
    report = evaluate(measured_outcomes)
    report["events_sha256"] = expected_sha256
    _write_exclusive(Path(out) / RESULT_FILE, report)
    return report

