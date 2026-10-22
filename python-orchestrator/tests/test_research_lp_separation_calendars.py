"""Offline tests of P-L2-1 (D14, 2026-10-22: the L2 capture pilot's development window kept out of every decisive use of the LP
programme, `research_lp.separation`) and of the rule for calendar dates changed after the freeze (LP-G5 §10.5, §2.4 row 15,
`research_lp.calendar_versions`).

Covered: the block's instants and its sha256 (the identity the pilot checks); the day files that meet the excluded interval; the
Gate 2 reader (rule block, T0 and first observation after the floor, a synthetic block accepted at DRAFT only); the capture loader
dropping the excluded rows; `research_lp count` (P3) refused before the floor; the calendar snapshots (official sources only, date
lists only, contiguous versions, retrieval after the freeze); the union rule (moved, added, cancelled dates; a half day of one version
gets both close exclusions; never an instant added), the as-of audit at each decision instant and the final snapshot required at
FROZEN. Synthetic data only; no socket (autouse guard)."""

from __future__ import annotations

import copy
import json
import socket
import urllib.request
from datetime import date
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import calendar_versions as cv
from scripts.research_lp import cli, g5_eval, g5_gate2, separation as sep, tape
from tests.test_research_lp_g5_gate2 import BASE, BTC, FROZEN_GATE1, frozen, refused, short_spec, write_capture

US = tape.SECOND_US
MINUTE_US = 60 * US


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(socket, "getaddrinfo", refuse)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)


def iso(text: str) -> int:
    return tape.parse_iso_utc(text)


# ----------------------------------------------------------------------------- P-L2-1: the block


def test_the_block_its_instants_and_its_sha256():
    b = sep.BLOCK
    assert b["reserved_interval_utc"] == ["2026-11-02T00:00:00Z", "2026-11-03T00:00:00Z"] and b["margin_s"] == 12 * 3600
    assert b["separation_floor_utc"] == "2026-11-03T12:00:00Z" and b["excluded_interval_utc"] == ["2026-11-02T00:00:00Z", "2026-11-03T12:00:00Z"]
    assert b["g5_gate2_t0_min_utc"] == "2026-11-04T00:00:00Z" and b["max_lookback_min"] == 540
    assert iso(b["g5_gate2_t0_min_utc"]) >= sep.floor_us() + 540 * MINUTE_US > iso(b["g5_gate2_t0_min_utc"]) - tape.DAY_S * US
    assert set(b["applies_to"]) == {"g5_gate1", "g5_gate2", "g2", "g4_gate2", "p3", "pilot"} and "P-L2-1" in b["decision"]
    # The pilot pins this value (l2-pilot-spec/2, separation.g5_gate2_block_sha256): any change of the block is a new version of both.
    assert sep.block_sha256() == sep.block_sha256(json.loads(json.dumps(sep.BLOCK))) == "4b96e2bd3987e123b597b6534a9c464580d560db399401849cf992af004d489e"
    assert sep.block_sha256(BASE["pilot_separation"]) == sep.block_sha256()
    sep.check_block(copy.deepcopy(sep.BLOCK))
    with pytest.raises(ValueError, match="differs"):
        sep.check_block({**sep.BLOCK, "margin_s": 0})


def test_the_excluded_interval_by_instant_and_by_day_file():
    ts = [iso("2026-11-01T23:59:59Z"), iso("2026-11-02T00:00:00Z"), iso("2026-11-03T11:59:59Z"), iso("2026-11-03T12:00:00Z"), iso("2026-11-04T00:00:00Z")]
    assert sep.inside(ts).tolist() == [False, True, True, False, False]
    with pytest.raises(sep.SeparationViolation, match="2 observation"):
        sep.refuse_inside(ts[:3], "a test layout")
    sep.refuse_inside(ts[3:], "a test layout")
    assert sep.dates_touching(["2026-11-01", "2026-11-02", "2026-11-03", "2026-11-04", "2026-11-05"]) == ["2026-11-02", "2026-11-03"]


# ----------------------------------------------------------------------------- P-L2-1: the Gate 2 reader and loader


