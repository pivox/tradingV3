# User Stories - Flux complet de `/api/mtf/run` au placement d'ordre

Ce document décrit toutes les user stories du flux complet, de l'appel API `/api/mtf/run` jusqu'au placement effectif de l'ordre sur Bitmart. Il couvre à la fois le **happy path** et tous les **cas d'erreur/skip**.

---

## Phase 1 : Runner - Orchestration initiale

### US-1.1 : En tant qu'opérateur, je veux lancer un run MTF via API afin de valider et potentiellement trader les symboles configurés

**Critères d'acceptation** :
- L'endpoint `/api/mtf/run` accepte POST avec JSON body
- Les paramètres optionnels ont des valeurs par défaut (`dry_run=true`, `workers=1`)
- Le profil MTF est injecté automatiquement si non fourni
- La réponse contient un `run_id` unique

**Cas d'erreur** :
- Erreur 400 si JSON invalide
- Erreur 500 si erreur serveur

---

### US-1.2 : En tant que système, je veux résoudre la liste de symboles à traiter afin d'éviter de traiter des symboles inactifs

**Critères d'acceptation** :
- Si `symbols` est vide, récupère tous les contrats actifs depuis la DB
- Ajoute les symboles de la queue `mtf_switch` avec expiration future
- Filtre selon le profil MTF (`mtf_contracts.<profile>.yaml`)
- Retourne une liste non vide de symboles

**Cas d'erreur** :
- Liste vide si aucun contrat actif
- Log warning si aucun symbole résolu

---

### US-1.3 : En tant que système, je veux synchroniser les positions et ordres ouverts avec Bitmart afin d'avoir l'état réel de l'exchange

**Critères d'acceptation** :
- Appelle `FuturesOrderSyncService::syncPositions()`
- Appelle `FuturesOrderSyncService::syncOpenOrders()`
- Met à jour les tables `futures_position` et `futures_order`
- Les symboles avec positions/ordres ouverts sont exclus du run MTF

**Cas d'erreur** :
- Erreur API Bitmart → log error, continue avec données DB existantes
- Timeout → log warning, utilise données DB

---

### US-1.4 : En tant que système, je veux filtrer les symboles avec positions/ordres ouverts afin d'éviter les doublons

**Critères d'acceptation** :
- Les symboles avec position ouverte sont exclus
- Les symboles avec ordre ouvert sont exclus
- Les symboles exclus ont leur `MtfSwitch` mis à jour :
  - Raison : `has_open_orders_or_positions`
  - Durée : `1m` si switch déjà OFF, sinon `5m`
- Les symboles exclus sont retournés dans `excludedSymbols`

**Cas d'erreur** :
- Aucun (filtrage silencieux)

---

### US-1.5 : En tant que système, je veux exécuter la validation MTF en séquentiel ou parallèle selon le paramètre `workers` afin d'optimiser le temps d'exécution

**Critères d'acceptation** :
- Si `workers=1` : exécution séquentielle via `MtfValidatorInterface::run()`
- Si `workers>1` : lance N processes `mtf:run-worker` et agrège les résultats JSON
- Les résultats sont agrégés dans un tableau `results`
- Les erreurs par symbole sont capturées sans bloquer les autres

**Cas d'erreur** :
- Erreur lors du lancement d'un worker → log error, continue avec autres workers
- Timeout worker → log warning, résultat partiel

---

### US-1.6 : En tant que système, je veux dispatcher la persistance asynchrone des indicateurs afin de ne pas bloquer la réponse API

**Critères d'acceptation** :
- Dispatch `IndicatorSnapshotPersistRequestMessage` sur transport `mtf_projection`
- Le message contient `symbols`, `timeframes`, `run_id`
- La réponse API n'attend pas la persistance
- Les snapshots sont persistés par `IndicatorSnapshotPersistRequestMessageHandler`

**Cas d'erreur** :
- Erreur dispatch → log warning, continue
- Handler échoue → message en failed queue

