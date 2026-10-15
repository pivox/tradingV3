"""Frozen constants of PREREGISTRATION-H2-GATE1.md (H2 Gate 1, residual-shock reversal effect study).

Every number of the protocol lives here, with its section. Tests monkeypatch the locations; nothing else may change
them. The code identity (every module of this package and the reused `research_swing2` modules) is pinned on the
FROZEN line of the protocol: a change after the freeze makes every claim fail.
"""

from __future__ import annotations

from pathlib import Path

from ..research_swing2.okx import STABLE

STUDY = "h2-gate1"
PREREG_FILE = "PREREGISTRATION-H2-GATE1.md"
FREEZE_MARKER = "## 15. Freeze"

# ----------------------------------------------------------------------------- locations (§2, §12)
REPO_ROOT = Path(__file__).resolve().parents[3]
PREREG_PATH = REPO_ROOT / "docs" / "handbook" / "research" / PREREG_FILE
SHA256_PATH = PREREG_PATH.with_suffix(".sha256")
PRIVATE_RESEARCH = Path("/home/haythem/workspace/perso/tradingV3-private/research")
OUT_DIR = PRIVATE_RESEARCH / "20261015T1200Z-h2-gate1" / "run"
# The 20 programme bases: raw OKX archive zips (static.okx.com), unconverted, `zips/<instId>/<instId>-candlesticks-<period>.zip`.
H2_TAPE = PRIVATE_RESEARCH / "20261015T1200Z-tape-okx-h2"
H2_MANIFEST = "manifest-h2-okx-candles.json"
# Every other OKX USDT swap family: the swing v2 monthly archive (converted by research_swing2.okx_universe),
# `klines/<instId>/<instId>-1m-<period>.npz` and its daily quote volume (UTC days).
SWING_TAPE = PRIVATE_RESEARCH / "20261014T113000Z-tape-okx-swing-v2-monthly"
SWING_MANIFEST = "manifest-candles.json"
SWING_VOLUME = "daily-quote-volume.json"
# Secondary universe source (metadata only): the OKX Europe FUTURES instruments list of 2026-10-15T05:35Z.
XPERP_SOURCE = PRIVATE_RESEARCH / "20261015T0100Z-h1-xperp-audit" / "raw" / "eea-futures.json"

# sha256 of the inputs, recorded before any read (file hashes only; no price was read to obtain them).
INPUT_SHA256 = {
    H2_MANIFEST: "95794fdd3395d31b7adc138e318a0df5669738505e1fae524646e0df486f0d00",
    SWING_MANIFEST: "516e71839b9b8ede8a57c38fc0d0dfe7f7a0eca7549e55dc6ee8410cfe9c5d01",
    SWING_VOLUME: "7c262b4424ebab5821d85640b420636a29437649fbae33bc42379ca6a41beedd",
    "eea-futures.json": "732044a8a7ce9dd31f1025f1a501c72ee0b268ef87c30d229e444beac9c44f8d",
}
# sha256 of the canonical JSON {"<instId>/<file>.npz": sha256} of every swing v2 npz file with period <= LAST_PERIOD
# (9,665 files; the 1,602 files dated 2026-10-07..12 are never opened).
SWING_NPZ_DIGEST = "b99f42f49e5fa421fddd6fdda0ec9186d1c3eff0cef5330c5cf33a9cabdacc22"
SWING_NPZ_FILES = 9665
CALIPER_RULE_SHA256 = "3753964e537cf5ebda76f425e4b3e5b0651807674b33b4f6e9d1a1aaec5ce372"

# ----------------------------------------------------------------------------- time (§2)
HOUR_MS = 3_600_000
MINUTE_MS = 60_000
DAY_MS = 86_400_000
GRID_START_MS = 1_661_990_400_000  # 2022-09-01T00:00Z: hourly bar 0 covers [00:00, 01:00)
DECISION_FROM_MS = 1_672_531_200_000  # 2023-01-01T00:00Z (first decision hour close)
DECISION_TO_MS = 1_790_812_800_000  # 2026-10-01T00:00Z (excluded)
LAST_PERIOD = "2026-10-06"  # no file with a later period is opened
DATA_END_MS = 1_791_302_400_000  # 2026-10-06T16:00Z: candles opening at or after it are dropped
UNIVERSE_MONTHS = ("2023-01", "2026-09")

# ----------------------------------------------------------------------------- universe (§3)
UNIVERSE_SIZE = 30
WARMUP_DAYS = 60
MIN_DAYS_IN_PREVIOUS_MONTH = 15
VOLUME_DAY_MIN_MINUTES = 720
EXCLUDED_BASES = frozenset(STABLE | {"XAU", "XAG", "USDG", "EUR", "GBP"})
# Bases of the 160 live crypto X-Perps (instCategory 1, ruleType xperp) of XPERP_SOURCE: secondary universe, reported.
XPERP_BASES = frozenset((
    "0G AAVE ACT ACU ADA AEON AERO AGLD AI ALGO ALLO APE APR APT AR ARB ARX ASTER ATOM AVAX AVNT AXS BASED BCH BEAT "
    "BICO BILL BIO BLUR BNB BONK BSB BTC CAP CASHCAT CFX CHIP CNPY CORE CP CRV CT DASH DATA DGAI DOGE DOS DOT DYDX EDGE "
    "EIGEN ENA ENSO ESP ETC ETH ETHFI FET FIL FLOCK GALA GRAM GRASS GRVT H HBAR HOME HYPE ICP INJ IOST IRYS JTO JUP "
    "KAITO KII KITE KMNO KSM LDO LINK LIT LTC MET MINA MON MOODENG MORPHO NEAR NES NIGHT NOT O OKB OL ONDO ONE ONT OP "
    "OPN ORDI PARTI PENDLE PENGU PEOPLE PEPE PIEVERSE POL PONS PROS PUMP PYTH QUANT RAVE RAY RE RENDER RESOLV RLS ROBO "
    "SAHARA SAND SEI SHIB SLX SOL SOON SOPH SPK STRK STX SUI SUSHI TAO TIA TRB TRIA TRUMP TRX UB UNI UP USELESS VIRTUAL "
    "VVV W WIF WLD WLFI XDP XLM XPL XRP YB ZAMA ZEC ZEN ZIL ZKP ZRO").split())

