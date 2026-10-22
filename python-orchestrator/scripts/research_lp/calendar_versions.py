"""Calendar dates changed after the freeze (LP-G5 §2.4 row 15, §10.5): versioned official snapshots, as-of classification, union rule.

Official calendars move after a freeze: federalreserve.gov writes that each FOMC date is tentative until confirmed at the meeting
before it, bls.gov updates its schedule "as needed", a shutdown cancels or postpones a release, the NYSE may add a closure. The G5
Gate 2 spec freezes its lists (version 0, re-read at the freeze, `freeze_fill.calendars_verified_utc`); this module reads the later
**snapshots** of the official pages and applies one deterministic rule, written before any data (`VERSIONING_BLOCK`, copied verbatim
into the Gate 2 spec as `calendars.versioning` and checked for equality by its reader):

- **as of the decision**: each decision instant is classified with the calendar version available at that instant (the latest
  version retrieved at or before it). No later version can make an instant an event or an admissible control (no hindsight);
- **union rule for late or ambiguous changes**: an instant is retained only if it is admissible under **every** version. A moved
  date excludes its old and its new day, an added date is excluded, a cancelled date stays excluded; a NYSE half day listed by one
  version only gets both close exclusions. The rule only removes instants, depends on official calendars only, never on an outcome;
- a FROZEN run needs a **final** snapshot retrieved after the end of the scored window (refuse_run otherwise, nothing read);
- every version is logged (retrieval date, publication date stated by the source, sha256 of the saved page and of the snapshot file),
  and the changes between versions (added, cancelled, moved) are written in the result with the instants they remove;
- a snapshot carries date lists only: never a hypothesis, threshold, horizon, window end, universe or cost;
- official sources only (nyse.com, bls.gov, federalreserve.gov; ISM only from ismworld.org with official access); dates not yet
  published (JOLTS 2027, ISM) are never invented and stay blocking in `calendars.incomplete_at_draft`.

No market data is read here: snapshots are calendar pages saved by hand (`raw_sha256`) and transcribed to JSON.
"""

from __future__ import annotations

import dataclasses
import hashlib
import json
import re
from datetime import date as Date
from pathlib import Path
from urllib.parse import urlparse

from . import tape

SNAPSHOT_SCHEMA = "g5-calendar-snapshot/1"
CAPABILITY = "calendars_versioned_as_of_union"
VERSIONED_LISTS = ("nyse_holidays", "nyse_half_days", "cpi", "employment_situation", "fomc_statement")
OFFICIAL_HOSTS = {
    "nyse_holidays": ("www.nyse.com", "nyse.com"),
    "nyse_half_days": ("www.nyse.com", "nyse.com"),
    "cpi": ("www.bls.gov", "bls.gov"),
    "employment_situation": ("www.bls.gov", "bls.gov"),
    "fomc_statement": ("www.federalreserve.gov", "federalreserve.gov"),
}
NOT_STATED = "not_stated_by_source"
SNAPSHOT_KEYS = ("schema", "version", "retrieved_utc", "lists", "sources", "note")
LIST_KEYS = ("covers", "dates")
SOURCE_KEYS = ("list", "url", "published", "retrieved_utc", "raw_sha256")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
COVERS_RE = re.compile(r"^(?P<a>\d{4}-\d{2}-\d{2}) \.\. (?P<b>\d{4}-\d{2}-\d{2})$")

