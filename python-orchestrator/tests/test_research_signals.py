from __future__ import annotations

import hashlib
import io
import json
import subprocess
import sys
import zipfile
from copy import deepcopy
from dataclasses import replace
from datetime import datetime
from datetime import timezone
from pathlib import Path

import pytest

from app.backtesting.research.binance_history import KLINE_HEADER, SOURCE, plan_archives
from app.backtesting.research.signals import SignalError, run_signals, select_sources
from app.backtesting.research import signals
from app.backtesting.research import signal_sources
from app.modern_trading_contracts import (CanonicalEffectiveConfigSnapshot,
                                          calculate_config_hash, calculate_snapshot_hash)

START = "2023-01-01T00:00:00Z"
END = "2023-01-01T00:02:00Z"


def _raw(symbol: str, times: tuple[int, ...]) -> bytes:
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w") as archive:
        content = "\n".join(f"{t},100,102,99,101,2,{t + 59999},200,3,1,100,0" for t in times)
        archive.writestr(f"{symbol}-1m-2023-01-01.csv", content)
    return stream.getvalue()


def dataset(root: Path, *, times: tuple[int, ...] = (1672531200000, 1672531260000),
            include_funding: bool = False) -> Path:
    root.mkdir()
    symbol = "BTCUSDT"
    source = plan_archives(symbol, datetime.fromisoformat(START.replace("Z", "+00:00")),
                           datetime.fromisoformat(END.replace("Z", "+00:00")))[0]
    raw = _raw(symbol, times)
    digest = hashlib.sha256(raw).hexdigest()
    raw_path = f"archives/klines/{source.filename}"
    (root / "archives/klines").mkdir(parents=True)
    (root / raw_path).write_bytes(raw)
    check = f"{digest}  {source.filename}".encode()
    (root / (raw_path + ".CHECKSUM")).write_bytes(check)
    entries = [{"symbol": symbol, "kind": "klines", "start": START.replace("Z", "+00:00"),
                "end": END.replace("Z", "+00:00"), "status": "ok", "error": None,
                "filename": source.filename, "url": source.url, "checksum_url": source.checksum_url,
                "evidence": "official_archive_checksum", "raw_path": raw_path,
                "checksum_path": raw_path + ".CHECKSUM", "sha256": digest,
                "size": len(raw), "count": 2, "first_open": 1672531200000,
                "last_close": 1672531319999, "gaps": [], "coverage": "complete"}]
    if include_funding:
        entries.append({"symbol": symbol, "kind": "funding", "start": START,
                        "end": END, "status": "missing"})
    manifest = {"identity": {"schema": 1, "source": SOURCE, "symbols": [symbol],
                             "start": START.replace("Z", "+00:00"),
                             "end": END.replace("Z", "+00:00"),
                             "include_funding": include_funding},
                "complete": not include_funding, "sources": entries}
    (root / "manifest.json").write_text(json.dumps(manifest))
    return root


def rest_dataset(root: Path) -> Path:
    dataset(root)
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    first = 1672531200000
    rows = [[t, "100", "102", "99", "101", "2", t + 59999, "200", 3, "1", "100", "0"]
            for t in (first, first + 60000)]
    raw = json.dumps(rows).encode()
    path = f"rest/BTCUSDT/{first}.json"
    (root / "rest/BTCUSDT").mkdir(parents=True)
    (root / path).write_bytes(raw)
    entry = manifest["sources"][0]
    for key in ("filename", "url", "checksum_url", "raw_path", "checksum_path", "sha256", "size"):
        entry.pop(key)
    entry["evidence"] = "download_sha256"
    entry["pages"] = [{"raw_path": path, "sha256": hashlib.sha256(raw).hexdigest(),
                       "size": len(raw), "url": "https://fapi.binance.com/fapi/v1/klines?"
                       f"symbol=BTCUSDT&interval=1m&startTime={first}&endTime={first + 119999}&limit=1000"}]
    manifest_path.write_text(json.dumps(manifest))
    return root


def test_retained_source_identity_reader_has_finite_nofollow_reads(tmp_path,monkeypatch):
    import time
    root = tmp_path/'identity'; root.mkdir()
    path = root/'item'; path.write_bytes(b'x'*(1024**2+1))
    identity = signal_sources._source_artifact_identity
    digest,size,raw = identity(root,'item',2*1024**2,time.monotonic()+10,capture=False)
    assert (digest,size,raw)==(hashlib.sha256(path.read_bytes()).hexdigest(),1024**2+1,None)
    assert identity(root,'item',2*1024**2,time.monotonic()+10,capture=True)[2]==path.read_bytes()
    with pytest.raises(signal_sources.SignalError,match='deadline'):
        identity(root,'item',2*1024**2,time.monotonic()-1)
    with pytest.raises(signal_sources.SignalError,match='size'):
        identity(root,'item',1,time.monotonic()+10)
    (root/'link').symlink_to(path)
    with pytest.raises(signal_sources.SignalError): identity(root,'link',2*1024**2,time.monotonic()+10)
    (root/'directory').mkdir()
    with pytest.raises(signal_sources.SignalError): identity(root,'directory',2*1024**2,time.monotonic()+10)
    with pytest.raises(signal_sources.SignalError): identity(root,'absent',2*1024**2,time.monotonic()+10)
    with pytest.raises(signal_sources.SignalError): identity(root,'../item',2*1024**2,time.monotonic()+10)
    with pytest.raises(signal_sources.SignalError): identity(Path('relative'),'item',2*1024**2,time.monotonic()+10)
    (root/'parent-link').symlink_to(root,target_is_directory=True)
    with pytest.raises(signal_sources.SignalError): identity(root,'parent-link/item',2*1024**2,time.monotonic()+10)


@pytest.mark.parametrize('change', ['deadline','growth','replacement'])
def test_source_identity_rechecks_between_chunks_and_rejects_midread_changes(tmp_path,monkeypatch,change):
    import time
    root=tmp_path/'raw'; root.mkdir(); path=root/'item'; path.write_bytes(b'x'*(1024**2+1))
    original=signal_sources.os.fdopen; calls=[]; now=[time.monotonic()]
    class Stream:
        def __init__(self,fd,*args,**kwargs): self.stream=original(fd,*args,**kwargs)
        def __enter__(self): return self
        def __exit__(self,*args): self.stream.close()
        def fileno(self): return self.stream.fileno()
        def read(self,size):
            calls.append(size); raw=self.stream.read(size)
            if len(calls)==1:
                if change=='deadline': now[0]+=100
                elif change=='growth': path.write_bytes(path.read_bytes()+b'!')
                else:
                    replacement=root/'replacement'; replacement.write_bytes(path.read_bytes())
                    replacement.replace(path)
            return raw
    monkeypatch.setattr(signal_sources.os,'fdopen',Stream)
    monkeypatch.setattr(signal_sources.time,'monotonic',lambda:now[0])
    with pytest.raises(signal_sources.SignalError,match='deadline' if change=='deadline' else 'changed'):
        signal_sources._source_artifact_identity(root,'item',2*1024**2,now[0]+10)
    assert all(size<=1024**2 for size in calls)
    if change=='deadline': assert len(calls)==1


@pytest.mark.parametrize('transport', ['archive','rest'])
def test_retained_subset_identity_is_exact_readonly_and_never_iterates_candles(tmp_path,monkeypatch,transport):
    import time
    root = dataset(tmp_path/'raw') if transport=='archive' else rest_dataset(tmp_path/'raw')
    selection = signal_sources.select_sources(root,START,END,START,'BTCUSDT')
    raw = (root/'manifest.json').read_bytes()
    before = {str(p):p.read_bytes() for p in root.rglob('*') if p.is_file()}
    def forbidden(*args,**kwargs): pytest.fail('retained source identity generated candles or selected evaluation inputs')
    monkeypatch.setattr(signal_sources,'_select_sources_validated',forbidden)
    monkeypatch.setattr(signal_sources,'iter_verified_candles',forbidden)
    verify = signal_sources._verify_bound_source_identity
    assert verify(root,raw,selection.start,selection.end,selection.score_start,'BTCUSDT',
                  selection.dataset_sha256,time.monotonic()+10)==selection
    assert before=={str(p):p.read_bytes() for p in root.rglob('*') if p.is_file()}
    with pytest.raises(signal_sources.SignalError,match='subset'):
        verify(root,raw,selection.start,selection.end,selection.score_start,'BTCUSDT','0'*64,time.monotonic()+10)
    with pytest.raises(signal_sources.SignalError,match='deadline'):
        verify(root,raw,selection.start,selection.end,selection.score_start,'BTCUSDT',selection.dataset_sha256,time.monotonic()-1)


