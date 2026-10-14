"""Audit of the OKX swing v2 instruments and the corrected monthly universe (owner's corrections of 2026-10-14).

- **Segments.** Each instrument's archived 1-minute history is split at every interruption longer than seven
  days (a delisting followed by a re-listing, or a contract change): each segment is its own series
  (``<instId>#<n>``), with its own warm-up, metadata, funding and universe membership. Shorter interruptions stay
  inside a segment and are handled by the engine (no entry with one in the warm-up; an open position is carried
  across, its first post-interruption bar applies).
- **Contract value (``ctVal``) and units.** Observed day by day, never carried backwards: from the public REST daily
  candles (``volCcy`` / ``vol``, OKX's own record, live instruments only) and from the archive minutes carrying both
  the base volume and the contract volume. ``ctMult`` is 1 for every linear swap observed; a change of the observed
  ratio inside a segment splits it. Units are cross-checked: REST quote volume against the archive quote volume on
  the days where both exist.
- **Reliable daily quote volume.** In order: the REST ``volCcyQuote`` (live instruments); the archive quote volume
  (every minute of the day carrying it); the archive base volume x close; the archive contract volume x a contract
  value observed on or BEFORE that day in the same segment. Anything else is unreliable and excluded from the
  ranking; the report compares the top 30 with and without the exclusion.
- **Delisted instruments' tick and lot** are reconstructed from the archive: the smallest positive increment of the
  traded prices and of the per-minute contract volume, per segment.
"""

from __future__ import annotations

import argparse
import json
import statistics
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from .okx import _get, _log
from .okx_universe import EXCLUDED, MIN_DAYS_IN_PREVIOUS_MONTH, UNIVERSE_FROM, UNIVERSE_SIZE, UNIVERSE_TO, WARMUP_DAYS, _month_start_ms, _months

SEGMENT_GAP_DAYS = 7
REST_FROM_MS = 1_661_990_400_000  # 2022-09-01
REST_TO_MS = 1_791_936_000_000  # 2026-10-14
DAY_S = 86_400


def _day(ms: int) -> int:
    return ms // 86_400_000


def rest_daily(inst: str) -> dict[int, list[float]]:
    """{UTC day: [vol (contracts), volCcy (base), volCcyQuote]} from the public 1Dutc history."""
    rows: dict[int, list[float]] = {}
    cursor = REST_TO_MS
    while cursor > REST_FROM_MS:
        data = _get(f"/api/v5/market/history-candles?instId={inst}&bar=1Dutc&after={cursor}&limit=100")["data"]
        if not data:
            break
        for row in data:
            if row[8] == "1":
                rows[_day(int(row[0]))] = [float(row[5]), float(row[6]), float(row[7])]
        cursor = min(int(row[0]) for row in data)
    return rows


