from __future__ import annotations

import hashlib
import io
import json
import os
import fcntl
import zipfile
from datetime import datetime, timezone
from pathlib import Path

import httpx
import pytest

from app.backtesting.research.acquire import AcquisitionError, acquire, main
from app.backtesting.research import acquire as acquire_module

UTC = timezone.utc
START = "2023-01-01T00:00:00Z"
END = "2023-01-01T00:02:00Z"


def fixture_zip() -> bytes:
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w") as archive:
        archive.writestr("BTCUSDT-1m-2023-01-01.csv", "\n".join(
            f"{t},100,102,99,101,2,{t + 59999},200,3,1,100,0"
            for t in (1672531200000, 1672531260000)))
    return stream.getvalue()


def client_for(handler):
    return httpx.Client(transport=httpx.MockTransport(handler), timeout=30)


def test_archive_success_manifest_resume_and_tamper(tmp_path: Path) -> None:
    raw = fixture_zip()
    calls: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(str(request.url))
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    root = tmp_path / "private"
    with client_for(handler) as client:
        assert acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0).complete
        assert len(calls) == 2
        assert acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0).complete
        assert len(calls) == 2
    manifest = json.loads((root / "manifest.json").read_text())
    entry = manifest["sources"][0]
    assert entry["evidence"] == "official_archive_checksum"
    assert entry["count"] == 2 and entry["coverage"] == "complete"
    assert (root / entry["raw_path"]).stat().st_mode & 0o777 == 0o600
    assert root.stat().st_mode & 0o777 == 0o700
    (root / entry["raw_path"]).write_bytes(b"tampered")
    with client_for(handler) as client, pytest.raises(AcquisitionError):
        acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0)
    assert len(calls) == 2


