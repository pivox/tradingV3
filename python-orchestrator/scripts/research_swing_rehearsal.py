#!/usr/bin/env python3
"""Rehearsal constants and the frozen-verifier pin of the swing campaign (swing campaign PR 6).

Two modes, both applied to a disposable checkout, never to a branch that is merged:

- ``constants``: the rehearsal. It shortens the training and validation phases to three-month
  slices, moves the "holdout" to a 2025 slice that is not the 2026 holdout (with the matching
  protocol cutoff), and keeps only the evidence gates of the screen, because a three-month
  slice cannot meet the trade-count gates.
- ``pin``: what the holdout pin (PR 7) does after the frozen commit. It writes
  ``frozen_verifier_inventory.swing.json`` (the frozen commit and the sha256 of every
  ``FROZEN_FILES`` entry at that commit, in canonical JSON), sets ``SWING_FROZEN_COMMIT`` and
  adds the inventory to both runner inventories.

usage: research_swing_rehearsal.py constants <python-orchestrator dir>
       research_swing_rehearsal.py pin <python-orchestrator dir> <frozen commit> <frozen python-orchestrator dir>
"""
from __future__ import annotations

import hashlib
import sys
from pathlib import Path

TRAINING = ('2024-05-01T00:00:00Z', '2024-07-01T00:00:00Z', '2024-10-01T00:00:00Z')
VALIDATION = ('2025-05-01T00:00:00Z', '2025-07-01T00:00:00Z', '2025-10-01T00:00:00Z')
HOLDOUT = ('2025-09-01T00:00:00Z', '2025-11-01T00:00:00Z', '2025-12-01T00:00:00Z')


def replace(path: Path, old: str, new: str) -> None:
    text = path.read_text()
    if text.count(old) != 1:
        raise SystemExit(f'{path}: expected one occurrence of {old[:60]!r}')
    path.write_text(text.replace(old, new))


def constants(root: Path) -> None:
    research = root/'app/backtesting/research'
    replace(research/'experiments.py',
            "WINDOWS = {'training': {'start':'2023-01-01T00:00:00Z','end':'2025-01-01T00:00:00Z'},\n"
            "           'validation': {'start':'2025-01-01T00:00:00Z','end':'2026-01-01T00:00:00Z'}}",
            f"WINDOWS = {{'training': {{'start':'{TRAINING[1]}','end':'{TRAINING[2]}'}},\n"
            f"           'validation': {{'start':'{VALIDATION[1]}','end':'{VALIDATION[2]}'}}}}  # REHEARSAL")
    replace(research/'experiments.py', "'cutoff':'2026-10-09T06:00:00Z',", f"'cutoff':'{HOLDOUT[2]}',")
    replace(research/'experiments.py',
            "    if metrics.get('closed_trades',0) < (100 if phase == 'training' else 50):",
            "    return reasons  # REHEARSAL: evidence gates only\n"
            "    if metrics.get('closed_trades',0) < (100 if phase == 'training' else 50):")
    replace(research/'orchestration.py',
            "WINDOWS = {'training': ('2022-11-01T00:00:00Z', '2023-01-01T00:00:00Z', '2025-01-01T00:00:00Z'),\n"
            "           'validation': ('2024-11-01T00:00:00Z', '2025-01-01T00:00:00Z', '2026-01-01T00:00:00Z')}",
            f"WINDOWS = {{'training': {TRAINING!r},\n           'validation': {VALIDATION!r}}}  # REHEARSAL")
    authority = research/'holdout_authority.py'
    replace(authority, "SOURCE_START = '2025-11-01T00:00:00Z'", f"SOURCE_START = '{HOLDOUT[0]}'  # REHEARSAL")
    replace(authority, "SCORE_START = '2026-01-01T00:00:00Z'", f"SCORE_START = '{HOLDOUT[1]}'  # REHEARSAL")
    replace(authority, "END = '2026-10-09T06:00:00Z'", f"END = '{HOLDOUT[2]}'  # REHEARSAL")
    print('rehearsal constants', root)


def pin(root: Path, commit: str, frozen: Path) -> None:
    research = root/'app/backtesting/research'
    sys.path.insert(0, str(root))
    from app.backtesting.research import holdout_authority as h
    files = {name: hashlib.sha256((frozen/name).read_bytes()).hexdigest() for name in h.FROZEN_FILES}
    # The authority reads it as canonical JSON: sorted, compact, one trailing newline.
    (research/h.SWING_INVENTORY).write_bytes(h.canonical_bytes({'commit': commit, 'files': files}))
    replace(research/'holdout_authority.py', 'SWING_FROZEN_COMMIT = None', f"SWING_FROZEN_COMMIT = '{commit}'")
    replace(research/'holdout_authority.py',
            "names = ('holdout.py','holdout_authority.py','frozen_verifier_inventory.json',",
            "names = ('holdout.py','holdout_authority.py','frozen_verifier_inventory.json',"
            "'frozen_verifier_inventory.swing.json',")
    replace(research/'campaign.py',
            "('statistics.py','holdout.py','holdout_authority.py','frozen_verifier_inventory.json',",
            "('statistics.py','holdout.py','holdout_authority.py','frozen_verifier_inventory.json',"
            "'frozen_verifier_inventory.swing.json',")
    print('pinned', root, 'to', commit)


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[0] == 'constants':
        constants(Path(argv[1]).resolve())
    elif len(argv) == 4 and argv[0] == 'pin':
        pin(Path(argv[1]).resolve(), argv[2], Path(argv[3]).resolve())
    else:
        raise SystemExit(__doc__)
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