---

### US-1.7 : En tant que système, je veux recalculer les TP/SL des positions ouvertes si `process_tp_sl=true` afin de maintenir des stops optimaux

**Critères d'acceptation** :
- Vérifie le throttling (ne recalcule pas à chaque run)
- Appelle `TpSlTwoTargetsService::recalculateForOpenPositions()`
- Met à jour les TP/SL via `modify-plan-order` Bitmart
- Log les résultats du recalcul

**Cas d'erreur** :
- Throttling actif → skip silencieux
- Erreur API → log warning, continue

---

### US-1.8 : En tant qu'opérateur, je veux recevoir une réponse enrichie avec métriques et statistiques afin de comprendre les résultats du run

**Critères d'acceptation** :
- Réponse contient `summary` (total, validés, invalidés)
- Réponse contient `summary_by_tf` (répartition par timeframe)
- Réponse contient `rejected_by` (raisons de rejet)
- Réponse contient `orders_placed` (compte et détails)
- Réponse contient `performance` (timings par étape)

**Cas d'erreur** :
- Aucun (enrichissement toujours effectué)

---

## Phase 2 : Validator - Validation MTF

### US-2.1 : En tant que système, je veux charger la configuration MTF du profil spécifié afin d'appliquer les bonnes règles de validation

**Critères d'acceptation** :
- Charge `config/app/mtf_validations.<profile>.yaml` via `MtfValidationConfigProvider`
- Résout les timeframes de contexte (ex: `['5m']`)
- Résout les timeframes d'exécution (ex: `['1m']`)
- Résout le mode de contexte (`pragmatic` ou `strict`)

**Cas d'erreur** :
- Profil introuvable → exception, symbole marqué `INVALID` avec `no_timeframes_in_config`
- YAML invalide → exception, symbole marqué `INVALID`

---

### US-2.2 : En tant que système, je veux récupérer les indicateurs techniques pour tous les timeframes requis afin d'évaluer les conditions MTF

**Critères d'acceptation** :
- Appelle `IndicatorProviderInterface::getIndicatorsForSymbolAndTimeframes()`
- Récupère les indicateurs pour contexte + exécution
- Les indicateurs incluent : RSI, MACD, EMA, SMA, ATR, VWAP, ADX, Bollinger, StochRSI
- Les indicateurs incluent les pivots (S1-S6, R1-R6)

**Cas d'erreur** :
- Pas assez de klines → symbole marqué `INVALID` avec `not_enough_klines`
- Erreur calcul indicateur → log warning, indicateur à null

---

### US-2.3 : En tant que système, je veux valider le contexte multi-timeframe afin de déterminer si le marché est favorable au trading

**Critères d'acceptation** :
- Appelle `ContextValidationService::validate()`
- Évalue chaque timeframe de contexte (ex: 5m)
- Mode `pragmatic` : tolère certains neutres si majorité valide
- Mode `strict` : exige tous les timeframes valides
- Retourne une décision de contexte (LONG, SHORT, NEUTRAL)

**Cas d'invalidation** :
- `no_context_timeframes` : aucune liste de timeframes
- `no_context_decisions` : aucune décision retournée
- `pragmatic_context_has_invalid_timeframes` : timeframes invalides en mode pragmatic
- `pragmatic_context_all_neutral` : tous les timeframes neutres
- `pragmatic_context_side_conflict` : conflit LONG/SHORT
- `strict_context_has_invalid_timeframes` : timeframes invalides en mode strict
- `strict_context_requires_non_neutral_all` : strict exige tous non-neutres
- `strict_context_side_conflict` : conflit LONG/SHORT en mode strict

---

### US-2.4 : En tant que système, je veux sélectionner le timeframe d'exécution optimal afin de trader au meilleur moment

**Critères d'acceptation** :
- Appelle `ExecutionSelectionService::select()`
- Évalue chaque timeframe d'exécution (ex: 1m, 5m)
- Applique les règles de sélection :
  - `drop_to_5m_if_any` : conditions pour passer à 5m
  - `forbid_drop_to_5m_if_any` : conditions pour bloquer 5m