@pytest.mark.parametrize('change', ['checksum_format','checksum_name','checksum_digest','checksum_unicode',
    'archive_size','rest_size','rest_path','rest_url','rest_json','rest_empty','rest_order','rest_count'])
def test_retained_source_identity_preserves_original_archive_and_rest_semantics(tmp_path,change):
    import time
    is_archive=change.startswith(('checksum','archive'))
    root=dataset(tmp_path/'raw') if is_archive else rest_dataset(tmp_path/'raw')
    manifest=json.loads((root/'manifest.json').read_bytes()); entry=manifest['sources'][0]
    if change.startswith('checksum'):
        path=root/entry['checksum_path']
        text={'checksum_format':'invalid','checksum_name':entry['sha256']+'  wrong.zip',
              'checksum_digest':'0'*64+'  '+entry['filename']}
        path.write_bytes(b'\xff' if change=='checksum_unicode' else text[change].encode())
    elif change=='archive_size': entry['size']+=1
    else:
        page=entry['pages'][0]; path=root/page['raw_path']
        if change=='rest_size': page['size']+=1
        elif change=='rest_path': page['raw_path']='rest/BTCUSDT/wrong.json'
        elif change=='rest_url': page['url']='https://wrong'
        else:
            rows=json.loads(path.read_bytes())
            if change=='rest_json': raw=b'not json'
            elif change=='rest_empty': raw=b'[]'
            else:
                if change=='rest_order': rows.reverse()
                else: rows.pop()
                raw=json.dumps(rows).encode()
            path.write_bytes(raw); page.update(size=len(raw),sha256=hashlib.sha256(raw).hexdigest())
    raw=json.dumps(manifest).encode()
    beginning,ending,score=map(signal_sources._utc,(START,END,START))
    selection=signal_sources._selection_from_manifest(raw,beginning,ending,score,'BTCUSDT')
    with pytest.raises(signal_sources.SignalError):
        signal_sources._verify_bound_source_identity(root,raw,beginning,ending,score,'BTCUSDT',
            selection.dataset_sha256,time.monotonic()+10)


FAKE_WORKER = r'''
import hashlib, json, os, sys, time
from datetime import datetime, timezone
mode = sys.argv[1] if len(sys.argv) > 1 else "ok"
evidence = json.load(open(sys.argv[2])) if len(sys.argv) > 2 else None
def digest(value):
    return "sha256:" + hashlib.sha256(json.dumps(value,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()).hexdigest()
opened = None
consumed = 0
for line in sys.stdin:
    frame = json.loads(line)
    schema = frame["schema_version"]
    if schema == "research-signal-open.v1":
        opened = frame
        if mode == "early": sys.exit(3)
        if mode == "badopen": print("{}", flush=True); continue
        if mode == "badjson": print("{", flush=True); sys.exit(0)
        if mode == "badframe": print("[]", flush=True); sys.exit(0)
        if mode == "longline": print("x" * 3000, end="", flush=True); sys.exit(0)
        if mode == "output_flood": print("x" * 3000, flush=True); sys.exit(0)
        acknowledgement = {"schema_version":"research-signal-opened.v1", "session_id":frame["session_id"],
              "source":{k:frame[k] for k in ("dataset_id","dataset_sha256","source_venue","source_network","market_type")},
              "execution":evidence["execution"],
              "baseline":evidence["baseline"],"effective_config_snapshot":evidence["snapshot"],"indicator_engine_version":"php_fallback_v1"}
        if mode == "badidentity": acknowledgement["source"]["source_venue"] = "okx"
        if mode == "badexec": acknowledgement["execution"]["mode_id"] = "forged"
        if mode == "badengine": acknowledgement["indicator_engine_version"] = "unknown"
        if mode == "emptybaseline": acknowledgement["baseline"] = {}
        if mode == "emptysnapshot": acknowledgement["effective_config_snapshot"] = {}
        if mode == "badfilehash":
            name = next(iter(acknowledgement["baseline"]["file_hashes"]))
            acknowledgement["baseline"]["file_hashes"][name] = "sha256:" + "0"*64
        if mode == "badsnapshothash": acknowledgement["effective_config_snapshot"]["snapshot_hash"] = "sha256:" + "0"*64
        print(json.dumps(acknowledgement), flush=True)
        if mode == "error_frame":
            print(json.dumps({"schema_version":"research-signal-error.v1","session_id":frame["session_id"],"reason_code":"fixture_failure"}),flush=True)
            sys.exit(2)
        if mode == "unknown_frame":
            print(json.dumps({"schema_version":"unexpected.v1"}),flush=True)
            sys.exit(0)
    elif schema == "research-signal-candles.v1":
        if mode == "stderr": sys.stderr.write("x" * 2000000); sys.stderr.flush()
        if mode == "timeout": time.sleep(5)
        consumed += len(frame["candles"])
        emitted = 0
        if mode in ("boundary", "scored", "badtick", "badcontext", "badhash", "badpassed",
                    "missing_symbol", "missing_contexts", "stale_context", "future_context",
                    "forged_digest", "forged_trace_hash"):
            tick = opened["end_ms"] if mode != "scored" else opened["start_ms"] + 60000
            keys = ("close","rsi","ema_20","ema_50","ema_200","macd_hist","vwap","atr","adx",
                    "ma9","ma21","bb_upper","bb_middle","bb_lower","ema","ema_prev","ema_200_slope",
                    "macd","pullback_age_bars","volume_ratio","ma_21_plus_k_atr")
            contexts = {}
            for name,step in (("1m",60000),("5m",300000),("15m",900000),("1h",3600000),("4h",14400000)):
                available = tick // step * step
                context = {key:1 for key in keys}
                context.update({"open_ms":available-step,"available_ms":available,
                                "research_window_hash":"sha256:"+"d"*64,
                                "adx":{"14":1,"15":1},"ema":{"9":1},"ema_prev":{"9":1},
                                "macd":{"hist":1},"pullback_age_bars":None,"volume_ratio":None})
                contexts[name] = context
            trace = {"schema_version":"canonical-setup-rule-runtime.v1","sample":1}
            result = {"schema_version":"research-signal-result.v1","session_id":frame["session_id"],
                  "source":{k:opened[k] for k in ("dataset_id","dataset_sha256","source_venue","source_network","market_type")},
                  "execution":evidence["execution"],
                  "baseline":acknowledgement["baseline"],"symbol":opened["symbol"],
                  "evaluated_ms":tick,"evaluated_at":datetime.fromtimestamp(tick/1000,timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000000Z"),
                  "passed":True,"reason_code":"sample_pass","contexts":contexts,"verdicts":{"sample":True},"trace":trace,
                  "numeric_context_hash":"sha256:"+"a"*64,"trace_hash":digest(trace)}
            if mode == "badtick": result["evaluated_ms"] += 900000
            if mode == "badcontext": result["contexts"]["1m"]["available_ms"] = opened["end_ms"]+1
            if mode == "missing_symbol": result.pop("symbol")
            if mode == "missing_contexts": result["contexts"] = {}
            if mode == "stale_context": result["contexts"]["5m"]["available_ms"] -= 300000
            if mode == "future_context": result["contexts"]["4h"]["available_ms"] += 14400000
            if mode == "badpassed": result["passed"] = "true"
            if mode == "forged_trace_hash": result["trace_hash"] = "sha256:" + "0"*64
            result["result_hash"] = digest(result)
            if mode == "badhash": result["result_hash"] = "forged"
            if mode == "forged_digest": result["result_hash"] = "sha256:" + "c"*64
            print(json.dumps(result), flush=True)
            emitted = 1
        print(json.dumps({"schema_version":"research-signal-batch-accepted.v1","session_id":frame["session_id"],
                          "consumed_candles":len(frame["candles"]) + int(mode == "badack"),"emitted_results":emitted + int(mode == "bad_ack_results")}), flush=True)
    elif schema == "research-signal-close.v1":
        if mode == "missing_summary": break
        end = opened["start_ms"] + consumed * 60000
        summary = {"schema_version":"research-signal-summary.v1","session_id":opened["session_id"],
             "source":{k:opened[k] for k in ("dataset_id","dataset_sha256","source_venue","source_network","market_type")},
             "execution":evidence["execution"],
             "baseline":acknowledgement["baseline"],"symbol":opened["symbol"],"consumed_candles":consumed + int(mode == "badcount"),
             "warmup_unavailable":0,"before_score_start":0,"evaluated_ticks":int(mode in ("boundary", "scored", "badtick", "badcontext", "badhash", "badpassed", "missing_symbol", "missing_contexts", "stale_context", "future_context", "forged_digest", "forged_trace_hash")),"passed_rules":int(mode in ("boundary", "scored", "badtick", "badcontext", "badhash", "badpassed", "missing_symbol", "missing_contexts", "stale_context", "future_context", "forged_digest", "forged_trace_hash")),"failed_rules":0,
             "first_source_open_ms":opened["start_ms"],"last_source_open_ms":end-60000,
             "source_available_end_ms":end,"promised_end_ms":opened["end_ms"],
             "score_start_ms":opened["score_start_ms"],"completion":"partial" if mode == "partial" else "complete"}
        if mode == "summary_identity": summary["source"]["source_venue"] = "okx"
        if mode == "summary_baseline": summary["baseline"]["config_hash"] = "forged"
        if mode == "summary_missing_symbol": summary.pop("symbol")
        if mode == "summary_bool": summary["evaluated_ticks"] = False
        if mode == "summary_extra": summary["unknown"] = True
        print(json.dumps(summary), flush=True)
        if mode == "extra": print("{}", flush=True)
'''


