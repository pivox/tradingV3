# Research Guarded Holdout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This plan authoring task does not authorize spawning agents or implementation; the parent will review it before implementation.

**Goal:** Implement the complete bounded C2b path from authentic selected-candidate freeze to once-only 2026 signal generation, shared portfolio simulation, independently verified statistics and retained reports, with distinct Paper work still pending.

**Architecture:** A fixed installed campaign authority invokes the retained authentic C1 verifier in an isolated subprocess. A private exact-scope capability and durable campaign-wide claim guard separate holdout entrypoints that share existing worker, simulation and statistics implementations. Existing training/validation entrypoints and frozen operational checkouts retain their current boundaries.

**Tech Stack:** Python standard library, existing Pydantic runtime, pytest, existing PHP bridge and research engine; synthetic tests only during development.

---

## Scope and implementation boundaries

### Delivery checkpoint — 2026-10-09

Task 1 is committed at `5968c181bca38f099145b8e1d414d6253e52eebe`: parent verification passed 116 authority tests, checked all 12 frozen inventory entries against the approved Git blobs, and measured 95.33% combined line/branch coverage. Task 2 is committed at `1f8161a0d103d77dd97eb980636d7ac9f86aad6a`: the parent independently passed 927 focused tests and reran synthetic pre-holdout parity against `8ef8addd`. That parity covers one positive 2023 fixture, exact bytes of seven ledgers and nonvolatile summary arithmetic; it is not a claim about operational results or every possible input.

The Task 2 implementer also passed 1,133 expanded tests with 14 opt-in PHP tests skipped. Changed-module combined line/branch coverage was 96.663347%; pure branch coverage was 93.760399%, with no exclusions added. These measurements precede Task 3 and do not replace its acceptance tests or required repository CI.

Task 3 controller/reporting is committed at `bf72a7cf7af134fcec896db11e67a3edb157e1fb`. Its parent-focused run recorded 974 passes and one stale inventory-fixture failure; the broader implementer run recorded 1,180 passes, 14 optional skips and the same failure. After correcting only that test to assert the exact sixteen-file inventory, the parent independently passed all 116 authority tests with application hashes unchanged. The full-run coverage plus corrected-module coverage in a separate database copy measured 96.675532% combined line/branch coverage across seven modules (93.243243% pure branch); controller-only combined coverage was 95.945946%. No exclusions were added. These separate runs are not described as a fresh full-suite local pass.

The single consolidated independent review is complete. Its two Important findings (production inventory mismatch and missing physical source verification) and Minor runner-provenance finding are corrected in `662994f12f0b15cf1a09144aff91c7ccc683e6f7`. Parent correction verification passed 22 targeted regressions, one direct inventory-equality test and six binding-scope cases. The broader 487-case run recorded 486 passes and one stale fixture mutation; the test-only correction then passed all six cases with application bytes unchanged. The fresh fifteen-file suite then passed 1,202 tests with 14 skips in 991.68 seconds: eight inventoried modules, including `binance_history.py`, measured 96.687924% combined line/branch coverage and 93.286713% pure branch coverage, with no exclusions. Both required CI jobs passed on `6594839d`. These measurements precede the subsequent output-placement correction and are not claimed as verification of its changed source lines.

The repository's automatic PR-open review also identified a P1: a fresh output inside a Git repository could consume the once-only claim before the signal stage rejected its placement. Commit `9bb11b9c5b6f54d12541a493d98af21fc46b8bf6` shares the existing pure location guard with pre-claim validation, without creating directories or requiring a future signal parent. Six synthetic invalid-placement regressions first reproduced the defect; the parent independently passed those six and one valid-parent case after correction. Fresh changed-module verification passed 340 authority/signal/controller cases in 159.96 seconds, then 16 existing focused cases in 4.71 seconds appended to a copy of that same-source coverage database. The two changed modules measured 95.632911% combined line/branch coverage and 90.909091% pure branch coverage with no exclusions; no pre-P1 arcs were reused. The other six application modules are byte-unchanged from the full correction suite. Final-head CI is required separately; no second review was requested.

Required complete CI on the final PR head and PR merge remain pending. No second independent review cycle is requested. No operational authority has been installed and no 2026 evaluation has been launched. Frozen training workers and their supervisor remain separate and must not be changed. Paper transfer remains distinct and pending; execution authority remains `none`.

The task checklists below retain the original implementation instructions; this checkpoint records accepted intermediate commits, not completion of the full plan.

Author only in the parent-managed `codex/research-guarded-holdout` worktree, based on `884f964e`. Do not edit execution `8ef8addd` or B1 runtime `9d05d841`, their dependency environment, reports or running processes. Read the approved historical specification and `2026-10-09-research-guarded-holdout-design.md` before implementation. No actual 2026 candle, signal, strategy or profit inspection, PHP worker launch or operational launch is part of development acceptance. Commands below are future verification commands, not commands executed while writing this plan.