- Retourne le TF optimal + side (LONG/SHORT)

**Cas d'invalidation** :
- `no_timeframe_selected` : aucun TF d'exécution sélectionné

---

### US-2.5 : En tant que système, je veux dispatcher un message de décision de trading si le symbole est tradable afin de déclencher TradeEntry de manière asynchrone

**Critères d'acceptation** :
- Si `isTradable=true` et `executionTimeframe` et `side` présents :
  - Dispatch `MtfTradingDecisionMessage` sur transport `mtf_decision`
  - Si `dry_run=false`, dispatch `MtfResultProjectionMessage` sur `mtf_projection`
- Le message contient `SymbolResultDto`, `MtfRunDto`, `runId`
- Le handler `MtfTradingDecisionMessageHandler` consomme le message

**Cas d'erreur** :
- Erreur dispatch → log error, symbole marqué comme non traité
- Handler échoue → message en failed queue, retry automatique

---

## Phase 3 : TradingDecisionHandler - Pont MTF → TradeEntry

### US-3.1 : En tant que système, je veux vérifier les préconditions TradeEntry avant de construire la requête afin d'éviter des appels inutiles

**Critères d'acceptation** :
- Vérifie que `executionTf` est présent
- Vérifie que `executionTf` est supporté
- Vérifie que `executionTf` est autorisé par TradeEntry config
- Vérifie que `signalSide` est présent (LONG/SHORT)
- Vérifie que prix et ATR sont disponibles si requis

**Cas de skip** :
- `missing_execution_tf` : TF d'exécution manquant
- `unsupported_execution_tf` : TF non supporté
- `execution_tf_not_allowed_by_trade_entry` : TF non autorisé
- `missing_signal_side` : Side manquant
- `missing_price_and_atr` : Prix/ATR manquants

---

### US-3.2 : En tant que système, je veux construire la requête TradeEntry à partir du signal MTF afin de préparer l'exécution

**Critères d'acceptation** :
- Appelle `TradeEntryRequestBuilder::fromMtfSignal()`
- Construit `TradeEntryRequest` avec :
  - `symbol`, `side`, `executionTf`
  - `orderType` (depuis config)
  - `riskPctPercent` (depuis config)
  - `stopFrom` (depuis config : 'atr' ou 'pivot')
  - `rMultiple` (depuis config)
- Récupère ATR sur TF d'exécution, fallback 5m puis 15m

**Cas de skip** :
- `unable_to_build_request` : Builder retourne null
- `atr_required_but_invalid` : ATR manquant/≤0 alors que `stop_from='atr'`

---

### US-3.3 : En tant que système, je veux exécuter TradeEntry en mode simulation ou live selon `dry_run` afin de tester sans risque

**Critères d'acceptation** :
- Si `dry_run=true` : appelle `TradeEntryService::buildAndSimulate()`
  - Retourne `status='simulated'`
  - Aucun ordre placé
- Si `dry_run=false` : appelle `TradeEntryService::buildAndExecute()`
  - Retourne `status='submitted'|'skipped'|'error'`
  - Ordre potentiellement placé

**Cas d'erreur** :
- Exception non capturée → log error, retourne `status='error'`

---

## Phase 4 : TradeEntry - BuildPreOrder

### US-4.1 : En tant que système, je veux récupérer les spécifications du contrat depuis Bitmart afin de connaître les contraintes d'ordre

**Critères d'acceptation** :
- Appelle `ContractProvider::getContractSpecs()`
- Récupère : `tickSize`, `pricePrecision`, `volPrecision`
- Récupère : `minVolume`, `maxVolume`, `marketMaxVolume`
- Récupère : `minLeverage`, `maxLeverage`
- Récupère : `contractSize`

**Cas d'erreur** :
- Contrat introuvable → exception, `status='error'`
- Erreur API → exception, `status='error'`