def _worker_evidence(app_dir: Path) -> dict:
    template = Path(__file__).parent / "fixtures/backtesting/php-effective-config-snapshot.json"
    snapshot = json.loads(template.read_text())
    execution = {"mode_id": "day_trading", "mode_version": "1.1.0",
                 "setup_id": "day_trading.trend_continuation.long", "setup_version": "1.1.0",
                 "exchange": "fake", "environment": "local", "side": "long",
                 "execution_capability": "backtest"}
    snapshot["request"] = execution
    snapshot["config"]["mode"].update({"mode_id": "day_trading", "mode_version": "1.1.0",
                                          "risk": {"budget": 1}})
    snapshot["config"]["setup"].update({"setup_id": execution["setup_id"],
                                           "setup_version": "1.1.0"})
    snapshot["config"]["environment"]["id"] = "local"
    renames = {}
    for index, layer in enumerate(snapshot["ordered_layers"]):
        original = layer["path"]
        path = app_dir / "config" / f"layer-{index}.yaml"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(f"layer {index}\n")
        renames[original] = str(path)
        layer["path"] = str(path)
    snapshot["ordered_files"] = [renames[path] for path in snapshot["ordered_files"]]
    for layer in snapshot["provenance"].values():
        layer["path"] = renames[layer["path"]]
    snapshot["config_hash"] = calculate_config_hash(snapshot["config"], snapshot["condition_catalog_hash"])
    snapshot["snapshot_hash"] = calculate_snapshot_hash(snapshot)
    CanonicalEffectiveConfigSnapshot.model_validate(snapshot)
    catalog = app_dir / "config/catalog.yaml"
    catalog.write_text("catalog fixture\n")
    files = [*snapshot["ordered_files"], str(catalog)]
    baseline = {"config_hash": snapshot["config_hash"],
                "condition_catalog_hash": snapshot["condition_catalog_hash"],
                "snapshot_hash": snapshot["snapshot_hash"], "setup_hash": "a" * 64,
                "file_hashes": {name: "sha256:" + hashlib.sha256(Path(name).read_bytes()).hexdigest()
                                for name in files},
                "mode_risk": snapshot["config"]["mode"]["risk"]}
    return {"execution": execution, "baseline": baseline, "snapshot": snapshot}


def fake_worker(app_dir: Path, mode: str = "ok") -> tuple[str, ...]:
    path = app_dir / "fake-worker-evidence.json"
    path.write_text(json.dumps(_worker_evidence(app_dir)))
    return (sys.executable, "-c", FAKE_WORKER, mode, str(path))


def opened_fixture(app_dir: Path, selection: object) -> dict:
    evidence = _worker_evidence(app_dir)
    return {"schema_version": "research-signal-opened.v1", "session_id": "session",
            "source": {"dataset_id": "research-" + selection.dataset_sha256[:24],
                       "dataset_sha256": selection.dataset_sha256, "source_venue": "binance_usdm",
                       "source_network": "mainnet", "market_type": "perpetual"},
            "execution": evidence["execution"], "baseline": evidence["baseline"],
            "effective_config_snapshot": evidence["snapshot"],
            "indicator_engine_version": "php_fallback_v1"}


