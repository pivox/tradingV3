"""Fresh public research evidence; current metadata never attests historical limits.

The CLI requires all four explicit research hypotheses. Funding retrieval covers
the approved October tail only and makes no claim about schedule completeness.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import tempfile
import time
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Callable
from urllib.parse import parse_qs, urlencode, urlsplit

import httpx

from app.modern_trading_contracts import _canonical_json
from .binance_history import SYMBOLS

EXCHANGE_URL = "https://fapi.binance.com/fapi/v1/exchangeInfo"
FUNDING_URL = "https://fapi.binance.com/fapi/v1/fundingRate"
START_MS = 1790812800000  # 2026-10-01T00:00:00Z inclusive
END_MS = 1791525600000  # 2026-10-09T06:00:00Z exclusive
EXCHANGE_CAP = 10 * 1024**2
FUNDING_CAP = 1024**2
ROOT_CAP = 256 * 1024**2
PAGE_LIMIT = 1000
MAX_PAGES = 16


class ComplementError(ValueError):
    """Evidence or private-storage precondition failed closed."""


class FetchError(ComplementError):
    def __init__(self, message: str, *, status: int | None = None, partial: bytes = b""):
        super().__init__(message)
        self.status = status
        self.partial = partial


def _hash(value: dict) -> str:
    return "sha256:" + hashlib.sha256(_canonical_json(value).encode()).hexdigest()


def _json(raw: bytes):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise ComplementError("duplicate JSON key")
            result[key] = value
        return result
    try:
        return json.loads(raw, object_pairs_hook=pairs)
    except (ValueError, UnicodeError, RecursionError) as exc:
        raise ComplementError("invalid JSON evidence") from exc


def _number(value, *, positive: bool = False) -> float:
    if not isinstance(value, str):
        raise ComplementError("decimal string required")
    try:
        decimal = Decimal(value)
        number = float(decimal)
    except (InvalidOperation, ValueError, OverflowError) as exc:
        raise ComplementError("invalid decimal evidence") from exc
    if not decimal.is_finite() or not math.isfinite(number) or (positive and number <= 0):
        raise ComplementError("invalid decimal evidence")
    return number


def _hypotheses(contract_size, leverage_cap, mmr_proxy_rate, liquidation_fee_rate) -> dict:
    values = dict(contract_size=contract_size, leverage_cap=leverage_cap,
                  mmr_proxy_rate=mmr_proxy_rate, liquidation_fee_rate=liquidation_fee_rate)
    if any(type(value) not in (int, float) or not math.isfinite(value) for value in values.values()):
        raise ComplementError("finite explicit hypotheses required")
    if not (contract_size > 0 and 1 <= leverage_cap <= 2 and 0 < mmr_proxy_rate < 1
            and 0 <= liquidation_fee_rate < 1):
        raise ComplementError("research hypotheses outside campaign limits")
    return values


def instrument_manifest(raw: bytes, retrieved_at: str, *, contract_size: float,
                        leverage_cap: float, mmr_proxy_rate: float,
                        liquidation_fee_rate: float) -> tuple[dict, dict]:
    hypotheses = _hypotheses(contract_size, leverage_cap, mmr_proxy_rate, liquidation_fee_rate)
    document = _json(raw)
    if not isinstance(document, dict) or not isinstance(document.get("symbols"), list):
        raise ComplementError("exchangeInfo symbols required")
    selected = {}
    for row in document["symbols"]:
        if not isinstance(row, dict):
            raise ComplementError("invalid exchangeInfo symbol")
        symbol = row.get("symbol")
        if symbol not in SYMBOLS:
            continue
        if symbol in selected or any(row.get(key) != expected for key, expected in
                (("contractType", "PERPETUAL"), ("quoteAsset", "USDT"), ("marginAsset", "USDT"))):
            raise ComplementError("unique USD-M USDT perpetual required")
        filters = row.get("filters")
        if not isinstance(filters, list):
            raise ComplementError("filters required")
        by_type = {}
        for item in filters:
            if not isinstance(item, dict) or not isinstance(item.get("filterType"), str):
                raise ComplementError("malformed filter")
            kind = item["filterType"]
            if kind in by_type:
                raise ComplementError("duplicate filter")
            by_type[kind] = item
        try:
            values = {"symbol": symbol, "tick_size": by_type["PRICE_FILTER"]["tickSize"],
                      "quantity_step": by_type["LOT_SIZE"]["stepSize"],
                      "min_quantity": by_type["LOT_SIZE"]["minQty"],
                      "max_quantity": by_type["LOT_SIZE"]["maxQty"],
                      "min_notional": by_type["MIN_NOTIONAL"]["notional"]}
        except KeyError as exc:
            raise ComplementError("missing precision filter") from exc
        numbers = {key: _number(value, positive=True) for key, value in values.items() if key != "symbol"}
        if (numbers["quantity_step"] < 1e-12 or numbers["min_quantity"] < numbers["quantity_step"]
                or numbers["max_quantity"] < numbers["min_quantity"]):
            raise ComplementError("incoherent quantity limits")
        selected[symbol] = values, dict(symbol=symbol, **numbers, **hypotheses)
    if set(selected) != set(SYMBOLS):
        raise ComplementError("all ten symbols required")
    binding = dict(source_url=EXCHANGE_URL, retrieved_at=retrieved_at,
                   raw_sha256=hashlib.sha256(raw).hexdigest())
    manifest = dict(schema_version="research-instrument-assumptions.v1", **binding,
        validity_statement="Current precision and limit observations and explicit research hypotheses; "
                           "historical 2023 specifications, leverage limits and MMR are not attested.",
        assumption_status="current_metadata_not_historical",
        symbols=[selected[symbol][1] for symbol in SYMBOLS])
    manifest["manifest_hash"] = _hash(manifest)
    evidence = dict(schema_version="research-precision-evidence.v1", **binding,
                    symbols=[selected[symbol][0] for symbol in SYMBOLS],
                    assumptions_manifest_hash=manifest["manifest_hash"])
    evidence["evidence_hash"] = _hash(evidence)
    return manifest, evidence


def funding_rows(raw: bytes, symbol: str, start: int, end: int) -> list[dict]:
    rows = _json(raw)
    if not isinstance(rows, list) or len(rows) > PAGE_LIMIT:
        raise ComplementError("invalid funding page")
    previous = start - 1
    for row in rows:
        if not isinstance(row, dict) or row.get("symbol") != symbol:
            raise ComplementError("funding symbol mismatch")
        timestamp = row.get("fundingTime")
        if type(timestamp) is not int or not start <= timestamp < end or timestamp <= previous:
            raise ComplementError("funding timestamp range or order")
        _number(row.get("fundingRate"))
        if "markPrice" in row:
            _number(row["markPrice"], positive=True)
        previous = timestamp
    return rows


def _fresh_root(root: Path) -> Path:
    root = Path(root)
    if not root.is_absolute() or ".." in root.parts:
        raise ComplementError("absolute root without traversal required")
    repo = Path(__file__).resolve().parents[4]
    repositories = [repo]
    pointer = repo / ".git"
    if pointer.is_file():
        gitdir = Path(pointer.read_text().strip().removeprefix("gitdir: ")).resolve()
        if gitdir.parent.name == "worktrees" and gitdir.parent.parent.name == ".git":
            repositories.append(gitdir.parent.parent.parent)
    if root == Path.home() or any(root == item or root in item.parents or item in root.parents
                                  for item in repositories):
        raise ComplementError("root must be outside repositories")
    if root.exists() or root.is_symlink():
        raise ComplementError("fresh root required")
    if not root.parent.is_dir():
        raise ComplementError("existing parent directory required")
    if any(parent.is_symlink() for parent in root.parents):
        raise ComplementError("symlink root ancestor")
    if any((parent / ".git").exists() or (parent / ".git").is_symlink()
           for parent in root.parents):
        raise ComplementError("root must be outside repositories")
    root.mkdir(mode=0o700)
    return root


def _write(root: Path, name: str, data: bytes) -> None:
    if sum(item.stat().st_size for item in root.iterdir()) + len(data) > ROOT_CAP:
        raise ComplementError("root storage cap")
    fd, temporary_name = tempfile.mkstemp(prefix=f".{name}.", suffix=".tmp", dir=root)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        # Atomic publication without replacing an existing immutable artifact.
        os.link(temporary, root / name, follow_symlinks=False)
    finally:
        temporary.unlink()


def _write_json(root: Path, name: str, value: dict) -> None:
    _write(root, name, (json.dumps(value, sort_keys=True, indent=2, allow_nan=False) + "\n").encode())


def _fetch(client: httpx.Client, url: str, cap: int, sleep: Callable) -> bytes:
    if url != EXCHANGE_URL:
        parts = urlsplit(url)
        query = parse_qs(parts.query)
        if (parts.scheme != "https" or parts.netloc != "fapi.binance.com"
                or parts.path != "/fapi/v1/fundingRate" or parts.fragment
                or set(query) != {"symbol", "startTime", "endTime", "limit"}
                or any(len(value) != 1 for value in query.values())
                or query["symbol"][0] not in SYMBOLS
                or not query["startTime"][0].isdigit()
                or not START_MS <= int(query["startTime"][0]) <= END_MS
                or query["endTime"] != [str(END_MS - 1)] or query["limit"] != [str(PAGE_LIMIT)]):
            raise ComplementError("unapproved public URL")
    for attempt in range(3):
        body = bytearray()
        try:
            with client.stream("GET", url, follow_redirects=False, timeout=30) as response:
                status = response.status_code
                if status != 200:
                    if (status == 429 or 500 <= status <= 599) and attempt < 2:
                        try:
                            delay = float(response.headers.get("Retry-After", 0.2 * 2**attempt))
                            if not math.isfinite(delay):
                                raise ValueError
                        except ValueError:
                            delay = 0.2 * 2**attempt
                        sleep(min(2, max(0, delay)))
                        continue
                    raise FetchError("HTTP response rejected", status=status)
                for chunk in response.iter_bytes():
                    available = cap - len(body)
                    body.extend(chunk[:available])
                    if len(chunk) > available:
                        raise FetchError("response size cap", partial=bytes(body))
                return bytes(body)
        except httpx.TransportError as exc:
            if attempt == 2:
                raise FetchError("transport failed", partial=bytes(body)) from exc
            sleep(0.2 * 2**attempt)
    raise ComplementError("retry bound exhausted")


def capture(root: Path, *, contract_size: float, leverage_cap: float,
            mmr_proxy_rate: float, liquidation_fee_rate: float,
            client: httpx.Client | None = None, sleep: Callable = time.sleep) -> dict:
    hypotheses = _hypotheses(contract_size, leverage_cap, mmr_proxy_rate, liquidation_fee_rate)
    root = _fresh_root(root)
    result = dict(schema_version="research-source-complements.v1", retrieval_complete=False,
                  start_ms=START_MS, end_exclusive_ms=END_MS, symbols=list(SYMBOLS),
                  metadata={"status": "not_attempted"}, funding=[], hypotheses=hypotheses)
    _write_json(root, "status.initial.json", result)
    owned = client is None
    http = client if client is not None else httpx.Client(trust_env=False, follow_redirects=False)
    def download(url: str, cap: int, name: str, entry: dict) -> bytes:
        try:
            raw = _fetch(http, url, cap, sleep)
        except FetchError as exc:
            entry.update(status="http_error" if exc.status else "failed", error=str(exc),
                         http_status=exc.status)
            if exc.partial:
                _write(root, name + ".partial", exc.partial)
                entry.update(partial_raw_path=name + ".partial",
                             partial_sha256=hashlib.sha256(exc.partial).hexdigest())
            raise
        _write(root, name, raw)
        entry.update(raw_path=name, raw_sha256=hashlib.sha256(raw).hexdigest(),
                     source_url=url, retrieved_at=datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))
        return raw
    try:
        metadata = result["metadata"]
        try:
            body = download(EXCHANGE_URL, EXCHANGE_CAP, "exchangeInfo.raw.json", metadata)
            manifest, evidence = instrument_manifest(body, metadata["retrieved_at"], **hypotheses)
            _write_json(root, "instrument-assumptions.json", manifest)
            _write_json(root, "precision-evidence.json", evidence)
            metadata.update(status="captured", manifest_hash=manifest["manifest_hash"],
                            evidence_hash=evidence["evidence_hash"])
        except ComplementError as exc:
            if metadata["status"] == "not_attempted":
                metadata.update(status="rejected", error=str(exc))
            return result
        refused = False
        for symbol in SYMBOLS:
            entry = dict(symbol=symbol, status="not_attempted", coverage="observed_only",
                         retrieval_complete=False, observations=[], pages=[])
            result["funding"].append(entry)
            if refused:
                continue
            cursor = START_MS
            try:
                for page in range(MAX_PAGES):
                    url = FUNDING_URL + "?" + urlencode(dict(symbol=symbol, startTime=cursor,
                                                            endTime=END_MS - 1, limit=PAGE_LIMIT))
                    page_entry = {"request_start_ms": cursor}
                    entry["pages"].append(page_entry)
                    body = download(url, FUNDING_CAP, f"funding-{symbol}-{page:02d}.raw.json", page_entry)
                    rows = funding_rows(body, symbol, cursor, END_MS)
                    page_entry["status"] = "validated"
                    entry["observations"].extend(rows)
                    if len(rows) < PAGE_LIMIT:
                        entry.update(status="observed" if entry["observations"] else "empty",
                                     retrieval_complete=True)
                        break
                    cursor = rows[-1]["fundingTime"] + 1
                else:
                    raise ComplementError("funding page bound exhausted without terminal page")
            except ComplementError as exc:
                entry.update(status="http_error" if isinstance(exc, FetchError) and exc.status else
                             "failed" if isinstance(exc, FetchError) else "rejected", error=str(exc))
                refused = isinstance(exc, FetchError) and exc.status in (403, 451)
        result["retrieval_complete"] = all(entry["retrieval_complete"] for entry in result["funding"])
        return result
    finally:
        result["status_hash"] = _hash(result)
        _write_json(root, "status.json", result)
        if owned:
            http.close()


def main(argv: list[str] | None = None, *, client: httpx.Client | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, required=True)
    for key in ("contract-size", "leverage-cap", "mmr-proxy-rate", "liquidation-fee-rate"):
        parser.add_argument("--" + key, type=float, required=True)
    args = parser.parse_args(argv)
    try:
        result = capture(**vars(args), client=client)
    except (ComplementError, OSError) as exc:
        parser.exit(2, f"Source complements refused: {exc}\n")
    return 0 if result["retrieval_complete"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
