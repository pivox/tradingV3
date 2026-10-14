"""Training run of regime-r2 (§6-§8). Validation and holdout are refused here by construction.

Usage: python -m scripts.research_regime.run train --data <dir> --out <dir>
"""

from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
from datetime import date
from pathlib import Path

import numpy as np

from . import annexes, data, detectors as det, evaluate as ev

TRAIN_END = date(2022, 12, 31)
LAYER_B_START = date(2020, 2, 1)
FIRST_DATE = {  # §5, table of first eligible dates
    "D1": date(2018, 3, 5), "D2": date(2018, 3, 1), "D3": date(2018, 3, 5),
    "D4": date(2021, 1, 8), "D5": date(2018, 9, 16), "D6": date(2018, 3, 5),
}
REPORT_PHASES = [  # §9, reporting only
    ("2018 baisse", date(2018, 1, 1), date(2018, 12, 31)),
    ("2019 reprise", date(2019, 1, 1), date(2019, 12, 31)),
    ("2020-03 → 2021-11 hausse", date(2020, 3, 1), date(2021, 11, 30)),
    ("2021-12 → 2022-12 baisse", date(2021, 12, 1), date(2022, 12, 31)),
]
N_RANDOM_A, N_RANDOM_B, N_BOOT, SEED = 1000, 200, 10_000, 20261014


def code_hash() -> str:
    h = hashlib.sha256()
    for p in sorted(Path(__file__).resolve().parent.glob("*.py")):
        h.update(p.name.encode() + p.read_bytes())
    return h.hexdigest()


def window_mask(m: data.Market, lo: date, hi: date) -> np.ndarray:
    col = np.zeros(len(m.days), dtype=bool)
    col[max(0, m.index(lo)):m.index(hi) + 1] = True
    return col


def layer_a_report(name: str, state: np.ndarray, rets: dict, elig: np.ndarray, comps: dict, m: data.Market,
                   rng: np.random.Generator) -> dict:
    r14 = rets[14]
    la = ev.layer_a(state, r14, elig, N_RANDOM_A, rng)
    med = float(np.nanmedian(la.rand_scores))
    p = float(np.mean(la.rand_scores >= la.score))
    days_ok = np.isfinite(la.daily)
    surplus_daily = (la.daily - la.rand_daily)[days_ok & np.isfinite(la.rand_daily)]
    ci_score = ev.block_bootstrap_ci(la.daily[days_ok], N_BOOT, rng)
    ci_surplus = ev.block_bootstrap_ci(surplus_daily, N_BOOT, rng)
    comp_scores = {k: ev.deterministic_score(v, r14, elig) for k, v in comps.items()}
    years = {}
    sd_full = np.where(days_ok & np.isfinite(la.rand_daily), la.daily - la.rand_daily, np.nan)
    for y in sorted({data.date_of(d).year for d in m.days}):
        cols = np.array([data.date_of(d).year == y for d in m.days])
        n = int((days_ok & cols).sum())
        if n:
            years[y] = {"days": n, "surplus": float(np.nanmean(sd_full[cols])) if np.isfinite(sd_full[cols]).any() else None}
    big = {y: v for y, v in years.items() if v["days"] >= 60 and v["surplus"] is not None}
    stable = len(big) > 0 and sum(v["surplus"] > 0 for v in big.values()) >= (2 / 3) * len(big)
    phases = {}
    for label, lo, hi in REPORT_PHASES:
        cols = window_mask(m, lo, hi)
        if np.isfinite(sd_full[cols]).any():
            phases[label] = float(np.nanmean(sd_full[cols]))
    neutral = elig & (state == 0) & np.isfinite(r14)
    active = elig & (state != 0) & np.isfinite(state) & np.isfinite(r14)
    neutral_value = {
        "neutral_mean_abs_r14": float(np.abs(r14[neutral]).mean()) if neutral.any() else None,
        "active_mean_abs_r14": float(np.abs(r14[active]).mean()) if active.any() else None,
        "neutral_mean_r14": float(r14[neutral].mean()) if neutral.any() else None,
        "active_mean_r14": float(r14[active].mean()) if active.any() else None,
        "neutral_share": float(neutral.sum() / max(1, (neutral | active).sum())),
    }
    secondary = {}
    for h in (7, 30):
        e_h = elig & np.isfinite(rets[h])
        lh = ev.layer_a(state, rets[h], e_h, N_RANDOM_B, rng)
        secondary[h] = {"score": lh.score, "surplus": lh.score - float(np.nanmedian(lh.rand_scores)),
                        "p": float(np.mean(lh.rand_scores >= lh.score))}
    passes = {
        "surplus_positive_p": (la.score - med) > 0 and p <= 0.10,
        "surplus_ci_low_positive": ci_surplus[0] > 0,
        "beats_deterministic": all(np.isfinite(v) and la.score > v for v in comp_scores.values()),
        "stable_years": bool(stable),
    }
    return {"score": la.score, "random_median": med, "surplus": la.score - med, "p": p,
            "ci_score_90": ci_score, "ci_surplus_90": ci_surplus, "comparators": comp_scores,
            "years": years, "phases": phases, "neutral_value": neutral_value, "secondary": secondary,
            "decision_days": int(days_ok.sum()), "passes": passes, "pass": all(passes.values())}