---

### US-4.2 : En tant que système, je veux récupérer le carnet d'ordres (order book) afin de connaître les prix de marché actuels

**Critères d'acceptation** :
- Appelle `OrderBookProvider::getOrderBook()`
- Récupère `bestBid` et `bestAsk`
- Calcule `spreadPct = (bestAsk - bestBid) / bestBid * 100`
- Récupère `lastPrice` et `markPrice`

**Cas d'erreur** :
- Order book vide → exception, `status='error'`
- Erreur API → exception, `status='error'`

---

### US-4.3 : En tant que système, je veux vérifier que le spread n'est pas trop large pour un ordre market afin d'éviter un slippage excessif

**Critères d'acceptation** :
- Si `orderType='market'` :
  - Vérifie `spreadPct <= market_max_spread_pct` (config)
  - Si dépassé → exception `market_order_spread_too_wide`
- Si `orderType='limit'` : pas de vérification spread

**Cas d'erreur** :
- `market_order_spread_too_wide` : spread > seuil configuré

---

### US-4.4 : En tant que système, je veux récupérer la balance disponible afin de calculer la taille de position

**Critères d'acceptation** :
- Appelle `AccountProvider::getAvailableBalance()`
- Récupère `availableUsdt` (balance disponible en USDT)
- Utilisé pour calculer `size = (balance * risk_pct_percent) / (entry - stop)`

**Cas d'erreur** :
- Balance non récupérée → exception, `status='error'`
- Balance insuffisante → géré dans BuildOrderPlan

---

### US-4.5 : En tant que système, je veux récupérer les pivots (supports/résistances) afin de calculer les stops basés sur pivots

**Critères d'acceptation** :
- Appelle `IndicatorProvider::getListPivot()` sur le TF pivot configuré
- Récupère S1-S6 (supports) et R1-R6 (résistances)
- Utilisé si `stop_from='pivot'` ou `pivot_sl_policy` configuré

**Cas d'erreur** :
- Pivots non disponibles → log warning, utilise ATR fallback si configuré

---

## Phase 5 : TradeEntry - BuildOrderPlan

### US-5.1 : En tant que système, je veux calculer la zone d'entrée basée sur VWAP/SMA21 et ATR afin de définir une plage de prix d'entrée optimale

**Critères d'acceptation** :
- Appelle `EntryZoneCalculator::compute()`
- Sélectionne l'ancre (VWAP ou SMA21 selon config)
- Récupère ATR sur `offset_atr_tf` (ou `pivot_tf` par défaut)
- Calcule demi-largeur : `width = k_atr * ATR`
- Clamp entre `pivot * w_min` et `pivot * w_max`
- Applique `asym_bias` si configuré (asymétrie selon side)
- Quantifie aux ticks exchange si `quantize_to_exchange_step=true`
- Retourne `EntryZone` avec `min`, `max`, `ttl_sec`, `rationale`

**Cas d'erreur** :
- ATR invalide → utilise fallback ATR ou exception
- Pivot invalide → exception, `status='error'`

---

### US-5.2 : En tant que système, je veux calculer le prix d'entrée optimal dans la zone d'entrée afin de maximiser les chances de fill

**Critères d'acceptation** :
- Pour LONG : `entry = bestAsk` (ou `zone.min` si `bestAsk < zone.min`)
- Pour SHORT : `entry = bestBid` (ou `zone.max` si `bestBid > zone.max`)
- Clamp dans `[bestBid, bestAsk]` si `order_type='limit'`
- Quantifie au `tickSize` exchange

**Cas d'erreur** :
- Prix hors zone après clamp → exception `entry_not_within_zone`

---

### US-5.3 : En tant que système, je veux calculer le stop loss basé sur ATR ou pivot selon la configuration afin de limiter les pertes

**Critères d'acceptation** :
- Si `stop_from='atr'` :
  - `stop = entry - (atr_k * ATR)` pour LONG
  - `stop = entry + (atr_k * ATR)` pour SHORT