VERSIONING_BLOCK = {
    "rule_id": "calendar_versions/1 (LP-G5 10.5 ; protocol 2.4 row 15 resolved 2026-10-22)",
    "version_0": "the frozen lists of this spec, re-read on the official pages at the freeze (retrieved at freeze_fill.calendars_verified_utc)",
    "snapshot_schema": SNAPSHOT_SCHEMA,
    "snapshot_lists": list(VERSIONED_LISTS),
    "as_of": ("each decision instant (T01: t_arm = h-5 ; T05: h+5 ; a control: its own t_arm) is classified with the calendar version available at that "
              "instant, the latest version whose retrieved_utc is at or before it ; no later version can make an instant an event or an admissible control "
              "(no hindsight)"),
    "late_or_ambiguous_changes": ("an instant is retained only if it is admissible under every version (version 0 and every later snapshot): a date that "
                                  "any version places in a list is excluded (a moved date excludes its old and its new day, an added date is excluded, a "
                                  "cancelled date stays excluded) ; a NYSE half day listed by some versions only gets both the 13:00 and the 16:00 ET close "
                                  "exclusions ; the union only removes instants, never adds one, and depends on the official calendars only, never on an "
                                  "outcome"),
    "final_snapshot": ("a FROZEN run requires a final snapshot retrieved after the end of the scored window (data-gap extension included) and before the "
                       "run ; otherwise refuse_run, nothing read"),
    "log": ("every version is logged with its retrieval date, the publication date stated by the source (or not_stated_by_source), the sha256 of the saved "
            "official page and the sha256 of the snapshot file ; the changes between successive versions (added, cancelled, moved) are written in the result "
            "(calendar_versions) with the instants they remove"),
    "scope": ("a snapshot carries date lists only (snapshot_lists, each with the range its source covers): never a hypothesis, threshold, horizon, window "
              "end, universe or cost ; it never extends a window"),
    "official_sources": {name: list(hosts) for name, hosts in OFFICIAL_HOSTS.items()},
    "source_priority": ("the official source of each list and nothing else: nyse.com (holidays, half days), bls.gov (CPI, Employment Situation ; JOLTS for "
                        "the descriptive stratum), federalreserve.gov (FOMC) ; ISM only from ismworld.org with official access, otherwise UNAVAILABLE ; a "
                        "secondary source never overrides an official one"),
    "unpublished": ("dates not yet published by their official source (JOLTS 2027, ISM) are never invented: they stay in calendars.incomplete_at_draft "
                    "and block the freeze until recorded or withdrawn by a dated decision"),
    "gate1": ("Gate 1: past, realised dates (window closed on 2026-10-14) ; no later version is read ; an official correction of a past date published "
              "before the Gate 1 run blocks the run until a new freeze"),
}


class SnapshotError(ValueError):
    """A calendar snapshot (or its set) the rule refuses."""


def check_versioning_block(block, where: str = "calendars.versioning") -> None:
    if block != VERSIONING_BLOCK:
        raise SnapshotError(f"{where}: differs from research_lp.calendar_versions.VERSIONING_BLOCK (the implemented rule)")


def _date(text, where: str) -> Date:
    try:
        return Date.fromisoformat(str(text))
    except ValueError:
        raise SnapshotError(f"{where}: {text!r} is not a YYYY-MM-DD date") from None


