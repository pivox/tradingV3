"""Universe root for the OKX swing v2 campaign: one series per instrument segment, its observed metadata.

Each member series of ``universe-monthly-v2.json`` (``<instId>`` or ``<instId>#<n>`` for an instrument whose history
splits into segments) becomes its own candle series, frame, funding list and instrument record:

- candles and funding restricted to the segment's span, cut at the end of the OKX data;
- ``contract_size`` = the contract value observed in the segment (REST ``volCcy / vol`` or archive base / contract
  volume; never a later value applied backwards: a segment with a change is reported and uses its first value);
- ``tick_size`` / ``quantity_step`` = the increments observed in the segment's archive (prices; contract volumes),
  ``min_quantity`` = one lot; no minimum notional beyond the 5-USDT floor. Current OKX metadata are reported beside
  them in the audit, not used.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from app.backtesting.research import swing as sw

from .data import OKX_END_MS, assemble_candles, assemble_funding, sha256_file
from .engine import FOUR_HOURS


def series_files(tape: Path, inst: str) -> list[Path]:
    return sorted((tape / "klines" / inst).glob(f"{inst}-1m-*.npz"), key=lambda p: p.name)


def build(audit_path: Path, universe_path: Path, tape: Path, costs: Path, out: Path) -> dict:
    audits = json.loads(audit_path.read_text())["instruments"]
    members = json.loads(universe_path.read_text())["members"]
    (out / "candles").mkdir(parents=True, exist_ok=True)
    (out / "features").mkdir(exist_ok=True)
    instruments, funding_doc, gaps_doc, receipt = [], {}, {}, {"end_ms_exclusive": OKX_END_MS, "series": {}}
    for series in members:
        inst = series.split("#")[0]
        segment = next(s for s in audits[inst]["segments"] if s["series"] == series)
        begin, end = segment["start_s"] * 1000, min(segment["end_s"] * 1000, OKX_END_MS)
        candles, candle_receipt = assemble_candles(series_files(tape, inst), series, end, begin)
        candles.write(out / "candles" / f"{series}.candles.bin", {"source": str(tape), "segment": [begin, end]})
        frame = sw.build_frame(series, sw.aggregate(candles, FOUR_HOURS))
        (out / "features" / f"{series}.frame.json").write_text(json.dumps(frame.to_json()))
        funding_doc[series] = assemble_funding([tape], inst, candles.start_ms, end)
        gaps_doc[series] = candle_receipt["gaps"]
        observed = segment["ct_val_observed"]
        if observed["median"] is None:
            raise SystemExit(f"{series}: no contract value observed in the segment")
        lot, lot_source = segment["lot_inferred"], "observed_in_segment"
        live = audits[inst].get("live_metadata")
        if lot is None and live is not None and abs(float(live["ctVal"]) / observed["median"] - 1) <= 0.01 and not observed["changes"]:
            # No contract volume in the archive files of this segment: the current lot, justified by an unchanged
            # contract value over the whole segment.
            lot, lot_source = float(live["lotSz"]), "current_lot_contract_value_unchanged"
        if lot is None:
            raise SystemExit(f"{series}: no lot observed and no justified current value")
        instruments.append({"symbol": series, "lot_source": lot_source, "contract_size": observed["median"] if not observed["changes"] else observed["min"],
                            "tick_size": segment["tick_inferred"], "quantity_step": lot,
                            "min_quantity": lot, "min_notional": 0.0,
                            "ct_val_changes": observed["changes"], "segment": [begin, end]})
        candle_receipt.update(funding_events=len(funding_doc[series]), bars_4h=frame.count)
        receipt["series"][series] = {k: v for k, v in candle_receipt.items() if k != "files"}
    (out / "funding.json").write_text(json.dumps(funding_doc))
    (out / "gaps.json").write_text(json.dumps(gaps_doc))
    (out / "instrument-assumptions.json").write_text(json.dumps({"schema_version": "okx-series-instruments.v1",
                                                                 "assumption_status": "observed_in_segment", "symbols": instruments}, indent=1))
    (out / "cost-assumptions.json").write_text(costs.read_text())
    receipt["audit_sha256"] = sha256_file(audit_path)
    receipt["universe_sha256"] = sha256_file(universe_path)
    (out / "build-receipt.json").write_text(json.dumps(receipt, indent=1, sort_keys=True) + "\n")
    return receipt


def relot(audit_path: Path, root: Path) -> list[str]:
    """Re-apply the lot rule to an existing root's instrument file (no candle or frame rebuilt)."""
    audits = json.loads(audit_path.read_text())["instruments"]
    path = root / "instrument-assumptions.json"
    document = json.loads(path.read_text())
    changed = []
    for item in document["symbols"]:
        if item["quantity_step"] is not None:
            item.setdefault("lot_source", "observed_in_segment")
            continue
        inst = item["symbol"].split("#")[0]
        segment = next(s for s in audits[inst]["segments"] if s["series"] == item["symbol"])
        live = audits[inst].get("live_metadata")
        observed = segment["ct_val_observed"]
        if live is None or abs(float(live["ctVal"]) / observed["median"] - 1) > 0.01 or observed["changes"]:
            raise SystemExit(f"{item['symbol']}: no lot observed and no justified current value")
        item.update(quantity_step=float(live["lotSz"]), min_quantity=float(live["lotSz"]), lot_source="current_lot_contract_value_unchanged")
        changed.append(item["symbol"])
    path.write_text(json.dumps(document, indent=1))
    return changed


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_swing2.okx_root")
    parser.add_argument("--audit", required=True)
    parser.add_argument("--relot", action="store_true", help="only re-apply the lot rule to an existing root")
    parser.add_argument("--universe")
    parser.add_argument("--tape")
    parser.add_argument("--costs")
    parser.add_argument("--out", required=True)
    args = parser.parse_args(argv)
    if args.relot:
        print(json.dumps({"relot": relot(Path(args.audit), Path(args.out))}))
        return 0
    receipt = build(Path(args.audit), Path(args.universe), Path(args.tape), Path(args.costs), Path(args.out))
    print(json.dumps({"series": len(receipt["series"])}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
