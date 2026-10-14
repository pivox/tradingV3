"""scalp-q3-r2 corrective pass, phase A1: why the episode-shift control executes more trades.

Reads the prepared training tables (`run/series/*.npz`, already consumed), replays the candidate
and the episode-shift control (old method, the same seeds as `analyse`) for C2-SL and C4-SL, and
measures orders, fills, exposure, the clustering of the signal episodes and how often an episode
starts while its pair is still busy. Descriptive only: nothing here selects or changes a rule.

    python -m scripts.research_exec.q3r2_diag --out RUN_DIR --seeds 100 > diag.json
"""

from __future__ import annotations

import argparse
import json
from collections import defaultdict
from pathlib import Path

import numpy as np

from .q3r2_engine import CONFIGS, NONE, standalone
from .q3r2_portfolio import SCENARIOS, Book, Stream, run_window
from .q3r2_run import BASE, TRAINING_WINDOWS, _load, _table
from .q3r2_stats import episodes, hour_pools, rng_for, shift_episodes
from .tick import SECOND_US

GAPS = (60, 300, 900, 3900)


def busy_share(cols: dict, signal: np.ndarray, variant: str, n: int) -> tuple[int, int, int]:
    """Episodes, episodes whose first second falls while the pair holds an order or a position (one-per-pair replay),
    and orders placed."""
    sig = signal[cols["status"][signal] != NONE] if signal.size else signal
    starts, _ = episodes(signal)
    filled, cnt = standalone(cols, sig, variant, n, BASE)
    # Busy intervals of the replay: reconstruct from the orders it placed.
    busy_col = cols["z_busy" if variant == "Z3" else "s_busy"]
    status = cols["status"]
    intervals = []
    idx, busy = 0, -1
    while idx < sig.size:
        s = int(sig[idx])
        st = int(status[s])
        end = s + 6 if st == 1 else s + 31 if st == 2 else int(busy_col[s]) if st == 3 else n
        intervals.append((s, end))
        idx = int(np.searchsorted(sig, end, side="left"))
    if not intervals:
        return int(starts.size), 0, 0
    a = np.array([i[0] for i in intervals])
    b = np.array([i[1] for i in intervals])
    pos = np.searchsorted(a, starts, side="right") - 1
    inside = (pos >= 0) & (starts > a[np.maximum(pos, 0)]) & (starts < b[np.maximum(pos, 0)])
    return int(starts.size), int(inside.sum()), int(cnt["orders"])


