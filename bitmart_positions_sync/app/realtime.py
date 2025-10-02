from __future__ import annotations

import asyncio
from dataclasses import dataclass
from asyncio import QueueEmpty
from typing import Mapping, Optional, Set

from .logging_config import get_logger


logger = get_logger(__name__)


def _normalize_set(values: Set[str]) -> Set[str]:
    return {value.strip().upper() for value in values if value and value.strip()}


@dataclass(frozen=True)
class SubscriptionFilter:
    symbols: Optional[Set[str]] = None
    statuses: Optional[Set[str]] = None
    sides: Optional[Set[str]] = None
    user_id: Optional[str] = None

    @staticmethod
    def from_query(params: Mapping[str, str]) -> "SubscriptionFilter":
        symbols: Set[str] = set()
        statuses: Set[str] = set()
        sides: Set[str] = set()

        if "symbol" in params:
            raw = params.get("symbol")
            if raw:
                symbols.update(part.strip() for part in raw.split(","))
        if "symbols" in params:
            raw = params.get("symbols")
            if raw:
                symbols.update(part.strip() for part in raw.split(","))
        if "status" in params:
            raw = params.get("status")
            if raw:
                statuses.update(part.strip() for part in raw.split(","))
        if "side" in params:
            raw = params.get("side")
            if raw:
                sides.update(part.strip() for part in raw.split(","))

        user_id = params.get("user") or params.get("user_id")

        return SubscriptionFilter(
            symbols=_normalize_set(symbols) or None,
            statuses=_normalize_set(statuses) or None,
            sides=_normalize_set(sides) or None,
            user_id=user_id.strip() if user_id else None,
        )

    def matches(self, *, symbol: str, status: str, side: str, user_id: Optional[str] = None) -> bool:
        if self.symbols and symbol.upper() not in self.symbols:
            return False
        if self.statuses and status.upper() not in self.statuses:
            return False
        if self.sides and side.upper() not in self.sides:
            return False
        if self.user_id and (user_id or "").strip() != self.user_id:
            return False
        return True


@dataclass
class _Subscriber:
    id: int
    queue: asyncio.Queue
    filters: SubscriptionFilter


@dataclass
class SubscriptionHandle:
    id: int
    queue: asyncio.Queue
    _hub: "RealtimeHub"

    async def close(self) -> None:
        await self._hub.unsubscribe(self.id)


class RealtimeHub:
    def __init__(self, *, queue_size: int = 100) -> None:
        self._queue_size = queue_size
        self._subscribers: dict[int, _Subscriber] = {}
        self._next_id = 0
        self._lock = asyncio.Lock()

    async def subscribe(self, filters: SubscriptionFilter) -> SubscriptionHandle:
        queue: asyncio.Queue = asyncio.Queue(maxsize=self._queue_size)
        async with self._lock:
            self._next_id += 1
            subscriber_id = self._next_id
            self._subscribers[subscriber_id] = _Subscriber(subscriber_id, queue, filters)
        return SubscriptionHandle(id=subscriber_id, queue=queue, _hub=self)

    async def unsubscribe(self, subscriber_id: int) -> None:
        async with self._lock:
            subscriber = self._subscribers.pop(subscriber_id, None)
        if subscriber is None:
            return
        # Drain queue to unblock pending consumers if any.
        try:
            while True:
                subscriber.queue.get_nowait()
        except QueueEmpty:
            pass

    async def publish(self, message: dict, *, symbol: str, status: str, side: str, user_id: Optional[str] = None) -> None:
        async with self._lock:
            subscribers = list(self._subscribers.values())

        dropped = 0
        for subscriber in subscribers:
            if not subscriber.filters.matches(symbol=symbol, status=status, side=side, user_id=user_id):
                continue
            try:
                subscriber.queue.put_nowait(message)
            except asyncio.QueueFull:
                dropped += 1

        if dropped:
            logger.warning("Dropped %s realtime messages because subscriber queues are full", dropped)

    async def active_subscribers(self) -> int:
        async with self._lock:
            return len(self._subscribers)
