from __future__ import annotations

import hashlib
import io
import json
import os
import zipfile
from dataclasses import FrozenInstanceError
from datetime import datetime, timedelta, timezone
from decimal import Decimal

import pytest

from app.backtesting.research import funding as f
from app.backtesting.research.binance_history import SOURCE, SYMBOLS, plan_archives
from app.backtesting.research.source_complements import START_MS, END_MS, _hash


def instant(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


START = instant("2023-01-01T00:00:00Z")
END = instant("2023-01-01T16:00:00Z")
T = int(START.timestamp() * 1000)


def private_write(path, content):
    missing = []
    cursor = path.parent
    while not cursor.exists():
        missing.append(cursor)
        cursor = cursor.parent
    for directory in reversed(missing):
        directory.mkdir(mode=0o700)
    path.write_bytes(content)
    os.chmod(path, 0o600)


def acquisition(tmp_path, rows=None, *, symbols=("BTCUSDT",), start=START, end=END):
    root = tmp_path / "acquisition"
    root.mkdir(mode=0o700)
    manifest = {"identity": {"schema": 1, "source": SOURCE, "symbols": list(symbols),
                            "start": start.isoformat(), "end": end.isoformat(), "include_funding": True},
                "complete": False, "sources": []}
    for symbol in symbols:
        for source in plan_archives(symbol, start.replace(second=0, microsecond=0),
                                    end.replace(second=0, microsecond=0), "funding"):
            selected_rows = (rows.get(source.start.strftime("%Y-%m"), []) if isinstance(rows, dict) else rows
                             if rows is not None else [(T + 1, "8", "-0.001"), (T + 28800002, "8", "0.002")])
            stream = io.BytesIO()
            with zipfile.ZipFile(stream, "w") as archive:
                archive.writestr(source.filename[:-4] + ".csv", "calc_time,funding_interval_hours,last_funding_rate\n" +
                                 "".join(f"{timestamp},{interval},{rate}\n" for timestamp, interval, rate in selected_rows))
            raw = stream.getvalue()
            digest = hashlib.sha256(raw).hexdigest()
            path = "archives/funding/" + source.filename
            private_write(root / path, raw)
            private_write(root / (path + ".CHECKSUM"), f"{digest}  {source.filename}\n".encode())
            selected = [row for row in selected_rows if int(start.timestamp()*1000) <= row[0] < int(end.timestamp()*1000)]
            manifest["sources"].append(dict(symbol=symbol, kind="funding", start=source.start.isoformat(),
                end=source.end.isoformat(), filename=source.filename, url=source.url, checksum_url=source.checksum_url,
                status="ok", evidence="official_archive_checksum", raw_path=path, checksum_path=path+".CHECKSUM",
                sha256=digest, size=len(raw), count=len(selected), first_open=selected[0][0] if selected else None,
                last_close=selected[-1][0] if selected else None, gaps=[], coverage="observed_only" if selected else "empty"))
    private_write(root / "manifest.json", json.dumps(manifest).encode())
    return root


def update_manifest(root, mutate):
    path = root / "manifest.json"
    manifest = json.loads(path.read_text())
    mutate(manifest)
    private_write(path, json.dumps(manifest).encode())


def supplement(tmp_path, rows=None):
    root = tmp_path / "supplement"
    root.mkdir(mode=0o700)
    rows = rows if rows is not None else [{"symbol": "BTCUSDT", "fundingTime": START_MS+1,
                                         "fundingRate": "-0.00010000", "markPrice": "1.2500"}]
    raw = json.dumps(rows).encode()
    private_write(root / "funding-BTCUSDT-00.raw.json", raw)
    page = dict(raw_path="funding-BTCUSDT-00.raw.json", raw_sha256=hashlib.sha256(raw).hexdigest(),
                request_start_ms=START_MS, retrieved_at="2026-10-09T07:12:35Z", status="validated",
                source_url=f"https://fapi.binance.com/fapi/v1/fundingRate?symbol=BTCUSDT&startTime={START_MS}&endTime={END_MS-1}&limit=1000")
    status = dict(schema_version="research-source-complements.v1", retrieval_complete=True,
                  start_ms=START_MS, end_exclusive_ms=END_MS, symbols=list(SYMBOLS),
                  metadata={"status":"captured"}, hypotheses={"contract_size":1},
                  funding=[dict(symbol="BTCUSDT", status="observed" if rows else "empty", coverage="observed_only",
                                retrieval_complete=True, observations=rows, pages=[page])])
    status["status_hash"] = _hash(status)
    private_write(root / "status.json", json.dumps(status).encode())
    return root


def test_archive_signed_decimal_jitter_immutable_provenance_and_repeatable_order(tmp_path):
    root = acquisition(tmp_path, symbols=("ETHUSDT", "BTCUSDT"))
    reader = f.FundingReader(root, START, END, symbols=("ETHUSDT", "BTCUSDT"))
    events = list(reader.iter_events())
    assert [(event.timestamp_ms, event.symbol) for event in events] == [
        (T+1, "BTCUSDT"), (T+1, "ETHUSDT"), (T+28800002,"BTCUSDT"), (T+28800002,"ETHUSDT")]
    assert events == list(reader.iter_events())
    assert events[0].rate == Decimal("-0.001") and events[0].declared_interval_hours == Decimal(8)
    assert events[0].observed_mark is None and len(events[0].raw_sha256) == 64
    assert events[0].record_sha256 == events[1].record_sha256
    assert events[0].source_hash != events[1].source_hash
    with pytest.raises(FrozenInstanceError):
        events[0].rate = Decimal(0)
    inventory = reader.inventory
    assert inventory.evidence_complete and inventory.coverage == "observed_only"
    assert inventory.symbols[0].count == 2 and inventory.symbols[0].max_abs_jitter_ms == 1
    assert len(inventory.acquisition_manifest_sha256) == 64
    assert inventory.inventory_hash.startswith("sha256:")


@pytest.mark.parametrize("field,value", [("url", "https://bad"), ("raw_path", "../escape"),
    ("checksum_path", "other"), ("sha256", "0"*64), ("size", 1), ("count", 0),
    ("first_open", 0), ("coverage", "complete"), ("evidence", "unverified")])
def test_archive_manifest_tampering_rejected(tmp_path, field, value):
    root = acquisition(tmp_path)
    update_manifest(root, lambda m: m["sources"][0].update({field:value}))
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))