The new lane must evaluate the selected catalogue identity plus baseline under baseline/adverse costs: four unique slots, or two when selected is baseline. It never creates a winner or reranks candidates. A no-winner freeze denies all evaluation. Paper remains a separate later campaign using supported recorded public captures and profiles; C2b report completion cannot mark the overall historical-plus-Paper request complete.

### File ownership and interfaces

Create under `python-orchestrator/app/backtesting/research/`:

- `holdout_authority.py`: installed authority, trusted frozen-verifier bridge, immutable contract/claim publication and exact typed capability. Import only standard-library modules at module level to avoid engine cycles.
- `holdout.py`: production controller, finite resource accounting, distinct holdout report builder and fixed-authority CLI.
- `frozen_verifier_inventory.json`: reviewed relative repository dependency inventory at approved commit `8ef8addd93a240ead5c01abbc67d053df44e8942`; no private absolute paths.

Modify `signal_sources.py`, `signals.py`, `campaign.py`, `portfolio_simulator.py`, and `statistics.py` only for the interfaces described below. Existing `experiments.py` and `orchestration.py` need no behavior changes. Update `_runner_code()` to inventory both new modules and the inventory JSON; update signal code inventory to include the authorization module it invokes. Preserve `campaign_evidence.Evidence` and its write-all helper.

Create `python-orchestrator/tests/test_research_holdout_authority.py`, `test_research_holdout.py`, and focused cases in existing source/signal/campaign/simulator/statistics tests. Test adapters live in tests and use monkeypatch; no production fake-worker/verifier flag or callback is added to the new public lane.

### Fixed trust and claim anchor

The sole campaign identifier is `historical-2026-10-09`. `load_campaign_authority()` reads exactly `Path.home()/'.config/tradingV3/research-authorities/historical-2026-10-09.json'`. This location is derived in code, with no `XDG_CONFIG_HOME`, environment or CLI override. Require owner UID, directory mode `0700`, file mode `0600`, regular files, no symlink ancestors and canonical JSON. This is an operator-controlled local trust record, not a cryptographic sandbox against its owner.

`install_campaign_authority(retained_attempt_path, retained_snapshot_path, recovery_environment_record_path=None)` is an explicit trusted provisioning API, not an evaluation CLI command. It reads the retained supervisor attempt/config and `input-code-identity.json`, verifies their relationship, derives registry, dataset, original Symfony app, instrument/cost and original interpreter paths, and refuses replacement of an installed authority. Normal provisioning checks the interpreter hash against the retained snapshot. If the original environment was lost after reboot, a separately reviewed owner-only recovery record may declare the reconstructed interpreter/dependencies: preserve original identity, record the new runtime inventory and recovery reason, and never claim old transitive byte identity. This provisioning record is trusted local configuration; evaluation has no executable/command/import/recovery override. Provision only after the original supervisor is terminal and its registry exists. No market observations are read during provisioning. Fixed production CLI is `python -m app.backtesting.research.holdout --output-root /explicit/fresh/private/root`, with optional *lower* timeout/storage caps only.

The authority stores the original canonical registry path and protocol hash. Derive the permanent sidecar as `registry.parent / (registry.name + '.c2b-holdout')`; refuse overlap with any input or output. Its immutable `authority.json` must equal the installed authority's campaign ID, registry path and protocol hash. The installed authority cannot switch registry later. Copying a freeze, renaming an output directory or supplying an alternative registry has no evaluation entrypoint.

### Frozen verifier closure and process isolation

The reviewed repository inventory consists of `app/__init__.py`, `app/backtesting/__init__.py`, `app/backtesting/research/__init__.py`, research `experiments.py`, `statistics.py`, `portfolio_simulator.py`, `app/modern_trading_contracts.py`, and `app/backtesting/{contracts.py,dataset.py,dataset_store.py,indicator_bridge.py,tradingcore_bridge.py}`. The five additional modules are imported by the nonempty backtesting package initializer. An import-only probe of the retained commit identified these twelve local modules and only the five external dependency families listed below; no verifier or market data was invoked. Preserve authentic package initialization and include a regression for that import closure, rather than testing only empty synthetic package initializers. Generate hashes from the named Git commit's blobs, not current disk contents or protocol-supplied digest values. Store relative paths and SHA-256 values in the new inventory JSON. Check every retained file against these trusted values before invocation; reject missing/extra package initializer/import changes.