def _iso_us(text, where: str) -> int:
    if not isinstance(text, str) or not re.match(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$", text):
        raise SnapshotError(f"{where}: {text!r} is not a UTC timestamp YYYY-MM-DDTHH:MM:SSZ")
    return tape.parse_iso_utc(text)


def _keys(document, where: str, keys: tuple) -> dict:
    if not isinstance(document, dict):
        raise SnapshotError(f"{where}: expected an object")
    unknown, missing = sorted(set(document) - set(keys)), sorted(set(keys) - set(document))
    if unknown or missing:
        raise SnapshotError(f"{where}: unknown keys {unknown} (a snapshot changes date lists only), missing keys {missing}")
    return document


def parse_snapshot(document, where: str) -> dict:
    """One snapshot file: schema, version, retrieval instant, per-list covered range and dates, official sources."""
    _keys(document, where, SNAPSHOT_KEYS)
    if document["schema"] != SNAPSHOT_SCHEMA:
        raise SnapshotError(f"{where}.schema: {document['schema']!r} is not {SNAPSHOT_SCHEMA}")
    version = document["version"]
    if not isinstance(version, int) or isinstance(version, bool) or version < 1:
        raise SnapshotError(f"{where}.version: a positive integer (version 0 is the frozen spec)")
    retrieved = _iso_us(document["retrieved_utc"], f"{where}.retrieved_utc")
    if not isinstance(document["note"], str):
        raise SnapshotError(f"{where}.note: a text (may be empty)")
    lists = document["lists"]
    if not isinstance(lists, dict) or not lists:
        raise SnapshotError(f"{where}.lists: a non-empty object keyed by {VERSIONED_LISTS}")
    parsed = {}
    for name, item in lists.items():
        if name not in VERSIONED_LISTS:
            raise SnapshotError(f"{where}.lists.{name}: not a date list of the rule {VERSIONED_LISTS} (a snapshot changes date lists only)")
        _keys(item, f"{where}.lists.{name}", LIST_KEYS)
        m = COVERS_RE.match(str(item["covers"]))
        if m is None:
            raise SnapshotError(f"{where}.lists.{name}.covers: 'YYYY-MM-DD .. YYYY-MM-DD'")
        lo, hi = _date(m.group("a"), f"{where}.lists.{name}.covers"), _date(m.group("b"), f"{where}.lists.{name}.covers")
        if hi < lo:
            raise SnapshotError(f"{where}.lists.{name}.covers: end before start")
        if not isinstance(item["dates"], list):
            raise SnapshotError(f"{where}.lists.{name}.dates: a list of YYYY-MM-DD")
        dates = frozenset(_date(d, f"{where}.lists.{name}.dates") for d in item["dates"])
        outside = sorted(d for d in dates if not lo <= d <= hi)
        if outside:
            raise SnapshotError(f"{where}.lists.{name}: {outside} outside its covers {lo} .. {hi}")
        parsed[name] = {"covers": (lo, hi), "dates": dates}
    sources = document["sources"]
    if not isinstance(sources, list) or not sources:
        raise SnapshotError(f"{where}.sources: a non-empty list (one official page per list at least)")
    seen = set()
    out_sources = []
    for i, src in enumerate(sources):
        w = f"{where}.sources[{i}]"
        _keys(src, w, SOURCE_KEYS)
        name = src["list"]
        if name not in parsed:
            raise SnapshotError(f"{w}.list: {name!r} is not a list of this snapshot")
        url = urlparse(str(src["url"]))
        if url.scheme != "https" or url.hostname not in OFFICIAL_HOSTS[name]:
            raise SnapshotError(f"{w}.url: {src['url']!r} is not an https page of the official source of {name} {OFFICIAL_HOSTS[name]} (source_priority)")
        published = src["published"]
        if published != NOT_STATED:
            _date(published, f"{w}.published")
        src_retrieved = _iso_us(src["retrieved_utc"], f"{w}.retrieved_utc")
        if src_retrieved > retrieved:
            raise SnapshotError(f"{w}.retrieved_utc: after the snapshot's retrieved_utc")
        if not isinstance(src["raw_sha256"], str) or not SHA256_RE.match(src["raw_sha256"]):
            raise SnapshotError(f"{w}.raw_sha256: the sha256 of the saved official page (lower-case hex)")
        seen.add(name)
        out_sources.append(dict(src))
    missing = sorted(set(parsed) - seen)
    if missing:
        raise SnapshotError(f"{where}.sources: no official page for {missing}")
    return {"version": version, "retrieved_us": retrieved, "retrieved_utc": document["retrieved_utc"], "lists": parsed, "sources": out_sources,
            "note": document["note"]}


def load_snapshots(directory: Path | None) -> list[dict]:
    """Every `*.json` of the directory, versions 1..K contiguous, retrieval instants strictly increasing; each file's sha256 kept."""
    if directory is None:
        return []
    directory = Path(directory)
    if not directory.is_dir():
        raise SnapshotError(f"{directory}: not a directory of calendar snapshots")
    out = []
    for path in sorted(directory.glob("*.json")):
        raw = path.read_bytes()
        try:
            document = json.loads(raw)
        except json.JSONDecodeError as error:
            raise SnapshotError(f"{path.name}: not JSON ({error})") from None
        item = parse_snapshot(document, path.name)
        item["file"] = path.name
        item["sha256"] = hashlib.sha256(raw).hexdigest()
        out.append(item)
    out.sort(key=lambda s: s["version"])
    for i, item in enumerate(out, start=1):
        if item["version"] != i:
            raise SnapshotError(f"{item['file']}: version {item['version']}, expected {i} (versions 1..K contiguous)")
        if i > 1 and item["retrieved_us"] <= out[i - 2]["retrieved_us"]:
            raise SnapshotError(f"{item['file']}: retrieved_utc not after version {i - 1}'s")
    return out


def build_versions(calendars: dict, verified_utc: str | None, snapshots: list[dict]) -> list[dict]:
    """Version 0 = the spec's lists (retrieved at freeze_fill.calendars_verified_utc, unknown in a draft); version k = version k-1 with
    the dates of each list snapshot k carries replaced inside that list's covered range (dates outside it are kept)."""
    v0_us = tape.parse_iso_utc(verified_utc) if verified_utc else None
    versions = [{"version": 0, "retrieved_us": v0_us, "retrieved_utc": verified_utc, "lists": {n: frozenset(calendars.get(n, ())) for n in VERSIONED_LISTS},
                 "file": None, "sha256": None, "sources": [], "note": "frozen spec lists"}]
    for snap in snapshots:
        if v0_us is not None and snap["retrieved_us"] <= v0_us:
            raise SnapshotError(f"{snap['file']}: retrieved_utc {snap['retrieved_utc']} not after version 0 ({verified_utc}): a pre-freeze reading belongs in the spec")
        lists = dict(versions[-1]["lists"])
        for name, item in snap["lists"].items():
            lo, hi = item["covers"]
            lists[name] = frozenset(d for d in lists[name] if not lo <= d <= hi) | item["dates"]
        versions.append({"version": snap["version"], "retrieved_us": snap["retrieved_us"], "retrieved_utc": snap["retrieved_utc"], "lists": lists,
                         "file": snap["file"], "sha256": snap["sha256"], "sources": snap["sources"], "note": snap["note"]})
    return versions


def changes(versions: list[dict]) -> list[dict]:
    """The log of the changes between successive versions: per list, the dates added and removed; a removal and an addition in the
    same calendar month of one list are paired as `moved` (earliest with earliest), the rest are `added` / `cancelled`."""
    log = []
    for prev, cur in zip(versions, versions[1:]):
        for name in VERSIONED_LISTS:
            removed, added = sorted(prev["lists"][name] - cur["lists"][name]), sorted(cur["lists"][name] - prev["lists"][name])
            moved = []
            for d in list(removed):
                same = [a for a in added if (a.year, a.month) == (d.year, d.month)]
                if same:
                    moved.append((d, same[0]))
                    removed.remove(d)
                    added.remove(same[0])
            for old, new in moved:
                log.append({"from_version": prev["version"], "to_version": cur["version"], "retrieved_utc": cur["retrieved_utc"], "list": name, "kind": "moved",
                            "old": old.isoformat(), "new": new.isoformat()})
            for d in added:
                log.append({"from_version": prev["version"], "to_version": cur["version"], "retrieved_utc": cur["retrieved_utc"], "list": name, "kind": "added",
                            "new": d.isoformat()})
            for d in removed:
                log.append({"from_version": prev["version"], "to_version": cur["version"], "retrieved_utc": cur["retrieved_utc"], "list": name, "kind": "cancelled",
                            "old": d.isoformat()})
    return log


def union_lists(versions: list[dict]) -> tuple[dict, frozenset]:
    """Each list = the union over every version; the half days listed by some versions only (both close exclusions apply)."""
    lists = {name: frozenset().union(*(v["lists"][name] for v in versions)) for name in VERSIONED_LISTS}
    common_half = frozenset.intersection(*(v["lists"]["nyse_half_days"] for v in versions)) if versions else frozenset()
    return lists, lists["nyse_half_days"] - common_half


def as_of_index(versions: list[dict], t_us: int) -> int:
    """Index of the version available at t_us: the latest with retrieved_us <= t_us (version 0 when its instant is unknown or later)."""
    best = 0
    for i, v in enumerate(versions):
        if v["retrieved_us"] is not None and v["retrieved_us"] <= t_us:
            best = i
    return best


def _with_lists(spec, lists: dict, disputed: frozenset):
    """A copy of the spec whose calendar lists are `lists` (macro releases and sessions read spec.calendars; the local-time generators'
    excluded days are rebuilt from their references)."""
    calendars = dict(spec.calendars)
    calendars.update(lists)
    calendars["nyse_half_days_disputed"] = disputed
    types = []
    for t in spec.types:
        gen = t.generator
        if gen.get("kind") == "local_time":
            gen = {**gen, "exclude_days": frozenset(d for r in gen["exclude_refs"] for d in calendars[r])}
            t = dataclasses.replace(t, generator=gen)
        types.append(t)
    return dataclasses.replace(spec, calendars=calendars, types=tuple(types))


def _local_time_event(tspec, spec_k, day: Date) -> bool:
    from . import g5_eval as g5

    gen = tspec.generator
    return g5.day_matches(gen["days"], day, spec_k.calendars) and day not in gen["exclude_days"]


def audit_as_of(spec, versions: list[dict], union_spec, lo_us: int, hi_us: int) -> dict:
    """Per local-time type (T05): every candidate day of [lo ; hi) classified with the version available at its decision instant and
    with the union; a day kept by the union and not by its as-of version would be hindsight: refused (never happens by construction)."""
    from . import g5_eval as g5

    specs = [_with_lists(spec, v["lists"], frozenset()) for v in versions]
    out = {}
    for t in union_spec.types:
        if t.generator.get("kind") != "local_time":
            continue
        base = specs[0].type(t.name)
        candidates = g5.local_time_instants(dataclasses.replace(base, generator={**base.generator, "days": "all", "exclude_days": frozenset()}),
                                            specs[0], lo_us, hi_us, apply_exclusions=False)
        removed, retained, by_version = [], 0, {}
        for moment, day, _ in candidates:
            decision = moment + int(t.arm_min) * g5.MINUTE_US
            k = as_of_index(versions, decision)
            by_version[k] = by_version.get(k, 0) + 1
            as_of = _local_time_event(specs[k].type(t.name), specs[k], day)
            kept = _local_time_event(t, union_spec, day)
            if kept and not as_of:
                raise SnapshotError(f"{t.name} {day}: retained by the union but not by its as-of version {versions[k]['version']} (hindsight)")
            retained += int(kept)
            if as_of and not kept:
                removed.append({"day": day.isoformat(), "as_of_version": versions[k]["version"], "reason": "excluded by another version (union rule)"})
        out[t.name] = {"decision_offset_min": int(t.arm_min), "candidate_days": len(candidates), "retained": retained, "removed_by_union": removed,
                       "as_of_version_counts": {str(versions[k]["version"]): n for k, n in sorted(by_version.items())}}
    return out


def apply_to_spec(spec, directory: Path | None, scored_end_us: int, require_final: bool) -> tuple[object, dict]:
    """Read the snapshots, build the versions, refuse a FROZEN run without a final snapshot, and return the spec whose lists are the
    union with the audit document (`calendar_versions` of the result). Called before any market value is read."""
    snapshots = load_snapshots(directory)
    versions = build_versions(spec.calendars, spec.freeze_fill.get("calendars_verified_utc"), snapshots)
    last = versions[-1]
    final_ok = len(versions) > 1 and last["retrieved_us"] is not None and last["retrieved_us"] >= scored_end_us
    if require_final and not final_ok:
        raise SnapshotError(f"no final calendar snapshot retrieved at or after the end of the scored window {tape.iso_utc(scored_end_us)} "
                            f"(VERSIONING_BLOCK.final_snapshot): refuse_run")
    lists, disputed = union_lists(versions)
    union_spec = _with_lists(spec, lists, disputed)
    lo_us = spec.gate2["t0_us"] if getattr(spec, "gate2", None) else spec.start_us
    document = {
        "rule": VERSIONING_BLOCK["rule_id"],
        "versions": [{"version": v["version"], "retrieved_utc": v["retrieved_utc"], "file": v["file"], "sha256": v["sha256"], "note": v["note"],
                      "sources": v["sources"]} for v in versions],
        "snapshots_sha256": hashlib.sha256("".join(v["sha256"] or "" for v in versions).encode()).hexdigest(),
        "final_snapshot": final_ok,
        "changes": changes(versions),
        "half_days_disputed": sorted(d.isoformat() for d in disputed),
        "union_minus_version_0": {n: sorted(d.isoformat() for d in lists[n] - versions[0]["lists"][n]) for n in VERSIONED_LISTS},
        "as_of": audit_as_of(spec, versions, union_spec, lo_us, scored_end_us),
    }
    return union_spec, document


def scored_end_us(spec) -> int:
    """End of the scored window with the largest data-gap extension the spec allows (Gate 2), else the spec window end."""
    gate2 = getattr(spec, "gate2", None)
    if not gate2:
        return spec.end_us
    from . import g5_gate2

    return max(g5_gate2.type_window(spec, t, int(gate2["max_gap_days"]))[1] for t in spec.types)
