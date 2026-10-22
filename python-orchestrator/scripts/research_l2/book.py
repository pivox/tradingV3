"""Frame headers, sequence tracking, the `books` order book and its checksum.

Sources (official OKX documentation; local copies of 2026-10-20 under
`tradingV3-private/research/20261020T1430Z-lp-programme/a4-data-desk/txt/`, EEA `my-docs-v5-en.txt`, global `docs-v5-en.txt`,
changelog `docs-v5-log_en.txt`):

- "WS / Order book channel" (EEA l.15095-15377): `books` = "400 depth levels will be pushed in the initial full snapshot.
  Incremental data will be pushed every 100 ms"; push field `action` = `snapshot` | `update`; level =
  `[price, size, "0" (deprecated), order count]`; `prevSeqId` "Only applicable to books, books-l2-tbt, books50-l2-tbt".
- "Sequence ID" (EEA l.15351-15371): "The prevSeqId in the new message matches with seqId of the previous message";
  snapshot `prevSeqId` = -1; no change for ~60 s ⇒ an incremental message with empty `asks`/`bids`, "seqId is the same as
  the last sent message and prevSeqId equals to seqId"; maintenance reset ⇒ "an incremental message with seqId smaller than
  prevSeqId", later messages follow the normal rule.
- Checksum: deprecated by the changelog of 2026-06-23 (l.2212-2228): "its value is fixed to 0 and must no longer be used
  for integrity verification. Please use seqId/prevSeqId"; `books5` carries no checksum. The algorithm is still written, but
  only in the Spread Trading order book section (`sprd-books-l2-tbt`, global l.38728-38742): merge rule "If there is the same price, compare the size. If the size is 0, delete this depth data.
  If the size changes, replace the original data. If there is no same price, sort by price (bid in descending order, ask in
  ascending order)"; "Use the first 25 bids and asks in the full load to form a string (where a colon connects the price and
  size in an ask or a bid), and then calculate the CRC32 value (32-bit signed integer)". The alternating order
  bid, ask, bid, ask… (and the remaining side appended when one side is shorter) comes from the earlier OKX text and is not
  re-readable in the current copy: it is used only when a non-zero checksum is delivered.

Nothing in this module prints or summarises a price. `frame_header` returns identifiers, sequence numbers, timestamps and
counts only; that is all the quality summary reads.
"""

from __future__ import annotations

import bisect
import json
import zlib
from collections import deque
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation

BOOK_CHANNELS = frozenset({"books"})  # incremental channels with seqId / prevSeqId
SNAPSHOT_CHANNELS = frozenset({"books5"})  # every push is a full 5-level snapshot
TRADE_CHANNELS = frozenset({"trades"})
CHECKSUM_LEVELS = 25

# Sequence verdicts (`SeqTracker.classify`).
SNAPSHOT = "snapshot"
CONTINUOUS = "continuous"
HEARTBEAT = "heartbeat"
RESET = "seq_reset"
DUPLICATE = "duplicate"
OUT_OF_ORDER = "out_of_order"
GAP = "gap"
WHILE_INVALID = "update_while_invalid"
MALFORMED = "malformed"
APPLIED = frozenset({SNAPSHOT, CONTINUOUS, HEARTBEAT, RESET})
BREAKS = frozenset({GAP, OUT_OF_ORDER, MALFORMED})