- Si `stop_from='pivot'` :
  - Sélectionne pivot selon `pivot_sl_policy` :
    - `nearest` : support/résistance le plus proche
    - `strongest` : S2/R2 puis S1/R1
    - `s1`...`s6` / `r1`...`r6` : pivot spécifique
  - Ajoute buffer (ex: 0.3%) pour éviter les wicks
  - Si pivot trop loin (>2%), fallback ATR
- Quantifie au `tickSize` exchange

**Cas d'erreur** :
- ATR invalide avec `stop_from='atr'` → exception
- Pivot invalide avec `stop_from='pivot'` → fallback ATR ou exception

---

### US-5.4 : En tant que système, je veux calculer le take profit basé sur R-multiple et pivots afin de définir un objectif de gain

**Critères d'acceptation** :
- Calcule `risk = |entry - stop|`
- `tp1 = entry + (r_multiple * risk)` pour LONG
- `tp1 = entry - (r_multiple * risk)` pour SHORT
- Si `tp1_r` configuré, ajuste selon ratio
- Si pivots disponibles, peut ajuster vers R1/R2 pour LONG ou S1/S2 pour SHORT
- Quantifie au `tickSize` exchange

**Cas d'erreur** :
- Aucun (TP toujours calculable)

---

### US-5.5 : En tant que système, je veux calculer la taille de position basée sur le risque configuré afin de respecter la gestion du risque

**Critères d'acceptation** :
- Calcule `risk_amount = available_usdt * (risk_pct_percent / 100)`
- Calcule `size = risk_amount / |entry - stop|`
- Clamp entre `minVolume` et `maxVolume` (ou `marketMaxVolume` si market)
- Arrondit selon `volPrecision`

**Cas d'erreur** :
- `size < minVolume` → exception, `status='error'`
- `size > maxVolume` → clamp à `maxVolume`

---

### US-5.6 : En tant que système, je veux calculer le levier dynamique basé sur le stop loss et les caps configurés afin d'optimiser l'exposition

**Critères d'acceptation** :
- Calcule levier théorique : `leverage = entry / |entry - stop|`
- Applique les caps :
  - `leverage_floor` (min, ex: 1)
  - `exchange_cap` (max exchange, ex: 125)
  - `max_loss_pct` (cap SL vs `initial_margin_usdt`)
  - `per_symbol_caps` (regex sur symboles)
  - `timeframe_multipliers` (multiplicateur par TF)
- Arrondit selon `leverage_rounding`

**Cas de skip** :
- `leverage_below_threshold` : levier ≤ 1 (garde TradeEntryService)
- `leverage_below_min` : levier < 1 (garde ExecutionBox)

---

### US-5.7 : En tant que système, je veux vérifier que le prix d'entrée est dans la zone d'entrée après tous les clamps afin d'éviter des entrées hors zone

**Critères d'acceptation** :
- Vérifie `zone.contains(candidate_price)`
- Si hors zone :
  - Calcule `zone_deviation = max(|zone.min - mark|, |zone.max - mark|) / mark`
  - Si `zone_deviation > zone_max_deviation_pct` :
    - Skip avec `skipped_out_of_zone` (cas nominal)
  - Sinon :
    - Exception `entry_not_within_zone` (bug logique)

**Cas de skip** :
- `skipped_out_of_zone` : zone trop éloignée du marché
- `zone_far_from_market` : déviation > seuil

**Cas d'erreur** :
- `entry_not_within_zone` : prix hors zone mais marché proche (bug)

---

### US-5.8 : En tant que système, je veux appliquer les filtres de zone d'entrée afin de rejeter les zones non conformes

**Critères d'acceptation** :
- Appelle `EntryZoneFilters::passAll()`
- Vérifie les filtres configurés (volume, spread, distance VWAP, etc.)
- Si un filtre échoue → exception `entry_zone_filters_rejection`

