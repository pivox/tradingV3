PHP_EXEC=docker exec -it symfony_php php

fetch-contracts:
	$(PHP_EXEC) bin/console app:bitmart:fetch-contracts

sync-symbol:
	@if [ -z "$(symbol)" ]; then \
		echo "❌ Veuillez spécifier le symbole : make sync-symbol symbol=BTCUSDT"; \
		exit 1; \
	fi
	$(PHP_EXEC) bin/console bitmart:kline:sync-all --symbol=$(symbol)

sync-all-symbols:
	bash scripts/sync_all.sh

latest:
	@if [ -z "$(symbol)" ]; then \
		echo "❌ Veuillez spécifier le symbole : make latest symbol=BTCUSDT [step=1]"; \
		exit 1; \
	fi
	$(PHP_EXEC) bin/console bitmart:kline:latest $(symbol) $(step)
