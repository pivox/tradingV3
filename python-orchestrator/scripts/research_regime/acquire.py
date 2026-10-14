"""Download daily spot / USD-M candles and USD-M funding from data.binance.vision (sha256 fail-closed).

Only months up to ``--until`` are fetched, so a training run never holds validation or holdout prices.
Each symbol is stored as one npz: spot/<SYM>.npz and perp/<SYM>.npz hold ``day`` (UTC days since the
epoch), ``open``, ``high``, ``low``, ``close``, ``qvol``; funding/<SYM>.npz holds ``ts_ms`` and ``rate``.
"""

from __future__ import annotations

import argparse
import concurrent.futures as cf
import csv
import hashlib
import io
import json
import re
import time
import urllib.parse
import urllib.request
import zipfile
from pathlib import Path

import numpy as np

from . import annexes

BASE = "https://data.binance.vision"
LIST = "https://s3-ap-northeast-1.amazonaws.com/data.binance.vision"
DAY_MS = 86_400_000


def _get(url: str, tries: int = 4) -> bytes:
    for attempt in range(tries):
        try:
            with urllib.request.urlopen(url, timeout=60) as r:
                return r.read()
        except Exception:  # noqa: BLE001 - network retries, re-raised on the last attempt
            if attempt == tries - 1:
                raise
            time.sleep(2 ** attempt)
    raise AssertionError("unreachable")


def list_keys(prefix: str, delimiter: str | None = None) -> list[str]:
    out, marker = [], ""
    while True:
        url = f"{LIST}?prefix={urllib.parse.quote(prefix)}"
        if delimiter:
            url += f"&delimiter={delimiter}"
        if marker:
            url += f"&marker={urllib.parse.quote(marker)}"
        xml = _get(url).decode()
        items = re.findall(r"<Prefix>([^<]*)</Prefix>", xml)[1:] if delimiter else re.findall(r"<Key>([^<]*)</Key>", xml)
        out += items
        if "<IsTruncated>true</IsTruncated>" not in xml:
            return out
        marker = items[-1]


def _ms(value: str) -> int:
    v = int(value)
    return v // 1000 if v > 10**14 else v  # spot files moved to microseconds in 2025


def fetch_zip(key: str) -> list[list[str]]:
    """Download one archive and its .CHECKSUM, verify sha256, return the CSV rows (header dropped)."""
    data = _get(f"{BASE}/{key}")
    expected = _get(f"{BASE}/{key}.CHECKSUM").decode().split()[0]
    if hashlib.sha256(data).hexdigest() != expected:
        raise RuntimeError(f"sha256 mismatch: {key}")
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        text = z.read(z.namelist()[0]).decode()
    rows = [r for r in csv.reader(io.StringIO(text)) if r]
    return [r for r in rows if r[0][:1].isdigit()]


def months_of(prefix: str, until: str) -> list[str]:
    keys = [k for k in list_keys(prefix) if k.endswith(".zip")]
    return sorted(k for k in keys if re.search(r"(\d{4}-\d{2})\.zip$", k).group(1) <= until)


def build_candles(keys: list[str]) -> dict[str, np.ndarray]:
    rows = [r for k in keys for r in fetch_zip(k)]
    by_day = {}
    for r in rows:
        day = _ms(r[0]) // DAY_MS
        by_day[day] = (float(r[1]), float(r[2]), float(r[3]), float(r[4]), float(r[7]))
    days = np.array(sorted(by_day), dtype=np.int64)
    vals = np.array([by_day[d] for d in days], dtype=np.float64).reshape(-1, 5)
    return {"day": days, "open": vals[:, 0], "high": vals[:, 1], "low": vals[:, 2], "close": vals[:, 3], "qvol": vals[:, 4]}


def build_funding(keys: list[str]) -> dict[str, np.ndarray]:
    rows = [r for k in keys for r in fetch_zip(k)]
    pairs = sorted({(_ms(r[0]), float(r[-1])) for r in rows})
    return {"ts_ms": np.array([p[0] for p in pairs], dtype=np.int64), "rate": np.array([p[1] for p in pairs], dtype=np.float64)}


def acquire(out: Path, until: str, workers: int) -> dict:
    excluded = annexes.annex_a_symbols()
    spot_syms = [p.split("/")[-2] for p in list_keys("data/spot/monthly/klines/", "/")]
    spot_syms = sorted(s for s in spot_syms if s.endswith("USDT") and s not in excluded and s.isascii())
    perp_syms = {p.split("/")[-2] for p in list_keys("data/futures/um/monthly/klines/", "/")}
    jobs = []
    for s in spot_syms:
        jobs.append(("spot", s, f"data/spot/monthly/klines/{s}/1d/"))
        if s in perp_syms:
            jobs.append(("perp", s, f"data/futures/um/monthly/klines/{s}/1d/"))
            jobs.append(("funding", s, f"data/futures/um/monthly/fundingRate/{s}/"))
    for kind in ("spot", "perp", "funding"):
        (out / kind).mkdir(parents=True, exist_ok=True)

    def run(job):
        kind, sym, prefix = job
        target = out / kind / f"{sym}.npz"
        if target.exists():
            return kind, sym, "cached"
        keys = months_of(prefix, until)
        if not keys:
            return kind, sym, "none"
        arrays = build_funding(keys) if kind == "funding" else build_candles(keys)
        np.savez(target, **arrays)
        return kind, sym, len(keys)

    manifest = {"until": until, "files": {}, "errors": {}}
    with cf.ThreadPoolExecutor(workers) as ex:
        futures = {ex.submit(run, j): j for j in jobs}
        for f in cf.as_completed(futures):
            kind, sym, _ = futures[f]
            try:
                manifest["files"][f"{kind}/{sym}"] = f.result()[2]
            except Exception as e:  # noqa: BLE001 - fail-closed: recorded, the run refuses to start
                manifest["errors"][f"{kind}/{sym}"] = repr(e)
    (out / "manifest.json").write_text(json.dumps(manifest, indent=1, sort_keys=True))
    return manifest


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", type=Path, required=True)
    ap.add_argument("--until", required=True, help="last month included, YYYY-MM")
    ap.add_argument("--workers", type=int, default=4)
    a = ap.parse_args()
    m = acquire(a.out, a.until, a.workers)
    print(json.dumps({"files": len(m["files"]), "errors": len(m["errors"])}))


if __name__ == "__main__":
    main()
