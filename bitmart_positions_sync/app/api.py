from __future__ import annotations

from fastapi import FastAPI, HTTPException, WebSocket, WebSocketDisconnect

from .logging_config import get_logger
from .realtime import SubscriptionFilter
from .service import PositionSyncService


logger = get_logger(__name__)


def create_api(service: PositionSyncService) -> FastAPI:
    app = FastAPI()

    @app.get("/status")
    async def status() -> dict:
        return {
            "running": service.is_running,
            "channels": service.channels(),
        }

    @app.post("/control/start")
    async def start() -> dict:
        started = await service.start()
        logger.info("Start requested -> started=%s", started)
        return {
            "running": service.is_running,
            "started": started,
            "channels": service.channels(),
        }

    @app.post("/control/stop")
    async def stop() -> dict:
        stopped = await service.stop()
        logger.info("Stop requested -> stopped=%s", stopped)
        return {
            "running": service.is_running,
            "stopped": stopped,
            "channels": service.channels(),
        }

    @app.post("/subscriptions/{symbol}")
    async def subscribe(symbol: str) -> dict:
        symbol_clean = symbol.strip().upper()
        if not symbol_clean:
            raise HTTPException(status_code=400, detail="symbol is required")
        await service.subscribe_symbol(symbol_clean)
        logger.info("Subscribed symbol %s", symbol_clean)
        return {
            "running": service.is_running,
            "channels": service.channels(),
        }

    @app.delete("/subscriptions/{symbol}")
    async def unsubscribe(symbol: str) -> dict:
        symbol_clean = symbol.strip().upper()
        if not symbol_clean:
            raise HTTPException(status_code=400, detail="symbol is required")
        await service.unsubscribe_symbol(symbol_clean)
        logger.info("Unsubscribed symbol %s", symbol_clean)
        return {
            "running": service.is_running,
            "channels": service.channels(),
        }

    @app.websocket("/ws/positions")
    async def positions_websocket(websocket: WebSocket) -> None:
        await websocket.accept()
        filters = SubscriptionFilter.from_query(websocket.query_params)
        handle = await service.subscribe_realtime(filters)
        try:
            snapshot = await service.snapshot(filters)
            seq = await service.current_sequence()
            await websocket.send_json({
                "type": "snapshot",
                "seq": seq,
                "positions": snapshot,
            })
            while True:
                message = await handle.queue.get()
                await websocket.send_json(message)
        except WebSocketDisconnect:
            logger.info("Positions websocket disconnected")
        except Exception as exc:
            logger.warning("Positions websocket error: %s", exc, exc_info=True)
        finally:
            await handle.close()

    return app