def result_fixture(tick: int) -> dict:
    contexts = {}
    for timeframe, step in signals.TIMEFRAME_MS.items():
        available = tick // step * step
        item = {key: 1 for key in signals.CONTEXT_FIELDS}
        item.update({"open_ms": available - step, "available_ms": available,
                     "research_window_hash": "sha256:" + "d" * 64,
                     "adx": {"14": 1}, "ema": {"9": 1}, "ema_prev": {"9": 1},
                     "macd": {"hist": 1}, "pullback_age_bars": None, "volume_ratio": None})
        contexts[timeframe] = item
    trace = {"schema_version": "canonical-setup-rule-runtime.v1", "sample": 1}
    frame = {"schema_version": "research-signal-result.v1", "session_id": "session",
             "source": {"dataset_id": "sample"}, "execution": {"mode_id": "day_trading"},
             "baseline": {"config_hash": "sample"}, "symbol": "BTCUSDT",
             "evaluated_ms": tick, "evaluated_at": datetime.fromtimestamp(tick / 1000, timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000000Z"),
             "passed": False, "reason_code": "sample_reject", "numeric_context_hash": "sha256:" + "a" * 64,
             "contexts": contexts, "trace_hash": signals._sha256_canonical(trace),
             "verdicts": {"sample": False}, "trace": trace}
    frame["result_hash"] = signals._sha256_canonical(frame)
    return frame


def test_selects_complete_candle_subset_from_partial_global_manifest(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset", include_funding=True)
    chosen = select_sources(root, START, END, START, "BTCUSDT")
    assert len(chosen.sources) == 1
    assert chosen.expected_candles == 2
    assert chosen.expected_evaluations == 0
    manifest = json.loads((root / "manifest.json").read_text())
    manifest["sources"][1]["error"] = "changed outside subset"
    (root / "manifest.json").write_text(json.dumps(manifest))
    later = select_sources(root, START, END, START, "BTCUSDT")
    assert later.dataset_sha256 == chosen.dataset_sha256
    assert later.manifest_sha256 != chosen.manifest_sha256


@pytest.mark.parametrize("mutate", ["gap", "hash", "path", "status", "count", "overlap", "oversize", "symlink"])
def test_rejects_bad_selected_archive(tmp_path: Path, mutate: str) -> None:
    root = dataset(tmp_path / "dataset")
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    entry = manifest["sources"][0]
    if mutate == "gap":
        entry["gaps"] = [[1672531260000, 1672531320000]]
    elif mutate == "hash":
        (root / entry["raw_path"]).write_bytes(b"tampered")
    elif mutate == "path":
        entry["raw_path"] = "../outside.zip"
    elif mutate == "status":
        entry["status"] = "incomplete"
    elif mutate == "overlap":
        manifest["sources"].append(dict(entry))
    elif mutate == "oversize":
        with (root / entry["raw_path"]).open("r+b") as stream:
            stream.truncate(64 * 1024 * 1024 + 1)
    elif mutate == "symlink":
        target = root / entry["raw_path"]
        sibling = target.with_name("saved.zip")
        target.rename(sibling)
        target.symlink_to(sibling)
    else:
        entry["count"] = 1
    manifest_path.write_text(json.dumps(manifest))
    with pytest.raises(SignalError):
        run_signals(root, tmp_path / "out", tmp_path, START, END, START,
                    ("BTCUSDT",), worker_argv=fake_worker(tmp_path))


def test_fake_worker_streams_and_reports_private_outputs(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset")
    out = tmp_path / "out"
    report = run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                         worker_argv=fake_worker(tmp_path))
    assert report["status"] == "complete"
    assert report["symbols"]["BTCUSDT"]["source_candles"] == 2
    assert report["symbols"]["BTCUSDT"]["evaluated_ticks"] == 0
    assert report["symbols"]["BTCUSDT"]["effective_config_snapshot"] == _worker_evidence(tmp_path)["snapshot"]
    assert report["totals"]["source_candles"] == 2
    assert report["totals"]["scored_evaluations"] == 0
    assert report["totals"]["elapsed_seconds"] > 0
    assert (out / "BTCUSDT.results.ndjson").read_bytes() == b""
    assert out.stat().st_mode & 0o777 == 0o700
    assert (out / "report.json").stat().st_mode & 0o777 == 0o600
    with pytest.raises(SignalError):
        run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                    worker_argv=fake_worker(tmp_path))


def test_producer_delivers_complete_frames_after_short_pipe_writes(
        tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    session = f"research-BTCUSDT-{selection.dataset_sha256[:20]}"
    expected = b"".join(signals._frames(selection, root, session))
    children = []
    delivered = bytearray()
    popen = subprocess.Popen

    class PrefixPipe:
        def __init__(self, inner):
            self.inner = inner

        def write(self, raw):
            prefix = raw[:17]
            written = self.inner.write(prefix)
            delivered.extend(prefix[:written])
            return written

        def __getattr__(self, name):
            return getattr(self.inner, name)

    def short_pipe_worker(*args, **kwargs):
        child = popen(*args, **kwargs)
        child.stdin = PrefixPipe(child.stdin)
        children.append(child)
        return child

    monkeypatch.setattr(signals.subprocess, "Popen", short_pipe_worker)
    out = tmp_path / "out"
    try:
        report = run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                             worker_argv=fake_worker(tmp_path), wall_timeout=2)
    finally:
        assert len(children) == 1
        assert children[0].poll() is not None
        assert children[0].stdin.closed
    assert bytes(delivered) == expected
    frames = [json.loads(line) for line in delivered.splitlines()]
    assert len(frames) == 3
    assert len(frames[1]["candles"]) == 2
    assert report["status"] == "complete"
    result = report["symbols"]["BTCUSDT"]
    assert result["source_candles"] == report["totals"]["source_candles"] == 2
    assert result["evaluated_ticks"] == report["totals"]["evaluated_ticks"] == 0
    assert result["result_sha256"] == hashlib.sha256(b"").hexdigest()
    assert (out / "BTCUSDT.results.ndjson").read_bytes() == b""
    assert result["stderr_bytes"] == 0
    assert children[0].returncode == 0


class ScriptedWriter:
    def __init__(self, actions):
        self.actions = iter(actions)
        self.delivered = bytearray()

    def write(self, raw):
        action = next(self.actions)
        if isinstance(action, Exception):
            raise action
        if type(action) is int and 0 < action <= len(raw):
            self.delivered.extend(raw[:action])
        return action


@pytest.mark.parametrize("actions", [[7], [2, 5], [1, 2, 1, 3],
                                     [InterruptedError(), 2, InterruptedError(), 5]])
def test_write_all_preserves_exact_bytes(actions) -> None:
    writer = ScriptedWriter(actions)
    signals._write_all(writer, b"a\xc3\xa9bcd\n", signals.time.monotonic() + 2)
    assert writer.delivered == b"a\xc3\xa9bcd\n"


@pytest.mark.parametrize("invalid", [0, None, -1, 8, True, 1.5, "1"])
def test_write_all_rejects_invalid_progress(invalid) -> None:
    writer = ScriptedWriter([invalid])
    with pytest.raises(SignalError, match="worker stdin write made invalid progress"):
        signals._write_all(writer, b"frame\n", signals.time.monotonic() + 2)
    assert writer.delivered == b""


def test_write_all_propagates_broken_pipe_after_prefix() -> None:
    failure = BrokenPipeError("closed synthetic pipe")
    writer = ScriptedWriter([2, failure])
    with pytest.raises(BrokenPipeError) as raised:
        signals._write_all(writer, b"frame\n", signals.time.monotonic() + 2)
    assert raised.value is failure
    assert writer.delivered == b"fr"


@pytest.mark.parametrize("actions, ticks, expected", [([], [2], b""),
                         ([2], [0, 2], b"fr"),
                         ([InterruptedError(), InterruptedError()], [0, 1, 2], b"")])
def test_write_all_checks_deadline_on_every_retry(
        monkeypatch: pytest.MonkeyPatch, actions, ticks, expected) -> None:
    clock = iter(ticks)
    monkeypatch.setattr(signals.time, "monotonic", lambda: next(clock))
    writer = ScriptedWriter(actions)
    with pytest.raises(SignalError, match="worker stdin producer timeout"):
        signals._write_all(writer, b"frame\n", 2)
    assert writer.delivered == expected


@pytest.mark.parametrize("failure", [0, None, -1, "oversized", "broken_pipe"])
def test_producer_write_failure_reaps_synthetic_worker(
        tmp_path: Path, monkeypatch: pytest.MonkeyPatch, failure) -> None:
    root = dataset(tmp_path / "dataset")
    children = []
    popen = subprocess.Popen

    class FailingPipe:
        def __init__(self, inner):
            self.inner = inner

        def write(self, raw):
            if b"research-signal-candles.v1" in bytes(raw):
                if failure == "broken_pipe":
                    raise BrokenPipeError("closed synthetic pipe")
                return len(raw) + 1 if failure == "oversized" else failure
            return self.inner.write(raw)

        def __getattr__(self, name):
            return getattr(self.inner, name)

    def failing_pipe_worker(*args, **kwargs):
        child = popen(*args, **kwargs)
        child.stdin = FailingPipe(child.stdin)
        children.append(child)
        return child

    monkeypatch.setattr(signals.subprocess, "Popen", failing_pipe_worker)
    out = tmp_path / "out"
    message = "closed synthetic pipe" if failure == "broken_pipe" else "invalid progress"
    with pytest.raises(SignalError, match="worker input failed: .*" + message):
        run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                    worker_argv=fake_worker(tmp_path), wall_timeout=2)
    assert len(children) == 1
    assert children[0].poll() is not None
    assert children[0].stdin.closed
    report = json.loads((out / "report.json").read_text())
    assert report["status"] == "failed"
    assert report["totals"]["completed_symbols"] == 0
    assert not (out / "BTCUSDT.results.ndjson").exists()
    assert not (out / "BTCUSDT.boundary.ndjson").exists()


@pytest.mark.parametrize("tamper", ["none", "hash", "url", "rows"])
def test_rest_payload_and_provenance(tmp_path: Path, tamper: str) -> None:
    root = rest_dataset(tmp_path / "dataset")
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    page = manifest["sources"][0]["pages"][0]
    if tamper == "hash":
        page["sha256"] = "0" * 64
    elif tamper == "url":
        page["url"] = "https://example.com/private"
    elif tamper == "rows":
        path = root / page["raw_path"]
        raw = json.loads(path.read_text())
        raw[1][0] += 60000
        altered = json.dumps(raw).encode()
        path.write_bytes(altered)
        page["sha256"] = hashlib.sha256(altered).hexdigest()
        page["size"] = len(altered)
    manifest_path.write_text(json.dumps(manifest))
    invocation = lambda: run_signals(root, tmp_path / "out", tmp_path, START, END, START,
                                     ("BTCUSDT",), worker_argv=fake_worker(tmp_path))
    if tamper == "none":
        assert invocation()["status"] == "complete"
    else:
        with pytest.raises(SignalError):
            invocation()


