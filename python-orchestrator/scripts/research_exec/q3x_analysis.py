"""q3x (EXPLORATORY-q3x.md §Mesures 3-4): tails, MAE / MFE and quiet seconds of the Q3 ablations.

Reads the screen outputs of ``--protocol q3x`` (per-trade outcomes) and replays the bars of every series that holds a
trade, to measure the worst / best bid between the fill and the exit and the share of quiet seconds at the decision.
Nothing here selects or changes a rule.

    python -m scripts.research_exec.q3x_analysis --screen DIR/screen-training.json --tape-dir TAPE \
        --instruments FILE --out DIR/q3x-analysis.json
"""

from __future__ import annotations

import argparse
import json
from collections import defaultdict
from pathlib import Path

import numpy as np

from . import screen as sc

CONFIGS = ("QB-W900-K1.5|long|E1|Z3", "S7-W900-K1.5|long|E1|Z3", "QD-W900-K1.5-T0.1|long|E1|Z3",
           "Q3-W900-K1.5-T0.1|long|E1|Z3", "Q3L-W900-K1.5-T0.1|long|E1|Z3")
MAJORS = {"BTCUSDT", "ETHUSDT", "SOLUSDT", "XRPUSDT", "DOGEUSDT", "BNBUSDT", "ADAUSDT", "LTCUSDT", "LINKUSDT", "AVAXUSDT"}


def _outcomes(screen_path: Path, config: str) -> np.ndarray:
    path = screen_path.parent / "screen-training-outcomes" / (config.replace("|", "__") + ".npz")
    with np.load(path) as z:
        return z["trades"]


def tails(net: np.ndarray) -> dict:
    if net.size == 0:
        return {"trades": 0}
    order = np.sort(net)
    k = max(1, int(round(0.05 * net.size)))
    total = float(net.sum())
    losses = net < 0
    runs, cur = 0, 0
    for x in losses:
        cur = cur + 1 if x else 0
        runs = max(runs, cur)
    return {"trades": int(net.size), "mean_bp": float(net.mean()), "sum_bp": total,
            "quantiles_bp": {q: float(np.quantile(net, q / 100)) for q in (1, 5, 50, 95, 99)},
            "worst10_bp": [float(x) for x in order[:10]],
            "top5pct_sum_bp": float(order[-k:].sum()), "bottom5pct_sum_bp": float(order[:k].sum()),
            "without_top5pct_mean_bp": float(order[:-k].mean()) if net.size > k else None,
            "without_bottom5pct_mean_bp": float(order[k:].mean()) if net.size > k else None,
            "longest_loss_streak": int(runs)}


