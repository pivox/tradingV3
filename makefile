# Makefile — Trading App & MTF Audit Commands

# ===================================
# Environment & Runtime Configuration
# ===================================

# Include root .env if present (sets RUNTIME, BITMART keys, etc.)
# Copy .env.example to .env and fill in your values.
-include .env

# Docker Compose binary (override with: COMPOSE="docker compose")
COMPOSE ?= docker-compose

# Additional compose files (e.g. FILES='-f docker-compose.override.yml')
FILES ?=

DC := $(COMPOSE) $(FILES)

# Trading App PHP service name in docker-compose
TA_SVC ?= trading-app-php

# Runtime mode: docker (default) or native
#   docker  — PHP commands run inside the Docker container
#   native  — PHP commands run directly on the host machine
#
# Override per-command:  RUNTIME=native make mtf-health-check
# Override persistently: make env-native  (writes RUNTIME=native to .env)
RUNTIME ?= docker

ifeq ($(RUNTIME),native)
  # Native: run PHP directly in trading-app/ on the host
  CONSOLE     := cd trading-app && php bin/console
  BASH_IN_APP := cd trading-app && bash
  EXEC_IN_APP :=
else
  # Docker: execute inside the running PHP container
  CONSOLE     := $(DC) exec -T $(TA_SVC) bin/console
  BASH_IN_APP := $(DC) exec -T $(TA_SVC) bash
  EXEC_IN_APP := $(DC) exec -T $(TA_SVC)
endif

# Defaults for investigation watch
SYMBOLS ?=
SINCE_MINUTES ?= 30
FORMAT ?= table
INTERVAL ?= 120

# ===================================
# Environment Switching
# ===================================

.PHONY: env-status env-native env-docker

env-status: ## Show current runtime environment
	@echo "RUNTIME : $(RUNTIME)"
	@if [ "$(RUNTIME)" = "native" ]; then \
		echo "Mode    : Native (PHP runs on host, trading-app/.env.local required)"; \
	else \
		echo "Mode    : Docker (PHP runs in container '$(TA_SVC)')"; \
	fi

env-native: ## Switch to native PHP runtime (sets RUNTIME=native in .env)
	@touch .env
	@if grep -q '^RUNTIME=' .env; then \
		sed -i 's/^RUNTIME=.*/RUNTIME=native/' .env; \
	else \
		echo "RUNTIME=native" >> .env; \
	fi
	@echo "Switched to RUNTIME=native."
	@echo "Next: copy trading-app/.env.native to trading-app/.env.local and fill in your values."

env-docker: ## Switch to Docker PHP runtime (sets RUNTIME=docker in .env)
	@touch .env
	@if grep -q '^RUNTIME=' .env; then \
		sed -i 's/^RUNTIME=.*/RUNTIME=docker/' .env; \
	else \
		echo "RUNTIME=docker" >> .env; \
	fi
	@echo "Switched to RUNTIME=docker."

# ===================================
# Trading App Build Commands
# ===================================

.PHONY: build-trading-app-dev build-trading-app-prod \
        rebuild-trading-app-dev rebuild-trading-app-prod \
        up-trading-app restart-trading-app

build-trading-app-dev: ## Build trading-app image for dev (APP_ENV=dev)
	$(DC) build --build-arg APP_ENV=dev $(TA_SVC)

build-trading-app-prod: ## Build trading-app image for prod (APP_ENV=prod)
	$(DC) build --build-arg APP_ENV=prod $(TA_SVC)

rebuild-trading-app-dev: build-trading-app-dev ## Rebuild + restart trading-app for dev
	$(DC) up -d --no-deps $(TA_SVC)

rebuild-trading-app-prod: build-trading-app-prod ## Rebuild + restart trading-app for prod
	$(DC) up -d --no-deps $(TA_SVC)

restart-trading-app: ## Restart trading-app PHP service
	$(DC) restart $(TA_SVC)

# ===================================
# Trading App Commands
# ===================================

