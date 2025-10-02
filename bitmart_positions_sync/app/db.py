from __future__ import annotations

import json
from contextlib import contextmanager
from typing import Dict, Iterator, Optional

import pymysql
from pymysql.cursors import DictCursor

from .config import DatabaseConfig
from .logging_config import get_logger
from .models import PositionUpdate


logger = get_logger(__name__)


class Database:
    def __init__(self, config: DatabaseConfig):
        self._config = config

    @contextmanager
    def connect(self) -> Iterator[pymysql.connections.Connection]:
        connection = pymysql.connect(
            host=self._config.host,
            port=self._config.port,
            user=self._config.username,
            password=self._config.password,
            database=self._config.name,
            charset=self._config.charset,
            autocommit=False,
            cursorclass=DictCursor,
        )
        try:
            yield connection
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()


class PositionRepository:
    def __init__(self, db: Database):
        self._db = db

    def upsert(self, update: PositionUpdate) -> None:
        with self._db.connect() as conn:
            with conn.cursor() as cursor:
                existing = self._find_existing(cursor, update.contract_symbol, update.side)
                if existing is None:
                    self._insert(cursor, update)
                else:
                    self._update(cursor, existing["id"], update)

    def _find_existing(self, cursor: pymysql.cursors.Cursor, contract_symbol: str, side: str) -> Optional[dict]:
        cursor.execute(
            """
            SELECT id, status
            FROM positions
            WHERE contract_symbol = %s AND side = %s
            ORDER BY (opened_at IS NULL), opened_at DESC, id DESC
            LIMIT 1
            """,
            (contract_symbol, side),
        )
        return cursor.fetchone()

    def _insert(self, cursor: pymysql.cursors.Cursor, update: PositionUpdate) -> None:
        logger.info("Inserting position %s %s", update.contract_symbol, update.side)
        cursor.execute(
            """
            INSERT INTO positions (
                contract_symbol,
                exchange,
                side,
                status,
                amount_usdt,
                entry_price,
                qty_contract,
                leverage,
                external_order_id,
                opened_at,
                closed_at,
                stop_loss,
                take_profit,
                pnl_usdt,
                meta,
                created_at,
                updated_at,
                time_in_force,
                expires_at,
                external_status,
                last_sync_at
            ) VALUES (
                %(contract_symbol)s,
                %(exchange)s,
                %(side)s,
                %(status)s,
                %(amount_usdt)s,
                %(entry_price)s,
                %(qty_contract)s,
                %(leverage)s,
                %(external_order_id)s,
                %(opened_at)s,
                %(closed_at)s,
                %(stop_loss)s,
                %(take_profit)s,
                %(pnl_usdt)s,
                %(meta)s,
                %(created_at)s,
                %(updated_at)s,
                %(time_in_force)s,
                %(expires_at)s,
                %(external_status)s,
                %(last_sync_at)s
            )
            """,
            self._to_db_payload(update, new=True),
        )

    def _update(self, cursor: pymysql.cursors.Cursor, position_id: int, update: PositionUpdate) -> None:
        logger.info("Updating position #%s %s %s", position_id, update.contract_symbol, update.side)
        payload = self._to_db_payload(update, new=False)
        payload["id"] = position_id
        cursor.execute(
            """
            UPDATE positions SET
                status = %(status)s,
                amount_usdt = %(amount_usdt)s,
                entry_price = %(entry_price)s,
                qty_contract = %(qty_contract)s,
                leverage = %(leverage)s,
                external_order_id = %(external_order_id)s,
                opened_at = %(opened_at)s,
                closed_at = %(closed_at)s,
                stop_loss = %(stop_loss)s,
                take_profit = %(take_profit)s,
                pnl_usdt = %(pnl_usdt)s,
                meta = %(meta)s,
                updated_at = %(updated_at)s,
                time_in_force = %(time_in_force)s,
                expires_at = %(expires_at)s,
                external_status = %(external_status)s,
                last_sync_at = %(last_sync_at)s
            WHERE id = %(id)s
            """,
            payload,
        )

    def _to_db_payload(self, update: PositionUpdate, *, new: bool) -> dict:
        now = update.last_sync_at
        meta_json = json.dumps(update.meta, separators=(",", ":"))
        payload = {
            "contract_symbol": update.contract_symbol,
            "exchange": update.exchange,
            "side": update.side,
            "status": update.status,
            "amount_usdt": update.amount_str(),
            "entry_price": update.entry_price_str(),
            "qty_contract": update.qty_contract_str(),
            "leverage": update.leverage_str(),
            "external_order_id": update.external_order_id,
            "opened_at": update.opened_at,
            "closed_at": update.closed_at,
            "stop_loss": update.stop_loss_str(),
            "take_profit": update.take_profit_str(),
            "pnl_usdt": update.pnl_usdt_str(),
            "meta": meta_json,
            "updated_at": now,
            "time_in_force": update.time_in_force,
            "expires_at": update.expires_at,
            "external_status": update.external_status,
            "last_sync_at": now,
        }
        if new:
            payload["created_at"] = now
        return payload

    def fetch_active_positions(self, exchange: str) -> Dict[str, dict]:
        with self._db.connect() as conn:
            with conn.cursor() as cursor:
                cursor.execute(
                    """
                    SELECT
                        id,
                        contract_symbol,
                        side,
                        exchange,
                        status,
                        amount_usdt,
                        entry_price,
                        qty_contract,
                        leverage,
                        external_order_id,
                        opened_at,
                        closed_at,
                        stop_loss,
                        take_profit,
                        pnl_usdt,
                        time_in_force,
                        expires_at,
                        external_status,
                        last_sync_at,
                        meta
                    FROM positions
                    WHERE exchange = %s AND status IN ('OPEN','NORMAL')
                    """,
                    (exchange,),
                )
                rows = cursor.fetchall()

        result: Dict[str, dict] = {}
        for row in rows:
            meta = row.get("meta")
            if isinstance(meta, str):
                try:
                    row["meta"] = json.loads(meta)
                except json.JSONDecodeError:
                    row["meta"] = {}
            elif meta is None:
                row["meta"] = {}
            key = f"{str(row['contract_symbol']).upper()}::{str(row['side']).upper()}"
            result[key] = row
        return result
