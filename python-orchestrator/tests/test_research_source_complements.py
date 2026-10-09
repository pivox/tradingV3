from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path

import httpx
import pytest

from app.backtesting.research import source_complements as sc
from app.backtesting.research.binance_history import SYMBOLS
from app.modern_trading_contracts import _canonical_json


HYPOTHESES = dict(contract_size=1, leverage_cap=2, mmr_proxy_rate=0.005,
                  liquidation_fee_rate=0.01)


def metadata():
    return {"symbols": [{"symbol": symbol, "contractType": "PERPETUAL",
                         "quoteAsset": "USDT", "marginAsset": "USDT",
                         "pricePrecision": 0, "quantityPrecision": 0,
                         "filters": [{"filterType": "PRICE_FILTER", "tickSize": "0.0100"},
                                     {"filterType": "LOT_SIZE", "stepSize": "0.0010",
                                      "minQty": "0.0010", "maxQty": "10000"},
                                     {"filterType": "MIN_NOTIONAL", "notional": "5.00"}]}
                        for symbol in SYMBOLS]}


def raw(value):
    return json.dumps(value).encode()


def client(handler):
    return httpx.Client(transport=httpx.MockTransport(handler))


def test_capture_private_exact_evidence_and_canonical_manifest(tmp_path):
    exchange = raw(metadata())
    calls = []

    def handler(request):
        calls.append(request)
        if request.url.path.endswith("exchangeInfo"):
            return httpx.Response(200, content=exchange)
        symbol = request.url.params["symbol"]
        assert int(request.url.params["endTime"]) == sc.END_MS - 1
        return httpx.Response(200, json=[{"symbol": symbol, "fundingTime": sc.START_MS + 1,
                                        "fundingRate": "-0.0001", "markPrice": "1.20"}])

    root = tmp_path / "capture"
    with client(handler) as http:
        result = sc.capture(root, **HYPOTHESES, client=http)
    assert result["retrieval_complete"] and len(calls) == 11
    assert result["funding"][0]["coverage"] == "observed_only"
    assert result["funding"][0]["observations"][0]["fundingTime"] == sc.START_MS + 1
    manifest = json.loads((root / "instrument-assumptions.json").read_text())
    digest = manifest.pop("manifest_hash")
    assert digest == "sha256:" + hashlib.sha256(_canonical_json(manifest).encode()).hexdigest()
    assert manifest["symbols"][0]["tick_size"] == 0.01
    assert manifest["raw_sha256"] == hashlib.sha256(exchange).hexdigest()
    assert manifest["assumption_status"] == "current_metadata_not_historical"
    assert "not attested" in manifest["validity_statement"]
    evidence = json.loads((root / "precision-evidence.json").read_text())
    assert evidence["symbols"][0]["tick_size"] == "0.0100"
    for path in root.rglob("*"):
        assert path.stat().st_mode & 0o777 == 0o600
    assert root.stat().st_mode & 0o777 == 0o700
    for page in result["funding"][0]["pages"]:
        assert hashlib.sha256((root / page["raw_path"]).read_bytes()).hexdigest() == page["raw_sha256"]


def test_artifacts_are_published_only_after_complete_private_write(tmp_path, monkeypatch):
    root = tmp_path / "atomic"
    root.mkdir(mode=0o700)
    destination = root / "status.json"

    def interrupted_publish(source, target, **kwargs):
        assert not destination.exists()
        assert Path(source).read_bytes() == b"complete bytes"
        assert Path(source).stat().st_mode & 0o777 == 0o600
        raise OSError("simulated publication interruption")

    monkeypatch.setattr(sc.os, "link", interrupted_publish)
    with pytest.raises(OSError, match="publication interruption"):
        sc._write(root, destination.name, b"complete bytes")
    assert not destination.exists()
    assert list(root.iterdir()) == []


def test_atomic_publication_never_overwrites_existing_artifacts(tmp_path):
    root = tmp_path / "immutable"
    root.mkdir(mode=0o700)
    destination = root / "evidence.json"
    destination.write_bytes(b"original")
    with pytest.raises(FileExistsError):
        sc._write(root, destination.name, b"replacement")
    assert destination.read_bytes() == b"original"
    assert list(root.iterdir()) == [destination]