fetch-contracts:
	$(CONSOLE) app:bitmart:fetch-contracts

sync-symbol:
	@if [ -z "$(symbol)" ]; then \
		echo "❌ Veuillez spécifier le symbole : make sync-symbol symbol=BTCUSDT"; \
		exit 1; \
	fi
	$(CONSOLE) bitmart:kline:sync-all --symbol=$(symbol)

sync-all-symbols:
	bash scripts/sync_all.sh

latest:
	@if [ -z "$(symbol)" ]; then \
		echo "❌ Veuillez spécifier le symbole : make latest symbol=BTCUSDT [step=1]"; \
		exit 1; \
	fi
	$(CONSOLE) bitmart:kline:latest $(symbol) $(step)


show-positions: ## Affiche l'état actuel des positions ouvertes
	$(CONSOLE) app:evaluate:positions

show-orders: ## Affiche l'état actuel des ordres ouvertes
	$(CONSOLE) app:bitmart:orders:open

show-pipeline: ## Affiche en continu le pipeline des contrats
	$(CONSOLE) app:monitor:contract-pipeline --interval=2

.PHONY: watch-investigate
watch-investigate: ## Boucle d'investigation toutes les 2m (SYMBOLS=SYM1,SYM2 [SINCE_MINUTES=30] [INTERVAL=120] [FORMAT=table|json])
	@if [ -z "$(SYMBOLS)" ]; then \
		echo "❌ Veuillez spécifier les symboles : make watch-investigate SYMBOLS=GLMUSDT,VELODROMEUSDT [SINCE_MINUTES=30] [INTERVAL=120] [FORMAT=table|json]"; \
		exit 1; \
	fi
	@echo "▶ investigate:no-order (watch) — SYMBOLS=$(SYMBOLS) SINCE_MINUTES=$(SINCE_MINUTES) INTERVAL=$(INTERVAL) FORMAT=$(FORMAT)"
	$(BASH_IN_APP) bin/investigate_no_order_watch.sh --symbols=$(SYMBOLS) --since-minutes=$(SINCE_MINUTES) --format=$(FORMAT) --interval=$(INTERVAL)

# ===================================
# MTF Audit & Health Check Commands
# ===================================

.PHONY: mtf-audit-help mtf-audit-full mtf-audit-calibration mtf-audit-summary \
        mtf-health-check mtf-audit-export mtf-audit-by-timeframe \
        mtf-audit-all-sides mtf-audit-by-side mtf-audit-weights \
        mtf-audit-rollup mtf-audit-success \
        calibrate-atr validate-contracts

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
SINCE ?= 7 days
ATR_TFS ?= 15m,5m,1m

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
	@$(CONSOLE) stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "2️⃣  Health Check ($(PERIOD))"
	@echo "────────────────────────────────────────────────────────────────"
	@$(CONSOLE) mtf:health-check --period=$(PERIOD) $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "3️⃣  Échecs par Timeframe"
	@echo "────────────────────────────────────────────────────────────────"
	@$(CONSOLE) stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
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
	@$(CONSOLE) stats:mtf-audit --report=all-sides $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "2/7 - Top conditions par side"
	@$(CONSOLE) stats:mtf-audit --report=by-side $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "3/7 - Poids par timeframe"
	@$(CONSOLE) stats:mtf-audit --report=weights $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "4/7 - Rollup multi-niveaux"
	@$(CONSOLE) stats:mtf-audit --report=rollup $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "5/7 - Échecs par timeframe"
	@$(CONSOLE) stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "6/7 - Validations réussies"
	@$(CONSOLE) stats:mtf-audit --report=success $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)
	@echo
	@echo "7/7 - Calibration"
	@$(CONSOLE) stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT)
	@echo
	@echo "════════════════════════════════════════════════════════════════"
	@echo "✅ Tous les rapports terminés"
	@echo "════════════════════════════════════════════════════════════════"