def test_partial_404_exit_nonzero_and_retry_limit(tmp_path: Path) -> None:
    calls = 0

    def missing(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return httpx.Response(404)

    with client_for(missing) as client:
        result = acquire(tmp_path / "missing", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0)
        assert not result.complete
        assert result.sources[0]["status"] == "missing"
        assert calls == 1
        assert main(["--root", str(tmp_path / "cli"), "--start", START, "--end", END,
                     "--symbols", "BTCUSDT"], client=client, reserve_bytes=0) != 0

    count = 0

    def transient(request: httpx.Request) -> httpx.Response:
        nonlocal count
        count += 1
        return httpx.Response(429, headers={"Retry-After": "0"})

    with client_for(transient) as client:
        result = acquire(tmp_path / "retry", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0, sleep=lambda _: None)
    assert not result.complete and count == 3
    assert result.sources[0]["status"] == "http_error"


def test_forbidden_does_not_retry_and_invalid_checksum(tmp_path: Path) -> None:
    calls = 0

    def forbidden(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return httpx.Response(403)

    with client_for(forbidden) as client:
        result = acquire(tmp_path / "forbidden", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0)
    assert calls == 1 and result.sources[0]["status"] == "http_error"

    def bad_hash(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text="0" * 64 + "  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=fixture_zip())

    with client_for(bad_hash) as client:
        result = acquire(tmp_path / "bad_hash", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0)
    assert result.sources[0]["status"] == "checksum_error"


def test_rejects_inrepo_symlink_conflict_and_cap(tmp_path: Path) -> None:
    def unused(request: httpx.Request) -> httpx.Response:
        raise AssertionError("network should not be called")

    with client_for(unused) as client:
        with pytest.raises(AcquisitionError):
            acquire(Path(__file__).parents[1] / "history", START, END,
                    symbols=("BTCUSDT",), client=client)
        main_repo = Path(__file__).parents[4]
        with pytest.raises(AcquisitionError):
            acquire(main_repo / "history", START, END,
                    symbols=("BTCUSDT",), client=client)
        target = tmp_path / "target"
        target.mkdir()
        (tmp_path / "link").symlink_to(target, target_is_directory=True)
        with pytest.raises(AcquisitionError):
            acquire(tmp_path / "link", START, END, symbols=("BTCUSDT",), client=client)
        root = tmp_path / "cap"
        with pytest.raises(AcquisitionError):
            acquire(root, START, END, symbols=("BTCUSDT",), client=client, root_cap_bytes=1)


def test_recent_tail_pagination_cutoff_and_empty(tmp_path: Path) -> None:
    start = "2026-10-09T00:00:00Z"
    end = "2026-10-09T00:03:00Z"
    now = datetime(2026, 10, 9, 0, 4, tzinfo=UTC)
    requested = []

    def paged(request: httpx.Request) -> httpx.Response:
        requested.append(request.url.params)
        opening = int(request.url.params["startTime"])
        row = [opening, "100", "102", "99", "101", "2", opening + 59999,
               "200", 3, "1", "100", "0"]
        return httpx.Response(200, json=[row])

    with client_for(paged) as client:
        result = acquire(tmp_path / "tail", start, end, symbols=("BTCUSDT",),
                         client=client, now=now, reserve_bytes=0)
    assert result.complete
    assert len(requested) == 3
    assert len({p["endTime"] for p in requested}) == 1
    assert result.sources[0]["evidence"] == "download_sha256"
    assert result.sources[0]["count"] == 3

    with client_for(lambda _: httpx.Response(200, json=[])) as client:
        empty = acquire(tmp_path / "empty_tail", start, end, symbols=("BTCUSDT",),
                        client=client, now=now, reserve_bytes=0)
    assert not empty.complete and empty.sources[0]["status"] == "missing"


def test_future_tail_excludes_open_bars_and_is_partial(tmp_path: Path) -> None:
    now = datetime(2026, 10, 9, 0, 1, 30, tzinfo=UTC)
    seen = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request.url.params)
        opening = int(request.url.params["startTime"])
        return httpx.Response(200, json=[[opening, "100", "102", "99", "101", "2",
                                          opening + 59999, "200", 3, "1", "100", "0"]])

    with client_for(handler) as client:
        result = acquire(tmp_path / "future", "2026-10-09T00:00:00Z", "2026-10-09T00:03:00Z",
                         symbols=("BTCUSDT",), now=now, client=client, reserve_bytes=0)
    assert not result.complete
    assert len(seen) == 1
    assert result.sources[0]["count"] == 1


def test_tail_rejects_nonprogress_and_bad_schema(tmp_path: Path) -> None:
    start, end = "2026-10-09T00:00:00Z", "2026-10-09T00:02:00Z"
    now = datetime(2026, 10, 9, 0, 3, tzinfo=UTC)
    cases = [
        [[0, "100", "102", "99", "101", "2", 59999, "200", 3, "1", "100", "0"]],
        {"error": "bad"},
        [[1791504000000, "NaN"]],
    ]
    for index, payload in enumerate(cases):
        with client_for(lambda _, p=payload: httpx.Response(200, json=p)) as client:
            result = acquire(tmp_path / f"tail_{index}", start, end, symbols=("BTCUSDT",),
                             now=now, client=client, reserve_bytes=0)
        assert not result.complete and result.sources[0]["status"] == "invalid"


def test_manifest_identity_lock_and_space_reserve(tmp_path: Path) -> None:
    root = tmp_path / "root"
    with client_for(lambda _: httpx.Response(404)) as client:
        acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0)
        with pytest.raises(AcquisitionError, match="identity"):
            acquire(root, START, "2023-01-01T00:03:00Z", symbols=("BTCUSDT",),
                    client=client, reserve_bytes=0)
        lock = os.open(root / ".writer.lock", os.O_RDWR)
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with pytest.raises(AcquisitionError, match="writer"):
                acquire(root, START, END, symbols=("BTCUSDT",), client=client,
                        reserve_bytes=0)
        finally:
            os.close(lock)
        with pytest.raises(AcquisitionError, match="reserve"):
            acquire(tmp_path / "no_space", START, END, symbols=("BTCUSDT",),
                    client=client, reserve_bytes=10**18)


def test_two_sources_preserve_success_when_second_missing(tmp_path: Path) -> None:
    raw = fixture_zip()

    def handler(request: httpx.Request) -> httpx.Response:
        if "ETHUSDT" in request.url.path:
            return httpx.Response(404)
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    with client_for(handler) as client:
        result = acquire(tmp_path / "partial", START, END, symbols=("BTCUSDT", "ETHUSDT"),
                         client=client, reserve_bytes=0)
    assert not result.complete
    assert [entry["status"] for entry in result.sources] == ["ok", "missing"]
    assert json.loads(result.manifest_path.read_text())["complete"] is False


def test_manifest_never_says_complete_before_all_sources(tmp_path: Path) -> None:
    raw = fixture_zip()
    root = tmp_path / "interrupted"

    def handler(request: httpx.Request) -> httpx.Response:
        if "ETHUSDT" in request.url.path:
            raise KeyboardInterrupt("interrupt after first source")
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    with client_for(handler) as client, pytest.raises(KeyboardInterrupt):
        acquire(root, START, END, symbols=("BTCUSDT", "ETHUSDT"), client=client,
                reserve_bytes=0)
    manifest = json.loads((root / "manifest.json").read_text())
    assert len(manifest["sources"]) == 1
    assert manifest["complete"] is False


def test_resume_tail_revalidates_stored_json(tmp_path: Path) -> None:
    root = tmp_path / "tail_resume"
    start, end = "2026-10-09T00:00:00Z", "2026-10-09T00:01:00Z"
    now = datetime(2026, 10, 9, 0, 2, tzinfo=UTC)
    opening = 1791504000000

    def handler(request: httpx.Request) -> httpx.Response:
        actual = int(request.url.params["startTime"])
        assert actual == opening
        return httpx.Response(200, json=[[actual, "100", "102", "99", "101", "2",
                                          actual + 59999, "200", 3, "1", "100", "0"]])

    with client_for(handler) as client:
        result = acquire(root, start, end, symbols=("BTCUSDT",), now=now,
                         client=client, reserve_bytes=0)
    assert result.complete
    entry = result.sources[0]
    page = root / entry["pages"][0]["raw_path"]
    malformed = b'{"not":"klines"}'
    page.write_bytes(malformed)
    manifest = json.loads(result.manifest_path.read_text())
    manifest["sources"][0]["pages"][0]["sha256"] = hashlib.sha256(malformed).hexdigest()
    manifest["sources"][0]["pages"][0]["size"] = len(malformed)
    result.manifest_path.write_text(json.dumps(manifest))
    with client_for(lambda _: (_ for _ in ()).throw(AssertionError("no network"))) as client:
        with pytest.raises(AcquisitionError):
            acquire(root, start, end, symbols=("BTCUSDT",), now=now,
                    client=client, reserve_bytes=0)


def test_refuses_symlink_inside_artifact_tree(tmp_path: Path) -> None:
    raw = fixture_zip()
    root = tmp_path / "root"
    root.mkdir()
    outside = tmp_path / "outside"
    outside.mkdir()
    (root / "archives").symlink_to(outside, target_is_directory=True)

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    with client_for(handler) as client, pytest.raises(AcquisitionError):
        acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0)
    assert not list(outside.iterdir())