def layer_b_report(state: np.ndarray, open_mask: np.ndarray, comps: dict, book: ev.Book, m: data.Market,
                   lo: int, hi: int, rng: np.random.Generator, engine: str = "run1",
                   blocked: np.ndarray | None = None) -> dict:
    """engine "run1": fixed notional (archived first run); "c1": self-financed correction (AUDIT-Q2-c1.md)."""
    out = {}
    hi_pnl = min(hi + 14, len(m.days) - 1)
    chains = {}
    for a in range(state.shape[0]):
        cols = np.where(open_mask[a] & np.isfinite(state[a]))[0]
        if len(cols):
            pi, q = ev.chain_params(state[a, cols])
            chains[a] = (cols, ev.sample_chains(pi, q, len(cols), N_RANDOM_B, rng))
    def run_one(st, costs):
        if engine == "c1":
            sim = ev.simulate_selffinanced(st, open_mask, book, costs, blocked, lo, hi_pnl)
            return sim, ev.metrics_selffinanced(sim, m.days, lo, hi_pnl)
        sim = ev.simulate(st, open_mask, book, costs)
        return sim, ev.metrics(sim["pnl"], m.days, lo, hi_pnl, sim["exposure"])

    for profile in ("normal", "adverse"):
        costs = ev.side_cost(m.symbols, profile)
        sim, res = run_one(state, costs)
        comp = {}
        for k, v in comps.items():
            comp[k] = run_one(np.where(open_mask, np.nan_to_num(v, nan=0.0), np.nan), costs)[1]
        nets = []
        for i in range(N_RANDOM_B):
            rs = np.full(state.shape, np.nan)
            for a, (cols, draws) in chains.items():
                rs[a, cols] = draws[i]
            nets.append(float(run_one(rs, costs)[1]["net"]))
        nets = np.array(nets)
        best_det = max(c["net"] for c in comp.values())
        checks = {
            "net_positive_beats_random": res["net"] > 0 and res["net"] > float(np.median(nets))
                                         and float(np.mean(nets >= res["net"])) <= 0.10,
            "calmar_beats_deterministic": all(res["calmar"] > c["calmar"] for c in comp.values()),
            "net_or_drawdown": res["net"] >= best_det or res["max_dd"] <= 0.75 * comp["always_long"]["max_dd"],
            "exposure_30pct": res["exposure"] >= 0.30,
        }
        out[profile] = {**res, "random_median_net": float(np.median(nets)), "random_p": float(np.mean(nets >= res["net"])),
                        "comparators": comp, "checks": checks, "pass": all(checks.values()),
                        "tranches": sim["tranches"], "blocked_positions": sim.get("blocked_positions")}
    out["pass"] = out["normal"]["pass"] and out["adverse"]["pass"]
    return out