def test_the_gate2_reader_puts_every_first_observation_after_the_floor():
    spec, _ = g5_eval.load_spec(Path(g5_gate2.__file__).parent / "specs" / "g5_gate2_spec.json")
    ps = spec.gate2["pilot_separation"]
    assert ps["is_rule"] and ps["draft_deviations"] == [] and spec.gate2["t0_date"] == date(2026, 11, 4)
    assert all(iso(v) >= sep.floor_us() for v in ps["first_observation_by_type_utc"].values())
    # A FROZEN spec at the earliest T0 passes; one day earlier is refused (the window start too), and so is any other block.
    assert g5_eval.parse_spec(frozen(copy.deepcopy(BASE)), FROZEN_GATE1).gate2["pilot_separation"]["is_rule"]
    refused(lambda d: d["window"].update({"start": "2026-11-03"}), r"pilot_separation \(P-L2-1\).*window.start 2026-11-03", base=frozen(copy.deepcopy(BASE)))
    refused(lambda d: d["pilot_separation"].update({"reading": "anything"}), "a FROZEN spec carries research_lp.separation.BLOCK", base=frozen(copy.deepcopy(BASE)))
    refused(lambda d: d["pilot_separation"].pop("rule"), "pilot_separation")
    refused(lambda d: d["pilot_separation"].update({"separation_floor_utc": "2026-11-03T06:00:00Z"}), "end of the reserved interval \\+ margin_s")
    refused(lambda d: d["pilot_separation"].update({"g5_gate2_t0_min_utc": "2026-11-05T00:00:00Z"}), "first 00:00 UTC at or after")
    # At DRAFT a window starting inside the excluded interval is accepted (synthetic tests) and reported.
    draft = copy.deepcopy(BASE)
    draft["window"]["start"] = "2026-11-02"
    deviations = g5_eval.parse_spec(draft).gate2["pilot_separation"]["draft_deviations"]
    assert any("window.start 2026-11-02" in d for d in deviations) and any("T01_funding_8h: first observation" in d for d in deviations)


def test_the_capture_loader_drops_the_excluded_rows_and_opens_no_file_of_the_pilot_day(tmp_path: Path, monkeypatch):
    root = tmp_path / "capture"
    write_capture(root, days=3)
    real = short_spec(mutate=lambda d: d.update({"pilot_separation": copy.deepcopy(sep.BLOCK)}))  # DRAFT, window 2026-11-02 -> 11-05, the real block
    assert real.gate2["pilot_separation"]["draft_deviations"]  # reported at DRAFT (refused at FROZEN)
    opened = []
    original = tape.load_day

    def spy(out_dir, layout, inst, day, allow_pending=True):
        opened.append(day)
        return original(out_dir, layout, inst, day, allow_pending)

    monkeypatch.setattr(tape, "load_day", spy)
    s = g5_eval.load_capture_series(root, real.instrument(BTC), real, {"instId": BTC}, index_dir=root)
    assert min(opened) == "2026-11-03" and "2026-11-02" not in opened
    assert s.coverage["rows_in_pilot_separation_dropped"]["trades"] == 12 * 3600 // 10 and s.coverage["rows_in_pilot_separation_dropped"]["mark"] == 12 * 60
    k = s.index_of([iso("2026-11-03T11:59:00Z"), iso("2026-11-03T12:01:00Z")])
    assert np.isnan(s.close[k[0]]) and np.isfinite(s.close[k[1]])
    # The synthetic block of the other tests leaves the same tape untouched.
    synthetic = short_spec()
    assert g5_eval.load_capture_series(root, synthetic.instrument(BTC), synthetic, {"instId": BTC}, index_dir=root).coverage["rows_in_pilot_separation_dropped"]["trades"] == 0


def test_p3_counting_is_refused_before_the_separation_floor(tmp_path: Path, capsys):
    for argv in (["count", "--not-before", "2026-11-02T00:00:00Z"], ["count", "--not-before", "2026-11-03T11:59:59Z"],
                 ["capture", "--counting-only", "--not-before", "2026-11-03T00:00:00Z"]):
        out = tmp_path / "p3"
        assert cli.main([argv[0], "--out", str(out), "--inst-ids", BTC, *argv[1:]]) == 2
        assert "reason=not_before_inside_pilot_separation" in capsys.readouterr().err and not out.exists()
    assert cli.build_parser().parse_args(["count", "--out", str(tmp_path / "x"), "--inst-ids", BTC, "--separation-waiver-reason", "dev"]).separation_waiver_reason == "dev"


# ----------------------------------------------------------------------------- calendar versions (protocol 10.5)


def snapshot(version: int, retrieved: str, lists: dict, **overrides) -> dict:
    hosts = {"cpi": "https://www.bls.gov/schedule/news_release/cpi.htm", "employment_situation": "https://www.bls.gov/schedule/news_release/empsit.htm",
             "fomc_statement": "https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm", "nyse_holidays": "https://www.nyse.com/markets/hours-calendars",
             "nyse_half_days": "https://www.nyse.com/markets/hours-calendars"}
    document = {"schema": cv.SNAPSHOT_SCHEMA, "version": version, "retrieved_utc": retrieved, "note": "test",
                "lists": {name: {"covers": covers, "dates": dates} for name, (covers, dates) in lists.items()},
                "sources": [{"list": name, "url": hosts[name], "published": "2026-11-20", "retrieved_utc": retrieved, "raw_sha256": "a" * 64} for name in lists]}
    document.update(overrides)
    return document