def test_recent_daily_404_uses_rest_and_funding_missing_is_explicit(tmp_path: Path) -> None:
    now = datetime(2026, 10, 9, 0, 3, tzinfo=UTC)
    start, end = "2026-10-08T23:59:00Z", "2026-10-09T00:01:00Z"
    seen = []

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(str(request.url))
        if request.url.host == "data.binance.vision":
            return httpx.Response(404)
        opening = int(request.url.params["startTime"])
        return httpx.Response(200, json=[[opening, "100", "102", "99", "101", "2",
                                          opening + 59999, "200", 3, "1", "100", "0"]])

    with client_for(handler) as client:
        result = acquire(tmp_path / "recent", start, end, symbols=("BTCUSDT",),
                         include_funding=True, now=now, client=client, reserve_bytes=0)
    assert not result.complete
    assert [e["status"] for e in result.sources] == ["ok", "ok", "missing"] or [
        e["status"] for e in result.sources] == ["ok", "ok", "missing", "missing"]
    assert all(e["evidence"] == "download_sha256" for e in result.sources[:2])
    assert any("fundingRate" in url for url in seen)


def test_rest_gap_and_5xx_retry_then_success(tmp_path: Path) -> None:
    calls = 0
    start, end = "2026-10-09T00:00:00Z", "2026-10-09T00:03:00Z"
    now = datetime(2026, 10, 9, 0, 4, tzinfo=UTC)
    opening = 1791504000000

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        if calls == 1:
            return httpx.Response(503)
        if calls == 2:
            return httpx.Response(200, json=[[opening, "100", "102", "99", "101", "2",
                                              opening + 59999, "200", 3, "1", "100", "0"]])
        skipped = opening + 120000
        return httpx.Response(200, json=[[skipped, "100", "102", "99", "101", "2",
                                          skipped + 59999, "200", 3, "1", "100", "0"]])

    with client_for(handler) as client:
        result = acquire(tmp_path / "gap", start, end, symbols=("BTCUSDT",), now=now,
                         client=client, reserve_bytes=0, sleep=lambda _: None)
    assert calls == 3
    assert result.sources[0]["gaps"] == [[opening + 60000, opening + 120000]] or \
        result.sources[0]["gaps"] == ((opening + 60000, opening + 120000),)
    assert not result.complete