@pytest.mark.parametrize("mode", ["early", "timeout", "stderr", "badopen", "output_flood",
                                  "missing_summary", "partial", "extra", "badjson", "badframe",
                                  "longline", "badidentity", "badexec", "badengine",
                                  "badack", "badcount", "summary_identity", "summary_baseline",
                                  "emptybaseline", "emptysnapshot", "badfilehash", "badsnapshothash",
                                  "summary_missing_symbol", "summary_bool", "summary_extra",
                                  "error_frame", "unknown_frame", "bad_ack_results"])
def test_worker_failure_retains_partial_report(tmp_path: Path, mode: str) -> None:
    root = dataset(tmp_path / "dataset")
    out = tmp_path / "out"
    with pytest.raises(SignalError):
        run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                    worker_argv=fake_worker(tmp_path, mode), wall_timeout=0.5,
                    max_stderr_bytes=1024,
                    max_output_bytes=2048 if mode == "output_flood" else 500_000)
    report = json.loads((out / "report.json").read_text())
    assert report["status"] == "failed"
    assert not (out / "BTCUSDT.results.ndjson").exists()


def test_rejects_holdout_and_pre2023_scoring(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset")
    with pytest.raises(SignalError):
        select_sources(root, START, "2026-01-01T00:01:00Z", START, "BTCUSDT")
    with pytest.raises(SignalError):
        select_sources(root, START, END, "2022-12-31T23:59:00Z", "BTCUSDT")


def test_end_boundary_is_diagnostic_not_scored() -> None:
    start = 1672531200000
    end = start + 900000
    assert signals._is_scored_tick(start, start, end)
    assert signals._is_scored_tick(end - 1, start, end)
    assert not signals._is_scored_tick(end, start, end)


def test_boundary_worker_frame_separates_diagnostic_file(tmp_path: Path,
                                                         monkeypatch: pytest.MonkeyPatch) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    monkeypatch.setattr(signals, "select_sources", lambda *args: replace(selection, expected_evaluations=1))
    monkeypatch.setattr(signals, "_first_evaluable_ms", lambda unused: 1672531320000)
    out = tmp_path / "out"
    report = run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                         worker_argv=fake_worker(tmp_path, "boundary"))
    symbol = report["symbols"]["BTCUSDT"]
    assert symbol["evaluated_ticks"] == 1
    assert symbol["scored_evaluations"] == 0
    assert symbol["boundary_diagnostic_count"] == 1
    assert (out / "BTCUSDT.results.ndjson").read_bytes() == b""
    diagnostic = json.loads((out / "BTCUSDT.boundary.ndjson").read_text())
    assert diagnostic["evaluated_ms"] == 1672531320000


def test_scored_worker_result_is_not_boundary(tmp_path: Path,
                                              monkeypatch: pytest.MonkeyPatch) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    monkeypatch.setattr(signals, "select_sources", lambda *args: replace(selection, expected_evaluations=1))
    monkeypatch.setattr(signals, "_first_evaluable_ms", lambda unused: 1672531260000)
    out = tmp_path / "out"
    report = run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                         worker_argv=fake_worker(tmp_path, "scored"))
    symbol = report["symbols"]["BTCUSDT"]
    assert symbol["scored_evaluations"] == 1
    assert symbol["scored_passed_rules"] == 1
    assert symbol["boundary_diagnostic_count"] == 0
    assert (out / "BTCUSDT.boundary.ndjson").read_bytes() == b""
    assert json.loads((out / "BTCUSDT.results.ndjson").read_text())["evaluated_ms"] == 1672531260000


@pytest.mark.parametrize("mode", ["badtick", "badcontext", "badhash", "badpassed",
                                  "missing_symbol", "missing_contexts", "stale_context",
                                  "future_context", "forged_digest", "forged_trace_hash"])
def test_rejects_forged_result_frame(tmp_path: Path, monkeypatch: pytest.MonkeyPatch,
                                     mode: str) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    monkeypatch.setattr(signals, "select_sources", lambda *args: replace(selection, expected_evaluations=1))
    monkeypatch.setattr(signals, "_first_evaluable_ms", lambda unused: 1672531320000)
    out = tmp_path / "out"
    with pytest.raises(SignalError):
        run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                    worker_argv=fake_worker(tmp_path, mode))
    assert json.loads((out / "report.json").read_text())["status"] == "failed"


@pytest.mark.parametrize("case", ["bad_symbol", "duplicate", "root_link", "output_link",
                                   "bad_worker", "bad_time", "in_repo"])
def test_rejects_unsafe_invocation_before_worker(tmp_path: Path, case: str) -> None:
    root = dataset(tmp_path / "dataset")
    out = tmp_path / "out"
    symbols = ("BTCUSDT",)
    worker = fake_worker(tmp_path)
    start = START
    if case == "bad_symbol":
        symbols = ("NOTUSDT",)
    elif case == "duplicate":
        symbols = ("BTCUSDT", "BTCUSDT")
    elif case == "root_link":
        linked = tmp_path / "linked"
        linked.symlink_to(root)
        root = linked
    elif case == "output_link":
        out.symlink_to(tmp_path / "other")
    elif case == "bad_worker":
        worker = ("python", "-c", FAKE_WORKER)
    elif case == "bad_time":
        start = "2023-01-01T00:00:01Z"
    else:
        out = Path(__file__).resolve().parent / "unsafe-output"
    with pytest.raises(SignalError):
        run_signals(root, out, tmp_path, start, END, START, symbols, worker_argv=worker)
    assert not out.exists() if case != "output_link" else out.is_symlink()


@pytest.mark.parametrize("case", ["missing_manifest", "invalid_json", "wrong_source", "wrong_schema",
                                   "wrong_symbol", "short_range", "bad_evidence", "bad_url",
                                   "bad_checksum_url", "bad_filename", "bad_first", "bad_last",
                                   "bad_coverage", "bad_source_range", "non_dict_source"])
def test_rejects_manifest_identity_and_source_forgery(tmp_path: Path, case: str) -> None:
    root = dataset(tmp_path / "dataset")
    path = root / "manifest.json"
    manifest = json.loads(path.read_text())
    entry = manifest["sources"][0]
    if case == "missing_manifest":
        path.unlink()
    elif case == "invalid_json":
        path.write_text("[")
    else:
        if case == "wrong_source":
            manifest["identity"]["source"] = {"venue": "okx"}
        elif case == "wrong_schema":
            manifest["identity"]["schema"] = 2
        elif case == "wrong_symbol":
            manifest["identity"]["symbols"] = ["ETHUSDT"]
        elif case == "short_range":
            manifest["identity"]["end"] = START
        elif case == "bad_evidence":
            entry["evidence"] = "unknown"
        elif case == "bad_url":
            entry["url"] = "https://example.com/forged.zip"
        elif case == "bad_checksum_url":
            entry["checksum_url"] = "https://example.com/forged.CHECKSUM"
        elif case == "bad_filename":
            entry["filename"] = "../../evil.zip"
        elif case == "bad_first":
            entry["first_open"] += 60000
        elif case == "bad_last":
            entry["last_close"] -= 60000
        elif case == "bad_coverage":
            entry["coverage"] = "incomplete"
        elif case == "bad_source_range":
            entry["start"] = "2023-01-02T00:00:00+00:00"
        else:
            manifest["sources"][0] = []
        path.write_text(json.dumps(manifest))
    with pytest.raises(SignalError):
        select_sources(root, START, END, START, "BTCUSDT")