**Cas d'erreur** :
- `entry_zone_filters_rejection` : filtres rejettent la zone

---

### US-5.9 : En tant que système, je veux vérifier que le stop loss ne provoque pas de liquidation afin de protéger le compte

**Critères d'acceptation** :
- Appelle `LiquidationGuard::assertSafe()`
- Vérifie que `stop` est à distance suffisante du prix de liquidation
- Si trop proche → exception

**Cas d'erreur** :
- Exception si risque de liquidation

---

## Phase 6 : TradeEntry - ExecuteOrderPlan

### US-6.1 : En tant que système, je veux vérifier l'idempotence via `decision_key` afin d'éviter les doublons

**Critères d'acceptation** :
- Vérifie si un ordre avec le même `decision_key` existe déjà
- Si oui → skip avec `status='skipped'`, `reason='duplicate_decision_key'`
- Si non → continue

**Cas de skip** :
- `duplicate_decision_key` : ordre déjà placé pour ce `decision_key`

---

### US-6.2 : En tant que système, je veux vérifier la limite de perte journalière afin de protéger le capital

**Critères d'acceptation** :
- Appelle `DailyLossGuard::canTrade()`
- Vérifie si la perte journalière cumulée < seuil configuré
- Si dépassé → skip avec `status='skipped'`, `reason='daily_loss_limit_reached'`

**Cas de skip** :
- `daily_loss_limit_reached` : limite journalière atteinte

---

### US-6.3 : En tant que système, je veux soumettre le levier à l'exchange avant de placer l'ordre afin d'utiliser le bon levier

**Critères d'acceptation** :
- Appelle `LeverageProvider::setLeverage(symbol, leverage)`
- Vérifie que le levier est bien appliqué
- Si échec → log warning, continue (certains exchanges appliquent automatiquement)

**Cas d'erreur** :
- Erreur API → log warning, continue (non bloquant)

---

### US-6.4 : En tant que système, je veux placer un ordre LIMIT avec retry en cas d'échec afin de maximiser les chances de soumission

**Critères d'acceptation** :
- Si `orderType='limit'` :
  - Tentative 1 : `price = entry` (prix idéal)
  - Si échec → Tentative 2 : `price = bestAsk` (LONG) ou `bestBid` (SHORT)
  - Si échec → Tentative 3 : `price = bestBid + tickSize` (LONG) ou `bestAsk - tickSize` (SHORT)
  - Appelle `OrderProvider::placeOrder()` avec `type='limit'`, `price`, `quantity=size`
  - Si toutes les tentatives échouent → `status='error'`, `reason='all_attempts_failed'`

**Cas de succès** :
- `status='submitted'` avec `client_order_id` et `exchange_order_id`

**Cas d'erreur** :
- `all_attempts_failed` : toutes les tentatives ont échoué
- `submit_failed` : erreur générique de soumission
- `submit_rejected_exchange` : rejeté par l'exchange

---

### US-6.5 : En tant que système, je veux placer un ordre MARKET si la zone d'entrée expire ou si configuré afin d'assurer l'exécution

**Critères d'acceptation** :
- Si `orderType='market'` ou fallback end-of-zone :
  - Appelle `OrderProvider::placeOrder()` avec `type='market'`, `quantity=size`
  - Vérifie que `spreadPct <= market_max_spread_pct` (déjà vérifié en preflight)
  - Attends le fill avec timeout (ex: 5s)
  - Si timeout → `status='error'`, `reason='market_order_fill_timeout'`

**Cas de succès** :
- `status='submitted'` avec prix de fill

**Cas d'erreur** :
- `market_order_submit_failed` : échec soumission
- `market_order_fill_timeout` : timeout fill
- `entry_price_not_found` : prix d'entrée non trouvé après fill

---

### US-6.6 : En tant que système, je veux programmer un watcher de timeout pour les ordres LIMIT afin d'annuler les ordres non remplis

