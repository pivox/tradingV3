"""Frozen annexes of regime-r2: universe exclusions (annex A) and symbol identity periods (annex B).

The code reads the lists from the frozen markdown files; it cannot add or remove a symbol.
"""

from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from datetime import date
from pathlib import Path

PREREG_DIR = Path(__file__).resolve().parent / "prereg"
SYMBOL = re.compile(r"\b[0-9A-Z]+USDT\b")


@dataclass(frozen=True)
class Exclusion:
    """A symbol excluded on [start, end] (inclusive dates; None = open bound)."""

    symbol: str
    start: date | None
    end: date | None


def verify_frozen(prereg_dir: Path = PREREG_DIR) -> dict[str, str]:
    """Check every file listed in PREREGISTRATION-regime-r2.sha256; raise on any mismatch."""
    checked = {}
    for line in (prereg_dir / "PREREGISTRATION-regime-r2.sha256").read_text().splitlines():
        digest, name = line.split()
        actual = hashlib.sha256((prereg_dir / name).read_bytes()).hexdigest()
        if actual != digest:
            raise RuntimeError(f"frozen file changed: {name}")
        checked[name] = digest
    return checked


def annex_a_symbols(prereg_dir: Path = PREREG_DIR) -> set[str]:
    """Every symbol of annex A, plus the two non-ASCII symbols of A5."""
    text = (prereg_dir / "ANNEX-A-exclusions.md").read_text()
    body = text[text.index("## A1."):]
    keep = {"BBUSDT", "STXUSDT", "QNTUSDT", "JUPUSDT", "SYRUPUSDT"}  # named in A3/A4 as staying
    symbols = set(SYMBOL.findall(body)) - keep
    symbols |= {"币安人生USDT", "牛来USDT"}
    return symbols


def annex_b_exclusions(prereg_dir: Path = PREREG_DIR) -> list[Exclusion]:
    """Rows of annex B whose status is excluded, with their period."""
    out = []
    for line in (prereg_dir / "ANNEX-B-symbol-identity.md").read_text().splitlines():
        if not line.startswith("| "):
            continue
        cells = [c.strip() for c in line.strip("|").split("|")]
        if len(cells) < 4 or cells[3] != "**exclu**":  # "exclu (A1)" rows are already in annex A
            continue
        symbols, period = cells[0], cells[1]
        start = end = None
        dates = re.findall(r"\d{4}-\d{2}-\d{2}", period)
        if "début →" in period and dates:
            end = date.fromisoformat(dates[0])
        elif "→ fin" in period and dates:
            start = date.fromisoformat(dates[0])
        for symbol in SYMBOL.findall(symbols):
            out.append(Exclusion(symbol, start, end))
    return out


def annex_b_inclusion_ends(prereg_dir: Path = PREREG_DIR) -> dict[str, date]:
    """Included rows bounded by 'début → D': the symbol is kept only up to D."""
    out = {}
    for line in (prereg_dir / "ANNEX-B-symbol-identity.md").read_text().splitlines():
        if not line.startswith("| "):
            continue
        cells = [c.strip() for c in line.strip("|").split("|")]
        if len(cells) < 4 or not cells[3].startswith("inclus") or "début →" not in cells[1]:
            continue
        dates = re.findall(r"\d{4}-\d{2}-\d{2}", cells[1])
        for symbol in SYMBOL.findall(cells[0]):
            out[symbol] = date.fromisoformat(dates[0])
    return out


def excluded_on(symbol: str, day: date, annex_a: set[str], annex_b: list[Exclusion]) -> bool:
    """True when the symbol cannot enter any calculation on that day."""
    if symbol in annex_a:
        return True
    for row in annex_b:
        if row.symbol != symbol:
            continue
        if (row.start is None or day >= row.start) and (row.end is None or day <= row.end):
            return True
    return False