@pytest.mark.parametrize("mutation", [
    lambda m: m["symbols"].pop(),
    lambda m: m["symbols"].append(m["symbols"][0]),
    lambda m: m["symbols"][0].update(contractType="CURRENT_QUARTER"),
    lambda m: m["symbols"][0].update(marginAsset="BTC"),
    lambda m: m["symbols"][0]["filters"].pop(),
    lambda m: m["symbols"][0]["filters"].append(m["symbols"][0]["filters"][0]),
    lambda m: m["symbols"][0]["filters"][0].update(tickSize="NaN"),
    lambda m: m["symbols"][0]["filters"][1].update(minQty="0"),
])
def test_metadata_fails_closed(mutation):
    value = metadata()
    mutation(value)
    with pytest.raises(sc.ComplementError):
        sc.instrument_manifest(raw(value), "2026-10-09T06:00:00Z", **HYPOTHESES)


@pytest.mark.parametrize("hypotheses", [dict(HYPOTHESES, leverage_cap=3),
    dict(HYPOTHESES, contract_size=0), dict(HYPOTHESES, mmr_proxy_rate=0),
    dict(HYPOTHESES, liquidation_fee_rate=1)])
def test_unknown_or_unsafe_hypotheses_rejected(hypotheses):
    with pytest.raises(sc.ComplementError):
        sc.instrument_manifest(raw(metadata()), "2026-10-09T06:00:00Z", **hypotheses)


@pytest.mark.parametrize("rows", [None, [{}], [{"symbol": "BAD", "fundingTime": sc.START_MS,
    "fundingRate": "0"}], [{"symbol": SYMBOLS[0], "fundingTime": sc.END_MS,
    "fundingRate": "0"}], [{"symbol": SYMBOLS[0], "fundingTime": sc.START_MS,
    "fundingRate": "NaN"}], [{"symbol": SYMBOLS[0], "fundingTime": sc.START_MS,
    "fundingRate": "0", "markPrice": "0"}]])
def test_invalid_funding_rows(rows):
    with pytest.raises(sc.ComplementError):
        sc.funding_rows(raw(rows), SYMBOLS[0], sc.START_MS, sc.END_MS)


def test_pagination_preserves_observed_only_and_rejects_nonprogress(tmp_path, monkeypatch):
    monkeypatch.setattr(sc, "PAGE_LIMIT", 2)
    calls = []
    def handler(request):
        if request.url.path.endswith("exchangeInfo"):
            return httpx.Response(200, json=metadata())
        start = int(request.url.params["startTime"])
        calls.append(start)
        rows = ([{"symbol": request.url.params["symbol"], "fundingTime": t,
                  "fundingRate": "0"} for t in (sc.START_MS + 1, sc.START_MS + 9)]
                if start == sc.START_MS else [])
        return httpx.Response(200, json=rows)
    with client(handler) as http:
        result = sc.capture(tmp_path / "paged", **HYPOTHESES, client=http)
    assert result["retrieval_complete"]
    assert calls[:2] == [sc.START_MS, sc.START_MS + 10]
    assert all(row["coverage"] == "observed_only" for row in result["funding"])


@pytest.mark.parametrize("status", [403, 451, 302, 404, 429, 500])
def test_http_failures_bounded_preserve_status(tmp_path, status):
    calls = []
    with client(lambda request: (calls.append(request), httpx.Response(status,
                headers={"Retry-After": "0"}))[1]) as http:
        result = sc.capture(tmp_path / str(status), **HYPOTHESES, client=http,
                            sleep=lambda _: None)
    assert not result["retrieval_complete"]
    assert result["metadata"]["status"] == "http_error"
    assert len(calls) == (3 if status in (429, 500) else 1)
    assert json.loads((tmp_path / str(status) / "status.json").read_text()) == result


def test_rejected_metadata_raw_preserved_and_oversize_bounded(tmp_path, monkeypatch):
    with client(lambda _: httpx.Response(200, content=b"{")) as http:
        result = sc.capture(tmp_path / "bad", **HYPOTHESES, client=http)
    assert result["metadata"]["status"] == "rejected"
    assert (tmp_path / "bad" / "exchangeInfo.raw.json").read_bytes() == b"{"
    monkeypatch.setattr(sc, "EXCHANGE_CAP", 2)
    with client(lambda _: httpx.Response(200, content=b"123")) as http:
        result = sc.capture(tmp_path / "large", **HYPOTHESES, client=http)
    assert not result["retrieval_complete"]


def test_foreign_colliding_symlink_and_repository_roots_are_untouched(tmp_path):
    existing = tmp_path / "foreign"
    existing.mkdir(mode=0o755)
    linked = tmp_path / "linked"
    linked.symlink_to(existing, target_is_directory=True)
    for root in (existing, linked, Path.cwd() / "forbidden", Path("relative"), Path("/")):
        with pytest.raises(sc.ComplementError):
            sc.capture(root, **HYPOTHESES)
    assert existing.stat().st_mode & 0o777 == 0o755


