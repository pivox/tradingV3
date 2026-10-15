"""Frozen constants of PREREGISTRATION-leverage-gt2.md: population P2-B4, configurations,
universe, windows, decision thresholds and the declared secondary list.

The configuration list is derived ONLY from the texts of the r10 / r10x / r11 / r12
pre-registrations (B4 exit, entry E1, no cost gate: K0, or no cost-gate token for R1). Each
entry cites its source section. `screen.build_grid` of each protocol must contain it (tested).
"""

from __future__ import annotations

from pathlib import Path

from ..research_exec.screen import R8_SYMBOLS
from .leverage import ARM_BANDS, DEFINITIONS, PRIMARY_BAND, REF_BAND, is_excluded_symbol

STUDY = "leverage-gt2"
PREREG_FILE = "PREREGISTRATION-leverage-gt2.md"
FREEZE_MARKER = "## 13. Freeze"

# Frozen locations (§12): the CLI refuses any other protocol file, hash file, output directory or
# input. Tests monkeypatch these constants; nothing else may change them.
REPO_ROOT = Path(__file__).resolve().parents[3]
PREREG_PATH = REPO_ROOT / "docs" / "handbook" / "research" / PREREG_FILE
SHA256_PATH = PREREG_PATH.with_suffix(".sha256")
PRIVATE_RESEARCH = Path("/home/haythem/workspace/perso/tradingV3-private/research")
OUT_DIR = PRIVATE_RESEARCH / "20261015T090000Z-leverage-gt2"
TAPE_DIR = PRIVATE_RESEARCH / "20261013T121000Z-tape-binance-r10"
INSTRUMENTS_PATH = PRIVATE_RESEARCH / "20261013T121000Z-scalping-120-r10" / "instrument-assumptions-r10.json"
# sha256 of the inputs, recorded before any read (file hashes only; no tape content is read).
INPUT_SHA256 = {
    "instruments": "cd940cffdd1212ff76c399917536e871ed0bfeed8733fa2bb74a7e5dc0b6fb92",
    "manifest-aggtrades.json": "e0f1a68f40d701ec1a035c0a31e8c0f5cd93e9597a78fccebe432bb0515a4986",
    "manifest-klines.json": "a2876b084566e4fb5e34b5c4cdfaae9a9a4aab1111b3e720f853014da569a205",
}

# Universe: the twenty r8/r10 pairs minus BTC, by name, before any computation.
P2_SYMBOLS = tuple(symbol for symbol in R8_SYMBOLS if not is_excluded_symbol(symbol))
MAJORS = ("ETHUSDT", "SOLUSDT", "XRPUSDT", "DOGEUSDT", "BNBUSDT", "ADAUSDT", "LTCUSDT", "LINKUSDT", "AVAXUSDT")
MIDCAPS = ("ARBUSDT", "OPUSDT", "APTUSDT", "SUIUSDT", "NEARUSDT", "TIAUSDT", "SEIUSDT", "INJUSDT", "ATOMUSDT", "FILUSDT")
# The r10 training windows (PREREGISTRATION-r10.md §1), the only windows read here.
WINDOWS = ("w2404", "w2405", "w2406", "w2408")
VOLUME_THRESHOLDS = (1.1, 1.2, 1.3)  # r10x analysis 1 stacks (EXPLORATORY-r10x.md §2)
# The 76 pair-week series (19 pairs x 4 windows, `load_binance_series` order); a run with any
# other series list, or with a missing day, is refused before its claim is consumed.
EXPECTED_SERIES = tuple(f"binance:{symbol}:{window}" for symbol in P2_SYMBOLS for window in WINDOWS)