`modern_trading_contracts.py` imports Pydantic. Trusted provisioning pins the real interpreter bytes, Python version and installed `pydantic`, `pydantic_core`, `annotated_types`, `typing_extensions` and `typing_inspection` dependency trees when present. Exclude bytecode/cache files; include compiled extension bytes and distribution metadata. Preserve relative path-to-root mappings and exact installed versions. Reject executable `.pth` requirements rather than enabling site processing. Bound this inventory to 8 MiB of JSON, 25,000 entries and 256 MiB hashed bytes; exceeding bounds fails provisioning. Keep the provisioned inventory private, outside Git. The interpreter's standard library and OS loader are trusted host runtime, not newly attested operating-system artifacts. These external dependencies are trusted at provisioning and immutable thereafter; no original transitive environment inventory survives to justify a claim of historical dependency byte identity. This is a bounded local workflow/reproducibility gate, not a generalized secure-runtime framework.

Invoke only `[authority.python, '-I', '-S', '-B', '-c', FIXED_BOOTSTRAP]` with `cwd=authority.frozen_python_root`, empty/small explicit environment, no caller arguments beyond the registry path encoded as JSON on stdin. The fixed bootstrap inserts only the pinned frozen repository and dependency roots into isolated `sys.path`, imports `experiments.verify_freeze`, and writes `canonical_bytes(verify_freeze(registry/'selection-freeze.json'))`. `-I -S` prevents user-site, `PYTHONPATH`, `.pth`, `sitecustomize` and `usercustomize` execution; the cwd is not relied upon for implicit imports. Validate loaded non-standard-library module origins against pinned roots/inventory before producing the response. Recheck pinned code/runtime hashes after child exit to detect mutation; preserve exit code and bounded stderr evidence. Use the same inventory checks for verification replay.

Read the original freeze as bounded canonical JSON before child launch; verify the child's returned bytes equal the original artifact exactly, not merely its `selected` field. No-winner denial happens before claim and before any holdout market/profit reads. A timeout, malformed response, unknown schema or nonzero exit denies authority.

### Resource limits and failure policy

Fixed defaults: overall wall deadline 7 days; frozen verifier 6 hours; each signal symbol 6 hours; each simulation 6 hours; owned-process cleanup at most 10 seconds. Use monotonic remaining time at every stage; a caller can reduce but cannot raise these limits. Reuse existing POSIX deadline context/owned-child cleanup patterns without observing or signaling old processes. Preserve verifier stdout at most 4 MiB (the old freeze bound), stderr at most 2 MiB, protocol at most 64 MiB and controller metadata at most 64 MiB. Stream pipe reads with concurrent drains; never `communicate()` into unbounded memory. On overflow stop only the new owned verifier.

Signal evidence cap 8 GiB total; each simulation evidence cap 4 GiB; statistics spool cap 4 GiB; persistent overall output cap 24 GiB including metadata, with 20 GiB disk reserve. Before every unit pass the lesser of remaining overall budget and its cap, accounting for already retained signals/units. During ten sequential signal-symbol runs account for the aggregate cap, rather than resetting the cap per symbol. Existing temporary SQLite statistics spool is reused as a bounded local arithmetic index; no service/application DB is introduced. Development here does not launch it. Require capacity for terminal metadata before claiming; use existing evidence reserves for units and a controller reserve of 64 KiB.

Preflight verifies authority, old freeze, unchanged baseline/PHP/instrument/cost/new-code identities, output safety and metadata/free-space capacity without opening 2026 candles/signals/profits. Then claim once. Missing candle coverage discovered after claim, missing funding, deadline, malformed bridge reply, ledger disagreement or I/O failure retains consumed status. Inconclusive source evidence is never ranked or reported as complete. Failed slots retain status/partial inventory; unstarted slots are explicitly `not_run_batch_failed`, not zero-PnL successes. Stop batch on a fatal failure; incomplete funding can finish all fixed units as inconclusive. There is no simulation/signal resume or automatic retry.

## Task 1: Trusted authority, isolated verifier and durable claim

**Files:** Create `holdout_authority.py`, `frozen_verifier_inventory.json`, and `tests/test_research_holdout_authority.py` at the paths above.

- [ ] Write synthetic denial and identity tests before implementation. Use small temporary package/runtime trees and monkeypatch the private transport function, never a public command override. The old-verifier transport test separately launches only a temporary synthetic Python package, with no PHP/data; its subprocess arguments must still match the production fixed bootstrap. Concrete cases:

```python
def test_no_winner_never_claims_or_reads_holdout(authority_fixture, monkeypatch):
    a = authority_fixture(selection_state='no_eligible_candidate')
    monkeypatch.setattr(a.module, '_invoke_frozen_verifier', a.synthetic_response)
    with pytest.raises(a.module.HoldoutError, match='no_selected_candidate'):
        a.module.prepare_claim(a.authority, a.output)
    assert not (a.anchor / 'claim.json').exists()
    assert a.holdout_reads == []

def test_fresh_output_cannot_repeat(authority_fixture, monkeypatch):
    a = authority_fixture(selection_state='selected', selected='baseline')
    monkeypatch.setattr(a.module, '_invoke_frozen_verifier', a.synthetic_response)
    claim = a.module.prepare_claim(a.authority, a.output)
    assert claim.slots == (('baseline', 'baseline'), ('baseline', 'adverse'))
    with pytest.raises(a.module.HoldoutError, match='already_claimed'):
        a.module.prepare_claim(a.authority, a.other_output)
```