def test_cli_requires_explicit_hypotheses_and_returns_failed_status(tmp_path):
    with pytest.raises(SystemExit):
        sc.main(["--root", str(tmp_path / "missing")])
    with client(lambda _: httpx.Response(451)) as http:
        assert sc.main(["--root", str(tmp_path / "cli"), "--contract-size", "1",
                        "--leverage-cap", "2", "--mmr-proxy-rate", "0.005",
                        "--liquidation-fee-rate", "0.01"], client=http) == 1


@pytest.mark.parametrize("value", [[], {}, {"symbols": [None]},
    {"symbols": metadata()["symbols"] + [{"symbol": "OTHER"}]},
])
def test_metadata_shape(value):
    if isinstance(value, dict) and len(value.get("symbols", [])) == 11:
        assert sc.instrument_manifest(raw(value), "2026-10-09T06:00:00Z", **HYPOTHESES)
    else:
        with pytest.raises(sc.ComplementError):
            sc.instrument_manifest(raw(value), "2026-10-09T06:00:00Z", **HYPOTHESES)


@pytest.mark.parametrize("filters", [None, [None], [{"filterType": "LOT_SIZE"}],
    [{"filterType": 1}]])
def test_malformed_filter_shapes(filters):
    value = metadata()
    value["symbols"][0]["filters"] = filters
    with pytest.raises(sc.ComplementError):
        sc.instrument_manifest(raw(value), "2026-10-09T06:00:00Z", **HYPOTHESES)


@pytest.mark.parametrize("value", [None, "invalid", "1e999", "-1"])
def test_decimal_invalid(value):
    with pytest.raises(sc.ComplementError):
        sc._number(value, positive=True)


def test_duplicate_keys_nan_hypothesis_and_canonical_php_float():
    with pytest.raises(sc.ComplementError):
        sc._json(b'{"a":1,"a":2}')
    with pytest.raises(sc.ComplementError):
        sc.instrument_manifest(raw(metadata()), "2026-10-09T06:00:00Z",
                               **dict(HYPOTHESES, leverage_cap=float("nan")))
    assert _canonical_json({"float": 1e-7, "whole": 1.0}) == '{"float":1.0e-7,"whole":1}'
    assert sc._hash({"float": 1e-7, "whole": 1.0}) != "sha256:" + hashlib.sha256(
        json.dumps({"float": 1e-7, "whole": 1.0}, separators=(",", ":")).encode()).hexdigest()


def test_funding_nonprogress_and_page_limit_keep_partial_evidence(tmp_path, monkeypatch):
    monkeypatch.setattr(sc, "PAGE_LIMIT", 1)
    monkeypatch.setattr(sc, "MAX_PAGES", 2)
    def handler(request):
        if request.url.path.endswith("exchangeInfo"):
            return httpx.Response(200, json=metadata())
        return httpx.Response(200, json=[{"symbol": request.url.params["symbol"],
            "fundingTime": sc.START_MS, "fundingRate": "0"}])
    with client(handler) as http:
        result = sc.capture(tmp_path / "duplicate", **HYPOTHESES, client=http)
    assert not result["retrieval_complete"]
    assert all(entry["status"] == "rejected" and len(entry["observations"]) == 1
               for entry in result["funding"])
    def progress(request):
        if request.url.path.endswith("exchangeInfo"):
            return httpx.Response(200, json=metadata())
        return httpx.Response(200, json=[{"symbol": request.url.params["symbol"],
            "fundingTime": int(request.url.params["startTime"]), "fundingRate": "0"}])
    with client(progress) as http:
        result = sc.capture(tmp_path / "bounded", **HYPOTHESES, client=http)
    assert not result["retrieval_complete"]
    assert all("page bound" in entry["error"] for entry in result["funding"])


def test_empty_terminal_pages_are_empty_observed_only_and_refusal_stops_requests(tmp_path):
    calls = []
    def handler(request):
        calls.append(request)
        return httpx.Response(200, json=metadata()) if request.url.path.endswith("exchangeInfo") else httpx.Response(451)
    with client(handler) as http:
        result = sc.capture(tmp_path / "refused", **HYPOTHESES, client=http)
    assert len(calls) == 2
    assert result["funding"][0]["status"] == "http_error"
    assert result["funding"][1]["status"] == "not_attempted"
    with client(lambda request: httpx.Response(200, json=metadata() if
                request.url.path.endswith("exchangeInfo") else [])) as http:
        result = sc.capture(tmp_path / "empty", **HYPOTHESES, client=http)
    assert result["retrieval_complete"]
    assert all(entry["status"] == "empty" and entry["coverage"] == "observed_only"
               for entry in result["funding"])