@pytest.mark.parametrize("case", ["null_symbols", "missing_start", "missing_entry_start", "null_filename"])
def test_malformed_manifest_reports_signal_error(tmp_path: Path, case: str) -> None:
    root = dataset(tmp_path / "dataset")
    path = root / "manifest.json"
    manifest = json.loads(path.read_text())
    if case == "null_symbols":
        manifest["identity"]["symbols"] = None
    elif case == "missing_start":
        del manifest["identity"]["start"]
    elif case == "missing_entry_start":
        del manifest["sources"][0]["start"]
    else:
        manifest["sources"][0]["filename"] = None
    path.write_text(json.dumps(manifest))
    with pytest.raises(SignalError):
        select_sources(root, START, END, START, "BTCUSDT")


@pytest.mark.parametrize("malformed", ["nan", "negative", "text", "fractional_time", "duplicate_time"])
def test_rest_candle_validation_fails_closed(tmp_path: Path, malformed: str) -> None:
    root = rest_dataset(tmp_path / "dataset")
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    page = manifest["sources"][0]["pages"][0]
    raw_path = root / page["raw_path"]
    rows = json.loads(raw_path.read_text())
    if malformed == "nan":
        rows[0][1] = "NaN"
    elif malformed == "negative":
        rows[0][5] = "-1"
    elif malformed == "text":
        rows[0][1] = "not-a-price"
    elif malformed == "fractional_time":
        rows[0][0] += 0.5
    else:
        rows[1][0] = rows[0][0]
    altered = json.dumps(rows).encode()
    raw_path.write_bytes(altered)
    page["sha256"] = hashlib.sha256(altered).hexdigest()
    page["size"] = len(altered)
    manifest_path.write_text(json.dumps(manifest))
    with pytest.raises(SignalError):
        run_signals(root, tmp_path / "out", tmp_path, START, END, START,
                    ("BTCUSDT",), worker_argv=fake_worker(tmp_path))


@pytest.mark.parametrize("case", ["checksum", "manifest_hash", "manifest_size", "duplicate_csv",
                                   "invalid_zip", "huge_checksum", "wrong_member"])
def test_archive_evidence_revalidated_before_worker(tmp_path: Path, case: str) -> None:
    root = dataset(tmp_path / "dataset")
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    entry = manifest["sources"][0]
    raw_path = root / entry["raw_path"]
    checksum_path = root / entry["checksum_path"]
    if case == "checksum":
        checksum_path.write_text("0" * 64 + "  " + entry["filename"])
    elif case == "manifest_hash":
        entry["sha256"] = "0" * 64
    elif case == "manifest_size":
        entry["size"] += 1
    elif case == "huge_checksum":
        with checksum_path.open("r+b") as stream:
            stream.truncate(4097)
    else:
        if case == "duplicate_csv":
            altered = _raw("BTCUSDT", (1672531200000, 1672531200000))
        elif case == "invalid_zip":
            altered = b"not a ZIP"
        else:
            stream = io.BytesIO()
            with zipfile.ZipFile(stream, "w") as archive:
                archive.writestr("wrong.csv", "bad")
            altered = stream.getvalue()
        raw_path.write_bytes(altered)
        digest = hashlib.sha256(altered).hexdigest()
        checksum_path.write_text(f"{digest}  {entry['filename']}")
        entry["sha256"] = digest
        entry["size"] = len(altered)
    manifest_path.write_text(json.dumps(manifest))
    with pytest.raises(SignalError):
        run_signals(root, tmp_path / "out", tmp_path, START, END, START,
                    ("BTCUSDT",), worker_argv=fake_worker(tmp_path))


@pytest.mark.parametrize("case", ["invalid_json", "empty", "too_many", "missing_final", "gap",
                                   "wrong_path", "wrong_size"])
def test_rest_page_shape_and_contiguity(tmp_path: Path, case: str) -> None:
    root = rest_dataset(tmp_path / "dataset")
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    page = manifest["sources"][0]["pages"][0]
    raw_path = root / page["raw_path"]
    rows = json.loads(raw_path.read_text())
    if case == "wrong_path":
        page["raw_path"] = "rest/BTCUSDT/forged.json"
    elif case == "wrong_size":
        page["size"] += 1
    else:
        if case == "invalid_json":
            raw = b"["
        elif case == "empty":
            raw = b"[]"
        elif case == "too_many":
            raw = json.dumps(rows[:1] * 1001).encode()
        else:
            if case == "missing_final":
                rows = rows[:1]
            else:
                rows[1][0] += 60000
                rows[1][6] += 60000
            raw = json.dumps(rows).encode()
        raw_path.write_bytes(raw)
        page["sha256"] = hashlib.sha256(raw).hexdigest()
        page["size"] = len(raw)
    manifest_path.write_text(json.dumps(manifest))
    with pytest.raises(SignalError):
        run_signals(root, tmp_path / "out", tmp_path, START, END, START,
                    ("BTCUSDT",), worker_argv=fake_worker(tmp_path))