The test fixture supplies synthetic bytes at all private seams; `_invoke_frozen_verifier` still has no injectable callable in production. Add concurrent processes claiming the same anchor, copied freeze under another registry, altered dependency byte, executable mismatch, `PYTHONPATH`/`sitecustomize` poison, oversized/noisy child, timeout, noncanonical freeze and nonexistent terminal cases. Assert denied calls never open sentinel holdout files.

- [ ] Future command: `python -m pytest tests/test_research_holdout_authority.py -q` from `python-orchestrator`; initially fail for missing module/interfaces.

- [ ] Implement these exact interfaces and value objects. All JSON copies are canonicalized and made immutable; use tuple bindings/serialized canonical bytes instead of mutable nested dict fields. Private mint sentinel must not be a public boolean or a user-supplied mapping:

```python
@dataclass(frozen=True)
class HoldoutAuthorization:
    authority_hash: str
    contract_hash: str
    claim_hash: str
    anchor: Path
    output_root: Path
    slots: tuple[tuple[str, str], ...]
    contract_bytes: bytes
    mode: str  # 'evaluate' or 'verify'; verified by the private issuance seal.
    _mint: object = field(repr=False, compare=False)

def load_campaign_authority() -> CampaignAuthority: ...
def install_campaign_authority(retained_attempt_path: Path,
                               retained_snapshot_path: Path,
                               recovery_environment_record_path: Path | None = None
                               ) -> CampaignAuthority: ...
def prepare_claim(authority: CampaignAuthority, output_root: Path,
                  *, timeout: float = OVERALL_SECONDS,
                  max_output_bytes: int = OVERALL_BYTES) -> HoldoutAuthorization: ...
def require_claim(auth: HoldoutAuthorization, *, operation: str,
                  variant_id: str | None = None, cost_profile: str | None = None,
                  symbols: tuple[str, ...] = SYMBOLS,
                  source_start: str | None = None,
                  score_start: str | None = None, end: str | None = None,
                  output_root: Path | None = None) -> dict: ...
```

`CampaignAuthority` is another frozen dataclass containing immutable canonical config/inventory bytes and exact resolved paths. `require_claim` returns a fresh decoded contract after verifying private mint, installed authority hash, anchor claim/contract bytes and hashes, exact full symbol priority and requested operation/slot/window/output. The private issuance seal contains the complete issued field tuple; require equality with current fields so `dataclasses.replace()` cannot broaden scope. Store slot output paths deterministically under `auth.output_root/'units'/variant_id/cost_profile`. Reverify binding at public entrypoints and after execution. Fake dataclass construction, altered fields and `None` deny. Keep engine imports local; use `symbols=None` in authority signatures if needed to avoid importing simulator constants during simulator import.

Create contract first under an exclusive sidecar lock (`flock` plus immutable publication); it declares window, identity transition, unique slots and resource limits but carries no evaluation authority. Publish claim second using `_publish_bytes`-style hard-link no-replace publication, file/parent fsync. Claim references contract hash, authority hash and fresh run ID. Once claim link exists, any fsync/return failure consumes it. A leftover temporary file, malformed contract, lock-held interrupted transaction or contract-without-claim is ambiguous: deny evaluation and retain evidence; do not repair/reclaim automatically. Test faults immediately before/after link/fsync and two concurrent writers. Locks alone never prove completion. Never delete claim/contract on cleanup.

- [ ] Future command: `python -m pytest tests/test_research_holdout_authority.py -q`; expect all synthetic authority/claim/isolation tests to pass. Verify generated inventory against the approved Git blob hashes, including package initializers and their transitive local imports.

- [ ] Review diff for private paths or import bypasses, then future commit: `git add python-orchestrator/app/backtesting/research/holdout_authority.py python-orchestrator/app/backtesting/research/frozen_verifier_inventory.json python-orchestrator/tests/test_research_holdout_authority.py && git commit -m 'feat(research): bind guarded holdout authority and durable claim'`.

## Task 2: Shared engine with exact holdout entrypoints

**Files:** Modify `signal_sources.py`, `signals.py`, `campaign.py`, `portfolio_simulator.py`, `statistics.py`; extend their existing synthetic test files and `test_research_holdout.py`.

Integration refinement after Task 1 (`5968c181`, 116 authority tests and 95.33% branch coverage independently confirmed): Task 2 may extend `holdout_authority.py` with a private shared monotonic budget and a checked remaining-limits helper. Start the evaluation clock before preflight and share its deadline across slot capabilities; do not restart it per symbol or unit. A retained-verification invocation has its own finite read-only budget, never evaluation authority. Account for existing output once, including signals, bindings, unit artifacts and metadata, while reserving terminal capacity.

