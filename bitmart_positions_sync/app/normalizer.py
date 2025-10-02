from __future__ import annotations

from datetime import datetime, timezone
from decimal import Decimal
from typing import Any, Dict, Iterable, Optional

from .models import PositionUpdate


QTY_KEYS = (
    "size",
    "current_amount",
    "hold_volume",
    "position_volume",
    "open_size",
    "available",
)
ENTRY_KEYS = (
    "entry_price",
    "avg_entry_price",
    "average_price",
    "avg_price",
)
LEVERAGE_KEYS = (
    "leverage",
    "position_leverage",
    "open_leverage",
)
STOP_LOSS_KEYS = (
    "stop_loss",
    "sl_price",
    "preset_stop_loss_price",
)
TAKE_PROFIT_KEYS = (
    "take_profit",
    "tp_price",
    "preset_take_profit_price",
)
PNL_KEYS = (
    "realised_pnl",
    "unrealised_pnl",
    "pnl",
    "unrealised_profit",
    "unrealisedProfit",
    "unrealized_pnl",
    "unrealized_profit",
    "unrealizedProfit",
    "unrealisedPnl",
    "unrealizedPnl",
    "realized_pnl",
    "realizedPnl",
    "realized_profit",
    "realisedProfit",
)
OPEN_TIME_KEYS = (
    "open_time",
    "created_at",
    "createdTime",
    "open_timestamp",
)
CLOSE_TIME_KEYS = (
    "close_time",
    "updated_at",
    "closedTime",
)
SIDE_NUMERIC = {1: "LONG", 2: "SHORT", -1: "SHORT"}
SIDE_TEXT = {
    "LONG": "LONG",
    "BUY": "LONG",
    "BID": "LONG",
    "OPEN_LONG": "LONG",
    "HOLD_LONG": "LONG",
    "SHORT": "SHORT",
    "SELL": "SHORT",
    "ASK": "SHORT",
    "OPEN_SHORT": "SHORT",
    "HOLD_SHORT": "SHORT",
}
DEFAULT_TIME_IN_FORCE = "GTC"
EXCHANGE_NAME = "bitmart"


def normalize_position(raw: Dict[str, Any]) -> Optional[PositionUpdate]:
    symbol = _extract_symbol(raw)
    if not symbol:
        return None

    side = _extract_side(raw)
    if not side:
        side = "LONG"

    qty = _extract_decimal(raw, QTY_KEYS)
    entry_price = _extract_decimal(raw, ENTRY_KEYS)
    leverage = _extract_decimal(raw, LEVERAGE_KEYS)
    stop_loss = _extract_decimal(raw, STOP_LOSS_KEYS)
    take_profit = _extract_decimal(raw, TAKE_PROFIT_KEYS)
    pnl = _extract_decimal(raw, PNL_KEYS)

    opened_at = _extract_datetime(raw, OPEN_TIME_KEYS)
    closed_at = _extract_datetime(raw, CLOSE_TIME_KEYS)

    status = "OPEN"
    if qty is None or qty == 0:
        status = "CLOSED"
    elif raw.get("status"):
        status = str(raw["status"]).upper()

    amount = Decimal("0")
    if qty is not None and entry_price is not None:
        amount = qty * entry_price

    last_sync = datetime.now(tz=timezone.utc)

    return PositionUpdate(
        contract_symbol=symbol,
        side=side,
        status=status,
        exchange=EXCHANGE_NAME,
        amount_usdt=amount,
        entry_price=entry_price,
        qty_contract=qty,
        leverage=leverage,
        external_order_id=_first_of(raw, ("order_id", "clOrdId", "client_oid", "clientOrderId")),
        opened_at=opened_at,
        closed_at=closed_at if status == "CLOSED" else None,
        stop_loss=stop_loss,
        take_profit=take_profit,
        pnl_usdt=pnl,
        time_in_force=str(raw.get("time_in_force", DEFAULT_TIME_IN_FORCE)).upper(),
        expires_at=None,
        external_status=str(raw.get("state") or raw.get("external_status") or "").upper() or None,
        last_sync_at=last_sync,
        meta=raw,
    )


def _extract_symbol(raw: Dict[str, Any]) -> Optional[str]:
    symbol = raw.get("symbol") or raw.get("contract") or raw.get("contract_symbol")
    if not symbol:
        return None
    return str(symbol).upper()


def _extract_side(raw: Dict[str, Any]) -> Optional[str]:
    if "side" in raw and raw["side"] is not None:
        value = raw["side"]
    else:
        value = raw.get("hold_side") or raw.get("position_side") or raw.get("holdSide")

    if value is None:
        return None

    if isinstance(value, (int, float)):
        key = int(value)
        return SIDE_NUMERIC.get(key)

    normalized = str(value).strip().upper()
    return SIDE_TEXT.get(normalized, normalized if normalized in {"LONG", "SHORT"} else None)


def _extract_decimal(raw: Dict[str, Any], keys: Iterable[str]) -> Optional[Decimal]:
    for key in keys:
        if key not in raw:
            continue
        value = raw.get(key)
        if value in (None, ""):
            continue
        try:
            return Decimal(str(value))
        except Exception:
            continue
    return None


def _extract_datetime(raw: Dict[str, Any], keys: Iterable[str]) -> Optional[datetime]:
    for key in keys:
        if key not in raw:
            continue
        value = raw.get(key)
        if value in (None, ""):
            continue
        if isinstance(value, (int, float)):
            if value > 10_000_000_000:
                value = value / 1000
            return datetime.fromtimestamp(float(value), tz=timezone.utc)
        if isinstance(value, str):
            stripped = value.strip()
            if stripped.isdigit():
                num = int(stripped)
                if num > 10_000_000_000:
                    num = num / 1000
                return datetime.fromtimestamp(float(num), tz=timezone.utc)
            try:
                return datetime.fromisoformat(stripped)
            except Exception:
                continue
    return None


def _first_of(raw: Dict[str, Any], keys: Iterable[str]) -> Optional[str]:
    for key in keys:
        value = raw.get(key)
        if value:
            return str(value)
    return None
