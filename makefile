
# Makefile — commandes Docker Compose pour api-rate-limiter-worker (suffixe -api-limiter)

# Service ciblé
SVC ?= api-rate-limiter-worker

# Binaire compose (ex: COMPOSE="docker compose")
COMPOSE ?= docker compose

# Fichiers compose additionnels (ex: FILES='-f docker-compose.yml -f docker-compose.workers.yml')
FILES ?=

DC := $(COMPOSE) $(FILES)

# Trading App service name
TA_SVC ?= trading-app-php

.PHONY: build-trading-app-dev build-trading-app-prod \
        rebuild-trading-app-dev rebuild-trading-app-prod \
        up-trading-app restart-trading-app

# Build image with dev tooling (xdebug, dev composer deps)
build-trading-app-dev: ## Build trading-app image for dev (APP_ENV=dev)
	$(DC) build --build-arg APP_ENV=dev $(TA_SVC)

# Build image optimized for production (no xdebug, no dev deps)
build-trading-app-prod: ## Build trading-app image for prod (APP_ENV=prod)
	$(DC) build --build-arg APP_ENV=prod $(TA_SVC)

# Rebuild + restart only the PHP service (dev)
rebuild-trading-app-dev: build-trading-app-dev ## Rebuild + restart trading-app for dev
	$(DC) up -d --no-deps $(TA_SVC)

# Rebuild + restart only the PHP service (prod)
rebuild-trading-app-prod: build-trading-app-prod ## Rebuild + restart trading-app for prod
	$(DC) up -d --no-deps $(TA_SVC)

# Restart PHP service without rebuilding
restart-trading-app: ## Restart trading-app PHP service
	$(DC) restart $(TA_SVC)

.PHONY: help-api-limiter build-api-limiter up-api-limiter rebuild-api-limiter \
        clean-rebuild-api-limiter watch-api-limiter status-api-limiter logs-api-limiter verify-api-limiter

help-api-limiter: ## Affiche l'aide pour les commandes -api-limiter
	@echo "Usage: make <target>-api-limiter [FILES='-f docker-compose.yml -f docker-compose.workers.yml']"
	@echo
	@grep -E '^[a-zA-Z0-9_-]+-api-limiter:.*?## ' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS=":.*?## "}; {printf "  \033[36m%-28s\033[0m %s\n", $$1, $$2}'

build-api-limiter: ## (1) Rebuild l'image du service
	$(DC) build $(SVC)

up-api-limiter: ## (2) Redémarre uniquement ce service (sans dépendances)
	$(DC) up -d --no-deps $(SVC)

rebuild-api-limiter: build-api-limiter up-api-limiter ## Enchaîne rebuild + restart

clean-rebuild-api-limiter: ## Rebuild propre (no-cache + pull) + recréation forcée
	$(DC) build --no-cache --pull $(SVC)
	$(DC) up -d --no-deps --force-recreate $(SVC)

watch-api-limiter: ## Dev auto: reconstruit/redéploie à la volée
	$(DC) watch $(SVC)

status-api-limiter: ## Vérifie l'état du service
	$(DC) ps $(SVC)

logs-api-limiter: ## Suit les logs en temps réel (Ctrl+C pour quitter)
	$(DC) logs -f $(SVC)

verify-api-limiter: ## Vérifie rapidement: statut + dernières lignes de logs
	$(DC) ps $(SVC)
	$(DC) logs --tail=50 $(SVC)



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


show-positions: ## Affiche l'état actuel des positions ouvertes
	docker-compose exec php php bin/console app:evaluate:positions

show-orders: ## Affiche l'état actuel des ordres ouvertes
	docker-compose exec php php bin/console app:bitmart:orders:open

show-pipeline: ## Affiche en continu le pipeline des contrats
	docker-compose exec php php bin/console app:monitor:contract-pipeline --interval=2