def write(directory: Path, *documents: dict) -> Path:
    directory.mkdir(parents=True, exist_ok=True)
    for d in documents:
        (directory / f"v{d['version']}.json").write_text(json.dumps(d))
    return directory


def frozen_spec():
    return g5_eval.parse_spec(frozen(copy.deepcopy(BASE)), FROZEN_GATE1)


def test_the_spec_carries_the_rule_and_the_reader_refuses_another_one():
    assert BASE["calendars"]["versioning"] == cv.VERSIONING_BLOCK and "fomc_statement" not in BASE["calendars"]["incomplete_at_draft"]
    refused(lambda d: d["calendars"]["versioning"].update({"as_of": "the final version for every instant"}), "VERSIONING_BLOCK")
    refused(lambda d: d["calendars"].pop("versioning"), "calendars")


@pytest.mark.parametrize("mutate, match", [
    (lambda d: d.update({"threshold_bp": 5}), "unknown keys"),  # a snapshot changes date lists only
    (lambda d: d["lists"].update({"jolts": {"covers": "2027-01-01 .. 2027-01-31", "dates": []}}), "not a date list of the rule"),
    (lambda d: d["sources"][0].update({"url": "https://www.investing.com/economic-calendar"}), "official source"),
    (lambda d: d["sources"][0].update({"url": "http://www.bls.gov/schedule/news_release/cpi.htm"}), "official source"),
    (lambda d: d["sources"].clear(), "non-empty list"),
    (lambda d: d["sources"][0].update({"raw_sha256": "x"}), "raw_sha256"),
    (lambda d: d["sources"][0].update({"published": "late november"}), "published"),
    (lambda d: d["sources"][0].update({"retrieved_utc": "2026-12-02T00:00:00Z"}), "after the snapshot"),
    (lambda d: d["lists"]["cpi"].update({"dates": ["2027-02-10"]}), "outside its covers"),
    (lambda d: d.update({"version": 0}), "positive integer"),
    (lambda d: d.update({"retrieved_utc": "2026-12-01"}), "UTC timestamp"),
])
def test_a_snapshot_is_refused_unless_official_dated_and_lists_only(tmp_path: Path, mutate, match):
    document = snapshot(1, "2026-12-01T10:00:00Z", {"cpi": ("2027-01-01 .. 2027-01-31", ["2027-01-14"])})
    mutate(document)
    with pytest.raises(cv.SnapshotError, match=match):
        cv.load_snapshots(write(tmp_path / "s", document))


def test_snapshot_versions_are_contiguous_increasing_and_after_the_freeze(tmp_path: Path):
    lists = {"cpi": ("2027-01-01 .. 2027-01-31", ["2027-01-13"])}
    with pytest.raises(cv.SnapshotError, match="expected 1"):
        cv.load_snapshots(write(tmp_path / "a", snapshot(2, "2026-12-01T10:00:00Z", lists)))
    with pytest.raises(cv.SnapshotError, match="not after version 1"):
        cv.load_snapshots(write(tmp_path / "b", snapshot(1, "2026-12-01T10:00:00Z", lists), snapshot(2, "2026-12-01T10:00:00Z", lists)))
    spec = frozen_spec()  # calendars_verified_utc 2026-11-02T10:00:00Z
    with pytest.raises(cv.SnapshotError, match="not after version 0"):
        cv.build_versions(spec.calendars, spec.freeze_fill["calendars_verified_utc"], cv.load_snapshots(write(tmp_path / "c", snapshot(1, "2026-11-01T00:00:00Z", lists))))


