# Flux fonctionnel — `POST /api/mtf/run` (profil `scalper_micro`)

Ce document décrit le flux **end-to-end** du pipeline MTF→TradeEntry pour le profil **micro scalper** :

- de l’appel HTTP `POST /api/mtf/run`
- jusqu’au statut **INVALID** (MTF non tradable)
- jusqu’au cas **ordre non placé** (TradeEntry non soumis / skip / error)
- jusqu’au cas **ordre placé** (`submitted`)

## 1) Entrée : `POST /api/mtf/run`

Endpoint : `RunnerController` (`trading-app/src/Controller/RunnerController.php`).

### 1.1 Parsing et valeurs par défaut

Le contrôleur accepte `POST` (JSON body) et `GET` (query string). Pour `POST` :

- `symbols` : array ou string CSV ; si absent/vide → le runner résout une liste depuis la DB (voir §2).
- `dry_run` : **par défaut `true`** (attention : pas d’ordres live tant que `dry_run=true`).
- `workers` : par défaut `1` (séquentiel) ; si `>1`, exécution parallèle par processes CLI (voir §3.2).
- `profile` / `mtf_profile` : si absent, le contrôleur **injecte automatiquement** le profil à partir de `trading-app/config/services.yaml` (premier mode `enabled=true` trié par `priority`).

Profil micro par défaut dans ce repo :

- `trading-app/config/services.yaml` : `mode` active `scalper_micro` et `app.trade_entry_default_mode: 'scalper_micro'`.

### 1.2 Exemple minimal (micro scalper)

Simulation (pas d’ordres) :

```json
{"dry_run": true, "workers": 1, "symbols": ["BTCUSDT"]}
```

Exécution live (place des ordres **uniquement** si MTF tradable + consumer messenger actif, cf. §5.1) :

```json
{"dry_run": false, "workers": 1, "symbols": ["BTCUSDT"], "mtf_profile": "scalper_micro"}
```

## 2) Runner : résolution des symboles + filtres “open state”

Service : `MtfRunnerService` (`trading-app/src/MtfRunner/Service/MtfRunnerService.php`).

### 2.1 Résolution de la liste de symboles

Si `symbols` est vide :

1) lecture des contrats “actifs” depuis la base via `ContractRepository::allActiveSymbolNames(profile)`  
2) ajout éventuel des symboles provenant de la “switch queue” (`consumeSymbolsWithFutureExpiration()`)

Pour `scalper_micro`, la sélection de contrats peut être pilotée par :

- `trading-app/config/app/mtf_contracts.scalper_micro.yaml`

(référence YAML : `docs/trading-app/10-mtf-contracts-yaml-reference.md`)

### 2.2 Contexte exchange

Le runner construit `ExchangeContext` avec valeurs par défaut :

- `exchange = bitmart` si non fourni
- `market_type = perpetual` si non fourni

### 2.3 Sync des tables (positions / orders)

Le runner exécute `syncTables()` (positions + open orders) avant de filtrer.

Note importante : dans le code actuel, `syncTables` est exécuté même si l’input `sync_tables=false` (le runner force `true`).

### 2.4 Filtrage “open orders / open positions” (ordre non placé *avant même MTF*)

Avant d’appeler le validateur, le runner peut **exclure** les symboles qui ont déjà :

- une position ouverte, ou
- un ordre ouvert.

Effet :

- le symbole n’est pas traité par MTF dans ce run,
- puis `updateSwitchesForExcludedSymbols()` désactive le switch du symbole :
  - raison : `has_open_orders_or_positions`
  - durée : `1m` si le switch était déjà OFF, sinon `5m`.

## 3) MTF validation (profil `scalper_micro`)

### 3.1 Config MTF micro scalper

Fichier : `trading-app/src/MtfValidator/config/validations.scalper_micro.yaml`.

Caractéristiques :

- `context_timeframes = ['5m']`
- `execution_timeframes = ['1m']`
- `mode = pragmatic`

### 3.2 Exécution séquentielle vs parallèle

- si `workers=1` : le runner appelle `MtfValidatorInterface::run()` en mémoire.
- si `workers>1` : le runner lance N processes `php bin/console mtf:run-worker --symbols=...` puis agrège leur JSON.

Dans les deux cas, la sortie “MTF” du runner (response `/api/mtf/run`) contient, par symbole :

- `status` : `READY` ou `INVALID`
- `execution_tf` : TF choisi (`1m` pour micro)
- `signal_side` : `long` / `short` (côté MTF)
- `reason` : `finalReason` (si `INVALID`)
- `context` et `execution` (détails MTF)

## 4) Invalidation MTF (statut `INVALID`) — liste des `reason`

Dans la réponse `/api/mtf/run`, le champ `reason` est `MtfResultDto.finalReason`.

### 4.1 Raisons “générales” (construction résultat vide)

- `no_timeframes_in_config` : aucune liste de timeframes résolue
- `not_enough_klines` : manque d’historique pour calculer les indicateurs

Source : `trading-app/src/MtfValidator/Service/MtfValidatorCoreService.php` (`buildEmptyResult()`).

### 4.2 Raisons “contexte” (`ContextValidationService`)

Le contexte est validé sur `5m`. La décision de contexte peut échouer avec :

- `no_context_timeframes`
- `no_context_decisions`
- `pragmatic_context_has_invalid_timeframes`
- `pragmatic_context_all_neutral`
- `pragmatic_context_side_conflict`
- `strict_context_has_invalid_timeframes`
- `strict_context_requires_non_neutral_all`
- `strict_context_side_conflict`

Source : `trading-app/src/MtfValidator/Service/ContextValidationService.php`.

### 4.3 Raisons “sélection d’exécution”

