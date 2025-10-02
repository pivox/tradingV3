from __future__ import annotations

import asyncio
import contextlib
import json
import time
from typing import AsyncIterator, Dict, List, Optional, Set

import websockets
from websockets.client import WebSocketClientProtocol
from websockets.exceptions import ConnectionClosed

from .config import BitmartConfig
from .logging_config import get_logger
from .signing import BitmartSigner


logger = get_logger(__name__)


class BitmartWebsocketClient:
    def __init__(self, config: BitmartConfig, signer: BitmartSigner):
        self._config = config
        self._signer = signer
        self._backoff = 5
        self._channels: Set[str] = set(config.ws_channels or [])
        self._lock = asyncio.Lock()
        self._connection: Optional[WebSocketClientProtocol] = None

    async def listen(self, stop_event: asyncio.Event) -> AsyncIterator[Dict]:
        while not stop_event.is_set():
            try:
                async with websockets.connect(self._config.ws_url, ping_interval=None) as ws:
                    logger.info("Connected to Bitmart websocket %s", self._config.ws_url)
                    await self._authenticate(ws)
                    await self._resubscribe(ws)
                    async with self._lock:
                        self._connection = ws
                    ping_task = asyncio.create_task(self._ping_loop(ws, stop_event))
                    try:
                        idle_timeout = max(self._config.ws_ping_interval * 2, 30)
                        while not stop_event.is_set():
                            try:
                                raw = await asyncio.wait_for(ws.recv(), timeout=idle_timeout)
                            except ConnectionClosed:
                                logger.info("Websocket connection closed")
                                break
                            except asyncio.CancelledError:
                                raise
                            except asyncio.TimeoutError:
                                logger.warning("Websocket idle for %s seconds, forcing reconnect", idle_timeout)
                                break
                            except Exception as exc:
                                logger.debug("Websocket recv error: %s", exc)
                                break

                            message = self._decode(raw)
                            if message:
                                yield message
                    finally:
                        ping_task.cancel()
                        with contextlib.suppress(asyncio.CancelledError):
                            await ping_task
            except asyncio.CancelledError:
                raise
            except Exception as exc:
                logger.warning("Websocket error: %s", exc, exc_info=True)
                await asyncio.sleep(self._backoff)
                self._backoff = min(self._backoff * 2, 60)
            else:
                self._backoff = 5
            finally:
                async with self._lock:
                    self._connection = None

        logger.info("Websocket listener stopped")

    async def disconnect(self) -> None:
        async with self._lock:
            if self._connection is not None:
                await self._connection.close()
                self._connection = None

    async def subscribe(self, channel: str) -> None:
        channel = channel.strip()
        if not channel:
            return
        async with self._lock:
            if channel in self._channels:
                return
            self._channels.add(channel)
            if self._connection is not None:
                await self._connection.send(json.dumps({"op": "subscribe", "args": [channel]}))
                logger.info("Subscribed channel %s", channel)

    async def unsubscribe(self, channel: str) -> None:
        channel = channel.strip()
        if not channel:
            return
        async with self._lock:
            if channel not in self._channels:
                return
            self._channels.remove(channel)
            if self._connection is not None:
                await self._connection.send(json.dumps({"op": "unsubscribe", "args": [channel]}))
                logger.info("Unsubscribed channel %s", channel)

    def list_channels(self) -> List[str]:
        return sorted(self._channels)

    async def _authenticate(self, ws: WebSocketClientProtocol) -> None:
        timestamp_ms = str(int(time.time() * 1000))
        signature = self._signer.sign_ws_login(timestamp_ms, self._config.ws_login_payload)
        payload = {
            "op": "login",
            "args": {
                "apiKey": self._config.api_key,
                "timestamp": timestamp_ms,
                "sign": signature,
                "memo": self._signer.api_memo,
            },
        }
        await ws.send(json.dumps(payload))
        logger.debug("Sent login message")

    async def _resubscribe(self, ws: WebSocketClientProtocol) -> None:
        channels = self.list_channels()
        if not channels:
            logger.warning("No websocket channels configured; skipping subscribe")
            return
        message = {"op": "subscribe", "args": channels}
        await ws.send(json.dumps(message))
        logger.info("Subscribed to channels: %s", ", ".join(channels))

    async def _ping_loop(self, ws: WebSocketClientProtocol, stop_event: asyncio.Event) -> None:
        interval = max(self._config.ws_ping_interval, 10)
        while not stop_event.is_set():
            await asyncio.sleep(interval)
            try:
                await ws.send(json.dumps({"op": "ping"}))
            except Exception as exc:
                logger.debug("Ping failed: %s", exc)
                return

    def _decode(self, raw: str) -> Dict:
        try:
            message = json.loads(raw)
        except json.JSONDecodeError:
            logger.debug("Failed to decode message: %s", raw)
            return {}
        if "event" in message and message["event"] in {"subscribe", "login"}:
            logger.debug("Control message: %s", message)
            return {}
        return message