def _int_or_none(value):
    if value is None or value == "":
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def frame_header(raw) -> dict:
    """The header kept next to each raw frame: kind, channel, instrument, action, seqId, prevSeqId, exchange ts (ms),
    checksum value, number of rows and of levels per side. Never a price, a size or a rate."""
    if raw == "pong" or raw == b"pong":
        return {"t": "pong"}
    try:
        message = json.loads(raw)
    except (TypeError, ValueError):
        return {"t": "unknown"}
    if not isinstance(message, dict):
        return {"t": "unknown"}
    arg = message.get("arg") if isinstance(message.get("arg"), dict) else {}
    if "event" in message:
        header = {"t": "event", "event": str(message.get("event")), "ch": str(arg.get("channel", "")), "inst": str(arg.get("instId", ""))}
        for key in ("code", "msg", "connId"):
            if key in message:
                header[key] = str(message.get(key))[:300]
        return header
    data = message.get("data")
    if not arg or not isinstance(data, list):
        return {"t": "unknown"}
    header = {"t": "data", "ch": str(arg.get("channel", "")), "inst": str(arg.get("instId", "")), "n": len(data)}
    if "action" in message:
        header["action"] = str(message.get("action"))
    first = data[0] if data and isinstance(data[0], dict) else {}
    header["ts"] = _int_or_none(first.get("ts"))
    if "seqId" in first:
        header["seq"] = _int_or_none(first.get("seqId"))
    if "prevSeqId" in first:
        header["prev"] = _int_or_none(first.get("prevSeqId"))
    if "checksum" in first:
        header["cs"] = _int_or_none(first.get("checksum"))
    if header["ch"] in BOOK_CHANNELS or header["ch"] in SNAPSHOT_CHANNELS:
        header["nb"] = len(first.get("bids") or [])
        header["na"] = len(first.get("asks") or [])
    if header["ch"] in TRADE_CHANNELS:
        header["ts_last"] = _int_or_none(data[-1].get("ts")) if isinstance(data[-1], dict) else None
    return header


class SeqTracker:
    """seqId / prevSeqId continuity of one (`books`, instrument) stream, in availability order.

    Rules (documented in L2-CAPTURE-PILOT.md §5):

    1. `snapshot` ⇒ VALID, `last_seq = seqId`, memory of seen pairs reset.
    2. VALID and `prevSeqId == last_seq`: `seqId == prevSeqId` ⇒ heartbeat; `seqId < prevSeqId` ⇒ documented sequence reset
       (continuous); otherwise continuous. All are applied.
    3. A (prevSeqId, seqId) pair already applied since the last snapshot ⇒ duplicate: never applied, validity unchanged.
    4. VALID and `seqId < last_seq` (not a reset, not a duplicate) ⇒ out of order (a late message): never applied, and the
       book becomes INVALID — no reordering buffer, the reader never waits for a later message.
    5. VALID and anything else ⇒ gap ⇒ INVALID.
    6. INVALID: every non-snapshot message is `update_while_invalid` (or a duplicate) and is not applied, until a snapshot.
    7. A missing seqId / prevSeqId on an update ⇒ malformed ⇒ INVALID.

    `invalidate(reason)` is called by the owner for a reconnect, a resubscription, an unsubscribe ack or a checksum failure.
    """

    def __init__(self, memory: int = 4096) -> None:
        self.valid = False
        self.last_seq: int | None = None
        self.reason = "no_snapshot_yet"
        self._seen: set = set()
        self._order: deque = deque()
        self._memory = int(memory)

    def _remember(self, pair: tuple) -> None:
        if pair in self._seen:
            return
        self._seen.add(pair)
        self._order.append(pair)
        if len(self._order) > self._memory:
            self._seen.discard(self._order.popleft())

    def invalidate(self, reason: str) -> None:
        self.valid = False
        self.reason = reason

    def classify(self, action: str | None, prev: int | None, seq: int | None) -> str:
        if action == "snapshot":
            self.valid = seq is not None
            self.reason = "" if self.valid else "snapshot_without_seq"
            self.last_seq = seq
            self._seen.clear()
            self._order.clear()
            if seq is not None:
                self._remember((prev, seq))
            return SNAPSHOT if self.valid else MALFORMED
        if seq is None or prev is None:
            self.invalidate("malformed")
            return MALFORMED
        pair = (prev, seq)
        if not self.valid:
            return DUPLICATE if pair in self._seen else WHILE_INVALID
        if prev == self.last_seq:
            verdict = HEARTBEAT if seq == prev else RESET if seq < prev else CONTINUOUS
            self.last_seq = seq
            self._remember(pair)
            return verdict
        if pair in self._seen:
            return DUPLICATE
        if self.last_seq is not None and seq < self.last_seq:
            self.invalidate("out_of_order")
            return OUT_OF_ORDER
        self.invalidate("gap")
        return GAP