def _archive(tape: Path, inst: str) -> dict[str, np.ndarray]:
    parts = []
    for path in sorted((tape / "klines" / inst).glob("*.npz")):
        with np.load(path) as data:
            part = {k: data[k] for k in data.files}
        for key in ("base_volume", "contracts"):
            part.setdefault(key, np.full(part["ts_s"].size, np.nan))
        parts.append(part)
    data = {k: np.concatenate([p[k] for p in parts]) for k in ("ts_s", "open", "high", "low", "close", "volume", "base_volume", "contracts")}
    order = np.argsort(data["ts_s"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(data["ts_s"].size, dtype=bool)
    keep[1:] = data["ts_s"][1:] != data["ts_s"][:-1]
    return {k: v[keep] for k, v in data.items()}


def _increment(values: np.ndarray) -> float | None:
    levels = np.unique(values[np.isfinite(values) & (values > 0)])
    steps = np.diff(levels)
    steps = steps[steps > levels.max() * 1e-12] if steps.size else steps
    return float(steps.min()) if steps.size else None


def _round_step(value: float | None) -> float | None:
    """The largest 1/2/5 x 10^k not above ``value`` (prices and lots are quoted on such steps)."""
    if value is None:
        return None
    exponent = np.floor(np.log10(value) + 1e-9)
    for mantissa in (5, 2, 1):
        step = mantissa * 10 ** exponent
        if step <= value * (1 + 1e-9):
            return float(step)
    return float(10 ** exponent)


def audit_instrument(tape: Path, inst: str, live: dict | None, rest: dict[int, list[float]] | None) -> dict:
    data = _archive(tape, inst)
    ts = data["ts_s"]
    breaks = np.flatnonzero(np.diff(ts) > SEGMENT_GAP_DAYS * DAY_S)
    bounds = list(zip(np.concatenate(([0], breaks + 1)), np.concatenate((breaks + 1, [ts.size]))))
    days = (ts // DAY_S).astype(np.int64)
    segments = []
    for n, (a, b) in enumerate(bounds, 1):
        seg = {k: v[a:b] for k, v in data.items()}
        sdays = days[a:b]
        both = np.isfinite(seg["base_volume"]) & np.isfinite(seg["contracts"]) & (seg["contracts"] > 0)
        ratio_by_day: dict[int, float] = {}
        for day in np.unique(sdays[both]):
            mask = both & (sdays == day)
            ratio_by_day[int(day)] = float(seg["base_volume"][mask].sum() / seg["contracts"][mask].sum())
        if rest:
            for day, (vol, vol_ccy, _) in rest.items():
                if int(sdays[0]) <= day <= int(sdays[-1]) and vol > 0:
                    ratio_by_day.setdefault(day, vol_ccy / vol)
        observed = sorted(ratio_by_day.items())
        values = [v for _, v in observed]
        changes = []
        for (d0, v0), (d1, v1) in zip(observed, observed[1:]):
            if abs(v1 / v0 - 1) > 0.01:
                changes.append([d1, v0, v1])
        tick = _round_step(_increment(np.concatenate([seg[k] for k in ("open", "high", "low", "close")])))
        lot = _round_step(_increment(seg["contracts"]))
        segments.append({
            "series": f"{inst}#{n}" if len(bounds) > 1 else inst, "start_s": int(seg["ts_s"][0]), "end_s": int(seg["ts_s"][-1]) + 60,
            "minutes": int(b - a), "ct_val_observed": {"first_day": observed[0][0] if observed else None, "days": len(observed),
                                                       "median": statistics.median(values) if values else None,
                                                       "min": min(values) if values else None, "max": max(values) if values else None,
                                                       "changes": changes[:10]},
            "tick_inferred": tick, "lot_inferred": lot,
        })
    report = {"instId": inst, "live": live is not None, "segments": segments}
    if live is not None:
        report["live_metadata"] = {k: live[k] for k in ("ctVal", "ctMult", "tickSz", "lotSz", "minSz", "listTime", "ctValCcy")}
    return report


def reliable_daily_volume(tape: Path, inst: str, audit: dict, rest: dict[int, list[float]] | None) -> dict[int, list]:
    """{day: [quote volume, source, minutes]} for the reliable days only."""
    data = _archive(tape, inst)
    days = (data["ts_s"] // DAY_S).astype(np.int64)
    out: dict[int, list] = {}
    observed: list[tuple[int, float]] = []
    for seg in audit["segments"]:
        pass
    ratio_days = {}
    both = np.isfinite(data["base_volume"]) & np.isfinite(data["contracts"]) & (data["contracts"] > 0)
    for day in np.unique(days[both]):
        mask = both & (days == day)
        ratio_days[int(day)] = float(data["base_volume"][mask].sum() / data["contracts"][mask].sum())
    if rest:
        for day, (vol, vol_ccy, _) in rest.items():
            if vol > 0:
                ratio_days.setdefault(day, vol_ccy / vol)
    observed = sorted(ratio_days.items())
    observed_days = np.array([d for d, _ in observed], dtype=np.int64)
    segment_of = np.searchsorted(np.array([s["start_s"] // DAY_S for s in audit["segments"]]), days, side="right") - 1
    for day in np.unique(days):
        mask = days == day
        minutes = int(mask.sum())
        if rest and int(day) in rest:
            out[int(day)] = [rest[int(day)][2], "rest_quote", minutes]
            continue
        quote, base, contracts, close = (data[k][mask] for k in ("volume", "base_volume", "contracts", "close"))
        if np.isfinite(quote).all():
            out[int(day)] = [float(quote.sum()), "archive_quote", minutes]
        elif np.isfinite(base).all():
            out[int(day)] = [float((base * close).sum()), "archive_base_x_close", minutes]
        elif np.isfinite(contracts).all() and observed_days.size:
            seg = audit["segments"][int(segment_of[mask][0])]
            index = np.searchsorted(observed_days, day, side="right") - 1  # on or before the day only
            if index >= 0 and observed_days[index] >= seg["start_s"] // DAY_S:
                out[int(day)] = [float((contracts * observed[index][1] * close).sum()), "archive_contracts_x_prior_ctval", minutes]
    return out


def build(tape: Path, inventory_path: Path, out_dir: Path) -> dict:
    families = [v["instId"] for v in json.loads(inventory_path.read_text())["instruments"].values() if v.get("files")]
    live = {i["instId"]: i for i in _get("/api/v5/public/instruments?instType=SWAP")["data"]}
    audits, volumes, crosscheck = {}, {}, {}
    for count, inst in enumerate(sorted(families), 1):
        rest = rest_daily(inst) if inst in live else None
        audit = audit_instrument(tape, inst, live.get(inst), rest)
        vols = reliable_daily_volume(tape, inst, audit, rest)
        if rest:
            data = _archive(tape, inst)
            days = (data["ts_s"] // DAY_S).astype(np.int64)
            ratios = []
            for day in np.unique(days):
                mask = days == day
                q = data["volume"][mask]
                if int(day) in rest and np.isfinite(q).all() and mask.sum() == 1440 and rest[int(day)][2] > 0:
                    ratios.append(float(q.sum()) / rest[int(day)][2])
            crosscheck[inst] = {"days": len(ratios), "median_archive_over_rest": statistics.median(ratios) if ratios else None,
                                "p05": float(np.percentile(ratios, 5)) if ratios else None, "p95": float(np.percentile(ratios, 95)) if ratios else None}
        audits[inst] = audit
        volumes[inst] = vols
        if count % 25 == 0:
            _log(f"audit {count}/{len(families)}")
    (out_dir / "instrument-audit.json").write_text(json.dumps({"segment_gap_days": SEGMENT_GAP_DAYS, "instruments": audits,
                                                              "unit_crosscheck": crosscheck}, indent=1) + "\n")
    (out_dir / "reliable-daily-volume.json").write_text(json.dumps(volumes) + "\n")
    return {"instruments": len(audits)}


def _universe(audits: dict, volumes: dict, reliable_only: bool, raw: dict | None) -> dict:
    months = {}
    for month in _months(UNIVERSE_FROM, UNIVERSE_TO):
        start_day = _month_start_ms(month) // 86_400_000
        prev_day = _month_start_ms(_months("2000-01", month)[-2]) // 86_400_000
        ranking = []
        for inst, audit in audits.items():
            base = inst.split("-")[0]
            if base in EXCLUDED:
                continue
            for seg in audit["segments"]:
                if not (seg["start_s"] // DAY_S <= start_day - WARMUP_DAYS and seg["end_s"] // DAY_S >= prev_day):
                    continue
                if reliable_only:
                    days = [v[0] for d, v in volumes[inst].items() if prev_day <= int(d) < start_day and v[2] >= 720
                            and seg["start_s"] // DAY_S <= int(d) <= seg["end_s"] // DAY_S]
                else:
                    days = [v for d, (v, minutes, missing) in raw.get(inst, {}).items()
                            if prev_day <= int(d) < start_day and minutes >= 720 and seg["start_s"] // DAY_S <= int(d) <= seg["end_s"] // DAY_S]
                if len(days) >= MIN_DAYS_IN_PREVIOUS_MONTH:
                    ranking.append((statistics.median(days), seg["series"], len(days)))
        ranking.sort(key=lambda r: (-r[0], r[1]))
        months[month] = {"universe": [s for _, s, _ in ranking[:UNIVERSE_SIZE]], "eligible": len(ranking),
                         "ranking_top40": [[s, round(v), n] for v, s, n in ranking[:40]]}
    return months


def universe(out_dir: Path, tape: Path) -> dict:
    audits = json.loads((out_dir / "instrument-audit.json").read_text())["instruments"]
    volumes = json.loads((out_dir / "reliable-daily-volume.json").read_text())
    raw = json.loads((tape / "daily-quote-volume.json").read_text())["days"]
    reliable = _universe(audits, volumes, True, None)
    unfiltered = _universe(audits, volumes, False, raw)
    effect = {m: {"entered_by_exclusion": sorted(set(reliable[m]["universe"]) - set(unfiltered[m]["universe"])),
                  "left_by_exclusion": sorted(set(unfiltered[m]["universe"]) - set(reliable[m]["universe"]))} for m in reliable}
    members = sorted({s for m in reliable.values() for s in m["universe"]})
    document = {"rule": {"size": UNIVERSE_SIZE, "rank": "median RELIABLE daily quote volume of M-1 (UTC days >= 720 minutes) within the segment",
                         "eligible": f"segment started >= {WARMUP_DAYS} days before the 1st of M; >= {MIN_DAYS_IN_PREVIOUS_MONTH} reliable days in M-1",
                         "segment_gap_days": SEGMENT_GAP_DAYS, "excluded_bases": sorted(EXCLUDED)},
                "months": reliable, "members": members, "effect_of_excluding_unreliable_volume": effect}
    (out_dir / "universe-monthly-v2.json").write_text(json.dumps(document, indent=1) + "\n")
    return document


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_swing2.okx_audit")
    sub = parser.add_subparsers(dest="command", required=True)
    b = sub.add_parser("build")
    b.add_argument("--tape", required=True)
    b.add_argument("--inventory", required=True)
    b.add_argument("--out", required=True)
    u = sub.add_parser("universe")
    u.add_argument("--tape", required=True)
    u.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    if args.command == "build":
        print(json.dumps(build(Path(args.tape), Path(args.inventory), Path(args.out))))
    else:
        document = universe(Path(args.out), Path(args.tape))
        print(json.dumps({"members": len(document["members"])}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