@pytest.mark.parametrize("rows", [[(T, "8", "NaN")], [(T, "0", "0")],
    [(T, "8", "0"), (T, "8", "0")], [(T+1, "8", "0"), (T, "8", "0")]])
def test_invalid_archive_records_rejected(tmp_path, rows):
    root = acquisition(tmp_path, rows)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))


def test_missing_evidence_never_zero_or_complete_schedule(tmp_path):
    root = acquisition(tmp_path)
    update_manifest(root, lambda m: m["sources"][0].update(status="missing"))
    reader = f.FundingReader(root, START, END, symbols=("BTCUSDT",))
    assert list(reader.iter_events()) == []
    assert not reader.inventory.evidence_complete
    assert reader.inventory.symbols[0].continuity == "inconclusive"
    assert "missing_evidence" in dict(reader.inventory.symbols[0].issue_counts)


def test_transition_mismatch_is_inconclusive_ordinary_missing_payment_is_gap(tmp_path):
    root = acquisition(tmp_path, [(T+1,"4","0"), (T+14400006,"2","0"), (T+36000006,"2","0")])
    quality = f.FundingReader(root, START, END, symbols=("BTCUSDT",)).inventory.symbols[0]
    assert dict(quality.issue_counts)["transition_inconclusive"] == 1
    assert dict(quality.issue_counts)["interval_gap"] == 1
    assert quality.continuity == "gaps"


def test_boundaries_missing_context_is_distinct_from_internal_gap(tmp_path):
    root = acquisition(tmp_path, [(T+14400000,"8","0")])
    quality = f.FundingReader(root, START, END, symbols=("BTCUSDT",)).inventory.symbols[0]
    assert quality.continuity == "inconclusive"
    assert "boundary_context_absent" in dict(quality.issue_counts)
    assert "interval_gap" not in dict(quality.issue_counts)


