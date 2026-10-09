"""Private bounded evidence and an independent streaming cashflow replay."""
from __future__ import annotations

from decimal import Decimal
import hashlib
import heapq
import json
import os
from pathlib import Path
import shutil

from .plans import _json
from .portfolio_simulator import number
from .signals import _private_output
from .signal_sources import SignalError

LEDGERS = ('plans','rejections','events','trades','cashflows','funding-events','marked-equity')
MAX_LINE = 2*1024**2
TERMINAL_STATUS_BYTES = 8192


class EvidenceError(RuntimeError):
    """Evidence could not be preserved or reconciled."""


def _decimal(value):
    if isinstance(value, Decimal):
        if not value.is_finite():
            raise EvidenceError('nonfinite ledger Decimal')
        return format(value,'f')
    raise TypeError('unsupported evidence value')


def encoded(value) -> bytes:
    return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False,
                      default=_decimal).encode()+b'\n'


class Evidence:
    def __init__(self, root: Path, source_roots: tuple[Path, ...], *,
                 max_bytes: int, min_free_bytes: int, initial_bytes: int = 0):
        root = Path(root)
        if (type(max_bytes) is not int or max_bytes <= TERMINAL_STATUS_BYTES
            or type(min_free_bytes) is not int or min_free_bytes < 0
            or type(initial_bytes) is not int or initial_bytes < 0):
            raise EvidenceError('evidence storage limits invalid')
        for source in source_roots:
            source = Path(source)
            if root == source or source in root.parents or root in source.parents:
                raise EvidenceError('output overlaps evidence source root')
        if initial_bytes+TERMINAL_STATUS_BYTES > max_bytes:
            raise EvidenceError('initial evidence and terminal status exceed disk cap')
        initial_free = shutil.disk_usage(root.parent).free if root.parent.is_dir() else 0
        if root.parent.is_dir() and initial_free < min_free_bytes+initial_bytes+TERMINAL_STATUS_BYTES:
            raise EvidenceError('initial evidence free space reserve')
        try:
            self.root = _private_output(root, source_roots[0] if source_roots else root/'unused')
        except SignalError as exc:
            raise EvidenceError(str(exc)) from exc
        self.max_bytes, self.min_free = max_bytes, min_free_bytes
        # Conservatively account for buffered writes not yet visible to disk_usage.
        self.free_budget = initial_free-min_free_bytes
        self.bytes = self.sequence = 0
        self.streams = {}
        self.hashes = {name:hashlib.sha256() for name in LEDGERS}
        self.counts = {name:0 for name in LEDGERS}
        self.finished = False

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        for stream in self.streams.values():
            if not stream.closed:
                stream.flush()
                os.fsync(stream.fileno())
                stream.close()

    def _reserve(self, size: int, *, terminal: bool = False) -> None:
        reserve = 0 if terminal else TERMINAL_STATUS_BYTES
        if self.bytes+size > self.max_bytes-reserve:
            raise EvidenceError('evidence disk cap exceeded')
        if (self.bytes+size > self.free_budget-reserve
            or shutil.disk_usage(self.root).free-size < self.min_free+reserve):
            raise EvidenceError('evidence free space reserve')
        self.bytes += size

    @staticmethod
    def check_status(value) -> None:
        if len(encoded(value)) > TERMINAL_STATUS_BYTES:
            raise EvidenceError('terminal status encoded size exceeded')

    def json(self, name: str, value) -> str:
        if Path(name).name != name or not name.endswith('.json'):
            raise EvidenceError('unsafe evidence filename')
        path = self.root/name
        if path.exists() or path.is_symlink():
            raise EvidenceError('immutable evidence file already exists')
        raw = encoded(value)
        terminal = name == 'status.json'
        if terminal:
            self.check_status(value)
        self._reserve(len(raw),terminal=terminal)
        temporary = self.root/('.'+name+'.tmp')
        fd = os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        # link publishes atomically and fails rather than replacing a target.
        os.link(temporary,path,follow_symlinks=False)
        temporary.unlink()
        self._sync_root()
        return hashlib.sha256(raw).hexdigest()

    def emit(self, name: str, value) -> None:
        if name not in LEDGERS or self.finished:
            raise EvidenceError('unknown or finished ledger')
        if 'ledger_sequence' in value:
            raise EvidenceError('ledger sequence is evidence-owned')
        raw = encoded({**value,'ledger_sequence':self.sequence})
        if len(raw) > MAX_LINE:
            raise EvidenceError('evidence line cap exceeded')
        self._reserve(len(raw))
        if name not in self.streams:
            fd = os.open(self.root/(name+'.partial.ndjson'),
                         os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            self.streams[name] = os.fdopen(fd,'wb')
        self.streams[name].write(raw)
        self.hashes[name].update(raw)
        self.counts[name] += 1
        self.sequence += 1

    def _sync_root(self):
        fd = os.open(self.root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def finish_ledgers(self) -> dict:
        if self.finished:
            raise EvidenceError('ledgers already finished')
        files = {}
        for name in LEDGERS:
            if name not in self.streams:
                fd = os.open(self.root/(name+'.partial.ndjson'),
                    os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                self.streams[name] = os.fdopen(fd,'wb')
            stream = self.streams[name]
            stream.flush()
            os.fsync(stream.fileno())
            stream.close()
            partial = self.root/(name+'.partial.ndjson')
            final = self.root/(name+'.ndjson')
            os.link(partial,final,follow_symlinks=False)
            partial.unlink()
            files[final.name] = {'sha256':self.hashes[name].hexdigest(),
                'count':self.counts[name],'bytes':final.stat().st_size}
        self.finished = True
        self._sync_root()
        return files


def _rows(path: Path):
    previous = -1
    with path.open('rb') as stream:
        while raw := stream.readline(MAX_LINE+1):
            if len(raw) > MAX_LINE or not raw.endswith(b'\n'):
                raise EvidenceError('replay line framing invalid')
            row = _json(raw)
            sequence = row.get('ledger_sequence')
            if type(sequence) is not int or sequence <= previous:
                raise EvidenceError('replay sequence invalid')
            previous = sequence
            yield row


def reconcile(root: Path, summary: dict) -> dict:
    """Replay by global evidence order, with at most four active positions."""
    fields = ('gross_pnl_quote','net_pnl_quote','fees_quote','funding_quote','spread_quote','slippage_quote')
    zero = {key:Decimal(0) for key in fields}
    totals = dict(zero)
    active = {}
    trades = 0
    previous = -1
    # Bind each stream's kind immediately, avoiding generator late binding.
    def rows(kind):
        for row in _rows(root/(kind+'.ndjson')):
            yield kind,row
    for kind,row in heapq.merge(rows('cashflows'),rows('trades'),key=lambda item:item[1]['ledger_sequence']):
        sequence = row['ledger_sequence']
        if sequence <= previous:
            raise EvidenceError('replay global sequence invalid')
        previous = sequence
        key = row.get('plan_hash')
        if not isinstance(key,str):
            raise EvidenceError('replay plan identity missing')
        if kind == 'cashflows':
            amount = number(row['amount_quote'])
            flow = row.get('kind')
            if flow == 'gross_pnl':
                field, component = 'gross_pnl_quote', amount
            elif flow == 'funding':
                field, component = 'funding_quote', amount
            elif flow in ('entry_fee','stop_fee','target_fee','liquidation_proxy_fee'):
                field, component = 'fees_quote', -amount
            elif flow in ('entry_spread','stop_spread','target_spread'):
                field, component = 'spread_quote', -amount
            elif flow in ('entry_slippage','stop_slippage','target_slippage'):
                field, component = 'slippage_quote', -amount
            else:
                raise EvidenceError('replay cashflow kind invalid')
            if field in ('fees_quote','spread_quote','slippage_quote') and amount > 0:
                raise EvidenceError('replay cost credit invalid')
            position = active.setdefault(key,dict(zero))
            if len(active) > 4:
                raise EvidenceError('replay concurrency invalid')
            position['net_pnl_quote'] += amount
            position[field] += component
            totals['net_pnl_quote'] += amount
            totals[field] += component
        else:
            position = active.pop(key,None)
            if position is None or any(number(row[field]) != position[field] for field in fields):
                raise EvidenceError('trade cashflow reconciliation conflict')
            trades += 1
    if (active or trades != summary['trades'] or totals['net_pnl_quote'] != number(summary['net_pnl_quote'])
        or Decimal('100000')+totals['net_pnl_quote'] != number(summary['wallet_quote'])):
        raise EvidenceError('wallet or trade totals unreconciled')
    return {'status':'verified', 'trades':trades, **totals}