def test_frame_batch_partition_and_line_limit(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    root = dataset(tmp_path / "dataset")
    chosen = select_sources(root, START, END, START, "BTCUSDT")
    one = {"symbol": "BTCUSDT", "open_ms": 1672531200000,
           "open": "100", "high": "102", "low": "99", "close": "101",
           "volume": "2", "source_record_id": "a" * 64}
    monkeypatch.setattr(signals, "iter_verified_candles", lambda *args: iter([one] * 1441))
    frames = [json.loads(frame) for frame in signals._frames(chosen, root, "session")]
    batches = [len(frame["candles"]) for frame in frames if frame["schema_version"] == "research-signal-candles.v1"]
    assert batches == [1440, 1]
    monkeypatch.setattr(signals, "MAX_LINE_BYTES", 40)
    with pytest.raises(SignalError, match="line size"):
        list(signals._frames(chosen, root, "session"))


def test_main_reports_cli_failure_without_network(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    from app.backtesting.research.signals import main
    root = dataset(tmp_path / "dataset")
    assert main(["--dataset-root", str(root), "--output-root", str(tmp_path / "out"),
                 "--app-dir", str(tmp_path), "--start", START, "--end", END,
                 "--score-start", START, "--symbols", "UNKNOWN"]) == 1
    assert "research signals failed" in capsys.readouterr().err


def test_main_success_output_uses_report(monkeypatch: pytest.MonkeyPatch,
                                         tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    monkeypatch.setattr(signals, "run_signals", lambda *args: {"status": "complete", "symbols": {"BTCUSDT": {}}})
    assert signals.main(["--dataset-root", str(tmp_path), "--output-root", str(tmp_path / "out"),
                         "--app-dir", str(tmp_path), "--start", START, "--end", END,
                         "--score-start", START, "--symbols", "BTCUSDT"]) == 0
    assert json.loads(capsys.readouterr().out) == {"status": "complete", "symbols": ["BTCUSDT"]}


def test_missing_default_php_worker_fails_before_output(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset")
    with pytest.raises(SignalError, match="PHP worker unavailable"):
        run_signals(root, tmp_path / "out", tmp_path, START, END, START, ("BTCUSDT",))
    assert not (tmp_path / "out").exists()


def test_code_hashes_require_all_bound_worker_files(tmp_path: Path) -> None:
    with pytest.raises(SignalError, match="required worker source unavailable"):
        signals._code_hashes(tmp_path, fake_worker=False)
    for name in signals.CODE_FILES:
        path = tmp_path / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php // isolated test fixture")
    for name in ("bin/console", "composer.json", "composer.lock", "vendor/composer/installed.json"):
        path = tmp_path / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("fixture")
    hashes = signals._code_hashes(tmp_path, fake_worker=False)
    assert len(hashes) == 5 + 4 + len(signals.CODE_FILES)
    assert str(Path(signals.__file__).with_name('holdout_authority.py')) in hashes


def test_private_output_rejects_symlink_parent_missing_parent_and_dataset(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset")
    linked = tmp_path / "linked"
    linked.symlink_to(tmp_path, target_is_directory=True)
    for candidate in (linked / "out", tmp_path / "missing" / "out", root / "nested", Path.home()):
        with pytest.raises(SignalError):
            signals._private_output(candidate, root)


@pytest.mark.parametrize("marker", ["directory", "file", "symlink"])
def test_private_output_rejects_other_repository_ancestors(tmp_path: Path, marker: str) -> None:
    root = dataset(tmp_path / "dataset")
    other = tmp_path / "other-repository"
    nested = other / "nested"
    nested.mkdir(parents=True)
    git_marker = other / ".git"
    if marker == "directory":
        git_marker.mkdir()
    elif marker == "file":
        git_marker.write_text("gitdir: elsewhere")
    else:
        git_marker.symlink_to(root)
    with pytest.raises(SignalError, match="repositor"):
        signals._private_output(nested / "new-output", root)
    assert not (nested / "new-output").exists()


def test_decimal_exponent_rejected_before_fixed_point_rendering(monkeypatch: pytest.MonkeyPatch) -> None:
    import builtins
    original = builtins.format
    def guard(value: object, spec: str = "") -> str:
        if spec == "f":
            raise AssertionError("unsafe fixed-point expansion")
        return original(value, spec)
    with monkeypatch.context() as patch:
        patch.setattr(builtins, "format", guard)
        with pytest.raises(SignalError):
            signal_sources._decimal_text("1e10000000")


def test_code_lineage_includes_calculator_runtime_and_parser(tmp_path: Path) -> None:
    for name in signals.CODE_FILES:
        path = tmp_path / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php // fixture")
    source = tmp_path / "src/TradingCore/Backtesting/Indicator/CanonicalPhpIndicatorCalculator.php"
    source.parent.mkdir(parents=True, exist_ok=True)
    source.write_text("<?php // calculator")
    config = tmp_path / "config/services.yaml"
    config.parent.mkdir(parents=True, exist_ok=True)
    config.write_text("services: {}")
    dependency = tmp_path / "vendor/example/runtime.php"
    dependency.parent.mkdir(parents=True, exist_ok=True)
    dependency.write_text("<?php // dependency")
    for name in ("bin/console", "composer.json", "composer.lock", "vendor/composer/installed.json"):
        path = tmp_path / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("fixture")
    hashes = signals._code_hashes(tmp_path, fake_worker=False)
    assert str(source) in hashes
    assert str(config) in hashes
    assert str(dependency) in hashes
    assert str(tmp_path / "bin/console") in hashes
    assert str(Path(signals.__file__).with_name("binance_history.py")) in hashes


def test_time_math_warmup_before_score_and_boundary(tmp_path: Path) -> None:
    start = datetime.fromisoformat("2022-11-01T00:00:00+00:00")
    score = datetime.fromisoformat("2023-01-01T00:00:00+00:00")
    end = datetime.fromisoformat("2023-02-01T00:00:00+00:00")
    assert signal_sources._expected_ticks(start, end, score) == (2977, 3999, 1856)
    chosen = replace(select_sources(dataset(tmp_path / "dataset"), START, END, START, "BTCUSDT"),
                     start=start, end=end, score_start=score)
    assert signals._first_evaluable_ms(chosen) == 1672531200000
    assert not signals._is_scored_tick(signal_sources._ms(end), signal_sources._ms(score), signal_sources._ms(end))


@pytest.mark.parametrize("text", ["bad", "2023-01-01", "2023-01-01T00:00:01Z",
                                    "2023-01-01T01:00:00+01:00"])
def test_utc_requires_explicit_minute_utc(text: str) -> None:
    with pytest.raises(SignalError):
        signal_sources._utc(text)


@pytest.mark.parametrize("value", [None, "not-a-decimal", "NaN", "-1", "1e100", "0.00000000000000000000000000000000000000000000000000000000000000001"])
def test_decimal_normalization_limits(value: object) -> None:
    with pytest.raises(SignalError):
        signal_sources._decimal_text(value)
    assert signal_sources._decimal_text("1.2500") == "1.2500"


@pytest.mark.parametrize("name", [None, "/absolute", "../escape"])
def test_private_stored_path_rejects_escape(name: object) -> None:
    with pytest.raises(SignalError):
        signal_sources._safe_relative(name)


def test_bounded_read_rejects_symlink_ancestor_and_file(tmp_path: Path) -> None:
    root = tmp_path / "root"
    root.mkdir()
    real = root / "real"
    real.mkdir()
    (real / "item").write_text("content")
    (root / "linked").symlink_to(real, target_is_directory=True)
    with pytest.raises(SignalError):
        signal_sources._read_bounded(root, "linked/item", 10)
    with pytest.raises(SignalError):
        signal_sources._read_bounded(root, "real/item", 2)
    assert signal_sources._read_bounded(root, "real/item", 10) == b"content"


def test_monthly_archive_origin_and_period_checks() -> None:
    january = datetime.fromisoformat("2023-01-01T00:00:00+00:00")
    february = datetime.fromisoformat("2023-02-01T00:00:00+00:00")
    source = plan_archives("BTCUSDT", january, february)[0]
    entry = {"filename": source.filename, "url": source.url,
             "checksum_url": source.checksum_url, "start": january.isoformat(), "end": february.isoformat()}
    assert signal_sources._archive_source(entry, "BTCUSDT") == source
    for mutation in ({"filename": "BTCUSDT-1m-2023-99.zip"},
                     {"end": "2023-03-01T00:00:00+00:00"}):
        with pytest.raises(SignalError):
            signal_sources._archive_source({**entry, **mutation}, "BTCUSDT")


def test_missing_rest_pages_rejected_at_selection(tmp_path: Path) -> None:
    root = rest_dataset(tmp_path / "dataset")
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    manifest["sources"][0]["pages"] = []
    manifest_path.write_text(json.dumps(manifest))
    with pytest.raises(SignalError, match="REST provenance missing"):
        select_sources(root, START, END, START, "BTCUSDT")


def test_archive_optional_official_header_is_accepted(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset")
    manifest_path = root / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    entry = manifest["sources"][0]
    raw_path = root / entry["raw_path"]
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w") as archive:
        header = ",".join(KLINE_HEADER)
        body = "\n".join(f"{t},100,102,99,101,2,{t+59999},200,3,1,100,0"
                         for t in (1672531200000, 1672531260000))
        archive.writestr("BTCUSDT-1m-2023-01-01.csv", header + "\n" + body)
    raw = stream.getvalue()
    raw_path.write_bytes(raw)
    digest = hashlib.sha256(raw).hexdigest()
    (root / entry["checksum_path"]).write_text(f"{digest}  {entry['filename']}")
    entry["sha256"] = digest
    entry["size"] = len(raw)
    manifest_path.write_text(json.dumps(manifest))
    assert len(list(signal_sources.iter_verified_candles(root, select_sources(root, START, END, START, "BTCUSDT")))) == 2


def test_iter_source_detects_gap_and_skips_outside_window(tmp_path: Path,
                                                          monkeypatch: pytest.MonkeyPatch) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    def records(*args: object) -> object:
        yield {"open_ms": 1672531140000}
        yield {"open_ms": 1672531260000}
        yield {"open_ms": 1672531320000}
    monkeypatch.setattr(signal_sources, "_archive_records", records)
    with pytest.raises(SignalError, match="gap or order"):
        list(signal_sources.iter_verified_candles(root, selection))


def test_iter_source_rejects_missing_final_candle(tmp_path: Path,
                                                  monkeypatch: pytest.MonkeyPatch) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    monkeypatch.setattr(signal_sources, "_archive_records",
                        lambda *args: iter([{"open_ms": 1672531200000}]))
    with pytest.raises(SignalError, match="count incomplete"):
        list(signal_sources.iter_verified_candles(root, selection))


def test_atomic_report_cleans_temp_on_publication_failure(tmp_path: Path,
                                                          monkeypatch: pytest.MonkeyPatch) -> None:
    def fail_replace(*args: object) -> None:
        raise OSError("simulated publication failure")
    monkeypatch.setattr(signals.os, "replace", fail_replace)
    with pytest.raises(OSError, match="publication failure"):
        signals._atomic_json(tmp_path / "report.json", {"status": "failed"})
    assert not (tmp_path / ".report.json.tmp").exists()


def test_worker_support_helpers_have_bounded_failure_paths(tmp_path: Path,
                                                           monkeypatch: pytest.MonkeyPatch) -> None:
    assert signals._rss_sample(-1) is None
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    monkeypatch.setattr(signals, "iter_verified_candles", lambda *args: iter(()))
    assert [json.loads(frame)["schema_version"] for frame in signals._frames(selection, root, "x")] == [
        "research-signal-open.v1", "research-signal-close.v1"]
    console = tmp_path / "bin/console"
    console.parent.mkdir()
    console.write_text("<?php")
    monkeypatch.setattr(signals.shutil, "which", lambda _: "/usr/bin/php")
    assert signals._worker_argv(tmp_path, None)[-2:] == ("app:research:signals", "--no-interaction")


def test_nonexistent_worker_executable_preserves_failed_report(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset")
    out = tmp_path / "out"
    with pytest.raises(SignalError, match="could not start"):
        run_signals(root, out, tmp_path, START, END, START, ("BTCUSDT",),
                    worker_argv=(str(tmp_path / "missing-executable"),))
    assert json.loads((out / "report.json").read_text())["status"] == "failed"


@pytest.mark.parametrize("fault", ["open_symbol", "snapshot_request", "snapshot_hash", "empty_baseline",
                                    "risk", "setup_hash", "baseline_hash", "files_missing",
                                    "file_hash_type", "file_relative", "file_outside", "file_absent",
                                    "file_symlink", "file_changed", "file_count"])
def test_open_evidence_rejects_forged_baseline_and_files(tmp_path: Path, fault: str) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    frame = opened_fixture(tmp_path, selection)
    snapshot, baseline = frame["effective_config_snapshot"], frame["baseline"]
    if fault == "open_symbol":
        frame["symbol"] = "BTCUSDT"
    elif fault == "snapshot_request":
        snapshot["request"]["mode_id"] = "scalping"
    elif fault == "snapshot_hash":
        snapshot["snapshot_hash"] = "sha256:" + "0" * 64
    elif fault == "empty_baseline":
        frame["baseline"] = {}
    elif fault == "risk":
        baseline["mode_risk"] = {"budget": 2}
    elif fault == "setup_hash":
        baseline["setup_hash"] = "bad"
    elif fault == "baseline_hash":
        baseline["config_hash"] = "sha256:" + "0" * 64
    elif fault == "files_missing":
        baseline["file_hashes"] = {}
    elif fault == "file_hash_type":
        first = next(iter(baseline["file_hashes"]))
        baseline["file_hashes"][first] = 42
    elif fault == "file_relative":
        first = next(iter(baseline["file_hashes"]))
        baseline["file_hashes"]["relative.yaml"] = baseline["file_hashes"].pop(first)
    elif fault == "file_outside":
        first = next(iter(baseline["file_hashes"]))
        baseline["file_hashes"][str(tmp_path.parent / "outside.yaml")] = baseline["file_hashes"].pop(first)
    elif fault == "file_absent":
        Path(next(iter(baseline["file_hashes"]))).unlink()
    elif fault == "file_symlink":
        first = Path(next(iter(baseline["file_hashes"])))
        moved = first.with_name("saved.yaml")
        first.rename(moved)
        first.symlink_to(moved)
    elif fault == "file_changed":
        Path(next(iter(baseline["file_hashes"]))).write_text("changed")
    else:
        baseline["file_hashes"][str(tmp_path / "extra.yaml")] = "sha256:" + "a" * 64
    with pytest.raises(SignalError):
        signals._validate_opened(frame, "session", selection, tmp_path)


@pytest.mark.parametrize("fault", ["shape", "timestamp", "no_contexts", "missing_timeframe",
                                    "context_type", "context_keys", "available_bool", "open_bool",
                                    "stale", "wrong_open", "window_hash", "result_hash_shape",
                                    "trace_hash_shape", "trace_hash_value", "trace_empty",
                                    "result_hash_value", "reason", "verdicts", "nonfinite"])
def test_result_evidence_rejects_forged_frames(fault: str) -> None:
    tick = 1672531200000
    frame = result_fixture(tick)
    if fault == "shape":
        frame.pop("evaluated_at")
    elif fault == "timestamp":
        frame["evaluated_at"] = "2023-01-01T00:01:00.000000Z"
    elif fault == "no_contexts":
        frame["contexts"] = {}
    elif fault == "missing_timeframe":
        frame["contexts"].pop("4h")
    elif fault == "context_type":
        frame["contexts"]["1m"] = []
    elif fault == "context_keys":
        frame["contexts"]["1m"].pop("rsi")
    elif fault == "available_bool":
        frame["contexts"]["1m"]["available_ms"] = True
    elif fault == "open_bool":
        frame["contexts"]["1m"]["open_ms"] = True
    elif fault == "stale":
        frame["contexts"]["5m"]["available_ms"] -= 300000
    elif fault == "wrong_open":
        frame["contexts"]["1h"]["open_ms"] += 3600000
    elif fault == "window_hash":
        frame["contexts"]["4h"]["research_window_hash"] = "bad"
    elif fault == "result_hash_shape":
        frame["result_hash"] = "bad"
    elif fault == "trace_hash_shape":
        frame["trace_hash"] = "bad"
    elif fault == "trace_hash_value":
        frame["trace_hash"] = "sha256:" + "0" * 64
    elif fault == "trace_empty":
        frame["trace"] = {}
    elif fault == "result_hash_value":
        frame["result_hash"] = "sha256:" + "0" * 64
    elif fault == "reason":
        frame["reason_code"] = ""
    elif fault == "verdicts":
        frame["verdicts"] = {}
    else:
        frame["contexts"]["1m"]["close"] = float("nan")
        frame["result_hash"] = "sha256:" + "0" * 64
    with pytest.raises(SignalError):
        signals._validate_result(frame, tick)


def test_valid_open_and_result_evidence(tmp_path: Path) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    frame = opened_fixture(tmp_path, selection)
    signals._validate_opened(frame, "session", selection, tmp_path)
    result = result_fixture(1672531200000)
    signals._validate_result(result, result["evaluated_ms"])
    result["trace"] = None
    result["result_hash"] = signals._sha256_canonical({key: value for key, value in result.items()
                                                       if key != "result_hash"})
    signals._validate_result(result, result["evaluated_ms"])


@pytest.mark.parametrize("case", ["relative", "outside", "snapshot_list", "execution_changed"])
def test_direct_identity_and_baseline_path_guards(tmp_path: Path, case: str) -> None:
    root = dataset(tmp_path / "dataset")
    selection = select_sources(root, START, END, START, "BTCUSDT")
    frame = opened_fixture(tmp_path, selection)
    if case in {"relative", "outside"}:
        hashes = frame["baseline"]["file_hashes"]
        catalog = str(tmp_path / "config/catalog.yaml")
        digest = hashes.pop(catalog)
        hashes["relative.yaml" if case == "relative" else str(tmp_path.parent / "outside.yaml")] = digest
    elif case == "snapshot_list":
        frame["effective_config_snapshot"] = []
    else:
        forged = deepcopy(frame)
        forged["symbol"] = "BTCUSDT"
        forged["execution"] = {"mode_id": "forged"}
        with pytest.raises(SignalError, match="execution or baseline changed"):
            signals._identity(forged, frame, "session", selection)
        return
    with pytest.raises(SignalError):
        signals._validate_opened(frame, "session", selection, tmp_path)


def test_child_termination_escalates_only_its_process() -> None:
    class Child:
        def __init__(self) -> None:
            self.calls: list[str] = []

        def poll(self) -> None:
            return None

        def terminate(self) -> None:
            self.calls.append("terminate")

        def wait(self, timeout: int) -> int:
            self.calls.append("wait")
            if self.calls.count("wait") == 1:
                raise subprocess.TimeoutExpired("fixture", timeout)
            return 0

        def kill(self) -> None:
            self.calls.append("kill")

    child = Child()
    signals._terminate(child)
    assert child.calls == ["terminate", "wait", "kill", "wait"]