def test_verified_rest_mark_and_explicit_hypothesis_remains_inconclusive_without_prior_interval(tmp_path):
    start, end = instant("2026-10-01T00:00:00Z"), instant("2026-10-09T06:00:00Z")
    root = acquisition(tmp_path, [], start=start, end=end)
    update_manifest(root, lambda m: m["sources"][0].update(status="missing"))
    tail = supplement(tmp_path)
    reader = f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail,
                             rest_interval_hypothesis="carry_forward_last_declared")
    event, = reader.iter_events()
    assert event.timestamp_ms == START_MS+1 and event.rate == Decimal("-0.00010000")
    assert event.observed_mark == Decimal("1.2500") and event.declared_interval_hours is None
    assert reader.inventory.evidence_complete
    assert reader.inventory.symbols[0].continuity == "inconclusive"
    assert reader.inventory.rest_hypothesis_hash.startswith("sha256:")


@pytest.mark.parametrize("mutation", [lambda s: s.update(status_hash="sha256:"+"0"*64),
    lambda s: s["funding"][0]["observations"][0].update(fundingRate="1"),
    lambda s: s["funding"][0]["pages"][0].update(source_url="https://bad"),
    lambda s: s["funding"][0]["pages"][0].update(raw_sha256="0"*64),
    lambda s: s["funding"][0].update(retrieval_complete=False)])
def test_supplement_tampering_rejected_even_when_rehashed(tmp_path, mutation):
    start, end = instant("2026-10-01T00:00:00Z"), instant("2026-10-09T06:00:00Z")
    root = acquisition(tmp_path, [], start=start, end=end)
    update_manifest(root, lambda m: m["sources"][0].update(status="missing"))
    tail = supplement(tmp_path)
    path = tail / "status.json"
    status = json.loads(path.read_text())
    mutation(status)
    if status["status_hash"] != "sha256:"+"0"*64:
        status.pop("status_hash")
        status["status_hash"] = _hash(status)
    private_write(path, json.dumps(status).encode())
    with pytest.raises(f.FundingError):
        f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail)


def test_unsafe_root_files_and_symbols_rejected(tmp_path):
    root = acquisition(tmp_path)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT", "BTCUSDT"))
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BAD",))
    os.chmod(root / "manifest.json", 0o644)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))


@pytest.mark.parametrize("value", [b'{"x":1,"x":2}', b'{"x":NaN}', b'\xff', b'{'])
def test_json_fail_closed(value):
    with pytest.raises(f.FundingError):
        f._json(value)


@pytest.mark.parametrize("value", ["bad", "2023-01-01", "2023-01-01T00:00:00.000001Z", 1,
    "2023-01-01T01:00:00+01:00"])
def test_exact_utc_required(value):
    with pytest.raises(f.FundingError):
        f._utc(value)


@pytest.mark.parametrize("value", [None, "NaN", "Infinity", "invalid"])
def test_decimal_exact_and_finite(value):
    with pytest.raises(f.FundingError):
        f._decimal(value)


def test_root_symlink_relative_nonprivate_and_missing_rejected(tmp_path):
    root = acquisition(tmp_path)
    link = tmp_path / "linked"
    link.symlink_to(root, target_is_directory=True)
    foreign = tmp_path / "foreign"
    foreign.mkdir(mode=0o755)
    for bad in (link, tmp_path / "missing", "relative", foreign):
        with pytest.raises(f.FundingError):
            f._root(bad)
    private_write(root / "not-directory", b"")
    with pytest.raises(f.FundingError):
        f._root(root / "not-directory")


def test_regular_private_bounded_files_and_no_symlinks(tmp_path):
    root = acquisition(tmp_path)
    for name in ("../escape", "/absolute", ""):
        with pytest.raises(f.FundingError):
            f._read(root, name, 100)
    with pytest.raises(f.FundingError):
        f._read(root, "missing", 100)
    with pytest.raises(f.FundingError):
        f._read(root, "manifest.json", 1)
    link = root / "linked"
    link.symlink_to(root / "manifest.json")
    with pytest.raises(f.FundingError):
        f._read(root, "linked", 10000)
    os.chmod(root, 0o755)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))