def test_incomplete_valid_archive_resumes_without_http(tmp_path: Path) -> None:
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w") as archive:
        archive.writestr("BTCUSDT-1m-2023-01-01.csv",
                         "1672531200000,100,102,99,101,2,1672531259999,200,3,1,100,0")
    raw = stream.getvalue()
    calls = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    root = tmp_path / "incomplete"
    with client_for(handler) as client:
        first = acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0)
        second = acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0)
    assert not first.complete and not second.complete
    assert first.sources[0]["status"] == "incomplete"
    assert calls == 2


def test_root_cap_includes_preexisting_files(tmp_path: Path) -> None:
    raw = fixture_zip()
    root = tmp_path / "occupied"
    root.mkdir()
    cap = 64 * 1024 * 1024
    with (root / "already.bin").open("wb") as output:
        output.truncate(cap)

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    with client_for(handler) as client, pytest.raises(AcquisitionError, match="capacity"):
        acquire(root, START, END, symbols=("BTCUSDT",), client=client, reserve_bytes=0,
                root_cap_bytes=cap)


@pytest.mark.parametrize("start,end,symbols", [
    ("bad", END, ("BTCUSDT",)),
    ("2023-01-01T00:00:00", END, ("BTCUSDT",)),
    (START, START, ("BTCUSDT",)),
    ("2023-01-01T00:00:01Z", END, ("BTCUSDT",)),
    (START, END, ()),
    (START, END, ("BTCUSDT", "BTCUSDT")),
    (START, END, ("UNKNOWN",)),
])
def test_invalid_cli_identity_does_not_contact_network(tmp_path: Path, start: str,
                                                       end: str, symbols: tuple[str, ...]) -> None:
    with client_for(lambda _: (_ for _ in ()).throw(AssertionError("network"))) as client:
        with pytest.raises(AcquisitionError):
            acquire(tmp_path / "invalid", start, end, symbols=symbols, client=client,
                    reserve_bytes=0)


def test_rejects_naive_now_bad_manifest_and_unsafe_lock(tmp_path: Path) -> None:
    with client_for(lambda _: httpx.Response(404)) as client:
        with pytest.raises(AcquisitionError, match="UTC now"):
            acquire(tmp_path / "now", START, END, symbols=("BTCUSDT",), client=client,
                    now=datetime(2023, 1, 2), reserve_bytes=0)
        root = tmp_path / "bad_manifest"
        root.mkdir()
        (root / "manifest.json").write_text("not JSON")
        with pytest.raises(AcquisitionError, match="manifest"):
            acquire(root, START, END, symbols=("BTCUSDT",), client=client,
                    reserve_bytes=0)
        other = tmp_path / "bad_lock"
        other.mkdir()
        (other / ".writer.lock").symlink_to(root / "manifest.json")
        with pytest.raises(AcquisitionError):
            acquire(other, START, END, symbols=("BTCUSDT",), client=client,
                    reserve_bytes=0)


def test_file_roots_and_cli_error_exit(tmp_path: Path) -> None:
    file_path = tmp_path / "file"
    file_path.write_text("x")
    with client_for(lambda _: httpx.Response(404)) as client:
        with pytest.raises(AcquisitionError):
            acquire(file_path, START, END, symbols=("BTCUSDT",), client=client,
                    reserve_bytes=0)
        with pytest.raises(AcquisitionError):
            acquire(Path("relative"), START, END, symbols=("BTCUSDT",), client=client,
                    reserve_bytes=0)
        assert main(["--root", str(tmp_path / "err"), "--start", "bad", "--end", END,
                     "--symbols", "BTCUSDT"], client=client, reserve_bytes=0) == 2


