from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from decimal import Decimal
from typing import Any, Dict, Optional


@dataclass
class PositionUpdate:
    contract_symbol: str
    side: str
    status: str
    exchange: str
    amount_usdt: Decimal
    entry_price: Optional[Decimal]
    qty_contract: Optional[Decimal]
    leverage: Optional[Decimal]
    external_order_id: Optional[str]
    opened_at: Optional[datetime]
    closed_at: Optional[datetime]
    stop_loss: Optional[Decimal]
    take_profit: Optional[Decimal]
    pnl_usdt: Optional[Decimal]
    time_in_force: str
    expires_at: Optional[datetime]
    external_status: Optional[str]
    last_sync_at: datetime
    meta: Dict[str, Any]

    def amount_str(self) -> str:
        return format(self.amount_usdt, 'f')

    def entry_price_str(self) -> Optional[str]:
        return format(self.entry_price, 'f') if self.entry_price is not None else None

    def qty_contract_str(self) -> Optional[str]:
        return format(self.qty_contract, 'f') if self.qty_contract is not None else None

    def leverage_str(self) -> Optional[str]:
        return format(self.leverage, 'f') if self.leverage is not None else None

    def stop_loss_str(self) -> Optional[str]:
        return format(self.stop_loss, 'f') if self.stop_loss is not None else None

    def take_profit_str(self) -> Optional[str]:
        return format(self.take_profit, 'f') if self.take_profit is not None else None

    def pnl_usdt_str(self) -> Optional[str]:
        return format(self.pnl_usdt, 'f') if self.pnl_usdt is not None else None