def okx_checksum(bids: list, asks: list, depth: int = CHECKSUM_LEVELS) -> int:
    """CRC32 (signed 32-bit) of `bid1px:bid1sz:ask1px:ask1sz:…` over the first `depth` levels of each side, prices and sizes
    as delivered (strings); when one side is shorter its missing levels are skipped."""
    parts: list[str] = []
    for k in range(depth):
        if k < len(bids):
            parts.append(f"{bids[k][0]}:{bids[k][1]}")
        if k < len(asks):
            parts.append(f"{asks[k][0]}:{asks[k][1]}")
    value = zlib.crc32(":".join(parts).encode("utf-8"))
    return value - (1 << 32) if value >= (1 << 31) else value


class LevelError(ValueError):
    pass


def _key(price) -> Decimal:
    try:
        value = Decimal(str(price))
    except (InvalidOperation, ValueError) as error:
        raise LevelError(f"unreadable price {price!r}") from error
    if not value.is_finite():
        raise LevelError(f"non-finite price {price!r}")
    return value


def _is_zero(size) -> bool:
    try:
        return Decimal(str(size)) == 0
    except (InvalidOperation, ValueError) as error:
        raise LevelError(f"unreadable size {size!r}") from error


@dataclass(frozen=True)
class BookView:
    """A frozen copy of the top of a book at one instant of availability (levels as delivered, best first)."""

    bids: tuple
    asks: tuple
    seq: int | None
    depth_bids: int
    depth_asks: int


class Book:
    """The full depth of one instrument rebuilt from `books` snapshot + updates (all levels kept, no reduction)."""

    def __init__(self) -> None:
        self.clear()

    def clear(self) -> None:
        self._levels = {"bids": {}, "asks": {}}
        self._keys = {"bids": [], "asks": []}  # ascending Decimal prices

    def _set(self, side: str, level) -> None:
        if not isinstance(level, (list, tuple)) or len(level) < 2:
            raise LevelError(f"unreadable level {level!r}")
        key = _key(level[0])
        levels, keys = self._levels[side], self._keys[side]
        if _is_zero(level[1]):
            if key in levels:
                del levels[key]
                keys.pop(bisect.bisect_left(keys, key))
            return
        if key not in levels:
            bisect.insort(keys, key)
        levels[key] = tuple(str(x) for x in level)

    def load_snapshot(self, bids, asks) -> None:
        self.clear()
        self.apply(bids, asks)

    def apply(self, bids, asks) -> None:
        for level in bids or []:
            self._set("bids", level)
        for level in asks or []:
            self._set("asks", level)

    def side(self, side: str, depth: int | None = None) -> list:
        keys = self._keys[side]
        ordered = reversed(keys) if side == "bids" else iter(keys)
        out = []
        for key in ordered:
            if depth is not None and len(out) >= depth:
                break
            out.append(self._levels[side][key])
        return out

    def depth(self) -> tuple[int, int]:
        return len(self._keys["bids"]), len(self._keys["asks"])

    def crossed(self) -> bool:
        bids, asks = self._keys["bids"], self._keys["asks"]
        return bool(bids and asks and bids[-1] >= asks[0])

    def checksum(self) -> int:
        return okx_checksum(self.side("bids", CHECKSUM_LEVELS), self.side("asks", CHECKSUM_LEVELS))

    def view(self, depth: int | None, seq: int | None) -> BookView:
        nb, na = self.depth()
        return BookView(bids=tuple(self.side("bids", depth)), asks=tuple(self.side("asks", depth)), seq=seq, depth_bids=nb, depth_asks=na)