def test_archive_transport_error_invalid_zip_and_redirect(tmp_path: Path) -> None:
    calls = 0

    def transport_error(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        raise httpx.ConnectError("offline")

    with client_for(transport_error) as client:
        result = acquire(tmp_path / "offline", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0, sleep=lambda _: None)
    assert calls == 3 and result.sources[0]["status"] == "http_error"

    with client_for(lambda _: httpx.Response(302, headers={"Location": "https://evil.example"})) as client:
        result = acquire(tmp_path / "redirect", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0)
    assert result.sources[0]["status"] == "http_error"

    raw = b"not-a-zip"
    def invalid(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    with client_for(invalid) as client:
        result = acquire(tmp_path / "invalid_zip", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0)
    assert result.sources[0]["status"] == "invalid"


def test_missing_checksum_is_distinct_from_missing_archive(tmp_path: Path) -> None:
    raw = fixture_zip()
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(404)
        return httpx.Response(200, content=raw)
    with client_for(handler) as client:
        result = acquire(tmp_path / "checksum_missing", START, END,
                         symbols=("BTCUSDT",), client=client, reserve_bytes=0)
    assert result.sources[0]["status"] == "checksum_error"


@pytest.mark.parametrize("mutation", ["path", "hash", "count", "checksum_symlink"])
def test_resume_archive_detects_manifest_and_file_conflicts(tmp_path: Path,
                                                            mutation: str) -> None:
    raw = fixture_zip()
    root = tmp_path / mutation

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    with client_for(handler) as client:
        result = acquire(root, START, END, symbols=("BTCUSDT",), client=client,
                         reserve_bytes=0)
    manifest = json.loads(result.manifest_path.read_text())
    entry = manifest["sources"][0]
    if mutation == "path":
        entry["raw_path"] = "../outside.zip"
    elif mutation == "hash":
        entry["sha256"] = "0" * 64
    elif mutation == "count":
        entry["count"] = 1
    else:
        check = root / entry["checksum_path"]
        check.unlink()
        check.symlink_to(root / entry["raw_path"])
    result.manifest_path.write_text(json.dumps(manifest))
    with client_for(lambda _: (_ for _ in ()).throw(AssertionError("no network"))) as client:
        with pytest.raises(AcquisitionError):
            acquire(root, START, END, symbols=("BTCUSDT",), client=client,
                    reserve_bytes=0)


@pytest.mark.parametrize("mutation", ["path", "hash", "summary", "order"])
def test_resume_rest_detects_manifest_and_page_conflicts(tmp_path: Path,
                                                          mutation: str) -> None:
    opening = 1791504000000
    start, end = "2026-10-09T00:00:00Z", "2026-10-09T00:01:00Z"
    now = datetime(2026, 10, 9, 0, 2, tzinfo=UTC)

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json=[[opening, "100", "102", "99", "101", "2",
                                          opening + 59999, "200", 3, "1", "100", "0"]])

    root = tmp_path / mutation
    with client_for(handler) as client:
        result = acquire(root, start, end, symbols=("BTCUSDT",), now=now,
                         client=client, reserve_bytes=0)
    manifest = json.loads(result.manifest_path.read_text())
    entry = manifest["sources"][0]
    if mutation == "path":
        entry["pages"][0]["raw_path"] = "../outside"
    elif mutation == "hash":
        entry["pages"][0]["sha256"] = "0" * 64
    elif mutation == "summary":
        entry["count"] = 2
    else:
        page = root / entry["pages"][0]["raw_path"]
        raw = page.read_bytes().replace(str(opening).encode(), str(opening + 60000).encode())
        page.write_bytes(raw)
        entry["pages"][0]["sha256"] = hashlib.sha256(raw).hexdigest()
        entry["pages"][0]["size"] = len(raw)
    result.manifest_path.write_text(json.dumps(manifest))
    with client_for(lambda _: (_ for _ in ()).throw(AssertionError("no network"))) as client:
        with pytest.raises(AcquisitionError):
            acquire(root, start, end, symbols=("BTCUSDT",), now=now,
                    client=client, reserve_bytes=0)


def test_tail_http_error_and_malformed_json(tmp_path: Path) -> None:
    start, end = "2026-10-09T00:00:00Z", "2026-10-09T00:01:00Z"
    now = datetime(2026, 10, 9, 0, 2, tzinfo=UTC)
    for index, response in enumerate((httpx.Response(403), httpx.Response(200, content=b"{"))):
        with client_for(lambda _, r=response: r) as client:
            result = acquire(tmp_path / f"tail_error_{index}", start, end,
                             symbols=("BTCUSDT",), now=now, client=client,
                             reserve_bytes=0)
        assert result.sources[0]["status"] == ("http_error" if index == 0 else "invalid")


def test_transport_rejects_unapproved_url_and_bounds_stream() -> None:
    with client_for(lambda _: httpx.Response(200, content=b"too large")) as client:
        for url in ("https://evil.example/fapi/v1/klines", "http://data.binance.vision/data/futures/um/x",
                    "https://data.binance.vision.evil.example/data/futures/um/x"):
            with pytest.raises(AcquisitionError):
                acquire_module._fetch(client, url, max_bytes=10, sleep=lambda _: None)
        with pytest.raises(Exception, match="size limit"):
            acquire_module._fetch(client, "https://data.binance.vision/data/futures/um/x",
                                  max_bytes=1, sleep=lambda _: None)


def test_bad_retry_after_is_bounded_then_success(tmp_path: Path) -> None:
    raw = fixture_zip()
    calls = 0
    delays = []

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        if calls == 1:
            return httpx.Response(429, headers={"Retry-After": "not-a-number"})
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)

    with client_for(handler) as client:
        result = acquire(tmp_path / "retry_after", START, END, symbols=("BTCUSDT",),
                         client=client, reserve_bytes=0, sleep=delays.append)
    assert result.complete and calls == 3 and delays == [0.2]