Use one concrete `research-holdout-unit-inputs.v1` binding format shared by campaign, simulator, statistics and the later controller. It includes authority/contract/claim hashes, slot/window/universe/risk, baseline/variant, B1 reports/source runs, instrument/cost documents and raw hashes, exact kernel assumptions, funding provenance/policy/coverage and PHP/current runner identities. Construction must genuinely verify its inputs, and immutable publication must not accept an unchecked caller-created mapping. Read-only retained verification must validate canonical bindings and referenced hashes without calling evaluation-only source generation or simulation; it must not rebuild a binding by weakening those operation guards. Keep the helper APIs in `campaign.py` unless a concrete cycle requires a smaller shared location agreed with the parent.

The agreed minimal binding anchor is an immutable marker at `auth.anchor/inputs/<variant>/<profile>.json`, containing authority/contract/claim/slot and canonical input-binding hash. Publication internally builds verified inputs; it does not accept arbitrary supplied mappings. Binding-without-marker or marker-without-binding after interruption fails closed and preserves both artifacts; there is no repair or new evaluation claim.

- [ ] Write tests preserving every existing closed default and proving a claimed capability is necessary at each new entrypoint. Keep original test expectations. Add forged authorization, changed dates, variant diff, costs, risk caps, symbol order, source hash and unclaimed slot cases before market/report/summary reads. Concrete shared-statistics case:

```python
def test_statistics_default_closed_and_guarded_arithmetic_shared(
        claimed_fixture, synthetic_holdout_ledgers):
    auth = claimed_fixture(selected='baseline')
    root, files = synthetic_holdout_ledgers(auth)
    with pytest.raises(StatisticsError, match='holdout_closed'):
        analyze_ledgers(root, files, symbols=SYMBOLS,
                        start_ms=1767225600000, end_ms=auth.end_ms)
    actual = analyze_holdout_ledgers(root, files, authorization=auth,
                                    variant_id='baseline', cost_profile='baseline')
    assert actual['net_pnl_quote'] == '2'
    assert actual['per_year']['2026']['closed_trades'] == 1
```

Here `auth.end_ms` is a read-only property decoded from the exact contract. Fixtures synthesize records at valid 2026 timestamps; no real data files are opened. Keep the fixed window in a private module constant, not configuration. Exact production-window guard tests use its real value. Short genuine engine end-to-end fixtures may monkeypatch that private constant to a three-minute synthetic scoring interval and synthetic warmup/count plumbing, then restore it; this seam is pytest-only and never exposed as an argument, environment variable or production adapter. Retain separate full-cutoff scope/coverage-count tests without simulating millions of minutes. Do not claim those short fixtures constitute an actual full-calendar holdout run.

- [ ] Future command: `python -m pytest tests/test_research_signals.py tests/test_research_campaign.py tests/test_research_portfolio_simulator.py tests/test_research_statistics.py tests/test_research_holdout.py -q`; new cases initially fail.

- [ ] Extract `signal_sources._select_sources_validated(root, beginning, ending, score, symbol)` containing existing coverage/provenance logic. Original `select_sources()` retains its original validation before calling it. Add `select_holdout_sources(root, symbol, *, authorization)` which calls `require_claim(operation='source', ...)` before manifest/candle selection and passes only the contract's fixed three dates. Include dataset-root/hash binding; a caller cannot swap datasets.

Extract `signals._run_selected_signals(...)` from `run_signals()` after source/argument validation. Original path retains all behavior. Add `run_holdout_signals(output_root, *, authorization)` which resolves dataset/app from the contract, selects all ten symbols through the guarded selector, validates output as exactly `auth.output_root/'signals'`, uses `_worker_argv(app_dir, None)` and the existing `_run_symbol` machinery, and tracks one remaining aggregate cap/deadline. Preserve actual new code inventory in reports. No worker argument accepted. Generate exactly one completed baseline signal report for all units.

- [ ] Extract `campaign._run_campaign_core(...)` without changing portfolio order, fill/funding/settlement/reconciliation semantics. Original `run_campaign()` continues training/validation validation and calls the core with no authorization. Add `run_holdout_campaign(signal_root, *, authorization, variant_id, cost_profile)`; resolve all other input/output paths from contract and call `require_claim` before any B1/source read. Extend `_verify_reports` with a private authorization argument defaulting to `None`: old branch keeps all old phase/date/code checks; authorized branch requires `phase='holdout'`, exact dates, all ten symbols and guarded source selection. Require new B1 code exactly matches current inventoried new code, baseline identity matches old contract and PHP inventory is unchanged. It must not pretend old training B1 Python hashes match changed modules.