# (configuration id, source, citation). Exit B4 = stop 1.5 x ATR5 (floor 2 ticks), time exit 2 h,
# taker no later than 23:59:00 UTC, no target; entry E1 with the contract's 45 s TTL.
CONFIGURATIONS: tuple[tuple[str, str, str], ...] = (
    # r10 §3: rules P{n}-A{3,4}-K{0,2,4,6}, sides implied (P1, P2 long; P3 short), exits B1-B4 → K0 x B4.
    ("P1-A3-K0|long|E1|B4", "r10", "PREREGISTRATION-r10.md §3 (rules P{n}-A{layers}-K{k}, k = 0 the 1.1.0 behaviour; B4 time-only exit)"),
    ("P1-A4-K0|long|E1|B4", "r10", "PREREGISTRATION-r10.md §3"),
    ("P2-A3-K0|long|E1|B4", "r10", "PREREGISTRATION-r10.md §3"),
    ("P2-A4-K0|long|E1|B4", "r10", "PREREGISTRATION-r10.md §3"),
    ("P3-A3-K0|short|E1|B4", "r10", "PREREGISTRATION-r10.md §3"),
    ("P3-A4-K0|short|E1|B4", "r10", "PREREGISTRATION-r10.md §3"),
    # r10x §2 analysis 1: P{n}-A4-K{0,2,4}-V{1.1,1.2,1.3} x B1-B4 → K0 x B4.
    *((f"P{n}-A4-K0-V{t}|{'short' if n == 3 else 'long'}|E1|B4", "r10x", "EXPLORATORY-r10x.md §2 analysis 1 (P{n}-A4-K{k}-V{t}, k = 0)")
      for n in (1, 2, 3) for t in ("1.1", "1.2", "1.3")),
    # r10x §2 analysis 2: P{n}-A{3,4}-K0-Z{1,0} x B1-B4 → B4.
    *((f"P{n}-A{a}-K0-Z{z}|{'short' if n == 3 else 'long'}|E1|B4", "r10x", "EXPLORATORY-r10x.md §2 analysis 2 (P{n}-A{3,4}-K0-Z{1,0})")
      for n in (1, 2, 3) for a in (3, 4) for z in (1, 0)),
    # r11 §2: P{1,2,3}-A3-K0-Z1 (already listed from r10x) and the pool P0-A3-K0-Z1 ("both"), exits B4/B2/B3 → B4.
    ("P0-A3-K0-Z1|both|E1|B4", "r11", "PREREGISTRATION-r11.md §2 (pool P0, long stream P1 ∪ P2, short stream P3; exit B4)"),
    # r12 §2: R1 x {long, short, both}, exit B4 only; no cost gate (K0-equivalent).
    ("R1|long|E1|B4", "r12", "PREREGISTRATION-r12.md §2 (zone alone, no cost gate, exit B4 only)"),
    ("R1|short|E1|B4", "r12", "PREREGISTRATION-r12.md §2"),
    ("R1|both|E1|B4", "r12", "PREREGISTRATION-r12.md §2"),
)
CONFIGURATION_IDS = tuple(item[0] for item in CONFIGURATIONS)
# r11 also lists P{1,2,3}-A3-K0-Z1 x B4 (PREREGISTRATION-r11.md §2): identical identifiers to the r10x rows, kept once.
ALSO_IN = {f"P{n}-A3-K0-Z1|{'short' if n == 3 else 'long'}|E1|B4": ("r11",) for n in (1, 2, 3)}

# ----------------------------------------------------------------------------- owner decisions pending
# Each of these is a frozen rule that the owner must set before the freeze (§12, decisions D1-D4).
# While any is None, `unresolved_decisions()` is non-empty and every claim is refused.
MULTIPLICITY_RULES = ("distinct_pair_day", "copies_config_pair_day")
MULTIPLICITY_RULE: str | None = None  # D1; recommended "distinct_pair_day"
R1_BOTH_RULES = ("drop", "keep_conflict_rule")
R1_BOTH_RULE: str | None = None  # D2; recommended "drop"
MAX_GUARD_RESUMPTIONS: int | None = None  # D3; 0 (no resumption) or 1
FUNDING_RULES = ("not_charged",)
FUNDING_RULE: str | None = None  # D4; "not_charged" (diagnostic only) or an amendment adding a provision


def unresolved_decisions() -> list[str]:
    out = []
    if MULTIPLICITY_RULE not in MULTIPLICITY_RULES:
        out.append("D1 multiplicity of nested configurations (N_min, E0a trigger, decision clusters)")
    if R1_BOTH_RULE not in R1_BOTH_RULES:
        out.append("D2 R1|both under one position per pair")
    if MAX_GUARD_RESUMPTIONS not in (0, 1):
        out.append("D3 resumption after a memory-guard abort")
    if FUNDING_RULE not in FUNDING_RULES:
        out.append("D4 funding")
    return out