mtf-audit-calibration: ## 🎯 Rapport de calibration (fail_pct moyen)
	@$(CONSOLE) stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT)

mtf-health-check: ## 🏥 Vérification de santé du système MTF
	@$(CONSOLE) mtf:health-check --period=$(PERIOD) $(TF_OPT) $(SYMBOLS_OPT)

mtf-audit-by-timeframe: ## ⏱️  Échecs agrégés par timeframe
	@$(CONSOLE) stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-all-sides: ## 📊 Top conditions bloquantes (tous sides)
	@$(CONSOLE) stats:mtf-audit --report=all-sides $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-by-side: ## 📊 Top conditions par side (long/short)
	@$(CONSOLE) stats:mtf-audit --report=by-side $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-weights: ## 📊 Poids (%) par condition et timeframe
	@$(CONSOLE) stats:mtf-audit --report=weights $(TF_OPT) $(SYMBOLS_OPT)

mtf-audit-rollup: ## 📊 Agrégation multi-niveaux (condition → TF → side)
	@$(CONSOLE) stats:mtf-audit --report=rollup $(TF_OPT) $(SYMBOLS_OPT)

mtf-audit-success: ## ✅ Dernières validations réussies
	@$(CONSOLE) stats:mtf-audit --report=success $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT)

mtf-audit-export: ## 💾 Export tous les rapports en JSON (OUTPUT_DIR=/tmp/mtf-audit)
	@echo "════════════════════════════════════════════════════════════════"
	@echo "  Export MTF Audit en JSON"
	@echo "════════════════════════════════════════════════════════════════"
	@echo "📁 Répertoire: $(OUTPUT_DIR)"
	@$(EXEC_IN_APP) mkdir -p $(OUTPUT_DIR)
	@echo
	@echo "Exportation en cours..."
	@$(CONSOLE) stats:mtf-audit --report=all-sides $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/all-sides.json
	@$(CONSOLE) stats:mtf-audit --report=by-side $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/by-side.json
	@$(CONSOLE) stats:mtf-audit --report=weights $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/weights.json
	@$(CONSOLE) stats:mtf-audit --report=rollup $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/rollup.json
	@$(CONSOLE) stats:mtf-audit --report=by-timeframe $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/by-timeframe.json
	@$(CONSOLE) stats:mtf-audit --report=success $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/success.json

# ===================================
# Calibration ATR & Validation Helpers
# ===================================

calibrate-atr: ## 🎯 Calibre les seuils ATR/close par TF via DB et affiche un patch YAML
	@echo "Calibration ATR/close — SINCE=$(SINCE) TFS=$(ATR_TFS)"
	$(CONSOLE) audit:atr:calibrate --since="$(SINCE)" -t $(ATR_TFS) --output-dir=var

validate-contracts: ## 🔎 Valide les contrats actifs pour un TF (TF=15m,5m,1m,1h,4h) LIMIT=100
	@if [ -z "$(TF)" ]; then \
		echo "❌ Spécifiez le TF: make validate-contracts TF=15m [LIMIT=100]"; \
		exit 1; \
	fi
	$(CONSOLE) app:indicator:contracts:validate $(TF) --limit=$(LIMIT) -vv
	@$(CONSOLE) stats:mtf-audit --report=calibration $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/calibration.json
	@$(CONSOLE) stats:mtf-audit --report=success $(TF_OPT) $(SYMBOLS_OPT) $(LIMIT_OPT) --format=json --output=$(OUTPUT_DIR)/success.json
	@$(CONSOLE) mtf:health-check --period=$(PERIOD) $(TF_OPT) $(SYMBOLS_OPT) --format=json --output=$(OUTPUT_DIR)/health-check.json
	@echo
	@echo "✅ Export terminé!"
	@echo "📁 Fichiers disponibles dans: $(OUTPUT_DIR)"
	@$(EXEC_IN_APP) ls -lh $(OUTPUT_DIR)/*.json 2>/dev/null || true
	@echo "════════════════════════════════════════════════════════════════"