def analyse(screen_path: Path, tape_dir: Path, instruments: Path) -> dict:
    doc = json.loads(screen_path.read_text())
    names = [item["name"] for item in doc["series"]]  # "binance:SYMBOL:window"
    trades = {c: _outcomes(screen_path, c) for c in CONFIGS}
    needed = sorted({int(s) for c in ("Q3-W900-K1.5-T0.1|long|E1|Z3", "QB-W900-K1.5|long|E1|Z3") for s in trades[c]["series"]})
    sc.use_protocol("q3x")
    spec = sc.PROTOCOLS["q3x"]
    series, _ = sc.load_binance_series(tape_dir, instruments, "training", symbols=spec["symbols"],
                                       windows=spec["training_windows"], depth=True)
    by_name = {s.name: s for s in series}
    excursions = {c: [] for c in ("Q3-W900-K1.5-T0.1|long|E1|Z3", "QB-W900-K1.5|long|E1|Z3")}
    stale_at = defaultdict(list)
    quiet_share = {}
    for sid in needed:
        s = by_name[names[sid]]
        data = s.data(sid)
        bars, feats = data.bars, data.features
        start = int(bars["ts"][0])
        quiet_share[names[sid]] = float((bars["n"] == 0).mean()) if "n" in bars else None
        for c in excursions:
            t = trades[c][trades[c]["series"] == sid]
            for row in t:
                i0 = int(row["fill_ts"] // 1_000_000) - start
                i1 = int(row["exit_ts"] // 1_000_000) - start
                bid = bars["bid"][max(i0, 0):max(i1, i0 + 1) + 1]
                bid = bid[np.isfinite(bid)]
                if bid.size == 0:
                    continue
                excursions[c].append(((bid.min() / row["entry"] - 1) * 1e4, (bid.max() / row["entry"] - 1) * 1e4, float(row["net_bp"])))
        t3 = trades["Q3-W900-K1.5-T0.1|long|E1|Z3"]
        for row in t3[t3["series"] == sid]:
            d = int(row["decision_s"]) - start
            if 0 <= d < feats["stale_900"].size:
                stale_at["Q3"].append((float(feats["stale_900"][d]), float(row["net_bp"])))
        del data
    out = {"configs": {}, "excursions": {}, "stale": {}, "quiet_share_by_series": quiet_share}
    for c in CONFIGS:
        t = trades[c]
        net = t["net_bp"].astype(float)
        sym = np.array([names[int(s)].split(":")[1] for s in t["series"]])
        day = (t["exit_ts"] // 86_400_000_000).astype(np.int64)
        daily = defaultdict(float)
        for d, v in zip(day, net):
            daily[int(d)] += float(v)
        by_pair = defaultdict(list)
        for p, v in zip(sym, net):
            by_pair[str(p)].append(float(v))
        out["configs"][c] = {
            "tails": tails(net),
            "worst_day_bp": min(daily.values()) if daily else None, "best_day_bp": max(daily.values()) if daily else None,
            "days": len(daily), "negative_days": sum(v < 0 for v in daily.values()),
            "by_pair": {p: {"trades": len(v), "mean_bp": float(np.mean(v)), "sum_bp": float(np.sum(v))} for p, v in sorted(by_pair.items())},
            "majors_mean_bp": float(net[[s in MAJORS for s in sym]].mean()) if any(s in MAJORS for s in sym) else None,
            "midcaps_mean_bp": float(net[[s not in MAJORS for s in sym]].mean()) if any(s not in MAJORS for s in sym) else None,
        }
    for c, rows in excursions.items():
        if not rows:
            continue
        a = np.array(rows)
        out["excursions"][c] = {"trades": len(rows),
                                "mae_quantiles_bp": {q: float(np.quantile(a[:, 0], q / 100)) for q in (1, 5, 25, 50)},
                                "mfe_quantiles_bp": {q: float(np.quantile(a[:, 1], q / 100)) for q in (50, 75, 95, 99)},
                                "share_mae_below": {str(x): float((a[:, 0] < -x).mean()) for x in (25, 50, 100, 200)},
                                "net_of_trades_mae_below_100bp": float(a[a[:, 0] < -100, 2].mean()) if (a[:, 0] < -100).any() else None}
    if stale_at["Q3"]:
        a = np.array(stale_at["Q3"])
        out["stale"]["Q3"] = {lab: {"trades": int(m.sum()), "mean_bp": float(a[m, 1].mean()) if m.any() else None}
                              for lab, m in (("<5%", a[:, 0] < 0.05), ("5-20%", (a[:, 0] >= 0.05) & (a[:, 0] < 0.20)), (">20%", a[:, 0] >= 0.20))}
        out["stale"]["Q3_decision_stale_quantiles"] = {q: float(np.quantile(a[:, 0], q / 100)) for q in (50, 90, 99)}
    q3, q3l = trades["Q3-W900-K1.5-T0.1|long|E1|Z3"], trades["Q3L-W900-K1.5-T0.1|long|E1|Z3"]
    k3 = {(int(r["series"]), int(r["decision_s"])) for r in q3}
    kl = {(int(r["series"]), int(r["decision_s"])) for r in q3l}
    only3 = [float(r["net_bp"]) for r in q3 if (int(r["series"]), int(r["decision_s"])) not in kl]
    onlyl = [float(r["net_bp"]) for r in q3l if (int(r["series"]), int(r["decision_s"])) not in k3]
    out["stale"]["overlap"] = {"q3": len(k3), "q3l": len(kl), "both": len(k3 & kl),
                               "q3_only_mean_bp": float(np.mean(only3)) if only3 else None, "q3_only": len(only3),
                               "q3l_only_mean_bp": float(np.mean(onlyl)) if onlyl else None, "q3l_only": len(onlyl)}
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--screen", type=Path, required=True)
    ap.add_argument("--tape-dir", type=Path, required=True)
    ap.add_argument("--instruments", type=Path, required=True)
    ap.add_argument("--out", type=Path, required=True)
    a = ap.parse_args()
    a.out.write_text(json.dumps(analyse(a.screen, a.tape_dir, a.instruments), indent=1))
    print(a.out)


if __name__ == "__main__":
    main()
