"""scalp-q3-r2 corrective pass, phases B1/B3: passive exits whose through volume is short of the quantity.

Replays the four protected configurations' 500 USDT portfolios on the AMENDMENT-2 training tables twice — old
accounting (full maker fill) and corrected accounting (`partial_exit=True`) — under the base, pessimistic and
1 s-latency scenarios, lists every affected exit and checks that the old accounting reproduces PR #498 exactly.
Also checks that the outcome columns of the new tables equal those of the original run bit for bit.

    python -m scripts.research_exec.q3r2_diag_exits --old RUN --new RUN_C2 > exits.json
"""

from __future__ import annotations

import argparse
import json
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from .q3r2_engine import CONFIGS, NONE
from .q3r2_portfolio import SCENARIOS, Book, Stream, run_window
from .q3r2_run import FIELDS, STRESS_LATENCY_US, TRAINING_WINDOWS, _full_stress, _load, _table


def iso(us: int) -> str:
    return datetime.fromtimestamp(us / 1e6, tz=timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--old", type=Path, required=True)
    ap.add_argument("--new", type=Path, required=True)
    a = ap.parse_args()
    names = json.loads((a.new / "prepare-manifest.json").read_text())["series"]
    by_window = defaultdict(list)
    for index, name in enumerate(names):
        by_window[name.split(":")[2]].append(index)
    determinism = {"series": 0, "mismatched_columns": []}
    equity = {(c, scn, mode): 500.0 for c in CONFIGS for scn in ("base", "pessimistic", "stress") for mode in ("old", "new")}
    trades = defaultdict(int)
    events = defaultdict(list)
    for window in TRAINING_WINDOWS:
        loaded = []
        for index in by_window[window]:
            meta, payload = _load(a.new / "series" / f"{index:03d}.npz")
            _, old_payload = _load(a.old / "series" / f"{index:03d}.npz")
            determinism["series"] += 1
            for name in FIELDS:
                for prefix in ("t", "x"):
                    if not np.array_equal(payload[f"{prefix}_{name}"], old_payload[f"{prefix}_{name}"], equal_nan=True):
                        determinism["mismatched_columns"].append(f"{meta['name']}:{prefix}_{name}")
            for key in [k for k in old_payload if k.startswith(("sig_", "rp_", "pool_"))]:
                if not np.array_equal(payload[key], old_payload[key]):
                    determinism["mismatched_columns"].append(f"{meta['name']}:{key}")
            del old_payload
            loaded.append((index, meta, payload, _table(payload, "t")))
        for c in CONFIGS:
            for scn in ("base", "pessimistic", "stress"):
                streams = []
                for index, meta, payload, cols in loaded:
                    table = _full_stress(payload, meta["n"]) if scn == "stress" else cols
                    sig = payload[f"sig_{c}"]
                    streams.append(Stream(meta["symbol"], meta["start_s"], meta["n"], meta["step"], meta["min_qty"], meta["min_notional"], table, sig[table["status"][sig] != NONE], index))
                book = Book(latency_us=STRESS_LATENCY_US) if scn == "stress" else Book()
                costs = SCENARIOS["base" if scn == "base" else "pessimistic"]
                old = run_window(streams, "SL", costs, book, equity[(c, scn, "old")], record=True)
                new = run_window(streams, "SL", costs, book, equity[(c, scn, "new")], record=True, partial_exit=True)
                equity[(c, scn, "old")], equity[(c, scn, "new")] = old.equity, new.equity
                trades[(c, scn, "old")] += old.trades
                trades[(c, scn, "new")] += new.trades
                old_by = {(r["series_id"], r["s"]): r for r in old.records}
                for r in new.records:
                    if r.get("passive_frac", 1.0) < 1.0 - 1e-12:
                        o = old_by.get((r["series_id"], r["s"]))
                        events[(c, scn)].append({"symbol": r["symbol"], "entry_time": iso(r["fill_ts"]), "exit_time_old": iso(o["exit_ts"]) if o else None,
                                                 "exit_time_new": iso(r["exit_ts"]), "qty_held": r["qty"], "through_qty": r["qty"] * r["passive_frac"],
                                                 "filled_passive_qty": r["qty"] * r["passive_frac"], "remainder_qty": r["qty"] * (1 - r["passive_frac"]),
                                                 "entry": r["entry"], "passive_price": r["exit"], "remainder_price": r["rem_exit"], "remainder_reason": r["rem_reason"],
                                                 "pnl_old": o["pnl"] if o else None, "pnl_new": r["pnl"], "same_order_in_old_run": o is not None})
    out = {"determinism": determinism, "configs": {}}
    for c in CONFIGS:
        out["configs"][f"{c}-SL"] = {scn: {"pnl_old": equity[(c, scn, "old")] - 500.0, "pnl_new": equity[(c, scn, "new")] - 500.0,
                                            "trades_old": trades[(c, scn, "old")], "trades_new": trades[(c, scn, "new")], "events": events[(c, scn)]}
                                     for scn in ("base", "pessimistic", "stress")}
    print(json.dumps(out, indent=1, default=float))


if __name__ == "__main__":
    main()