def train(data_dir: Path, out_dir: Path, engine: str = "run1") -> dict:
    frozen = annexes.verify_frozen()
    out_dir.mkdir(parents=True, exist_ok=True)
    spot, perp, fund = data.load_dir(data_dir)
    first = data.date_of(min(int(v["day"][0]) for v in spot.values()))
    m = data.build_market(spot, perp, fund, first, date(2023, 1, 31), annexes.annex_a_symbols(), annexes.annex_b_exclusions())
    u50, u20 = data.universe(m, 50), data.universe(m, 20)
    rows = np.where(u20.any(axis=1))[0]
    trend = det.simple_trend(m)
    d1, d2 = det.d1_ma200(m), det.d2_momentum(m)
    d3, breadth = det.d3_breadth(m, u50)
    states = {"D1": d1, "D2": d2, "D3": d3, "D4": det.d4_funding(m, rows), "D5": det.d5_volatility(m, d2, rows),
              "D6": det.d6_combo(d1, d3)}
    rets = {h: ev.forward_return(m, h) for h in (7, 14, 30)}
    book = ev.build_book(m)
    blocked = ev.missing_funding_block(m) if engine == "c1" else None
    btc = np.array([s == "BTCUSDT" for s in m.symbols])[:, None]
    results = {}
    for i, (name, st) in enumerate(states.items()):
        rng = np.random.default_rng(SEED + i)
        win = window_mask(m, FIRST_DATE[name], TRAIN_END)[None, :]
        base = win & ~np.isnan(st)
        comps = {"always_long": np.where(np.isnan(st), np.nan, 1.0), "always_short": np.where(np.isnan(st), np.nan, -1.0),
                 "simple_trend": trend}
        res = {"window": [str(FIRST_DATE[name]), str(TRAIN_END)]}
        res["q1_basket"] = layer_a_report(name, st, rets, base & u20, comps, m, rng)
        res["q1_btc"] = layer_a_report(name, st, rets, base & btc, comps, m, rng)
        b_lo = max(FIRST_DATE[name], LAYER_B_START)
        lo, hi = m.index(b_lo), m.index(TRAIN_END)
        winb = window_mask(m, b_lo, TRAIN_END)[None, :]
        open_basket = u20 & book.tradable & ~np.isnan(st) & winb
        open_btc = btc & book.tradable & ~np.isnan(st) & winb
        res["q2_basket"] = layer_b_report(st, open_basket, comps, book, m, lo, hi, rng, engine, blocked)
        res["q2_btc"] = layer_b_report(st, open_btc, comps, book, m, lo, hi, rng, engine, blocked)
        res["pass_q1"] = res["q1_basket"]["pass"]
        res["pass_q2"] = res["q2_basket"]["pass"]
        results[name] = res
        print(json.dumps({name: {"q1": res["pass_q1"], "q2": res["pass_q2"], "surplus": res["q1_basket"]["surplus"]}}), flush=True)
    passing = [k for k, v in results.items() if v["pass_q1"] and v["pass_q2"]]
    passing.sort(key=lambda k: -results[k]["q1_basket"]["ci_surplus_90"][0])
    selected = passing[:2]
    try:
        commit = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=Path(__file__).parent, text=True).strip()
    except Exception:  # noqa: BLE001
        commit = None
    summary = {
        "protocol": "regime-r2", "phase": "training", "q2_engine": engine, "frozen": frozen, "code_sha256": code_hash(), "git_commit": commit,
        "data_manifest_sha256": hashlib.sha256((data_dir / "manifest.json").read_bytes()).hexdigest(),
        "assets": len(m.asset_ids), "notes": m.notes, "missing_funding_days": book.missing_funding_days,
        "deviations": ["D4-OI not computed in this run (secondary, non-selectable; daily metrics archives not acquired)"],
        "results": results, "selected": selected,
        "verdict": "selected" if selected else "no_detector_passes_training",
        "next": "validation 2023-2024 for the selected detectors" if selected else "stop: validation and holdout stay unread",
    }
    name = "training.json" if engine == "run1" else f"training-{engine}.json"
    (out_dir / name).write_text(json.dumps(summary, indent=1, default=float))
    return summary


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("phase", choices=["train"])
    ap.add_argument("--data", type=Path, required=True)
    ap.add_argument("--out", type=Path, required=True)
    ap.add_argument("--q2-engine", choices=["run1", "c1"], default="run1")
    a = ap.parse_args()
    s = train(a.data, a.out, a.q2_engine)
    print(json.dumps({"verdict": s["verdict"], "selected": s["selected"]}))


if __name__ == "__main__":
    main()
