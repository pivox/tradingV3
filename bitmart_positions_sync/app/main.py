from __future__ import annotations

import asyncio
import sys

import uvicorn

from .api import create_api
from .bitmart_rest import BitmartRestClient
from .bitmart_ws import BitmartWebsocketClient
from .config import AppConfig
from .db import Database, PositionRepository
from .logging_config import configure_logging, get_logger
from .service import PositionSyncService
from .signing import BitmartSigner


logger = get_logger(__name__)


async def _async_main() -> None:
    config = AppConfig.from_env()
    configure_logging(config.log_level)

    if not config.bitmart.api_key or not config.bitmart.api_secret or not config.bitmart.api_memo:
        logger.error("Missing Bitmart credentials. Ensure BITMART_API_KEY, BITMART_SECRET_KEY and BITMART_API_MEMO are set.")
        sys.exit(1)

    signer = BitmartSigner(api_secret=config.bitmart.api_secret, api_memo=config.bitmart.api_memo)
    websocket_client = BitmartWebsocketClient(config.bitmart, signer)
    rest_client = BitmartRestClient(config.bitmart, signer)
    repository = PositionRepository(Database(config.database))
    service = PositionSyncService(
        websocket_client=websocket_client,
        rest_client=rest_client,
        repository=repository,
        poll_interval=config.bitmart.poll_interval,
    )

    if config.auto_start:
        started = await service.start()
        logger.info("Auto start enabled -> started=%s", started)

    app = create_api(service)
    uvicorn_config = uvicorn.Config(
        app,
        host=config.api_host,
        port=config.api_port,
        log_level="info",
        lifespan="on",
    )
    server = uvicorn.Server(uvicorn_config)

    logger.info(
        "Bitmart position sync API listening on %s:%s (auto_start=%s)",
        config.api_host,
        config.api_port,
        config.auto_start,
    )

    try:
        await server.serve()
    finally:
        await service.stop()


def main() -> None:
    asyncio.run(_async_main())


if __name__ == "__main__":
    main()