Add `authorization: HoldoutAuthorization | None = None` as the final `RunAssumptions` field (use deferred/local imports to avoid a cycle). Preserve every existing `__post_init__` check; replace only its window predicate with two explicit branches:

```python
if self.phase == 'holdout':
    contract = require_claim(self.authorization, operation='simulate',
        variant_id=dict(self.identity)['variant_id'],
        symbols=self.symbols)
    require_exact_assumption_bindings(self, contract)
elif self.authorization is not None or not original_preholdout_window_valid(self):
    raise ValueError('research_source_window_invalid')
```

`CostAssumptions` has no profile field. Add `HoldoutAuthorization.for_slot(variant_id, cost_profile)` returning a minted immutable copy restricted to that one existing claimed slot; controller/campaign pass it to simulator/statistics. `require_claim(operation='simulate')` requires one restricted slot and rejects a batch capability. `require_exact_assumption_bindings` compares fixed start/end, variant/baseline/config/snapshot/instrument/cost/research hashes, exact `costs.wire()`, source tuples and unchanged risk/cost policy to that slot's immutable unit input binding. Persist binding at `auth.output_root/'inputs'/variant_id/(cost_profile+'.json')`, outside the fresh campaign output directory, before simulation. Never authorize arbitrary simulator dates through a generic helper.

- [ ] Extract `statistics._analyze_validated_ledgers(...)` from the body after the original range guard. Public `analyze_ledgers()` keeps that guard and calls the shared implementation. Add `analyze_holdout_ledgers(root, files, *, authorization, variant_id, cost_profile)` that checks claim, unit input binding, exact root/window/identity/source/cost before invoking the same arithmetic. Do not move or weaken root/line/hash/count checks.

The existing range predicate also checks the exact seven ledger filenames and a nonempty universe. Preserve those structural checks on **both** entrypoints; extracting the date guard must not accidentally remove them from the holdout lane. Likewise, retain every `RunAssumptions` type, tuple, identity, instrument, source and funding-policy validation after adding the separately authorized date branch. Add explicit missing/extra-ledger and malformed-funding tests for the new lane. The retained PHP signal session already accepts timestamps through the fixed exclusive cutoff; no PHP date-policy or baseline change is needed.

Extract the common evidence/status/ledger reconciliation body of `_verify_campaign()` into `_verify_bound_campaign(...)`, taking fully validated immutable expected identity, source, costs, window and binding values. Existing `verify_campaign()` still invokes new `_validate_protocol()` and existing WINDOWS binding, then common verification. New `verify_holdout_campaign(root, *, authorization, variant_id, cost_profile)` validates contract and unit input binding before opening `summary.json` or ledgers, then calls common verification. Verify source counts/priority, fees/funding, PnL/R/undefined ratios, marked drawdown, completeness and final manifests. It never calls old/new selection validation on the historical protocol. Keep v1 ledger schemas; holdout manifest/status add contract/claim/authority hashes and phase, with existing schemas accepted only by the explicit holdout verifier.

- [ ] Future targeted tests above must pass; additionally run `python -m pytest tests/test_research_experiments.py tests/test_research_orchestration.py -q` to prove old defaults remain closed. Add synthetic pre-holdout fixture parity against the approved Git version, comparing nonvolatile summaries/ledgers and explicitly excluding only run IDs, timing, paths and code inventory; no PHP invocation.

- [ ] Future commit after review: `git add python-orchestrator/app/backtesting/research/signal_sources.py python-orchestrator/app/backtesting/research/signals.py python-orchestrator/app/backtesting/research/campaign.py python-orchestrator/app/backtesting/research/portfolio_simulator.py python-orchestrator/app/backtesting/research/statistics.py python-orchestrator/tests && git commit -m 'feat(research): execute scoped holdout through shared engine'` (stage only task-owned test edits).

## Task 3: Complete finite controller, evidence and synthetic end-to-end acceptance

**Files:** Create `holdout.py` and `tests/test_research_holdout_controller.py`; complete `test_research_holdout.py`; update design status/documented CLI and limits only after implementation acceptance. The parent owns the plan/spec documentation; the implementer stages only its code and tests.

Integration refinements agreed during Task 3 (acceptance still pending):

- The genuine-engine controller fixture scores three synthetic minutes but retains 250 four-hour warmup intervals (60,000 minutes) for each of ten pairs. Its runtime under branch instrumentation is therefore not a three-minute-source fixture. This remains synthetic acceptance, not a full-calendar or actual-market result.
- Include the author checkout's `orchestration.py` and `experiments.py` in the new runner inventory when importing their wall guard and CSV helper. Do not modify these helpers or the old frozen inventory. Detect changed dependency bytes after claim and during retained verification.
- Failure diagnostics must not hash gigabytes of partial artifacts inside the short cleanup allowance. Use a bounded metadata-only inventory with explicit `hash_not_computed`; bound traversal time and entry count as well as publication size, and account for hard links by inode. Mark truncation explicitly. A digest of a retained inventory fragment must never be described as a complete artifact inventory or content-integrity proof. Complete successful terminal evidence still references actual required artifact hashes.
- Read-only retained verification must enforce the actual retained-output size cap and its finite deadline, but must not require new persistent-output write capacity or subtract a terminal-write reserve. Independent statistics still require the configured free-space floor on the filesystem actually used for their temporary spool. If that is the same full filesystem, verification must fail its temporary-space check. Verification never permits generation, simulation, repairs or persistent artifact writes.