**Critères d'acceptation** :
- Si `orderType='limit'` et ordre soumis :
  - Dispatch `CancelOrderMessage` avec `DelayStamp` de 120s
  - Le message contient `symbol`, `client_order_id`, `exchange_order_id`
  - `CancelOrderMessageHandler` vérifie le statut après 120s :
    - Si `FILLED` ou `PARTIALLY_FILLED` → ne fait rien
    - Si `PENDING` → annule l'ordre et réactive `MtfSwitch` avec délai 15m
    - Si déjà `CANCELLED` → ne fait rien

**Cas d'erreur** :
- Erreur dispatch → log warning (non bloquant)

---

### US-6.7 : En tant que système, je veux programmer un watcher de fill pour les ordres LIMIT afin de désarmer le dead-man switch si rempli

**Critères d'acceptation** :
- Si `orderType='limit'` et ordre soumis :
  - Dispatch `LimitFillWatchMessage` avec `DelayStamp` de 5s
  - Le message contient `symbol`, `client_order_id`, `exchange_order_id`
  - `LimitFillWatchMessageHandler` vérifie le statut :
    - Si `FILLED` → désarme le `CancelOrderMessage` (si possible)
    - Si `PENDING` → continue la surveillance

**Cas d'erreur** :
- Erreur dispatch → log warning (non bloquant)

---

### US-6.8 : En tant que système, je veux programmer un watcher out-of-zone pour annuler les ordres LIMIT si le prix sort de la zone

**Critères d'acceptation** :
- Si `orderType='limit'` et ordre soumis :
  - Dispatch `OutOfZoneWatchMessage` avec `DelayStamp` de 30s
  - Le message contient `symbol`, `zone_min`, `zone_max`, `client_order_id`
  - `OutOfZoneWatchMessageHandler` vérifie si le prix est hors zone :
    - Si hors zone → annule l'ordre LIMIT
    - Si dans zone → continue la surveillance

**Cas d'erreur** :
- Erreur dispatch → log warning (non bloquant)

---

### US-6.9 : En tant que système, je veux attacher les TP/SL à l'ordre si supporté par l'exchange afin de protéger automatiquement la position

**Critères d'acceptation** :
- Si l'exchange supporte `preset_take_profit_price` et `preset_stop_loss_price` :
  - Les TP/SL sont inclus dans le payload `placeOrder()`
- Sinon :
  - Appelle `TpSlAttacher::attach()` après soumission
  - Ou utilise `TpSlTwoTargetsService` pour positions ouvertes

**Cas d'erreur** :
- `tp_sl_service_unavailable` : service TP/SL indisponible
- Erreur API → log warning (non bloquant, TP/SL peut être attaché plus tard)

---

### US-6.10 : En tant que système, je veux désactiver le MtfSwitch pour 4h après placement d'ordre afin d'éviter les retrades immédiats

**Critères d'acceptation** :
- Si ordre soumis avec succès :
  - Appelle `MtfSwitchRepository::turnOffSymbolFor4Hours(symbol)`
  - Le symbole est exclu des prochains runs MTF pendant 4h
  - Si l'ordre est annulé (timeout), le switch est réactivé avec délai 15m

**Cas d'erreur** :
- Erreur update switch → log warning (non bloquant)

---

### US-6.11 : En tant que système, je veux persister l'événement de soumission d'ordre afin d'avoir un audit trail complet

**Critères d'acceptation** :
- Appelle `TradeLifecycleLogger::logOrderSubmittedEvent()`
- Persiste dans `trade_lifecycle_event` :
  - `event_type='order_submitted'`
  - `symbol`, `decision_key`, `trade_id`, `run_id`
  - `client_order_id`, `exchange_order_id`
  - `entry`, `stop`, `take_profit`, `size`, `leverage`
  - `extra` (JSON avec détails complets)

**Cas d'erreur** :
- Erreur DB → log error (non bloquant)

---

## Phase 7 : Post-exécution - Hooks & Monitoring

### US-7.1 : En tant que système, je veux exécuter le hook post-soumission si fourni afin de permettre des actions personnalisées