# ----------------------------------------------------------------------------- event (§4)
BAR_MIN_MINUTES = 54
MIN_MARKET_MEMBERS = 20
BETA_WINDOW_H = 1440  # 60 days of hourly returns
BETA_LAG_H = 24  # the β window ends at t - 24 h (the shock window is excluded)
BETA_MIN_SHARE = 0.80
SHOCK_H = 24
K = 3.0
K_SENSITIVITY = 2.5
ZM_MAX = 1.0
REFRACTORY_H = 120
BTC = "BTC-USDT-SWAP"  # market sensitivity, reported only

# ----------------------------------------------------------------------------- outcome (§5)
LATENCY_MIN = 5
ENTRY_MAX_DELAY_MIN = 15  # the entry close is the first 1-min close in [t + 5 min, t + 20 min]
EXIT_MAX_DELAY_MIN = 60  # the exit close is the first 1-min close in [T, T + 60 min]
HORIZONS_H = (24, 72, 120)
PRIMARY_HORIZON_H = 72

# ----------------------------------------------------------------------------- controls (§6)
C2_Z_MAX = 1.0
C2_ZM_MIN = 2.0
# Owner decision of 2026-10-15: candidates are first crossings (first hour of each episode of a coin meeting |z| < 1
# and |z_m| >= 2), with the events' per-coin refractory period; every other C2 rule unchanged.
C2_CANDIDATES = "first_crossing"
C2_REFRACTORY_H = REFRACTORY_H
# Amendment A1 (owner decision of 2026-10-15, before any read): the C2 matching pool of an event whose decision hour
# falls in calendar month M holds the candidates of the calendar months M-1, M and M+1, clipped to the decision period
# (first month: M and M+1; last month: M-1 and M). Name -> half-width of the window in calendar months.
C2_POOLS = {"month": 0, "three_months": 1}
C2_POOL: str | None = "three_months"
C2_POOL_RULE_SHA256 = "192beca2577808eb1bcb98d1c4b9363c83ea35f2a46671700df11e7668bdc893"  # sim/c2-pool-3m/ACCEPTANCE-RULE.md
C2_CALIPERS = {"none": None, "50": 0.50, "35": 0.35, "25": 0.25}
C2_CALIPER_NAME: str | None = "none"  # set by the synthetic rule CALIPER-RULE.md (sha256 CALIPER_RULE_SHA256)
C2_MIN_MATCH = 0.80
C1_PER_EVENT = 20
C1_EXCLUSION_H = 120
SEED = 20261015

# ----------------------------------------------------------------------------- statistics and gates (§7, §8)
BOOTSTRAP_DRAWS = 10_000
CI_LEVEL = 0.95
G1_MIN_EVENTS = 200
G1_MIN_BASES = 15
G1_MIN_MONTHS = 24
G3_FLOOR = 0.0040  # 40 bp, log-return units
G4_RULES = ("point_estimate", "ci_lower_bound")
G4_RULE: str | None = "point_estimate"  # owner amendment 2026-10-15: S2 > 0 decisive, its CI published only
G6_MIN_PERIODS = 5
G6_MIN_EVENTS = 10
G7_TRIM = 0.05

# ----------------------------------------------------------------------------- run guards (§12)
MAX_GUARD_RESUMPTIONS = {"events": 1, "outcomes": 0}
MIN_AVAILABLE_MB = 4000
MIN_FREE_DISK_GB = 20


def caliper() -> float | None:
    if C2_CALIPER_NAME not in C2_CALIPERS:
        raise ValueError("the C2 caliper is not set")
    return C2_CALIPERS[C2_CALIPER_NAME]


def c2_pool_half_width() -> int:
    if C2_POOL not in C2_POOLS:
        raise ValueError("the C2 matching pool is not set")
    return C2_POOLS[C2_POOL]


def unresolved_decisions() -> list[str]:
    out = []
    if G4_RULE not in G4_RULES:
        out.append("G4 rule (point estimate or CI lower bound)")
    if C2_CALIPER_NAME not in C2_CALIPERS:
        out.append("C2 size caliper")
    if C2_CANDIDATES != "first_crossing":
        out.append("C2 candidates (first crossings, owner decision of 2026-10-15)")
    if C2_POOL != "three_months":
        out.append("C2 matching pool (calendar months M-1, M, M+1 clipped to the period, owner Amendment A1 of 2026-10-15)")
    return out