For failure between claim link and successful return from `prepare_claim`, a private receipt attached only to that call's exception may support bounded failure publication. Validate the exact freshly produced claim bytes, contract and output identity; never recover ownership from an unrelated existing claim or global last-call state. This receipt grants no evaluation capability. Failure publication is best effort, preserves the primary exception and never repairs or retries the consumed claim. Test failures both before and after linking, including an unrelated pre-existing claim.

- [ ] Write controller tests using a claimed synthetic authority and monkeypatching private frozen-verifier/worker transport/source iterators. Reuse existing `tests.test_research_campaign.Builder`, `tests.test_research_portfolio_simulator.plan`, signal result fixtures and ledger fixtures. Do not monkeypatch `PortfolioSimulator`, statistics arithmetic, claim publication or reconciliation. Test controller slots, single signal generation, every unit's genuine simulator, funding/cost cashflows and independently recomputed statistics. Concrete assertions:

```python
@pytest.mark.parametrize('selected, expected_units', [('baseline', 2), ('width_050', 4)])
def test_complete_synthetic_batch(controller_fixture, selected, expected_units):
    f = controller_fixture(selected=selected)
    report = f.module.run_holdout(f.output)
    assert f.signal_generation_calls == 1
    assert len(report['units']) == expected_units
    assert all(u['statistics_verified'] for u in report['units'])
    assert report['holdout_status'] == 'complete'
    assert report['paper_transfer'] == 'distinct_pending'
    assert report['execution_authority'] == 'none'
    assert f.simulator_calls == expected_units
    assert f.warmup_scored_count == 0
    assert f.last_scored_timestamp < f.cutoff_ms
```

Also test fatal second-unit failure retains first verified result, remaining slots become explicitly unstarted/consumed, failed signals retain partial reports, terminal-write failure leaves claim, missing funding makes final report inconclusive, and adverse costs differ only by original frozen profile. Inject deadline/store overflow at controller boundaries; test no unauthorized process cleanup and no retry under another output directory.

- [ ] Future command: `python -m pytest tests/test_research_holdout.py -q`; initially fails for missing complete controller.

- [ ] Implement `run_holdout(output_root: Path, *, timeout=OVERALL_SECONDS, max_output_bytes=OVERALL_BYTES) -> dict` with this exact sequence:

```python
authority = load_campaign_authority()
auth = prepare_claim(authority, output_root, timeout=timeout,
                     max_output_bytes=max_output_bytes)
try:
    signals = run_holdout_signals(auth.output_root / 'signals', authorization=auth)
    for variant_id, profile in auth.slots:
        bind_unit_inputs(auth, signals, variant_id, profile)
        slot_auth = auth.for_slot(variant_id, profile)
        run_holdout_campaign(auth.output_root / 'signals', authorization=slot_auth,
                             variant_id=variant_id, cost_profile=profile)
        stats = verify_holdout_campaign(unit_root(auth, variant_id, profile),
                    authorization=slot_auth, variant_id=variant_id, cost_profile=profile)
        publish_unit_result(auth, variant_id, profile, stats)
    return publish_holdout_report(auth)
except BaseException as exc:
    publish_batch_failure_best_effort(auth, exc)
    raise
```

Define `bind_unit_inputs`, `unit_root`, `publish_unit_result`, `publish_holdout_report`, `publish_batch_failure_best_effort` in `holdout.py`; they are private helpers in implementation (underscore names permitted consistently). `bind_unit_inputs` verifies new B1 source/code/baseline plus funding inventory and freezes exact hashes into the unit's no-replace input binding before running. `for_slot` verifies and restricts the original claim; it never creates another evaluation claim. Limits passed to engine/statistics are derived from remaining clock/storage budgets, never independently reset defaults. Use controller's finite wall guard so `BaseException` deadlines unwind owned workers and publish failure best effort. Do not catch and continue after malformed evidence.

Report JSON, CSV and readable Markdown to immutable output files. Include net win rate, trade count, net PnL, profit factor with explicit undefined reason, mean realized net R, marked drawdown, exposure, fees/funding and per-pair/year counts/results; preserve units separately and report comparator delta only for matching scenarios. Include old/new code inventories, changed Python modules and reason, original selected diff/freeze identity, B1 transition, source quality, costs and all hypothetical-execution/universe/metadata limitations. Final terminal references report hashes and every unit hash. `holdout_status='complete'` requires every fixed unit complete and independently verified; inconclusive units produce `inconclusive`, failures produce `failed`. Paper always stays `distinct_pending` and execution authority `none`.