def active_configurations() -> tuple[str, ...]:
    """The configurations E0a and E1 run: the 31 source rows, minus `R1|both` when D2 = drop."""
    if R1_BOTH_RULE not in R1_BOTH_RULES:
        raise ValueError("D2 (R1|both) is not decided")
    if R1_BOTH_RULE == "drop":
        return tuple(identifier for identifier in CONFIGURATION_IDS if identifier != "R1|both|E1|B4")
    return CONFIGURATION_IDS


def multiplicity_rule() -> str:
    if MULTIPLICITY_RULE not in MULTIPLICITY_RULES:
        raise ValueError("D1 (multiplicity of nested configurations) is not decided")
    return MULTIPLICITY_RULE


# Inference (owner decisions 2026-10-15).
ALPHA = 0.05
N_MIN = 400  # filled trades per arm
F_MIN = 0.3  # E0a census x f_min ≥ N_min triggers E1 for a band
PRIMARY = ("eng", PRIMARY_BAND, "fill")
BOOTSTRAP_RESAMPLES = 10_000
BOOTSTRAP_SEED = 20261015
SLIPPAGE_GRID_TICKS = (0, 1, 2, 5)  # extra adverse ticks on the taker exit (sensitivity, in R)
ROBUST_MIN_WEEKS = 3  # descriptive: same sign on ≥ 3 of the 4 weeks
ROBUST_MAX_PAIR_SHARE = 0.30  # descriptive: no pair above 30 % of a band's trades
STRATA_AXES = ("atr5_bp", "tick_bp", "stale_900")  # C2, terciles frozen by E0a (outcome-free)

# The secondary list for Holm, declared before any calculation. A test that cannot run
# (an arm under N_min, or not triggered by E0a) keeps p = 1 in the family.
SECONDARY_TESTS: tuple[tuple[str, str], ...] = (
    ("S1", "Δ ]3,5] vs ]1,2] under L_eng, per fill"),
    ("S2", "Δ ]5,10] vs ]1,2] under L_eng, per fill"),
    ("S3", "Δ ]2,3] vs ]1,2] under L_eng, intention to trade (admitted unfilled order = 0 R)"),
    ("S4", "Δ ]2,3] vs ]1,2] under L_stop, per fill"),
    ("S5", "Δ ]3,5] vs ]1,2] under L_stop, per fill"),
    ("S6", "Δ ]5,10] vs ]1,2] under L_stop, per fill"),
    ("S7", "C1: mean (R − R_C1) in ]2,3] under L_eng (same signals, stop widened to L_eng = 1.5, no pair blocking)"),
    ("S8", "C2: Δ ]2,3] vs ]1,2] under L_eng within volatility x tick x liquidity strata (common support, band-weighted)"),
    ("S9", "Δ ]2,3] vs ]1,2] under L_eng without the two-tick floor strata"),
)

# Listed as future work in the protocol, NOT authorised now, never run by this package.
NOT_AUTHORISED = ("B1", "B2", "B3", "K2 under L_stop", "sigma exits on q3x", "500-USDT portfolio replay", "tight stop at equal volatility")


def arms() -> list[tuple[str, str]]:
    """(definition, band) of every E1 arm that may run (subject to the E0a trigger)."""
    return [(definition, band) for definition in DEFINITIONS for band in ARM_BANDS]


def arm_key(definition: str, band: str) -> str:
    return f"{definition}:{band}"


__all__ = ["ALPHA", "ARM_BANDS", "CONFIGURATIONS", "CONFIGURATION_IDS", "EXPECTED_SERIES", "F_MIN", "N_MIN", "P2_SYMBOLS", "PRIMARY_BAND", "REF_BAND", "SECONDARY_TESTS", "WINDOWS",
           "active_configurations", "arm_key", "arms", "multiplicity_rule", "unresolved_decisions"]
