from __future__ import annotations

import asyncio
import contextlib
from datetime import datetime, timezone
from decimal import Decimal
from typing import Any, Dict, Iterable, List, Optional, Set

from .bitmart_rest import BitmartRestClient
from .bitmart_ws import BitmartWebsocketClient
from .db import PositionRepository
from .logging_config import get_logger
from .models import PositionUpdate
from .normalizer import EXCHANGE_NAME, DEFAULT_TIME_IN_FORCE, normalize_position
from .realtime import RealtimeHub, SubscriptionFilter, SubscriptionHandle


logger = get_logger(__name__)


class PositionSyncService:
    def __init__(
        self,
        websocket_client: BitmartWebsocketClient,
        rest_client: BitmartRestClient,
        repository: PositionRepository,
        poll_interval: int,
    ) -> None:
        self._ws = websocket_client
        self._rest = rest_client
        self._repo = repository
        self._poll_interval = max(poll_interval, 30)
        self._stop_event = asyncio.Event()
        self._tasks: List[asyncio.Task] = []
        self._lock = asyncio.Lock()
        self._base_channel = self._detect_base_channel()
        self._hub = RealtimeHub()
        self._state: Dict[str, PositionUpdate] = {}
        self._state_lock = asyncio.Lock()
        self._load_lock = asyncio.Lock()
        self._state_loaded = False
        self._sequence = 0
        self._exchange = EXCHANGE_NAME

    @property
    def is_running(self) -> bool:
        return any(not task.done() for task in self._tasks)

    def channels(self) -> List[str]:
        return self._ws.list_channels()

    async def subscribe_realtime(self, filters: SubscriptionFilter) -> SubscriptionHandle:
        await self._ensure_state_loaded()
        return await self._hub.subscribe(filters)

    async def snapshot(self, filters: SubscriptionFilter) -> List[Dict[str, Any]]:
        await self._ensure_state_loaded()
        async with self._state_lock:
            positions = [
                self._serialize_position(position)
                for position in self._state.values()
                if filters.matches(
                    symbol=position.contract_symbol,
                    status=position.status,
                    side=position.side,
                )
            ]
        positions.sort(key=lambda item: (item["contractSymbol"], item["side"]))
        return positions

    async def current_sequence(self) -> int:
        async with self._state_lock:
            return self._sequence

    async def start(self) -> bool:
        async with self._lock:
            if self.is_running:
                return False
            self._stop_event.clear()
            await self._ensure_state_loaded()
            self._tasks = [
                asyncio.create_task(self._consume_websocket()),
                asyncio.create_task(self._poll_loop()),
            ]
            return True

    async def stop(self) -> bool:
        async with self._lock:
            if not self._tasks:
                return False
            self._stop_event.set()
            await self._ws.disconnect()
            for task in self._tasks:
                task.cancel()
                with contextlib.suppress(asyncio.CancelledError):
                    await task
            self._tasks = []
            return True

    async def subscribe_symbol(self, symbol: str) -> None:
        symbol = symbol.strip().upper()
        if not symbol:
            return
        channel = self._channel_for_symbol(symbol)
        await self._ws.subscribe(channel)

    async def unsubscribe_symbol(self, symbol: str) -> None:
        symbol = symbol.strip().upper()
        if not symbol:
            return
        channel = self._channel_for_symbol(symbol)
        await self._ws.unsubscribe(channel)

    async def _consume_websocket(self) -> None:
        async for message in self._ws.listen(self._stop_event):
            updates = self._extract_updates(message)
            if not updates:
                continue
            await self._apply_updates(updates, notify=True)

    async def _poll_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                payload = self._rest.fetch_positions()
            except Exception as exc:
                logger.warning("REST poll failed: %s", exc, exc_info=True)
            else:
                updates = self._extract_updates(payload)
                await self._apply_snapshot(updates)
            try:
                await asyncio.wait_for(self._stop_event.wait(), timeout=self._poll_interval)
            except asyncio.TimeoutError:
                continue

    async def _apply_updates(self, updates: Iterable[PositionUpdate], *, notify: bool) -> None:
        for update in updates:
            await self._persist_update(update)
            await self._update_state(update, notify=notify)

    async def _persist_update(self, update: PositionUpdate) -> None:
        try:
            await asyncio.to_thread(self._repo.upsert, update)
        except Exception as exc:
            logger.error(
                "Failed to persist position %s %s: %s",
                update.contract_symbol,
                update.side,
                exc,
                exc_info=True,
            )

    def _extract_updates(self, message: Dict[str, Any]) -> List[PositionUpdate]:
        data = self._extract_data(message)
        updates: List[PositionUpdate] = []
        for entry in data:
            if not isinstance(entry, dict):
                continue
            normalized = normalize_position(entry)
            if normalized:
                updates.append(normalized)
        return updates

    def _extract_data(self, message: Dict[str, Any]) -> List[Dict[str, Any]]:
        if not message:
            return []

        if "table" in message and "position" not in str(message["table"]):
            return []

        data = message.get("data")
        if isinstance(data, list):
            return [item for item in data if isinstance(item, dict)]

        if isinstance(data, dict) and "positions" in data and isinstance(data["positions"], list):
            return [item for item in data["positions"] if isinstance(item, dict)]

        if "positions" in message and isinstance(message["positions"], list):
            return [item for item in message["positions"] if isinstance(item, dict)]

        if isinstance(message, dict) and message.get("symbol"):
            return [message]

        return []

    def _channel_for_symbol(self, symbol: str) -> str:
        base = self._base_channel or "futures/position"
        return f"{base}:{symbol}"

    def _detect_base_channel(self) -> str:
        channels = self._ws.list_channels()
        for channel in channels:
            if ":" not in channel:
                return channel
        return "futures/position"

    async def _update_state(self, update: PositionUpdate, *, notify: bool) -> None:
        key = self._state_key(update.contract_symbol, update.side)
        async with self._state_lock:
            previous = self._state.get(key)
            self._state[key] = update
            if notify:
                event_type = self._determine_event(previous, update)
                if event_type:
                    self._sequence += 1
                    sequence = self._sequence
                else:
                    sequence = None
            else:
                event_type = None
                sequence = None
        if not notify or event_type is None or sequence is None:
            return
        payload = {
            "type": event_type,
            "seq": sequence,
            "position": self._serialize_position(update),
            "previous": self._serialize_position(previous) if previous else None,
            "timestamp": datetime.now(tz=timezone.utc).isoformat(),
        }
        await self._hub.publish(
            payload,
            symbol=update.contract_symbol,
            status=update.status,
            side=update.side,
        )

    async def _ensure_state_loaded(self) -> None:
        if self._state_loaded:
            return
        async with self._load_lock:
            if self._state_loaded:
                return
            try:
                payload = await asyncio.to_thread(self._rest.fetch_positions)
            except Exception as exc:
                logger.warning("Initial REST sync failed: %s", exc, exc_info=True)
                self._state_loaded = True
                return
            updates = self._extract_updates(payload)
            if updates:
                await self._apply_updates(updates, notify=False)
            observed = {self._state_key(update.contract_symbol, update.side) for update in updates}
            await self._close_missing_positions(observed, notify=False)
            self._state_loaded = True

    async def _apply_snapshot(self, updates: List[PositionUpdate]) -> None:
        observed_keys = {self._state_key(update.contract_symbol, update.side) for update in updates}
        if updates:
            await self._apply_updates(updates, notify=True)
        await self._close_missing_positions(observed_keys)

    def _determine_event(self, previous: Optional[PositionUpdate], current: PositionUpdate) -> Optional[str]:
        if previous is None and self._is_closed(current):
            return "position.closed"
        if previous is None:
            return "position.opened"
        if self._is_closed(current):
            if not self._is_closed(previous):
                return "position.closed"
            return "position.updated"
        if previous.qty_contract != current.qty_contract:
            return "position.quantity_changed"
        if previous.status != current.status:
            return "position.updated"
        if previous.entry_price != current.entry_price or previous.pnl_usdt != current.pnl_usdt:
            return "position.updated"
        return None

    def _state_key(self, symbol: str, side: str) -> str:
        return f"{symbol.upper()}::{side.upper()}"

    def _serialize_position(self, position: Optional[PositionUpdate]) -> Optional[Dict[str, Any]]:
        if position is None:
            return None
        return {
            "contractSymbol": position.contract_symbol,
            "side": position.side,
            "status": position.status,
            "exchange": position.exchange,
            "amountUsdt": self._decimal_to_float(position.amount_usdt),
            "entryPrice": self._decimal_to_float(position.entry_price),
            "qtyContract": self._decimal_to_float(position.qty_contract),
            "leverage": self._decimal_to_float(position.leverage),
            "externalOrderId": position.external_order_id,
            "openedAt": self._datetime_to_iso(position.opened_at),
            "closedAt": self._datetime_to_iso(position.closed_at),
            "stopLoss": self._decimal_to_float(position.stop_loss),
            "takeProfit": self._decimal_to_float(position.take_profit),
            "pnlUsdt": self._decimal_to_float(position.pnl_usdt),
            "timeInForce": position.time_in_force,
            "expiresAt": self._datetime_to_iso(position.expires_at),
            "externalStatus": position.external_status,
            "lastSyncAt": self._datetime_to_iso(position.last_sync_at),
            "meta": position.meta,
            "key": self._state_key(position.contract_symbol, position.side),
            "isClosed": self._is_closed(position),
        }

    @staticmethod
    def _decimal_to_float(value: Optional[Decimal]) -> Optional[float]:
        if value is None:
            return None
        try:
            return float(value)
        except (ValueError, TypeError):
            return None

    @staticmethod
    def _datetime_to_iso(value: Optional[datetime]) -> Optional[str]:
        if value is None:
            return None
        if value.tzinfo is None:
            value = value.replace(tzinfo=timezone.utc)
        return value.isoformat()

    @staticmethod
    def _is_closed(update: PositionUpdate) -> bool:
        if update.status.upper() == "CLOSED":
            return True
        qty = update.qty_contract
        if qty is None:
            return False
        if isinstance(qty, Decimal):
            return qty == 0
        try:
            return Decimal(str(qty)) == 0
        except Exception:
            return False

    async def _close_missing_positions(self, observed_keys: Set[str], *, notify: bool = True) -> None:
        try:
            active_positions = await asyncio.to_thread(self._repo.fetch_active_positions, self._exchange)
        except Exception as exc:
            logger.error("Failed to fetch active positions: %s", exc, exc_info=True)
            return

        missing_keys = set(active_positions.keys()) - observed_keys
        if not missing_keys:
            return

        now = datetime.now(tz=timezone.utc)
        forced_updates = [
            self._build_forced_close_update(active_positions[key], now)
            for key in missing_keys
        ]

        if forced_updates:
            await self._apply_updates(forced_updates, notify=notify)

    def _build_forced_close_update(self, row: Dict[str, Any], closed_at: datetime) -> PositionUpdate:
        entry_price = self._decimal_from_any(row.get("entry_price"))
        leverage = self._decimal_from_any(row.get("leverage"))
        stop_loss = self._decimal_from_any(row.get("stop_loss"))
        take_profit = self._decimal_from_any(row.get("take_profit"))
        pnl_usdt = self._decimal_from_any(row.get("pnl_usdt"))
        expires_at = row.get("expires_at")
        opened_at = row.get("opened_at")

        meta = self._ensure_dict_meta(row.get("meta"))
        if row.get("amount_usdt") is not None:
            meta.setdefault("last_known_amount_usdt", str(row["amount_usdt"]))
        if row.get("qty_contract") is not None:
            meta.setdefault("last_known_qty_contract", str(row["qty_contract"]))
        meta["sync_status"] = "closed_by_snapshot"
        meta["sync_closed_at"] = closed_at.isoformat()

        return PositionUpdate(
            contract_symbol=str(row.get("contract_symbol", "")).upper(),
            side=str(row.get("side", "")).upper(),
            status="CLOSED",
            exchange=str(row.get("exchange", self._exchange)),
            amount_usdt=Decimal("0"),
            entry_price=entry_price,
            qty_contract=Decimal("0"),
            leverage=leverage,
            external_order_id=row.get("external_order_id"),
            opened_at=opened_at,
            closed_at=closed_at,
            stop_loss=stop_loss,
            take_profit=take_profit,
            pnl_usdt=pnl_usdt,
            time_in_force=str(row.get("time_in_force") or DEFAULT_TIME_IN_FORCE).upper(),
            expires_at=expires_at,
            external_status="CLOSED",
            last_sync_at=closed_at,
            meta=meta,
        )

    @staticmethod
    def _decimal_from_any(value: Any) -> Optional[Decimal]:
        if value is None:
            return None
        if isinstance(value, Decimal):
            return value
        try:
            return Decimal(str(value))
        except Exception:
            return None

    @staticmethod
    def _ensure_dict_meta(value: Any) -> Dict[str, Any]:
        if isinstance(value, dict):
            return dict(value)
        return {}