@pytest.mark.parametrize("mode", [0o755, 0o775])
def test_collector_intermediate_directory_is_safe_beneath_private_root(tmp_path, mode):
    root = acquisition(tmp_path)
    os.chmod(root / "archives", mode)
    assert len(list(f.FundingReader(root, START, END, symbols=("BTCUSDT",)).iter_events())) == 2


@pytest.mark.parametrize("mutation", [lambda m: m["identity"].update(source="other"),
    lambda m: m["sources"].append(m["sources"][0]), lambda m: m.pop("identity"),
    lambda m: m["sources"][0].pop("filename")])
def test_manifest_identity_and_duplicate_source_rejected(tmp_path, mutation):
    root = acquisition(tmp_path)
    update_manifest(root, mutation)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))


def test_unsupported_hypothesis_outside_window_and_source_list_filtering(tmp_path):
    root = acquisition(tmp_path)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",), rest_interval_hypothesis="8h")
    with pytest.raises(f.FundingError):
        f.FundingReader(root, END, START, symbols=("BTCUSDT",))
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START-timedelta(milliseconds=1), END, symbols=("BTCUSDT",))
    update_manifest(root, lambda m: m["sources"].append(dict(kind="klines", symbol="BTCUSDT")))
    reader = f.FundingReader(root, START, END-timedelta(milliseconds=1), symbols=("BTCUSDT",))
    assert len(list(reader.iter_events())) == 2


def replace_archive(root, content, member="BTCUSDT-fundingRate-2023-01.csv"):
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w") as archive:
        archive.writestr(member, content)
    raw = stream.getvalue()
    digest = hashlib.sha256(raw).hexdigest()
    name = "BTCUSDT-fundingRate-2023-01.zip"
    private_write(root / "archives/funding" / name, raw)
    private_write(root / "archives/funding" / (name+".CHECKSUM"), f"{digest}  {name}".encode())
    update_manifest(root, lambda m: m["sources"][0].update(sha256=digest, size=len(raw)))


@pytest.mark.parametrize("content,member", [(b"a,b,c\n", "wrong.csv"), (b"a,b\n", "BTCUSDT-fundingRate-2023-01.csv"),
    (b"calc_time,funding_interval_hours,last_funding_rate\n\n", "BTCUSDT-fundingRate-2023-01.csv"),
    (b'\xff', "BTCUSDT-fundingRate-2023-01.csv")])
def test_zip_member_and_csv_corruption_rejected(tmp_path, content, member):
    root = acquisition(tmp_path)
    replace_archive(root, content, member)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))


def test_archive_expansion_and_record_caps(tmp_path, monkeypatch):
    root = acquisition(tmp_path)
    monkeypatch.setattr(f, "MAX_CSV_BYTES", 1)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))
    monkeypatch.setattr(f, "MAX_CSV_BYTES", 100000)
    monkeypatch.setattr(f, "MAX_RECORD_BYTES", 1)
    with pytest.raises(f.FundingError):
        f.FundingReader(root, START, END, symbols=("BTCUSDT",))


def test_adjacent_context_month_join_and_no_future_supplement_diagnosis(tmp_path):
    jan = instant("2023-01-31T16:00:00Z")
    feb = instant("2023-02-01T00:00:00Z")
    root = acquisition(tmp_path, {"2023-01":[(int(jan.timestamp()*1000)+1,"8","0")],
        "2023-02":[(int(feb.timestamp()*1000)+2,"8","0"), (int(feb.timestamp()*1000)+28800003,"8","0")]},
        start=START, end=instant("2023-03-01T00:00:00Z"))
    tail = supplement(tmp_path)
    reader = f.FundingReader(root, feb, feb+timedelta(hours=4), symbols=("BTCUSDT",), supplement_root=tail)
    assert reader.inventory.symbols[0].continuity == "declared_interval_consistent"
    assert list(reader.iter_events())[0].timestamp_ms == int(feb.timestamp()*1000)+2
    assert all(b.kind == "archive" for b in reader.inventory.sources)
    assert any(b.role == "boundary_context" for b in reader.inventory.sources)


def test_left_boundary_absent_even_when_successor_context_exists(tmp_path):
    root = acquisition(tmp_path, [(T+14400000,"8","0"), (T+43200000,"8","0")])
    quality = f.FundingReader(root, START, START+timedelta(hours=8), symbols=("BTCUSDT",)).inventory.symbols[0]
    assert "boundary_context_absent" in dict(quality.issue_counts)