def test_transport_truncation_retries_and_keeps_partial_bytes(tmp_path):
    class Interrupted(httpx.SyncByteStream):
        def __iter__(self):
            yield b'{"symbols":'
            raise httpx.ReadError("interrupted")
    calls = []
    with client(lambda request: (calls.append(request), httpx.Response(200,
                stream=Interrupted()))[1]) as http:
        result = sc.capture(tmp_path / "truncated", **HYPOTHESES, client=http,
                            sleep=lambda _: None)
    assert len(calls) == 3 and result["metadata"]["status"] == "failed"
    assert (tmp_path / "truncated" / "exchangeInfo.raw.json.partial").read_bytes() == b'{"symbols":'


@pytest.mark.parametrize("final_failure", ["transport", "forbidden"])
def test_earlier_partial_bytes_survive_later_empty_retry_failures(tmp_path, final_failure):
    calls = []

    class FirstInterrupted(httpx.SyncByteStream):
        def __iter__(self):
            yield b'{"symbols":'
            raise httpx.ReadError("first attempt truncated")

    def handler(request):
        calls.append(request)
        if len(calls) == 1:
            return httpx.Response(200, stream=FirstInterrupted())
        if final_failure == "forbidden":
            return httpx.Response(403)
        raise httpx.ReadError("no response bytes")

    root = tmp_path / "earlier-partial"
    with client(handler) as http:
        result = sc.capture(root, **HYPOTHESES, client=http, sleep=lambda _: None)
    assert not result["retrieval_complete"]
    assert result["metadata"]["partial_attempt"] == 1
    assert (root / result["metadata"]["partial_raw_path"]).read_bytes() == b'{"symbols":'
    assert result["metadata"]["partial_sha256"] == hashlib.sha256(b'{"symbols":').hexdigest()
    assert len(calls) == (3 if final_failure == "transport" else 2)


@pytest.mark.parametrize("retry_after", ["invalid", "nan"])
def test_retry_header_fallback(retry_after):
    delays = []
    with client(lambda _: httpx.Response(429, headers={"Retry-After": retry_after})) as http:
        with pytest.raises(sc.FetchError):
            sc._fetch(http, sc.EXCHANGE_URL, 10, delays.append)
    assert delays == [0.2, 0.4]


def test_nonexistent_parent_symlink_ancestor_and_root_cap(tmp_path, monkeypatch):
    with pytest.raises(sc.ComplementError):
        sc._fresh_root(tmp_path / "missing" / "child")
    linked = tmp_path / "link"
    linked.symlink_to(tmp_path, target_is_directory=True)
    with pytest.raises(sc.ComplementError):
        sc._fresh_root(linked / "child")
    root = tmp_path / "cap"
    root.mkdir()
    monkeypatch.setattr(sc, "ROOT_CAP", 1)
    with pytest.raises(sc.ComplementError):
        sc._write(root, "too-large", b"ab")


def test_owned_client_closed_and_cli_validation_failure(tmp_path, monkeypatch):
    http = client(lambda _: httpx.Response(403))
    monkeypatch.setattr(sc.httpx, "Client", lambda **kwargs: http)
    result = sc.capture(tmp_path / "owned", **HYPOTHESES)
    assert not result["retrieval_complete"] and http.is_closed
    with pytest.raises(SystemExit) as error:
        sc.main(["--root", str(tmp_path / "cli-bad"), "--contract-size", "0",
                 "--leverage-cap", "2", "--mmr-proxy-rate", "0.005",
                 "--liquidation-fee-rate", "0.01"])
    assert error.value.code == 2


def test_other_repository_root_rejected(tmp_path):
    repo = tmp_path / "other-repository"
    repo.mkdir()
    (repo / ".git").mkdir()
    with pytest.raises(sc.ComplementError, match="repositor"):
        sc._fresh_root(repo / "capture")


def test_unhashable_symbol_rejected():
    value = metadata()
    value["symbols"][0]["symbol"] = []
    with pytest.raises(sc.ComplementError):
        sc.instrument_manifest(raw(value), "2026-10-09T06:00:00Z", **HYPOTHESES)


@pytest.mark.parametrize("url", ["https://example.com", sc.FUNDING_URL,
    sc.EXCHANGE_URL + "?secret=1", sc.FUNDING_URL + "?symbol=BAD&startTime=1&endTime=2&limit=1000"])
def test_fetch_rejects_unapproved_url_without_network(url):
    with client(lambda _: pytest.fail("unapproved request")) as http:
        with pytest.raises(sc.ComplementError, match="URL"):
            sc._fetch(http, url, 100, lambda _: None)