def test_atomic_storage_preserves_existing_bytes_and_refuses_conflict(tmp_path: Path) -> None:
    path = tmp_path / "data" / "one.zip"
    acquire_module._atomic_file(path, b"same")
    acquire_module._atomic_file(path, b"same")
    with pytest.raises(AcquisitionError, match="conflict"):
        acquire_module._atomic_file(path, b"other")
    outside = tmp_path / "outside"
    outside.mkdir()
    (tmp_path / "link").symlink_to(outside, target_is_directory=True)
    with pytest.raises(AcquisitionError):
        acquire_module._atomic_file(tmp_path / "link" / "other.zip", b"x")
    with pytest.raises(AcquisitionError, match="missing"):
        acquire_module._read_file(tmp_path / "absent.zip")


def test_atomic_storage_detects_race_after_temp_write(tmp_path: Path,
                                                      monkeypatch: pytest.MonkeyPatch) -> None:
    path = tmp_path / "race.zip"
    real_fsync = os.fsync
    def race(descriptor: int) -> None:
        path.write_bytes(b"other")
        real_fsync(descriptor)
    monkeypatch.setattr(acquire_module.os, "fsync", race)
    with pytest.raises(AcquisitionError, match="conflict"):
        acquire_module._atomic_file(path, b"mine")
    assert path.read_bytes() == b"other"
    assert not list(tmp_path.glob("*.tmp"))


@pytest.mark.parametrize("field,value", [
    ("coverage", "observed_only"),
    ("first_open", 0),
    ("gaps", [[1, 2]]),
    ("url", "https://evil.example/archive.zip"),
])
def test_resume_archive_rejects_forged_metadata(tmp_path: Path, field: str,
                                                value: object) -> None:
    raw = fixture_zip()
    root = tmp_path / field
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith(".CHECKSUM"):
            return httpx.Response(200, text=f"{hashlib.sha256(raw).hexdigest()}  BTCUSDT-1m-2023-01-01.zip")
        return httpx.Response(200, content=raw)
    with client_for(handler) as client:
        result = acquire(root, START, END, symbols=("BTCUSDT",), client=client,
                         reserve_bytes=0)
    manifest = json.loads(result.manifest_path.read_text())
    manifest["sources"][0][field] = value
    result.manifest_path.write_text(json.dumps(manifest))
    with client_for(lambda _: (_ for _ in ()).throw(AssertionError("no network"))) as client:
        with pytest.raises(AcquisitionError):
            acquire(root, START, END, symbols=("BTCUSDT",), client=client,
                    reserve_bytes=0)


@pytest.mark.parametrize("field,value", [
    ("gaps", [[1, 2]]),
    ("url", "https://evil.example"),
    ("evidence", "unknown"),
])
def test_resume_rest_rejects_forged_metadata(tmp_path: Path, field: str,
                                             value: object) -> None:
    opening = 1791504000000
    now = datetime(2026, 10, 9, 0, 2, tzinfo=UTC)
    root = tmp_path / field
    def handler(_: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json=[[opening, "100", "102", "99", "101", "2",
                                          opening + 59999, "200", 3, "1", "100", "0"]])
    with client_for(handler) as client:
        result = acquire(root, "2026-10-09T00:00:00Z", "2026-10-09T00:01:00Z",
                         symbols=("BTCUSDT",), now=now, client=client, reserve_bytes=0)
    manifest = json.loads(result.manifest_path.read_text())
    if field == "url":
        manifest["sources"][0]["pages"][0]["url"] = value
    else:
        manifest["sources"][0][field] = value
    result.manifest_path.write_text(json.dumps(manifest))
    with client_for(lambda _: (_ for _ in ()).throw(AssertionError("no network"))) as client:
        with pytest.raises(AcquisitionError):
            acquire(root, "2026-10-09T00:00:00Z", "2026-10-09T00:01:00Z",
                    symbols=("BTCUSDT",), now=now, client=client, reserve_bytes=0)