def exposure(res) -> dict:
    """Position-seconds, notional-seconds and order-reservation seconds of a recorded window result."""
    pos_s = sum((r["exit_ts"] - r["fill_ts"]) / SECOND_US for r in res.records)
    notional_s = sum(r["notional"] * (r["exit_ts"] - r["fill_ts"]) / SECOND_US for r in res.records)
    fees = sum(r["fee_in"] + r["fee_out"] for r in res.records)
    return {"position_s": pos_s, "notional_s": notional_s, "fees": fees}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", type=Path, required=True)
    ap.add_argument("--seeds", type=int, default=100)
    ap.add_argument("--configs", default="C2,C4")
    a = ap.parse_args()
    names = json.loads((a.out / "prepare-manifest.json").read_text())["series"]
    by_window = defaultdict(list)
    for index, name in enumerate(names):
        by_window[name.split(":")[2]].append(index)
    configs = a.configs.split(",")
    agg = {c: defaultdict(float) for c in configs}
    gaps = {c: defaultdict(int) for c in configs}
    ctrl_port = {c: np.zeros((a.seeds, 5)) for c in configs}  # trades, orders, position_s, notional_s, pnl
    ctrl_equity = {c: np.full(a.seeds, 500.0) for c in configs}
    cand_equity = {c: 500.0 for c in configs}
    for window in TRAINING_WINDOWS:
        loaded = []
        for index in by_window[window]:
            meta, payload = _load(a.out / "series" / f"{index:03d}.npz")
            loaded.append((index, meta, payload, _table(payload, "t")))
        pools = {index: hour_pools(meta["start_s"], meta["n"]) for index, meta, _, _ in loaded}
        for c in configs:
            ci = CONFIGS.index(c)
            for index, meta, payload, cols in loaded:
                sig = payload[f"sig_{c}"]
                starts, lengths = episodes(sig)
                agg[c]["signal_seconds"] += sig.size
                agg[c]["episodes"] += starts.size
                if starts.size > 1:
                    ends = starts[:-1] + lengths[:-1]
                    gap = starts[1:] - ends
                    for g in GAPS:
                        gaps[c][f"next_episode_within_{g}s"] += int((gap < g).sum())
                    gaps[c]["pairs_of_consecutive_episodes"] += int(gap.size)
                ep, inside, orders = busy_share(cols, sig, "SL", meta["n"])
                agg[c]["cand_episodes_starting_busy"] += inside
                agg[c]["cand_standalone_orders"] += orders
                for seed in range(min(a.seeds, 20)):
                    sh = shift_episodes(sig, meta["start_s"], meta["n"], rng_for(1, ci, seed, index), pools[index])
                    ep2, inside2, orders2 = busy_share(cols, sh, "SL", meta["n"])
                    agg[c]["ctrl_signal_seconds"] += sh.size / min(a.seeds, 20)
                    agg[c]["ctrl_episodes"] += ep2 / min(a.seeds, 20)
                    agg[c]["ctrl_episodes_starting_busy"] += inside2 / min(a.seeds, 20)
                    agg[c]["ctrl_standalone_orders"] += orders2 / min(a.seeds, 20)
            streams = []
            for index, meta, payload, cols in loaded:
                sig = payload[f"sig_{c}"]
                streams.append(Stream(meta["symbol"], meta["start_s"], meta["n"], meta["step"], meta["min_qty"], meta["min_notional"], cols, sig[cols["status"][sig] != NONE], index))
            res = run_window(streams, "SL", SCENARIOS["base"], Book(), cand_equity[c], record=True)
            cand_equity[c] = res.equity
            ex = exposure(res)
            agg[c]["cand_portfolio_trades"] += res.trades
            agg[c]["cand_portfolio_orders"] += res.orders
            agg[c]["cand_position_s"] += ex["position_s"]
            agg[c]["cand_notional_s"] += ex["notional_s"]
            agg[c]["cand_fees"] += ex["fees"]
            agg[c]["cand_refused_slots"] += res.refusals["slots"]
            for seed in range(a.seeds):
                cs = []
                for index, meta, payload, cols in loaded:
                    sh = shift_episodes(payload[f"sig_{c}"], meta["start_s"], meta["n"], rng_for(1, ci, seed, index), pools[index])
                    sh = sh[cols["status"][sh] != NONE] if sh.size else sh
                    cs.append(Stream(meta["symbol"], meta["start_s"], meta["n"], meta["step"], meta["min_qty"], meta["min_notional"], cols, sh, index))
                r2 = run_window(cs, "SL", SCENARIOS["base"], Book(), float(ctrl_equity[c][seed]), record=True)
                ctrl_equity[c][seed] = r2.equity
                e2 = exposure(r2)
                ctrl_port[c][seed] += (r2.trades, r2.orders, e2["position_s"], e2["notional_s"], r2.pnl)
                agg[c]["ctrl_fees"] += e2["fees"] / a.seeds
                agg[c]["ctrl_refused_slots"] += r2.refusals["slots"] / a.seeds
    total_s = 45 * 86400
    out = {}
    for c in configs:
        x = dict(agg[c])
        x["cand_pnl"] = cand_equity[c] - 500.0
        cp = ctrl_port[c]
        x.update({"ctrl_portfolio_trades": float(cp[:, 0].mean()), "ctrl_portfolio_orders": float(cp[:, 1].mean()), "ctrl_position_s": float(cp[:, 2].mean()),
                  "ctrl_notional_s": float(cp[:, 3].mean()), "ctrl_pnl_mean": float(cp[:, 4].mean()), "ctrl_pnl_per_trade": float(cp[:, 4].sum() / cp[:, 0].sum()),
                  "cand_mean_notional_exposure": x["cand_notional_s"] / total_s, "ctrl_mean_notional_exposure": float(cp[:, 3].mean()) / total_s,
                  "cand_mean_positions": x["cand_position_s"] / total_s, "ctrl_mean_positions": float(cp[:, 2].mean()) / total_s,
                  "cand_mean_hold_s": x["cand_position_s"] / max(x["cand_portfolio_trades"], 1), "ctrl_mean_hold_s": float(cp[:, 2].sum() / cp[:, 0].sum())})
        x["gaps"] = dict(gaps[c])
        out[c] = x
    print(json.dumps(out, indent=1))


if __name__ == "__main__":
    main()
