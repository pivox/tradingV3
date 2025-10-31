# Makefile — Trading App & MTF Audit Commands

# Binaire compose (ex: COMPOSE="docker compose")
COMPOSE ?= docker-compose

# Fichiers compose additionnels (ex: FILES='-f docker-compose.yml')
FILES ?=

DC := $(COMPOSE) $(FILES)

# Trading App service name
TA_SVC ?= trading-app-php

# ===================================
# Trading App Build Commands
# ===================================

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

# ===================================
# Trading App Commands
# ===================================

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

# ===================================
# MTF Audit & Health Check Commands
# ===================================

.PHONY: mtf-audit-help mtf-audit-full mtf-audit-calibration mtf-audit-summary \
        mtf-health-check mtf-audit-export mtf-audit-by-timeframe \
        mtf-audit-all-sides mtf-audit-by-side mtf-audit-weights \
        mtf-audit-rollup mtf-audit-success

mtf-audit-help: ## Affiche l'aide pour les commandes d'audit MTF
	@echo "════════════════════════════════════════════════════════════════"
	@echo "  Commandes d'Audit MTF"
	@echo "════════════════════════════════════════════════════════════════"
	@echo
	@echo "📊 Commandes principales:"
	@echo "  make mtf-audit-summary          → Résumé complet (calibration + health-check + by-timeframe)"
	@echo "  make mtf-audit-full             → Tous les rapports stats:mtf-audit"
	@echo "  make mtf-audit-calibration      → Rapport de calibration (fail_pct moyen)"
	@echo "  make mtf-health-check           → Vérification de santé du système"
	@echo "  make mtf-audit-export           → Export tous les rapports en JSON"
	@echo
	@echo "📋 Rapports individuels:"
	@echo "  make mtf-audit-all-sides        → Top conditions bloquantes (tous sides)"
	@echo "  make mtf-audit-by-side          → Top conditions par side (long/short)"
	@echo "  make mtf-audit-by-timeframe     → Échecs agrégés par timeframe"
	@echo "  make mtf-audit-weights          → Poids (%) par condition et timeframe"
	@echo "  make mtf-audit-rollup           → Agrégation multi-niveaux"
	@echo "  make mtf-audit-success          → Dernières validations réussies"
	@echo
	@echo "⚙️  Options disponibles:"
	@echo "  PERIOD=24h                      → Période pour health-check (ex: 24h, 7d, 1w)"
	@echo "  TF=1h,4h                        → Filtrer par timeframes"
	@echo "  SYMBOLS=BTCUSDT,ETHUSDT         → Filtrer par symboles"
	@echo "  LIMIT=50                        → Limiter le nombre de lignes"
	@echo
	@echo "📖 Exemples:"
	@echo "  make mtf-audit-calibration TF=1h"
	@echo "  make mtf-health-check PERIOD=7d"
	@echo "  make mtf-audit-all-sides SYMBOLS=BTCUSDT LIMIT=20"
	@echo
	@echo "════════════════════════════════════════════════════════════════"

# Variables pour les commandes d'audit
PERIOD ?= 24h
TF ?=
SYMBOLS ?=
LIMIT ?= 100
OUTPUT_DIR ?= /tmp/mtf-audit

# Helpers pour construire les options
TF_OPT := $(if $(TF),-t $(TF),)
SYMBOLS_OPT := $(if $(SYMBOLS),--symbols=$(SYMBOLS),)
LIMIT_OPT := -l $(LIMIT)

mtf-audit-summary: ## 📊 Résumé complet: calibration + health-check + by-timeframe
	@echo "════════════════════════════════════════════════════════════════"
	@echo "  MTF AUDIT - Résumé Complet"
	@echo "════════════════════════════════════════════════════════════════"
	@echo
	@echo "1️⃣  Rapport de Calibration"
	@echo "────────────────────────────────────────────────────────────────"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "2️⃣  Health Check ($(PERIOD))"
	@echo "────────────────────────────────────────────────────────────────"
	@$(DC) exec -T $(TA_SVC) bin/console mtf:health-check --period=$(PERIOD) $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "3️⃣  Échecs par Timeframe"
	@echo "────────────────────────────────────────────────────────────────"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "════════════════════════════════════════════════════════════════"
	@echo "✅ Résumé terminé"
	@echo "════════════════════════════════════════════════════════════════"

mtf-audit-full: ## 📋 Lance tous les rapports stats:mtf-audit
	@echo "════════════════════════════════════════════════════════════════"
	@echo "  MTF AUDIT - Tous les rapports"
	@echo "════════════════════════════════════════════════════════════════"
	@echo
	@echo "1/7 - Top conditions (tous sides)"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=all-sides $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "2/7 - Top conditions par side"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=by-side $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "3/7 - Poids par timeframe"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=weights $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "4/7 - Rollup multi-niveaux"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=rollup $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "5/7 - Échecs par timeframe"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "6/7 - Validations réussies"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=success $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "7/7 - Calibration"
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "════════════════════════════════════════════════════════════════"
	@echo "✅ Tous les rapports terminés"
	@echo "════════════════════════════════════════════════════════════════"

mtf-audit-calibration: ## 🎯 Rapport de calibration (fail_pct moyen)
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT)

mtf-health-check: ## 🏥 Vérification de santé du système MTF
	@$(DC) exec -T $(TA_SVC) bin/console mtf:health-check --period=$(PERIOD) $(TF_OPT) $(SYMBOLS_OPT)

mtf-audit-by-timeframe: ## ⏱️  Échecs agrégés par timeframe
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-all-sides: ## 📊 Top conditions bloquantes (tous sides)
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=all-sides $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-by-side: ## 📊 Top conditions par side (long/short)
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=by-side $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-weights: ## 📊 Poids (%) par condition et timeframe
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=weights $(TF_OPT) $(SYMBOLS_OPT)

mtf-audit-rollup: ## 📊 Agrégation multi-niveaux (condition → TF → side)
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=rollup $(TF_OPT) $(SYMBOLS_OPT)

mtf-audit-success: ## ✅ Dernières validations réussies
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=success $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-export: ## 💾 Export tous les rapports en JSON (OUTPUT_DIR=/tmp/mtf-audit)
	@echo "════════════════════════════════════════════════════════════════"
	@echo "  Export MTF Audit en JSON"
	@echo "════════════════════════════════════════════════════════════════"
	@echo "📁 Répertoire: $(OUTPUT_DIR)"
	@$(DC) exec -T $(TA_SVC) mkdir -p $(OUTPUT_DIR)
	@echo
	@echo "Exportation en cours..."
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=all-sides $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/all-sides.json
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=by-side $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/by-side.json
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=weights $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/weights.json
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=rollup $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/rollup.json
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/by-timeframe.json
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=success $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/success.json
	@$(DC) exec -T $(TA_SVC) bin/console stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/calibration.json
	@$(DC) exec -T $(TA_SVC) bin/console mtf:health-check --period=$(PERIOD) $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/health-check.json
	@echo
	@echo "✅ Export terminé!"
	@echo "📁 Fichiers disponibles dans: $(OUTPUT_DIR) (dans le conteneur)"
	@$(DC) exec -T $(TA_SVC) ls -lh $(OUTPUT_DIR)/*.json 2>/dev/null || true
	@echo "════════════════════════════════════════════════════════════════"
