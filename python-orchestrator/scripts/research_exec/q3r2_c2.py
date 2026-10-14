"""scalp-q3-r2, corrective pass (AMENDMENT-2-scalp-q3-r2.md): comparable control, partial passive exits, gate, validation.

    python -m scripts.research_exec.q3r2_c2 analyse --out RUN_C2 --phase training --seeds 1000
    python -m scripts.research_exec.q3r2_c2 claim --out RUN_NOV --amendment AMENDMENT-2.md --expected-sha256 H --gate GATE.json
    python -m scripts.research_exec.q3r2_c2 prepare --out RUN_NOV --tape-dir T --instruments I --funding-dir F --workers 2 --phase validation
    python -m scripts.research_exec.q3r2_c2 analyse --out RUN_NOV --phase validation --gate GATE.json --seeds 1000

Primary control (`cluster_shift`): the candidate's signal episodes of all pairs of a window are grouped into global
clusters (two episodes, of the same pair or not, closer than `CLUSTER_GAP_S` belong to the same cluster: an order or
position opened in one can still be alive in the other, through the per-pair busy rule or the portfolio's slots);
each cluster is moved as one block, every pair and every relative time kept, to a start drawn uniformly among the
window's seconds with the same UTC hour as its start (a start that keeps the block inside the window when one
exists, else the block wraps around the window). Frequency, persistence, the per-pair clustering and the cross-pair
synchrony of the candidate are kept; only the alignment with the market state is lost.

Secondary references: the episode shift of the frozen protocol (`q3r2_stats.shift_episodes`, same seeds as before)
and, as information, the band-state control without the flow condition (drawn per stratum).
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import time
from collections import defaultdict
from pathlib import Path

import numpy as np

from . import q3r2_run as qr
from . import screen as sc
from .q3r2_engine import CONFIG_SIGMA, CONFIGS, CROSSED, DEAD, FILLED, NONE, PASSIVE, STOP, UNFILLED, exit_mix, standalone
from .q3r2_portfolio import SCENARIOS, Book, Stream, run_window
from .q3r2_stats import (BOOT_RESAMPLES, SEED_BASE, band_state_draws, bootstrap_surplus, bootstrap_total, episodes, hour_pools, max_drawdown, p_value, rng_for,
                         shift_episodes, signal_summary, strata, tails)
from .tick import SECOND_US

CANDIDATES = ("C2", "C4")  # the two configurations forwarded by the frozen verdict; Z3-SL200 only
VARIANT = "SL"
CLUSTER_GAP_S = 3932  # TTL 30 s + fallback 3 900 s + latency 0.25 s + 1 s of busy rounding: no order or position outlives it
QREF_NOTIONAL = 110.0  # signal level: reference order (the risk-sized notional at 500 USDT) for the partial passive exits
TOL_TRADES = 0.15  # AMENDMENT-2 §3: control's mean executed trades within ±15 % of the candidate's
TOL_EXPOSURE = 0.20  # control's mean position time and notional exposure within ±20 %
VALIDATION_WINDOWS = {"v2411a": ("2024-11-04", "2024-11-08"), "v2411b": ("2024-11-11", "2024-11-15"),
                      "v2411c": ("2024-11-18", "2024-11-22"), "v2411d": ("2024-11-25", "2024-11-29")}
sc.BINANCE_WINDOWS.update(VALIDATION_WINDOWS)
CONTROLS = ("cluster", "episode", "band")


# ----------------------------------------------------------------------------- controls


def global_clusters(signals: dict[int, np.ndarray], gap: int = CLUSTER_GAP_S) -> list[dict]:
    """Clusters of the episodes of all series of one window (all series share the window's clock)."""
    eps = []
    for index, sig in signals.items():
        starts, lengths = episodes(sig)
        eps += [(int(a), int(a + l), int(index)) for a, l in zip(starts.tolist(), lengths.tolist())]
    eps.sort()
    clusters: list[dict] = []
    for a, b, index in eps:
        if clusters and a - clusters[-1]["end"] < gap:
            cl = clusters[-1]
            cl["end"] = max(cl["end"], b)
            cl["members"].append((index, a - cl["start"], b - a))
        else:
            clusters.append({"start": a, "end": b, "members": [(index, 0, b - a)]})
    return clusters


def cluster_shift(clusters: list[dict], start_s: int, n: int, rng: np.random.Generator, pools: list[np.ndarray] | None = None) -> dict[int, np.ndarray]:
    pools = pools if pools is not None else hour_pools(start_s, n)
    out: dict[int, list] = defaultdict(list)
    for cl in clusters:
        length = cl["end"] - cl["start"]
        pool = pools[int((start_s + cl["start"]) // 3600 % 24)]
        inside = pool[pool <= n - length]
        new = int(rng.choice(inside if inside.size else pool))
        for index, rel, ln in cl["members"]:
            out[index].append((new + rel + np.arange(ln, dtype=np.int64)) % n)
    return {index: np.unique(np.concatenate(parts)) for index, parts in out.items()}


# ----------------------------------------------------------------------------- partial passive exits at the signal level


def corrected_view(cols: dict, n: int, variant: str, start_s: int) -> dict:
    """Signal level (AMENDMENT-2 B2): every filled order exits a reference quantity (QREF_NOTIONAL / entry); where the
    through volume is short, the remainder leaves by Z3 (or its stop) and the pair stays busy until then."""
    view = dict(cols)
    p = "z" if variant == "Z3" else "s"
    rows = np.flatnonzero(cols["status"] == FILLED)
    busy = cols[f"{p}_busy"].copy()
    frac = np.ones(n)
    rem_exit = cols[f"{p}_exit"].copy()
    rem_reason = cols[f"{p}_reason"].copy()
    if rows.size and "pv_thr" in cols:
        qref = QREF_NOTIONAL / cols["price"][rows]
        mix = exit_mix(cols, rows, variant, qref)
        short = mix["frac"] < 1.0 - 1e-12
        frac[rows] = mix["frac"]
        rem_exit[rows] = mix["rem_exit"]
        rem_reason[rows] = mix["rem_reason"]
        busy[rows[short]] = ((mix["end_ts"][short] - start_s * SECOND_US) // SECOND_US + 1).astype(busy.dtype)
    view[f"{p}_busy"] = busy
    view["_frac"], view["_rem_exit"], view["_rem_reason"] = frac, rem_exit, rem_reason
    return view


def net_bp(view: dict, filled: np.ndarray, variant: str, maker: float, taker: float, slip: float) -> dict[str, np.ndarray]:
    p = "z" if variant == "Z3" else "s"
    entry = view["price"][filled]
    x1, reason = view[f"{p}_exit"][filled], view[f"{p}_reason"][filled]
    f = view["_frac"][filled] if "_frac" in view else np.ones(filled.size)
    x2 = view["_rem_exit"][filled] if "_rem_exit" in view else x1
    gross = (f * (x1 - entry) + (1 - f) * (x2 - entry)) / entry * 1e4
    taker1 = reason != PASSIVE
    fees = maker + f * np.where(taker1, taker, maker) + (1 - f) * taker
    slip_bp = slip * (f * taker1 + (1 - f))
    return {"net": gross - fees - slip_bp, "gross": gross, "fees": fees + slip_bp}


# ----------------------------------------------------------------------------- per-seed portfolio statistics


class PortfolioSeeds:
    """Per-seed portfolio statistics, carried across windows (equity, peak and drawdown in exit order)."""

    FIELDS = ("trades", "orders", "pnl", "position_s", "notional_s", "fees", "gains", "losses", "max_dd", "refused_slots")

    def __init__(self, seeds: int, capital: float = 500.0) -> None:
        self.v = {k: np.zeros(seeds) for k in self.FIELDS}
        self.equity = np.full(seeds, capital)
        self.peak = np.full(seeds, capital)
        self.daily: dict[int, np.ndarray] = defaultdict(lambda: np.zeros(seeds))
        self.by_window: dict[str, np.ndarray] = defaultdict(lambda: np.zeros(seeds))
        self.seeds = seeds

    def add(self, seed: int, res, window: str) -> None:
        v = self.v
        v["trades"][seed] += res.trades
        v["orders"][seed] += res.orders
        v["pnl"][seed] += res.pnl
        v["refused_slots"][seed] += res.refusals["slots"]
        eq, peak, dd = self.equity[seed], self.peak[seed], v["max_dd"][seed]
        for r in sorted(res.records, key=lambda r: r["exit_ts"]):
            v["position_s"][seed] += (r["exit_ts"] - r["fill_ts"]) / SECOND_US
            v["notional_s"][seed] += r["notional"] * (r["exit_ts"] - r["fill_ts"]) / SECOND_US
            v["fees"][seed] += r["fee_in"] + r["fee_out"]
            if r["pnl"] > 0:
                v["gains"][seed] += r["pnl"]
            else:
                v["losses"][seed] -= r["pnl"]
            eq += r["pnl"]
            peak = max(peak, eq)
            dd = max(dd, peak - eq)
        self.equity[seed], self.peak[seed], v["max_dd"][seed] = res.equity, max(peak, res.equity), dd
        for d, val in res.daily.items():
            self.daily[int(d)][seed] += val
        self.by_window[window][seed] += res.pnl


def candidate_portfolio_stats(records: list, total_s: float) -> dict:
    pnl = np.array([r["pnl"] for r in records]) if records else np.zeros(0)
    pos_s = sum((r["exit_ts"] - r["fill_ts"]) / SECOND_US for r in records)
    not_s = sum(r["notional"] * (r["exit_ts"] - r["fill_ts"]) / SECOND_US for r in records)
    gains, losses = pnl[pnl > 0].sum(), -pnl[pnl < 0].sum()
    order = np.argsort([r["exit_ts"] for r in records], kind="stable") if records else np.zeros(0, dtype=int)
    eq = 500.0 + np.concatenate([[0.0], np.cumsum(pnl[order])])
    srt = np.sort(pnl)
    k = max(1, int(round(0.05 * pnl.size))) if pnl.size else 0
    return {"trades": int(pnl.size), "pnl": float(pnl.sum()), "position_s": pos_s, "mean_positions": pos_s / total_s, "notional_s": not_s, "mean_notional_exposure": not_s / total_s,
            "fees": float(sum(r["fee_in"] + r["fee_out"] for r in records)), "slippage": float(sum(r["slip"] for r in records)), "funding": float(sum(r["funding"] for r in records)),
            "profit_factor": float(gains / losses) if losses > 0 else None, "max_dd_realised": max_drawdown(eq)[0], "mean_hold_s": pos_s / max(pnl.size, 1),
            "worst_trade": float(pnl.min()) if pnl.size else None, "worst_trade_risk_multiple": float(max(-r["pnl"] / (r["notional"] * Book().risk_unit(SCENARIOS["base"])) for r in records)) if records else None,
            "top5_sum": float(srt[-k:].sum()) if k else 0.0, "bottom5_sum": float(srt[:k].sum()) if k else 0.0, "without_top5": float(srt[:-k].sum()) if k else 0.0,
            "partial_exits": int(sum(1 for r in records if r.get("passive_frac", 1.0) < 1.0 - 1e-12)),
            "stop_pnl": float(sum(r["pnl"] for r in records if r["reason"] == STOP)), "stops": int(sum(1 for r in records if r["reason"] == STOP))}


def seed_summary(ps: PortfolioSeeds, cand: dict, total_s: float, day_keys_cand: dict) -> dict:
    v = ps.v
    pnl = v["pnl"]
    surplus = cand["pnl"] - pnl
    keys = sorted(set(int(k) for k in day_keys_cand) | set(ps.daily))
    diffs = np.array([day_keys_cand.get(str(k), 0.0) - (ps.daily[k].mean() if k in ps.daily else 0.0) for k in keys])
    with np.errstate(invalid="ignore", divide="ignore"):
        pf = np.where(v["losses"] > 0, v["gains"] / v["losses"], np.nan)
    ratio = lambda a, b: (a / b - 1.0) if b else None
    out = {"seeds": int(ps.seeds), "trades_mean": float(v["trades"].mean()), "orders_mean": float(v["orders"].mean()), "pnl_mean": float(pnl.mean()), "pnl_median": float(np.median(pnl)),
           "pnl_p95": float(np.quantile(pnl, 0.95)), "fees_mean": float(v["fees"].mean()), "profit_factor_median": float(np.nanmedian(pf)) if np.isfinite(pf).any() else None,
           "max_dd_mean": float(v["max_dd"].mean()), "mean_positions": float(v["position_s"].mean() / total_s), "mean_notional_exposure": float(v["notional_s"].mean() / total_s),
           "mean_hold_s": float(v["position_s"].sum() / max(v["trades"].sum(), 1)), "refused_slots_mean": float(v["refused_slots"].mean()),
           "p_value": p_value(cand["pnl"], pnl), "surplus_mean": float(surplus.mean()), "surplus_quantiles": {str(q): float(np.quantile(surplus, q / 100)) for q in (5, 25, 50, 75, 95)},
           "surplus_day_blocks": bootstrap_total(diffs),
           "residual_trades": ratio(float(v["trades"].mean()), cand["trades"]), "residual_position_time": ratio(float(v["position_s"].mean()), cand["position_s"]),
           "residual_notional_exposure": ratio(float(v["notional_s"].mean()), cand["notional_s"]),
           "by_window_pnl_mean": {w: float(a.mean()) for w, a in ps.by_window.items()}}
    return out


# ----------------------------------------------------------------------------- analysis


def windows_of(phase: str) -> tuple[str, ...]:
    return qr.TRAINING_WINDOWS if phase == "training" else tuple(VALIDATION_WINDOWS)


def analyse(args) -> int:
    out = Path(args.out)
    phase = args.phase
    windows = windows_of(phase)
    if phase == "validation":
        claim = json.loads((out / "validation-claim.json").read_text())
        gate = json.loads(Path(args.gate).read_text())
        if gate.get("decision") != "GATE_PASS" or claim.get("gate_sha256") != hashlib.sha256(Path(args.gate).read_bytes()).hexdigest():
            raise SystemExit("validation refused: no passed gate matching the claim")
        candidates = tuple(gate["forwarded"])
        if (out / "results-c2.json").exists():
            raise SystemExit("validation already analysed once: refusing a second read")
    else:
        candidates = CANDIDATES
    prep = json.loads((out / "prepare-manifest.json").read_text())
    names = prep["series"]
    by_window: dict[str, list[int]] = defaultdict(list)
    for index, name in enumerate(names):
        w = name.split(":")[2]
        if w not in windows:
            raise SystemExit(f"window {w} is not part of phase {phase}")
        by_window[w].append(index)
    all_days = sorted({(sc._day_s(sc.BINANCE_WINDOWS[w][0]) // 86400) + k for w in windows for k in range(5)})
    day_index = {d: i for i, d in enumerate(all_days)}
    total_s = len(all_days) * 86400.0
    R = args.seeds
    nd = len(all_days)
    sig_acc = {(c, k): (np.zeros((R, nd)), np.zeros((R, nd))) for c in candidates for k in CONTROLS}
    sig_trades = {(c, k): np.zeros(R) for c in candidates for k in CONTROLS}
    sig_pair = {(c, k): defaultdict(lambda: np.zeros(2)) for c in candidates for k in CONTROLS}
    port = {(c, k): PortfolioSeeds(R) for c in candidates for k in CONTROLS}
    port_pess = {c: PortfolioSeeds(R) for c in candidates}
    band_unmatched = defaultdict(int)
    cand: dict = {c: defaultdict(list) for c in candidates}
    cand_counters = {c: defaultdict(int) for c in candidates}
    stress: dict = {c: defaultdict(list) for c in candidates}
    states = {(c, s): {"equity": 500.0, "records": [], "items": [], "window_marks": [], "daily": defaultdict(float), "refusals": defaultdict(int), "orders": 0, "partial": 0,
                       "partial_unfillable": 0, "partial_exit": 0, "by_window": {}}
              for c in candidates for s in ("base", "pessimistic", "stress")}
    started = time.time()
    for wi, window in enumerate(windows):
        qr.guard(out)
        loaded = []
        for index in by_window[window]:
            meta, payload = qr._load(out / "series" / f"{index:03d}.npz")
            cols = qr._table(payload, "t")
            if "pv_thr" not in cols:
                raise SystemExit(f"{meta['name']}: table prepared without the AMENDMENT-2 remainder columns")
            loaded.append((index, meta, payload, cols, corrected_view(cols, meta["n"], VARIANT, meta["start_s"])))
        if not loaded:
            continue
        n, start_s = loaded[0][1]["n"], loaded[0][1]["start_s"]
        assert all(m["n"] == n and m["start_s"] == start_s for _, m, _, _, _ in loaded)
        pools = hour_pools(start_s, n)
        days_rel = ((start_s + np.arange(n, dtype=np.int64)) // 86400) - start_s // 86400
        day_base = day_index[start_s // 86400]

        def dayidx(secs: np.ndarray) -> np.ndarray:
            return day_base + days_rel[secs]

        for c in candidates:
            ci = CONFIGS.index(c)
            z_key = "d_z_cal" if CONFIG_SIGMA[c] == "sigma_900" else "d_z_live"
            # Candidate, signal level.
            orders_strata = {}
            for index, meta, payload, cols, view in loaded:
                sig = payload[f"sig_{c}"]
                filled, cnt = standalone(view, sig, VARIANT, n, qr.BASE, count_decisions=True)
                for k, val in cnt.items():
                    cand_counters[c][k] += val
                for scn, costs in (("base", SCENARIOS["base"]), ("pessimistic", SCENARIOS["pessimistic"])):
                    nb = net_bp(view, filled, VARIANT, costs.maker_bp, costs.taker_bp, costs.slip_bp)
                    cand[c][f"net_{scn}"].append(nb["net"])
                    if scn == "base":
                        cand[c]["gross"].append(nb["gross"])
                        cand[c]["fees"].append(nb["fees"])
                pos = np.searchsorted(payload["d_s"], filled)
                cand[c]["day"].append(dayidx(filled))
                cand[c]["symbol"].append(np.array([meta["symbol"]] * filled.size))
                cand[c]["window"].append(np.array([window] * filled.size))
                cand[c]["exit_ts"].append(view["s_exit_ts"][filled])
                cand[c]["reason"].append(view["s_reason"][filled])
                cand[c]["frac"].append(view["_frac"][filled])
                cand[c]["mae"].append(payload["d_s_mae"][pos])
                cand[c]["z_same"].append(net_bp(corrected_view(cols, n, "Z3", start_s), filled, "Z3", 2.0, 5.0, 0.0)["net"])
                cand[c]["slip_stop"].append(view["s_slip_bp"][filled])
                orders = qr._orders(view, sig, VARIANT, n)
                opos = np.searchsorted(payload["d_s"], orders)
                orders_strata[index] = strata((start_s + orders) // 3600 % 24, payload[z_key][opos], payload["d_stale"][opos])
                sview = corrected_view(qr._full_stress(payload, n), n, VARIANT, start_s)
                sfilled, _ = standalone(sview, sig, VARIANT, n, qr.STRESS, count_decisions=True)
                stress[c]["net"].append(net_bp(sview, sfilled, VARIANT, 3.0, 7.5, 3.0)["net"])
            # Candidate portfolios.
            for scn in ("base", "pessimistic", "stress"):
                st = states[(c, scn)]
                streams = []
                for index, meta, payload, cols, view in loaded:
                    table = qr._full_stress(payload, n) if scn == "stress" else cols
                    sig = payload[f"sig_{c}"]
                    streams.append(Stream(meta["symbol"], start_s, n, meta["step"], meta["min_qty"], meta["min_notional"], table, sig[table["status"][sig] != NONE], index))
                book = Book(latency_us=qr.STRESS_LATENCY_US) if scn == "stress" else Book()
                res = run_window(streams, VARIANT, SCENARIOS["base" if scn == "base" else "pessimistic"], book, st["equity"], record=True, partial_exit=True)
                for r in res.records:
                    r["window"] = window
                    if scn != "stress":
                        payload = next(p for i, _, p, _, _ in loaded if i == r["series_id"])
                        k = int(np.searchsorted(payload["d_s"], r["s"]))
                        if k < payload["d_s"].size and payload["d_s"][k] == r["s"]:
                            off, ln = int(payload["d_path_off"][k]), int(payload["d_path_len"][k])
                            r["path"] = payload["d_path"][off:off + ln].tolist()
                st["window_marks"].append((window, st["equity"], res.equity))
                st["items"].extend(res.items)
                st["partial_unfillable"] += res.partial_unfillable
                st["records"].extend(res.records)
                st["by_window"][window] = res.pnl
                st["equity"] = res.equity
                st["orders"] += res.orders
                st["partial"] += res.partial
                st["partial_exit"] += res.partial_exit
                for k, val in res.refusals.items():
                    st["refusals"][k] += val
                for d, val in res.daily.items():
                    st["daily"][int(d)] += val
            # Controls.
            clusters = global_clusters({index: payload[f"sig_{c}"] for index, _, payload, _, _ in loaded})
            for seed in range(R):
                draws = {"cluster": cluster_shift(clusters, start_s, n, rng_for(3, ci, seed, wi), pools),
                         "episode": {index: shift_episodes(payload[f"sig_{c}"], start_s, n, rng_for(1, ci, seed, index), pools) for index, _, payload, _, _ in loaded}}
                band = {}
                for index, meta, payload, cols, view in loaded:
                    d, unmatched = band_state_draws(payload[f"pool_{c}_s"], payload[f"pool_{c}_st"], orders_strata[index], rng_for(2, ci, 1, seed, index))
                    band[index] = d
                    if seed == 0:
                        band_unmatched[c] += unmatched
                draws["band"] = band
                for kind, by_series in draws.items():
                    acc_s, acc_c = sig_acc[(c, kind)]
                    streams = []
                    for index, meta, payload, cols, view in loaded:
                        secs = by_series.get(index, np.zeros(0, dtype=np.int64))
                        secs = secs[cols["status"][secs] != NONE] if secs.size else secs
                        filled, _ = standalone(view, secs, VARIANT, n, qr.BASE)
                        if filled.size:
                            net = net_bp(view, filled, VARIANT, 2.0, 5.0, 0.0)["net"]
                            np.add.at(acc_s[seed], dayidx(filled), net)
                            np.add.at(acc_c[seed], dayidx(filled), 1.0)
                            sig_trades[(c, kind)][seed] += filled.size
                            pp = sig_pair[(c, kind)][meta["symbol"]]
                            pp += (net.sum(), filled.size)
                        streams.append(Stream(meta["symbol"], start_s, n, meta["step"], meta["min_qty"], meta["min_notional"], cols, secs, index))
                    res = run_window(streams, VARIANT, SCENARIOS["base"], Book(), float(port[(c, kind)].equity[seed]), record=True, partial_exit=True)
                    port[(c, kind)].add(seed, res, window)
                    if kind == "cluster":
                        res_p = run_window(streams, VARIANT, SCENARIOS["pessimistic"], Book(), float(port_pess[c].equity[seed]), record=True, partial_exit=True)
                        port_pess[c].add(seed, res_p, window)
        print(f"{qr._now()} {phase} window {window} done ({time.time() - started:.0f} s)", file=sys.stderr, flush=True)
    results = summarise(args, phase, candidates, cand, cand_counters, stress, states, sig_acc, sig_trades, sig_pair, port, port_pess, band_unmatched, all_days, total_s, windows)
    results["elapsed_seconds"] = round(time.time() - started, 1)
    target = out / "results-c2.json"
    target.write_text(json.dumps(results, indent=1, default=qr._json_default) + "\n")
    print(json.dumps({"phase": phase, "decision": results["decision"]}))
    return 0


def _cat(parts):
    return {k: np.concatenate(v) if v else np.zeros(0) for k, v in parts.items()}


def summarise(args, phase, candidates, cand, cand_counters, stress, states, sig_acc, sig_trades, sig_pair, port, port_pess, band_unmatched, all_days, total_s, windows) -> dict:
    res: dict = {"protocol": "scalp-q3-r2 / AMENDMENT-2", "phase": phase, "generated_at": qr._now(), "seeds": args.seeds, "code": sc.code_identity(),
                 "candidates": list(candidates), "windows": list(windows), "days": len(all_days), "cluster_gap_s": CLUSTER_GAP_S, "qref_notional": QREF_NOTIONAL, "rows": {}}
    day_window = np.repeat(np.arange(len(windows)), 5)
    pvals = {}
    for c in candidates:
        t = _cat(cand[c])
        net = t["net_base"]
        cnt = dict(cand_counters[c])
        day_sum = np.bincount(t["day"].astype(np.int64), weights=net, minlength=len(all_days)) if net.size else np.zeros(len(all_days))
        day_cnt = np.bincount(t["day"].astype(np.int64), minlength=len(all_days)).astype(float) if net.size else np.zeros(len(all_days))
        stopped = t["reason"] == STOP
        row = {"id": f"{c}-SL", "counters": cnt, "fill_rate": cnt["filled"] / cnt["orders"] if cnt.get("orders") else None,
               "signal": signal_summary(net, t["gross"], t["fees"]), "signal_pessimistic": signal_summary(t["net_pessimistic"], t["gross"], t["fees"]),
               "signal_stress": signal_summary(_cat(stress[c])["net"], _cat(stress[c])["net"], _cat(stress[c])["net"] * 0),
               "tails": tails(net, t["exit_ts"]), "partial_exits_signal": int((t["frac"] < 1 - 1e-12).sum()),
               "by_pair": qr._group_sums(t["symbol"], net), "by_window": qr._group_sums(t["window"], net),
               "stop": {"stops": int(stopped.sum()), "stopped_net_sum_bp": float(net[stopped].sum()), "gains_forgone_bp": float(np.maximum(t["z_same"][stopped] - net[stopped], 0).sum()),
                        "losses_avoided_bp": float(np.maximum(net[stopped] - t["z_same"][stopped], 0).sum()), "same_orders_z3_mean_bp": float(t["z_same"][stopped].mean()) if stopped.any() else None},
               "without_best_day_sum_bp": float(day_sum.sum() - day_sum.max()) if net.size else None, "controls": {}}
        for kind in CONTROLS:
            acc_s, acc_c = sig_acc[(c, kind)]
            with np.errstate(invalid="ignore", divide="ignore"):
                means = acc_s.sum(axis=1) / acc_c.sum(axis=1)
            mean = row["signal"].get("mean_net_bp")
            entry = {"signal": {"mean_bp": float(np.nanmean(means)), "median_bp": float(np.nanmedian(means)), "p95_bp": float(np.nanquantile(means, 0.95)),
                                "trades_per_seed": float(sig_trades[(c, kind)].mean()), "residual_trades": float(sig_trades[(c, kind)].mean() / max(net.size, 1) - 1),
                                "p_value": p_value(mean, means) if mean is not None else None,
                                "surplus_day_blocks": bootstrap_surplus(day_sum, day_cnt, acc_s.sum(axis=0), acc_c.sum(axis=0)),
                                "surplus_window_blocks": bootstrap_surplus(day_sum, day_cnt, acc_s.sum(axis=0), acc_c.sum(axis=0), blocks=day_window),
                                "by_pair_mean_bp": {k: float(v[0] / v[1]) for k, v in sorted(sig_pair[(c, kind)].items()) if v[1]}}}
            if kind == "band":
                entry["unmatched_orders_seed0"] = band_unmatched[c]
            row["controls"][kind] = entry
        row["portfolio"] = {}
        for scn in ("base", "pessimistic", "stress"):
            st = states[(c, scn)]
            ps = candidate_portfolio_stats(st["records"], total_s)
            ps.update({"orders": st["orders"], "refusals": dict(st["refusals"]), "partial_entries": st["partial"], "partial_exit_events": st["partial_exit"],
                       "by_window": st["by_window"], "daily": {str(k): v for k, v in sorted(st["daily"].items())}, "worst_day": min(st["daily"].values()) if st["daily"] else 0.0})
            metrics = qr.portfolio_metrics(st, all_days, 500.0)
            for key in ("max_dd_mtm_usdt", "max_dd_mtm_rel", "max_open_positions", "mean_open_positions", "max_gross_usdt", "mean_engaged_usdt", "max_engaged_usdt",
                        "min_available_usdt", "longest_loss_streak", "worst_day_usdt", "best_day_usdt", "days_negative", "days", "loss_p95_usdt", "loss_p99_usdt", "equity_hourly"):
                ps[key] = metrics.get(key)
            ps["max_dd_mtm"] = metrics.get("max_dd_mtm_usdt")
            ps["partial_exit_records"] = [{k: r[k] for k in ("symbol", "s", "fill_ts", "exit_ts", "qty", "entry", "exit", "rem_exit", "rem_reason", "passive_frac", "pnl")}
                                          for r in st["records"] if r.get("passive_frac", 1.0) < 1.0 - 1e-12]
            row["portfolio"][scn] = ps
        cand_daily = row["portfolio"]["base"]["daily"]
        row["portfolio_controls"] = {kind: seed_summary(port[(c, kind)], row["portfolio"]["base"], total_s, cand_daily) for kind in CONTROLS}
        pess = row["portfolio"]["pessimistic"]
        row["portfolio_controls"]["cluster_pessimistic"] = seed_summary(port_pess[c], pess, total_s, pess["daily"])
        pvals[c] = row["controls"]["cluster"]["signal"]["p_value"]
        res["rows"][f"{c}-SL"] = row
    res["holm"] = holm(pvals)
    if phase == "training":
        res["gate"] = gate(res)
        res["decision"] = res["gate"]["decision"]
    else:
        res["validation"] = validation_verdict(res)
        res["decision"] = res["validation"]["decision"]
    return res


def holm(pvals: dict) -> dict:
    items = sorted((p if p is not None else 1.0, c) for c, p in pvals.items())
    m = len(items)
    out, running = {}, 0.0
    for k, (p, c) in enumerate(items):
        running = max(running, min(1.0, (m - k) * p))
        out[c] = running
    return out


def comparable(row: dict) -> dict:
    pc = row["portfolio_controls"]["cluster"]
    sig = row["controls"]["cluster"]["signal"]
    checks = {"signal_trades": abs(sig["residual_trades"]) <= TOL_TRADES, "portfolio_trades": abs(pc["residual_trades"] or 0) <= TOL_TRADES,
              "position_time": abs(pc["residual_position_time"] or 0) <= TOL_EXPOSURE, "notional_exposure": abs(pc["residual_notional_exposure"] or 0) <= TOL_EXPOSURE}
    return {"checks": checks, "ok": all(checks.values()), "residuals": {"signal_trades": sig["residual_trades"], "portfolio_trades": pc["residual_trades"],
                                                                         "position_time": pc["residual_position_time"], "notional_exposure": pc["residual_notional_exposure"]}}


def gate(res: dict) -> dict:
    """AMENDMENT-2 §5: the frozen training criteria T1–T11 with the corrected engine and control, plus the comparability
    tolerances; a candidate that fails is withdrawn; none left → NO_GO_AFTER_CORRECTION; a comparability failure →
    STATISTICAL_BLOCKER for that candidate."""
    per = {}
    for key, row in res["rows"].items():
        sig = row["signal"]
        cl = row["controls"]["cluster"]["signal"]
        band = row["controls"]["band"]["signal"]
        base = row["portfolio"]["base"]
        pcl = row["portfolio_controls"]["cluster"]
        pairs = row["by_pair"]
        pos_pairs = sum(1 for p in pairs.values() if p["sum_bp"] > 0)
        pos_windows = sum(1 for w in row["by_window"].values() if w["sum_bp"] > 0)
        trimmed = row["tails"].get("trimmed_5_5_mean_bp")
        t = {"T1_trades": sig["trades"] >= 300, "T2_mean": (sig["mean_net_bp"] or -1) >= 2.0,
             "T3_surplus": (cl["p_value"] or 1) <= 0.01 and (cl["surplus_day_blocks"]["ci90"][0] or -1) > 0,
             "T4_flow": (band["p_value"] or 1) <= 0.05 and (band["surplus_day_blocks"]["point"] or -1) > 0,
             "T5_portfolio": base["pnl"] > 0 and base["trades"] >= 150 and (pcl["p_value"] or 1) <= 0.05,
             "T6_pessimistic": (row["signal_pessimistic"]["mean_net_bp"] or -1) > 0 and row["portfolio"]["pessimistic"]["pnl"] > 0,
             "T7_stress": (row["signal_stress"].get("mean_net_bp") or -1) > 0,
             "T8_consistency": len(pairs) >= 10 and pos_pairs / max(len(pairs), 1) >= 0.60 and pos_windows >= 5,
             "T9_tails": trimmed is not None and trimmed > 0 and (row["without_best_day_sum_bp"] or -1) > 0,
             "T10_risk": (base.get("max_dd_mtm") or 0) <= 50.0 and (base["worst_trade_risk_multiple"] or 0) <= 2.0 and base["worst_day"] >= -15.0,
             "T11_fill": (row["fill_rate"] or 0) >= 0.20}
        comp = comparable(row)
        status = "eligible" if all(t.values()) and comp["ok"] else ("statistical_blocker" if all(t.values()) else "withdrawn")
        per[key] = {"criteria": t, "comparability": comp, "status": status}
    forwarded = [k.split("-")[0] for k, v in per.items() if v["status"] == "eligible"]
    if forwarded:
        decision = "GATE_PASS"
    elif any(v["status"] == "statistical_blocker" for v in per.values()):
        decision = "STATISTICAL_BLOCKER"
    else:
        decision = "NO_GO_AFTER_CORRECTION"
    return {"per_candidate": per, "forwarded": forwarded, "decision": decision}


def validation_verdict(res: dict) -> dict:
    """AMENDMENT-2 §6 / PREREGISTRATION §9: the twelve validation criteria, Holm over the forwarded candidates."""
    per = {}
    for key, row in res["rows"].items():
        c = key.split("-")[0]
        sig = row["signal"]
        cl = row["controls"]["cluster"]["signal"]
        base = row["portfolio"]["base"]
        pairs = row["by_pair"]
        pos_pairs = sum(1 for p in pairs.values() if p["sum_bp"] > 0)
        dd_limit = 0.10 * 500.0
        # V1 is the frozen §9 count (signal level, like T1); the portfolio's executed trades are reported beside it.
        v = {"V1_trades_150": sig["trades"] >= 150, "V2_mean_net_positive": (sig["mean_net_bp"] or -1) > 0, "V3_pf_1.05": (sig["profit_factor"] or 0) >= 1.05,
             "V4_surplus_positive": (cl["surplus_day_blocks"]["point"] or -1) > 0, "V5_holm_p_0.05": res["holm"].get(c, 1.0) <= 0.05,
             "V6_ci90_lb_positive": (cl["surplus_day_blocks"]["ci90"][0] or -1) > 0,
             "V7_pairs": len(pairs) >= 10 and pos_pairs / max(len(pairs), 1) >= 0.50, "V8_pessimistic_mean_positive": (row["signal_pessimistic"]["mean_net_bp"] or -1) > 0,
             "V9_trimmed_positive": (row["tails"].get("trimmed_5_5_mean_bp") or -1) > 0, "V10_portfolio_pnl_positive": base["pnl"] > 0,
             "V11_dd_10pct": (base["max_dd_mtm"] if base.get("max_dd_mtm") is not None else base["max_dd_realised"]) <= dd_limit, "V12_worst_trade_2x": (base["worst_trade_risk_multiple"] or 0) <= 2.0}
        per[key] = {"criteria": v, "passes": all(v.values()), "pairs_with_trades": len(pairs), "pairs_positive": pos_pairs, "portfolio_trades": base["trades"]}
    # AMENDMENT-2 §6: judged per candidate; VALIDATION_PASS when at least one forwarded candidate passes all twelve (only those go on).
    decision = "VALIDATION_PASS" if any(x["passes"] for x in per.values()) else "VALIDATION_FAIL"
    return {"per_candidate": per, "decision": decision, "passing": [k for k, v in per.items() if v["passes"]]}


# ----------------------------------------------------------------------------- validation claim and preparation


def claim(args) -> int:
    """One-shot claim (AMENDMENT-2 §6): records the frozen amendment and the passed gate before any November file is read."""
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    target = out / "validation-claim.json"
    if target.exists():
        raise SystemExit("claim already exists: November is read once")
    actual = hashlib.sha256(Path(args.amendment).read_bytes()).hexdigest()
    if actual != args.expected_sha256:
        raise SystemExit(f"amendment hash mismatch {actual}")
    gate_doc = json.loads(Path(args.gate).read_text())
    if gate_doc.get("decision") != "GATE_PASS":
        raise SystemExit(f"gate is {gate_doc.get('decision')}: November stays closed")
    doc = {"claimed_at": qr._now(), "amendment_sha256": actual, "gate_sha256": hashlib.sha256(Path(args.gate).read_bytes()).hexdigest(), "forwarded": gate_doc["forwarded"],
           "windows": VALIDATION_WINDOWS}
    target.write_text(json.dumps(doc, indent=1) + "\n")
    print(json.dumps(doc))
    return 0


def prepare(args) -> int:
    out = Path(args.out)
    if args.phase != "validation" or not (out / "validation-claim.json").exists():
        raise SystemExit("prepare here is for the claimed validation only (training: q3r2_run prepare)")
    args.windows = tuple(VALIDATION_WINDOWS)
    return qr.prepare(args)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_exec.q3r2_c2")
    sub = parser.add_subparsers(dest="command", required=True)
    a = sub.add_parser("analyse")
    a.add_argument("--out", required=True)
    a.add_argument("--phase", choices=("training", "validation"), required=True)
    a.add_argument("--seeds", type=int, default=1000)
    a.add_argument("--gate", default="")
    c = sub.add_parser("claim")
    c.add_argument("--out", required=True)
    c.add_argument("--amendment", required=True)
    c.add_argument("--expected-sha256", required=True)
    c.add_argument("--gate", required=True)
    p = sub.add_parser("prepare")
    p.add_argument("--out", required=True)
    p.add_argument("--tape-dir", required=True)
    p.add_argument("--instruments", required=True)
    p.add_argument("--funding-dir", required=True)
    p.add_argument("--workers", type=int, default=2)
    p.add_argument("--prereg", default="")
    p.add_argument("--limit", type=int, default=0)
    p.add_argument("--phase", default="validation")
    args = parser.parse_args(argv)
    if args.command == "analyse":
        return analyse(args)
    if args.command == "claim":
        return claim(args)
    return prepare(args)


if __name__ == "__main__":
    sys.exit(main())