Si l’exécution ne sélectionne aucun timeframe/side :

- `no_timeframe_selected`

Source : `trading-app/src/MtfValidator/Service/ExecutionSelectionService.php` → `ExecutionSelectionDto.reasonIfNone`.

## 5) Passage MTF→TradeEntry (ordre placé / non placé)

Le placement d’ordre n’est pas fait par `MtfRunnerService` directement.

### 5.1 Déclencheur (Messenger)

Quand un symbole est `isTradable=true`, `MtfValidatorService` dispatch :

- `MtfTradingDecisionMessage` (déclenche TradeEntry)
- et, si `dry_run=false`, `MtfResultProjectionMessage` (projection/audit)

Le traitement “TradeEntry” est fait par `MtfTradingDecisionMessageHandler` → `TradingDecisionHandler`.

Important : selon la config Messenger, ce traitement peut être :

- **asynchrone** (queue doctrine/transport) : `/api/mtf/run` renvoie “MTF READY” mais l’ordre sera traité plus tard par le consumer,
- **synchrone** (transport sync) : l’ordre peut être traité immédiatement.

### 5.2 Préconditions TradeEntry (garde TradingDecisionHandler)

Même si MTF est READY, `TradingDecisionHandler` peut décider de **ne pas tenter** TradeEntry et retourne :

```json
{"trading_decision": {"status":"skipped","reason":"trading_conditions_not_met"}}
```

Causes internes (logs `order_journey.preconditions.blocked`) :

- `missing_execution_tf`
- `unsupported_execution_tf`
- `execution_tf_not_allowed_by_trade_entry`
- `missing_signal_side`
- `missing_price_and_atr`

Source : `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php` (`canExecuteMtfTrading()`).

### 5.3 Construction de la requête TradeEntry (builder)

`TradeEntryRequestBuilder::fromMtfSignal()` construit la requête minimale (risk, stop mode, order_type, etc.) à partir :

- du symbole
- du side (LONG/SHORT)
- du `executionTf` (ici `1m`)
- d’un prix courant (optionnel)
- d’un ATR (recherché sur TF d’exécution puis fallback `5m` puis `15m`)
- du mode (ici `scalper_micro` par défaut)

Si le builder retourne `null`, `TradingDecisionHandler` retourne :

- `reason = unable_to_build_request`

Cause majeure (spécifique au profil micro, car `stop_from='atr'`) :

- `atr_required_but_invalid` : ATR manquant/≤0 alors que `stop_from='atr'` est configuré.

Source : `trading-app/src/TradeEntry/Builder/TradeEntryRequestBuilder.php`.

### 5.4 Exécution TradeEntry (simulate vs live)

Selon `dry_run` :

- `dry_run=true` → `TradeEntryService::buildAndSimulate()` (status `simulated`)
- `dry_run=false` → `TradeEntryService::buildAndExecute()` (status `submitted|skipped|error`)

## 6) Ordre non placé — liste des `reason` (TradeEntry)

Quand TradeEntry n’aboutit pas à une soumission, deux cas distincts existent :

### 6.1 `status = skipped` avec `raw.reason` (raison *structurée*)

Raisons structurées émises par TradeEntry :

- `daily_loss_limit_reached` : coupe-circuit journalier (DailyLossGuard)
- `skipped_out_of_zone` : entrée rejetée car la zone est trop éloignée du marché (EntryZoneOutOfBoundsException)
- `leverage_below_threshold` : levier final ≤ 1 (garde TradeEntryService)
- `leverage_below_min` : levier < 1 (garde ExecutionBox)

Sources :

- `trading-app/src/TradeEntry/Policy/DailyLossGuard.php`
- `trading-app/src/TradeEntry/Exception/EntryZoneOutOfBoundsException.php`
- `trading-app/src/TradeEntry/Service/TradeEntryService.php`
- `trading-app/src/TradeEntry/Execution/ExecutionBox.php`

### 6.2 `status = error` (pas de raison “enum” unique)

Ici, l’ordre n’est pas placé mais la raison est portée :

- soit par `raw.reason` (cas market),
- soit par les logs / messages d’exception (cas preflight/plan/limit).

Raisons `raw.reason` observables côté **market** :

- `market_order_submit_failed`
- `market_order_fill_timeout`
- `entry_price_not_found`
- `tp_sl_service_unavailable`

Source : `trading-app/src/TradeEntry/Execution/ExecutionBox.php`.

Cas d’erreur “exception” (pas de `raw.reason` garanti) :

- échec preflight (`PreTradeChecks`) : ex. spread trop large en market (`market_order_spread_too_wide`) → exception, puis `TradingDecisionHandler` retourne `trading_decision.status=error`.
- échec planification (ex: “Prix d’entrée hors zone calculée”, “Stop loss invalide”, “EntryZoneFilters ont rejeté…”) → exception.
- échec soumission limit : `ExecutionBox` retourne `status=error` avec `raw.order=null` (la chaîne `all_attempts_failed` n’est pas exposée en `raw.reason`, seulement en logs).

## 7) Ordre placé — `status = submitted`

Quand l’ordre est soumis, `TradingDecisionHandler` renvoie une `trading_decision` de forme :

```json
{
  "status": "submitted",
  "client_order_id": "...",
  "exchange_order_id": "...",
  "raw": { ... }
}
```

Notes micro scalper :

- par défaut `trade_entry.scalper_micro.yaml` configure `order_type='limit'` et `order_mode=1`
- un basculement “fin de zone” peut transformer un plan en `order_type='market'` si `ttl` est faible et que les garde-fous passent

Références :

- config : `trading-app/config/app/trade_entry.scalper_micro.yaml`
- flux TradeEntry : `docs/trading-app/06-trade-entry.md`