def test_the_union_rule_the_change_log_and_the_as_of_audit(tmp_path: Path):
    """v1 (2026-12-01): CPI of January moved 01-13 -> 01-14, FOMC 2027-01-27 cancelled, an unscheduled FOMC added on 2027-02-03; v2 (2027-04-02,
    the final one): a NYSE closure added on 2027-02-22 and a half day on 2027-03-25. Every date any version lists is excluded; the as-of
    version decides nothing the union would add; the days the union removes are listed with the version of their decision instant."""
    spec = frozen_spec()
    v1 = snapshot(1, "2026-12-01T10:00:00Z", {"cpi": ("2027-01-01 .. 2027-01-31", ["2027-01-14"]), "fomc_statement": ("2027-01-01 .. 2027-02-28", ["2027-02-03"])})
    v2 = snapshot(2, "2027-04-02T10:00:00Z", {"nyse_holidays": ("2027-02-01 .. 2027-02-28", ["2027-02-15", "2027-02-22"]),
                                              "nyse_half_days": ("2027-03-01 .. 2027-03-31", ["2027-03-25"])})
    directory = write(tmp_path / "snapshots", v1, v2)
    end = cv.scored_end_us(spec)
    assert end == iso("2027-04-01T00:00:00Z")  # D7: no extension beyond 2027-03-31
    union, doc = cv.apply_to_spec(spec, directory, end, require_final=True)
    assert doc["final_snapshot"] and [v["version"] for v in doc["versions"]] == [0, 1, 2] and all(v["sha256"] for v in doc["versions"][1:])
    kinds = {(c["list"], c["kind"], c.get("old"), c.get("new")) for c in doc["changes"]}
    assert kinds == {("cpi", "moved", "2027-01-13", "2027-01-14"), ("fomc_statement", "cancelled", "2027-01-27", None),
                     ("fomc_statement", "added", None, "2027-02-03"), ("nyse_holidays", "added", None, "2027-02-22"),
                     ("nyse_half_days", "added", None, "2027-03-25")}
    assert {date(2027, 1, 13), date(2027, 1, 14)} <= union.calendars["cpi"] and {date(2027, 1, 27), date(2027, 2, 3)} <= union.calendars["fomc_statement"]
    assert date(2027, 2, 22) in union.calendars["nyse_holidays"] and doc["half_days_disputed"] == ["2027-03-25"]
    # T05: the union only removes instants (01-14 CPI under v1, 02-22 closed under v2), never adds one.
    t05 = spec.type("T05_us_open")
    before = {d for _, d, _ in g5_eval.local_time_instants(t05, spec)}
    after = {d for _, d, _ in g5_eval.local_time_instants(union.type("T05_us_open"), union)}
    assert after <= before and before - after == {date(2027, 1, 14), date(2027, 2, 22)}
    audit = doc["as_of"]["T05_us_open"]
    removed = {r["day"]: r["as_of_version"] for r in audit["removed_by_union"]}
    # 01-13: CPI under v0 only; its decision (14:35 UTC) sees v1, which moved the CPI away: kept as of the decision, excluded by the union.
    # 02-22: the closure was published after its decision instant (v2 retrieved 2027-04-02): kept as of the decision, excluded by the union.
    assert removed == {"2027-01-13": 1, "2027-02-22": 1} and audit["retained"] == len(after)
    assert set(audit["as_of_version_counts"]) == {"0", "1"}  # no T05 decision instant is after the final snapshot
    # The half day of v2 only: both the 13:00 ET and the 16:00 ET close exclusions on 2027-03-25.
    lo, hi = iso("2027-03-25T00:00:00Z"), iso("2027-03-26T00:00:00Z")
    closes = sorted(tape.iso_utc(a)[11:16] for a, b, name in g5_eval.session_intervals(union, lo, hi) if name == "us_cash_close" and lo <= a < hi)
    assert closes == ["17:00", "20:00"]  # EDT since 2027-03-14
    assert sorted(tape.iso_utc(a)[11:16] for a, b, name in g5_eval.session_intervals(spec, lo, hi) if name == "us_cash_close" and lo <= a < hi) == ["20:00"]
    # Macro footprints of every version (FOMC 01-27 cancelled stays excluded, 02-03 added excluded).
    fomc = {tape.iso_utc(a)[:10] for a, b, name in g5_eval.macro_intervals(union, iso("2027-01-20T00:00:00Z"), iso("2027-02-10T00:00:00Z")) if "fomc" in name.lower()}
    assert {"2027-01-27", "2027-02-03"} <= fomc
    # A FROZEN run without a snapshot retrieved after the scored window is refused (nothing read); a draft reads its version 0 only.
    with pytest.raises(cv.SnapshotError, match="no final calendar snapshot"):
        cv.apply_to_spec(spec, write(tmp_path / "early", v1), end, require_final=True)
    with pytest.raises(cv.SnapshotError, match="no final calendar snapshot"):
        cv.apply_to_spec(spec, None, end, require_final=True)
    draft = g5_eval.parse_spec(copy.deepcopy(BASE))
    same, doc0 = cv.apply_to_spec(draft, None, cv.scored_end_us(draft), require_final=False)
    assert doc0["changes"] == [] and not doc0["final_snapshot"] and same.calendars["cpi"] == draft.calendars["cpi"]