Report mapping checked against commit `5968c181`: `net_win_rate` and `roi_wallet_rate` are fractions, `mean_realized_net_r` and `net_profit_factor` are dimensionless, and PnL/cost/drawdown/exposure fields are quote amounts. Preserve `profit_factor_undefined_reason` and `expectancy_undefined_reason`; no-trade rates/R/PF are null, unlike a genuine zero win rate with completed trades. `maximum_drawdown_quote` is marked-equity drawdown, distinct from `cashflow_wallet_maximum_drawdown_quote`; retain `marked_equity_verified`, `marked_equity_peak_quote`, `marked_equity_samples` and the OHLC-proxy limitation. `per_year[year].net_pnl_quote` follows trade exit year; `cashflow_net_pnl_quote` and `cashflow_vs_exit_attribution_difference_quote` preserve UTC cashflow attribution separately. Pairs share one wallet and phase/scenario wallets reset; do not add their ROI values. Carry counters including `scored`, `passed` and `failed_rules`, coverage/cost evidence flags and `observed_only` funding provenance. Existing `experiments._csv_text` provides formula-safe text/null handling, but its candidate report is not a holdout comparator report. Test two/four-unit deduplication, null versus zero, same-scenario delta joins, annual attribution, and missing/failed/inconclusive units across JSON/CSV/French Markdown. Never replace absent statistics with successful zero-PnL rows.

Add `verify_retained_holdout() -> dict` and CLI `--verify-retained` (mutually exclusive with output-root evaluation). It loads the fixed authority and original consumed claim, reverifies original freeze/code and output identity, then mints `mode='verify'` capabilities for existing units only. `require_claim` denies source generation and simulation operations for this mode; statistics may replay retained ledgers without rewriting artifacts. This path neither creates a new claim nor repairs a failed batch. Test that replay cannot call generation/simulation even after crash or under another output root.

- [ ] Future focused suite from `python-orchestrator`:

```bash
python -m pytest tests/test_research_holdout_authority.py tests/test_research_holdout.py tests/test_research_holdout_controller.py tests/test_research_signals.py tests/test_research_campaign.py tests/test_research_portfolio_simulator.py tests/test_research_statistics.py tests/test_research_experiments.py tests/test_research_orchestration.py tests/test_research_funding.py tests/test_research_campaign_evidence.py -q
python -m compileall -q app/backtesting/research
git diff --check
```

Expected: all selected synthetic tests pass, compile exits zero, diff check exits zero. Use the available author venv, not the frozen operational environments. Run the repository's required CI checks discovered from its actual workflows before PR integration. Review module import inventories and guard call ordering; retain one independent consolidated review/correction cycle before merge per approved campaign workflow. No real held-out launch is a test.

For focused local runs in the lean author environment, add `--noconftest -p no:cacheprovider`; the full CI retains its normal repository fixtures, PostgreSQL smoke and coverage configuration. The required Python workflow uses branch coverage with a 95% overall gate; do not add coverage exclusions to make this change pass. Before implementation, the eight existing research test modules listed above collected 722 tests and passed at `884f964e` (without the two not-yet-created holdout test modules).

- [ ] Future task-owned commit: stage `holdout.py`, `test_research_holdout_controller.py`, `test_research_holdout.py` and only the agreed integration changes in `holdout_authority.py`/`signals.py`; commit as `feat(research): retain complete guarded holdout batch evidence`. The parent commits the plan/spec separately after checking their status against delivered evidence.

## Acceptance and subsequent authorized campaign action

Accept implementation only when all three tasks and relevant checks pass, the frozen original files/reports are unchanged, and synthetic tests exercise genuine simulator/statistics through the controller. An authority shim alone is not C2b completion. Retain the original worktrees/environment for authentic verification; losing them is a reported blocker, not permission to accept new code as the old verifier.

After review/merge, independently inspect original supervisor terminal evidence. If there is a valid selected freeze, provision the fixed authority once and launch the bounded C2b campaign using the approved exact cutoff; the existing user authorization does not require routine reapproval. If no winner or failed/incomplete original campaign, report that scientific outcome and keep holdout closed. Never retune to obtain eligibility. After actual C2b completion, proceed to the distinct supported Paper transfer phase; this plan does not implement or certify that phase.

Plan decisions are settled: fixed installed authority, retained-code isolated verifier, registry-anchored consumed claim, shared engine/arithmetic, two/four slot deduplication, conservative failure retention and finite limits. The capacity limits can yield explicit incomplete evidence; they cannot silently authorize increased caps or coverage substitutions.