**Critères d'acceptation** :
- Si `PostExecutionHookInterface` fourni et `status='submitted'` :
  - Appelle `hook->onSubmitted(request, result, decisionKey)`
  - Le hook peut :
    - Mettre à jour des métriques externes
    - Envoyer des notifications
    - Déclencher des workflows externes

**Cas d'erreur** :
- Exception dans hook → log error (non bloquant)

---

### US-7.2 : En tant que système, je veux persister l'événement de skip si l'ordre n'a pas été placé afin d'analyser les raisons

**Critères d'acceptation** :
- Si `status='skipped'` :
  - Appelle `TradeLifecycleLogger::logSymbolSkippedEvent()`
  - Persiste dans `trade_lifecycle_event` :
    - `event_type='order_skipped'`
    - `reason_code` (ex: `skipped_out_of_zone`, `daily_loss_limit_reached`)
    - `symbol`, `decision_key`, `run_id`
    - `extra` (JSON avec contexte)

**Cas d'erreur** :
- Erreur DB → log error (non bloquant)

---

### US-7.3 : En tant que système, je veux persister l'événement de zone skip si la zone est rejetée afin d'analyser les patterns

**Critères d'acceptation** :
- Si `EntryZoneOutOfBoundsException` levée :
  - Appelle `ZoneSkipPersistenceService::persist()`
  - Persiste dans `trade_zone_events` :
    - `reason='skipped_out_of_zone'` ou `'zone_far_from_market'`
    - `symbol`, `timeframe`, `config_profile`
    - `zone_min`, `zone_max`, `candidate_price`
    - `zone_dev_pct`, `zone_max_dev_pct`
    - `atr_pct`, `volume_ratio`, `spread_bps`

**Cas d'erreur** :
- Erreur DB → log error (non bloquant)

---

## Résumé des statuts finaux

### Statuts possibles

1. **`submitted`** : Ordre placé avec succès sur Bitmart
   - `client_order_id` et `exchange_order_id` présents
   - Watchers programmés
   - MtfSwitch désactivé 4h

2. **`simulated`** : Ordre simulé (dry_run=true)
   - Aucun ordre réel placé
   - Plan complet calculé

3. **`skipped`** : Ordre skippé avec raison structurée
   - `reason` dans `raw.reason`
   - Événement persisté

4. **`error`** : Erreur lors de l'exécution
   - `reason` peut être présent dans `raw.reason`
   - Exception loggée

### Raisons de skip principales

- **MTF** : `no_timeframes_in_config`, `not_enough_klines`, `pragmatic_context_all_neutral`, `no_timeframe_selected`
- **Préconditions** : `missing_execution_tf`, `execution_tf_not_allowed_by_trade_entry`, `atr_required_but_invalid`
- **Guards** : `daily_loss_limit_reached`, `leverage_below_threshold`
- **Zone** : `skipped_out_of_zone`, `zone_far_from_market`

### Raisons d'erreur principales

- **Preflight** : `market_order_spread_too_wide`, `contract_specs_unavailable`
- **Planification** : `entry_not_within_zone`, `entry_zone_filters_rejection`
- **Exécution** : `all_attempts_failed`, `market_order_submit_failed`, `market_order_fill_timeout`

---

## Glossaire

- **MTF** : Multi-Timeframe (validation multi-timeframe)
- **TF** : Timeframe (1m, 5m, 15m, 1h, 4h)
- **ATR** : Average True Range (indicateur de volatilité)
- **VWAP** : Volume Weighted Average Price
- **SMA** : Simple Moving Average
- **RSI** : Relative Strength Index
- **MACD** : Moving Average Convergence Divergence
- **R-multiple** : Ratio risque/récompense (ex: 1.3 = gain 1.3× le risque)
- **MtfSwitch** : Mécanisme de désactivation temporaire d'un symbole
- **Decision Key** : Clé unique par décision de trading (idempotence)

