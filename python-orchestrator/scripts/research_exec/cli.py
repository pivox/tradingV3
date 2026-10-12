"""Command line of the execution study.

    python -m scripts.research_exec.cli extract --dataset DIR --out venue.npz --receipt venue.receipt.json
    python -m scripts.research_exec.cli simulate --extract venue.npz --out venue.orders.npz \
        --summary venue.summary.json [--rule through|touch] [--latency-ms 250] [--ttl-s 45] ...
    python -m scripts.research_exec.cli report --summaries a.json b.json --out report.md
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import capture, report, stats
from .simulate import Parameters, simulate_extract


def code_identity() -> dict[str, str]:
    package = Path(__file__).resolve().parent
    return {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in sorted(package.glob("*.py"))}


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _json_default(value):
    if isinstance(value, (np.integer,)):
        return int(value)
    if isinstance(value, (np.floating,)):
        return None if np.isnan(value) else float(value)
    if isinstance(value, np.ndarray):
        return value.tolist()
    raise TypeError(f"not serialisable: {type(value)}")


def command_extract(args: argparse.Namespace) -> int:
    started = time.time()
    symbols = tuple(args.symbols.split(",")) if args.symbols else None
    extracted = capture.extract(Path(args.dataset), symbols)
    capture.save_extract(extracted, Path(args.out))
    receipt = {
        **extracted.receipt,
        "extracted_at": _now(),
        "elapsed_seconds": round(time.time() - started, 1),
        "code": code_identity(),
        "instruments": {symbol: instrument.to_dict() for symbol, instrument in extracted.instruments.items()},
    }
    Path(args.receipt).write_text(json.dumps(receipt, indent=1, sort_keys=True, default=_json_default) + "\n")
    print(json.dumps({"venue": extracted.venue, "symbols": receipt["symbols"], "elapsed_seconds": receipt["elapsed_seconds"]}))
    return 0


def command_simulate(args: argparse.Namespace) -> int:
    started = time.time()
    extracted = capture.load_extract(Path(args.extract))
    params = Parameters(
        grid_s=args.grid_s, latency_ms=args.latency_ms, ttl_s=args.ttl_s, stale_s=args.stale_s,
        maker_bp=args.maker_bp, taker_bp=args.taker_bp, rule=args.rule,
    )
    orders = simulate_extract(extracted.quotes, extracted.trades, extracted.instruments, params)
    np.savez_compressed(Path(args.out), **{symbol: rows for symbol, rows in orders.items()})
    summaries = {symbol: stats.summarize_symbol(rows, params.maker_bp) for symbol, rows in orders.items()}
    document = {
        "venue": extracted.venue,
        "dataset_id": extracted.dataset_id,
        "parameters": params.to_dict(),
        "symbols": summaries,
        "verdict": stats.verdict(summaries, params.rule),
        "generated_at": _now(),
        "elapsed_seconds": round(time.time() - started, 1),
        "code": code_identity(),
    }
    Path(args.summary).write_text(json.dumps(document, indent=1, sort_keys=True, default=_json_default) + "\n")
    print(json.dumps({"venue": extracted.venue, "rule": params.rule, "maker_edge": document["verdict"]["maker_edge"], "elapsed_seconds": document["elapsed_seconds"]}))
    return 0


def command_report(args: argparse.Namespace) -> int:
    summaries = [json.loads(Path(path).read_text()) for path in args.summaries]
    receipts = [json.loads(Path(path).read_text()) for path in args.receipts] if args.receipts else []
    Path(args.out).write_text(report.render(summaries, receipts))
    print(json.dumps({"report": args.out, "summaries": len(summaries)}))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_exec")
    sub = parser.add_subparsers(dest="command", required=True)
    extract = sub.add_parser("extract")
    extract.add_argument("--dataset", required=True)
    extract.add_argument("--out", required=True)
    extract.add_argument("--receipt", required=True)
    extract.add_argument("--symbols", default="")
    extract.set_defaults(func=command_extract)
    simulate = sub.add_parser("simulate")
    simulate.add_argument("--extract", required=True)
    simulate.add_argument("--out", required=True)
    simulate.add_argument("--summary", required=True)
    simulate.add_argument("--rule", choices=("through", "touch"), default="through")
    simulate.add_argument("--grid-s", type=float, default=5.0)
    simulate.add_argument("--latency-ms", type=float, default=250.0)
    simulate.add_argument("--ttl-s", type=float, default=45.0)
    simulate.add_argument("--stale-s", type=float, default=10.0)
    simulate.add_argument("--maker-bp", type=float, default=2.0)
    simulate.add_argument("--taker-bp", type=float, default=5.0)
    simulate.set_defaults(func=command_simulate)
    rep = sub.add_parser("report")
    rep.add_argument("--summaries", nargs="+", required=True)
    rep.add_argument("--receipts", nargs="*", default=[])
    rep.add_argument("--out", required=True)
    rep.set_defaults(func=command_report)
    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