def test_tail_outside_adjacent_window_cannot_create_a_fake_gap(tmp_path):
    root = acquisition(tmp_path)
    tail = supplement(tmp_path)
    reader = f.FundingReader(root, START, END, symbols=("BTCUSDT",), supplement_root=tail)
    assert "interval_gap" not in dict(reader.inventory.symbols[0].issue_counts)
    assert all(source.kind == "archive" for source in reader.inventory.sources)


def test_overlap_archive_and_supplement_rejected(tmp_path):
    start,end = instant("2026-10-01T00:00:00Z"), instant("2026-10-09T06:00:00Z")
    root = acquisition(tmp_path, [(START_MS+1,"8","0")], start=start, end=end)
    tail = supplement(tmp_path)
    with pytest.raises(f.FundingError, match="overlap"):
        f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail)


def test_rest_carry_forward_hypothesis_is_named_and_hashed_and_no_hidden_default(tmp_path):
    start,end = instant("2026-09-30T16:00:00Z"), instant("2026-10-01T04:00:00Z")
    root = acquisition(tmp_path, {"2026-09":[(START_MS-28800000+1,"8","0")], "2026-10":[]}, start=start, end=end)
    update_manifest(root, lambda m: m["sources"][1].update(status="missing"))
    tail = supplement(tmp_path)
    assumed = f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail,
                             rest_interval_hypothesis="carry_forward_last_declared")
    assert assumed.inventory.symbols[0].continuity == "assumed_interval"
    unknown = f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail)
    assert unknown.inventory.symbols[0].continuity == "inconclusive"
    assert assumed.inventory.inventory_hash != unknown.inventory.inventory_hash


def test_issue_samples_bounded_counts_not_truncated(tmp_path, monkeypatch):
    monkeypatch.setattr(f, "MAX_ISSUES", 1)
    root = acquisition(tmp_path, [(T,"8","0"), (T+1000,"8","0"), (T+2000,"8","0")])
    quality = f.FundingReader(root, START, END, symbols=("BTCUSDT",)).inventory.symbols[0]
    assert len(quality.issues) == 1
    assert dict(quality.issue_counts)["interval_mismatch"] == 2


def test_empty_archive_provenance_remains_bound_in_inventory(tmp_path):
    root = acquisition(tmp_path, [])
    reader = f.FundingReader(root, START, END, symbols=("BTCUSDT",))
    assert reader.inventory.evidence_complete
    assert len(reader.inventory.sources) == 1
    assert reader.inventory.sources[0].role == "selected"
    assert reader.inventory.symbols[0].continuity == "inconclusive"


def test_empty_tail_provenance_and_source_status_bound(tmp_path):
    start,end = instant("2026-10-01T00:00:00Z"), instant("2026-10-09T06:00:00Z")
    root = acquisition(tmp_path, [], start=start, end=end)
    update_manifest(root, lambda m: m["sources"][0].update(status="missing"))
    tail = supplement(tmp_path, [])
    reader = f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail)
    assert reader.inventory.evidence_complete
    assert len(reader.inventory.sources) == 1
    assert reader.inventory.sources[0].kind == "rest"
    assert list(reader.iter_events()) == []


def test_source_overlap_is_rejected_before_inventory_claim_even_when_first_archive_record_is_after_window(tmp_path):
    start,end = instant("2026-10-01T00:00:00Z"), instant("2026-10-09T06:00:00Z")
    root = acquisition(tmp_path, [(START_MS+28800000,"8","0")], start=start, end=end)
    tail = supplement(tmp_path)
    with pytest.raises(f.FundingError, match="overlap"):
        f.FundingReader(root, start, start+timedelta(hours=4), symbols=("BTCUSDT",), supplement_root=tail)


def test_iterator_revalidates_root_privacy_and_frozen_raw_hash(tmp_path):
    root = acquisition(tmp_path)
    reader = f.FundingReader(root, START, END, symbols=("BTCUSDT",))
    os.chmod(root, 0o755)
    with pytest.raises(f.FundingError):
        list(reader.iter_events())
    os.chmod(root, 0o700)
    private_write(root / "archives/funding/BTCUSDT-fundingRate-2023-01.zip", b"corrupted")
    with pytest.raises(f.FundingError):
        list(reader.iter_events())


def test_rest_successor_boundary_labels_assumption_even_when_selected_events_are_declared(tmp_path):
    start,end = instant("2026-09-30T16:00:00Z"), instant("2026-10-01T00:00:00Z")
    root = acquisition(tmp_path, [(START_MS-28800000+1,"8","0")], start=start, end=end)
    tail = supplement(tmp_path)
    assumed = f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail,
                             rest_interval_hypothesis="carry_forward_last_declared")
    quality = assumed.inventory.symbols[0]
    assert quality.count == 1 and quality.interval_counts == (("8", 1),)
    assert quality.continuity == "assumed_interval"
    unknown = f.FundingReader(root, start, end, symbols=("BTCUSDT",), supplement_root=tail)
    assert unknown.inventory.symbols[0].continuity == "inconclusive"


@pytest.mark.parametrize("offset", [0, 1])
def test_short_empty_window_between_verified_regular_payments_is_consistent(tmp_path, offset):
    root = acquisition(tmp_path, [(T+offset,"8","0"), (T+28800000+offset+1,"8","0")])
    reader = f.FundingReader(root, START+timedelta(hours=1), START+timedelta(hours=2), symbols=("BTCUSDT",))
    quality = reader.inventory.symbols[0]
    assert list(reader.iter_events()) == []
    assert quality.count == 0 and quality.first_ms is None and quality.last_ms is None
    assert quality.continuity == "declared_interval_consistent"
    assert quality.issue_counts == () and reader.inventory.evidence_complete
    assert reader.inventory.coverage == "observed_only" and reader.inventory.schedule_attestation == "not_attested"


@pytest.mark.parametrize("rows,expected", [
    ([], "inconclusive"),
    ([(T,"8","0")], "inconclusive"),
    ([(T+28800000,"8","0")], "inconclusive"),
    ([(T,"8","0"), (T+57600000,"8","0")], "gaps"),
    ([(T,"4","0"), (T+14400005,"2","0")], "inconclusive"),
])
def test_empty_window_needs_both_witnesses_and_preserves_gap_or_transition(tmp_path, rows, expected):
    root = acquisition(tmp_path, rows)
    quality = f.FundingReader(root, START+timedelta(hours=1), START+timedelta(hours=2), symbols=("BTCUSDT",)).inventory.symbols[0]
    assert quality.count == 0 and quality.continuity == expected
    if expected == "gaps":
        assert "interval_gap" in dict(quality.issue_counts)
    elif len(rows) == 2:
        assert "transition_inconclusive" in dict(quality.issue_counts)
    else:
        assert "boundary_context_absent" in dict(quality.issue_counts)


def test_empty_archive_rest_bracket_uses_explicit_interval_hypothesis(tmp_path):
    start,end = instant("2026-09-30T16:00:00Z"), instant("2026-10-01T00:00:00Z")
    root = acquisition(tmp_path, [(START_MS-28800000+1,"8","0")], start=start, end=end)
    tail = supplement(tmp_path)
    left,right = start+timedelta(hours=1), start+timedelta(hours=2)
    assumed = f.FundingReader(root, left, right, symbols=("BTCUSDT",), supplement_root=tail,
                             rest_interval_hypothesis="carry_forward_last_declared")
    assert assumed.inventory.symbols[0].count == 0
    assert assumed.inventory.symbols[0].continuity == "assumed_interval"
    unknown = f.FundingReader(root, left, right, symbols=("BTCUSDT",), supplement_root=tail)
    assert unknown.inventory.symbols[0].continuity == "inconclusive"


def test_exact_start_and_exclusive_end_archive_payments_remain_distinct(tmp_path):
    root = acquisition(tmp_path, [(T,"8","0"), (T+28800000,"8","0")])
    reader = f.FundingReader(root, START, START+timedelta(hours=8), symbols=("BTCUSDT",))
    events = tuple(reader.iter_events())
    assert len(events) == 1 and events[0].timestamp_ms == T
    assert reader.inventory.symbols[0].continuity == "declared_interval_consistent"
