# Référence YAML — `trade_entry*.yaml` (TradeEntry)

Cette page documente **les clés, types, valeurs acceptées, valeurs par défaut et comportements** des fichiers :

- `trading-app/config/app/trade_entry.yaml` (fallback)
- `trading-app/config/app/trade_entry.<mode>.yaml` (profils)

Elle complète `docs/trading-app/06-trade-entry.md` (formules SL/TP/levier/zone) avec une vue **“schéma YAML / valeurs possibles”**.

## Résolution du fichier (mode → YAML)

Le mode actif est résolu par `TradeEntryModeContext` :

- si un `mode` explicite est fourni (API/worker) → il est utilisé tel quel
- sinon : le premier mode `enabled: true` (trié par `priority`) dans `trading-app/config/services.yaml`
- sinon fallback : `app.trade_entry_default_mode` (dans `trading-app/config/services.yaml`)

Le fichier est résolu par `TradeEntryConfigProvider` :

- mapping interne :
  - `regular` → `trade_entry.regular.yaml` (si présent) sinon fallback `trade_entry.yaml`
  - `scalping` → `trade_entry.scalper.yaml` (si présent)
- sinon : pattern `trade_entry.<mode>.yaml` (ex: `trade_entry.scalper_micro.yaml`, `trade_entry.crash.yaml`)

Sources :

- `trading-app/src/Config/TradeEntryModeContext.php`
- `trading-app/src/Config/TradeEntryConfigProvider.php`
- `trading-app/config/services.yaml`

## Structure racine

Clés racine attendues :

- `version` (string) : uniquement utilisé pour traçabilité (et par certains caches ailleurs dans le projet).
- `meta` (map, optionnel) : descriptif humain.
- `trade_entry` (map) : bloc principal consommé par `TradeEntryConfig`.

## `trade_entry.defaults` (paramètres “généraux”)

Ces valeurs sont lues par :

- `TradeEntryRequestBuilder` (construction de la requête MTF → TradeEntry)
- `OrderPlanBuilder` (via `TradeEntryRequest`, pour entry/SL/TP/levier)
- `TpSlTwoTargetsService` (SL/TP post-entrée)
- `DynamicLeverageService` (levier dynamique)

### Risque / budget

- `risk_pct_percent` (float)  
  - conversion : si `> 1.0` alors `risk_pct = risk_pct_percent/100`, sinon utilisé tel quel
  - utilisé dans :
    - `TradeEntryRequestBuilder` (base du `riskPct` du trade)
    - `DynamicLeverageService` (base du levier = `riskPct/stopPct`)

- `timeframe_multipliers` (map tf→float)  
  - utilisé **uniquement** par `DynamicLeverageService` pour moduler le **levier** selon `executionTf`
  - valeur par défaut : `1.0` si la clé TF est absente

- `initial_margin_usdt` (float)  
  - budget cible pour le plan (borné par le solde dispo)

- `fallback_account_balance` (float)  
  - utilisé uniquement si `initial_margin_usdt <= 0` (fallback : `initial_margin = fallback_account_balance * riskPct`)

### Stop loss (source + paramètres)

- `stop_from` (string) : **valeurs fonctionnelles**
  - `risk` : stop calculé à partir du risque USDT et de la taille (méthode “risk”)
  - `atr` : stop basé sur ATR (ATR obligatoire)
  - `pivot` : stop basé sur pivots (si pivots dispo)
  - valeur par défaut côté builder : `risk`

Comportements importants :

- si `stop_from = atr` et ATR manquant/≤0 :
  - `TradeEntryRequestBuilder` **rejette** la requête (retourne `null`)
  - `OrderPlanBuilder` lève une exception si on arrive quand même avec `stopFrom=atr` et ATR invalide

- `stop_fallback` (string) : **valeurs acceptées**
  - `atr` | `risk` | `none`
  - si valeur inconnue → forcé à `atr` (normalisation dans `TradeEntryRequestBuilder`)
  - utilisé uniquement quand `stop_from = pivot` et que le stop pivot est jugé “trop loin”

- `atr_k` (float) : multiplicateur ATR du stop (utilisé si `stop_from=atr` ou fallback `stop_fallback=atr`)

- `pivot_sl_policy` (string) : **valeurs acceptées** (normalisées dans `StopLossCalculator::fromPivot`)
  - familles “nearest” : `nearest`, `nearest_below`, `nearest_above`
  - familles “strongest” : `strongest`, `strongest_below`, `strongest_above`
  - sélection explicite : `s1`..`s6` (Long), `r1`..`r6` (Short)
  - note : `nearest_below/above` et `strongest_below/above` sont **des alias** et se comportent comme `nearest` / `strongest`

- `pivot_sl_buffer_pct` (float)
  - si `< 0` → traité comme `null` (donc fallback interne)
  - appliqué “autour du pivot” :
    - Long : `stop = pivot * (1 - bufferPct)`
    - Short : `stop = pivot * (1 + bufferPct)`
  - fallback interne si `null` : `0.0015` (0.15%)

- `pivot_sl_min_keep_ratio` (float)
  - si `<= 0` → traité comme `null`
  - appliqué comme garde sur la distance pivot/entry (si fourni)

- `sl_full_size` (bool)
  - utilisé par `TpSlTwoTargetsService` (gestion SL/TP2)

### Take profit (TP)

- `r_multiple` (float) : multiple “R” de TP (TP théorique = entry ± `r_multiple` * |entry-stop|)
- `tp_policy` (string)
  - lu et loggé, mais **n’influe pas** sur le choix du pivot dans `TakeProfitCalculator::alignTakeProfitWithPivot` (le choix est “le plus proche pivot au-delà du TP théorique”)
  - donc : toutes les valeurs string sont acceptées, mais ne changent pas la sélection actuelle

- `tp_buffer_pct` (float|null)
  - si `<= 0` → forcé à `null`
  - Long : TP pivot légèrement *en dessous* de la résistance (`(1 - bufferPct)`)
  - Short : TP pivot légèrement *au-dessus* du support (`(1 + bufferPct)`)

- `tp_buffer_ticks` (int|null)
  - si `<= 0` → forcé à `null`
  - Long : TP pivot - `bufferTicks * tick`
  - Short : TP pivot + `bufferTicks * tick`

- `tp_min_keep_ratio` (float) : garde “ne pas dégrader trop le R” (voir `06-trade-entry.md`)
- `tp_max_extra_r` (float|null)
  - si `< 0` → forcé à `null`
  - cap : `R_effectif <= R_théorique + tp_max_extra_r`

### Ordres (type / mode / openType)

- `order_type` (string)
  - `OrderPlanBuilder` traite **uniquement** la valeur exacte `limit` comme limit ; toute autre valeur est traitée comme “market”
  - valeurs recommandées : `limit` | `market`

- `open_type` (string)
  - transmis à l’exchange via `submitLeverage(symbol, leverage, openType)`
  - le code ne valide pas l’énumération ; les valeurs possibles dépendent de l’API BitMart (courant : `isolated` / `cross`)

- `order_mode` (int)
  - transmis à BitMart comme `mode`
  - valeurs observées/mentionnées dans le code :
    - `1` : taker (utilisé pour market et certains limits)
    - `3` : IOC (utilisé par fallback fin de zone, `ExecutionBox`)
    - `4` : maker-only (mentionné dans les commentaires)
  - aucune validation stricte : tout int est accepté (risque de rejet côté exchange)

### Gardes prix (entrée)

Ces champs alimentent `OrderPlanBuilder` (limit-entry) :

- `market_max_spread_pct` (float)
  - conversion : si `> 1.0` alors `/100`
  - utilisé uniquement si `order_type=market` : `PreTradeChecks` bloque si `spreadPct > market_max_spread_pct`

- `inside_ticks` (int) : fallback “ancien” si pas de zone exploitable
- `max_deviation_pct` (float) : clamp autour du mark (valeur >1 traitée comme pourcentage dans `OrderPlanBuilder::normalizePercent`)
- `implausible_pct` (float) : garde “entry délirant vs mark”
- `zone_max_deviation_pct` (float) : rejette une zone si trop éloignée du prix de référence (`entry_zone.rejected_by_deviation`)

### Multiplicateurs timeframe (rôle exact des 2 maps)

Il existe **deux** multiplications “par TF”, avec des rôles distincts :

1) **Sizing / notionnel / risque / TP** (au niveau du builder)  
   - source : `trade_entry.leverage.timeframe_multipliers[executionTf]` (défaut `1.0`)  
   - effets :
     - `riskPct = (risk_pct_percent/100) * tfMult` (donc `riskUsdt` et la taille, donc le **notionnel**)
     - `rMultiple = defaults.r_multiple * tfMult` (donc le **TP**)
   - implémentation : `TradeEntryRequestBuilder`

2) **Levier** (au niveau du calcul dynamique)  
   - source : `trade_entry.defaults.timeframe_multipliers[executionTf]` (défaut `1.0`)  
   - effet : `leveragePreCaps = (riskPct/stopPct) * tfMult * volMult`  
   - implémentation : `DynamicLeverageService`

Note : `TradeEntryRequest->leverageMultiplier` est positionné à `1.0` par le builder ; `OrderPlanBuilder` ne l’applique que si un appelant le force explicitement.

Sources :

- `trading-app/src/TradeEntry/Builder/TradeEntryRequestBuilder.php`
- `trading-app/src/TradeEntry/Service/Leverage/DynamicLeverageService.php`
- `trading-app/src/TradeEntry/OrderPlan/OrderPlanBuilder.php`

## `trade_entry.risk` (gestion risque “guard”)

Clés utilisées :

- `daily_max_loss_usdt` (float) : **active** le coupe-circuit si `> 0`
- `daily_loss_count_unrealized` (bool) :
  - `true` : utilise l’`equity` si disponible
  - `false` : utilise le `availableBalance`

Clés présentes dans certains YAML mais **non consommées** par les guards actuels :

- `fixed_risk_pct`
- `daily_max_loss_pct`
- `max_concurrent_positions`

Source : `trading-app/src/TradeEntry/Policy/DailyLossGuard.php`

## `trade_entry.leverage` (levier dynamique)

Clés du bloc `trade_entry.leverage` réellement consommées :

Par `DynamicLeverageService` :

- `floor` (float) : levier minimum appliqué après clamp
- `exchange_cap` (float) : cap “soft” additionnel (avant cap exchange réel)
- `per_symbol_caps` (list)
  - items : `{ symbol_regex: string, cap: float }`
  - si `symbol_regex` matche (regex encapsulée `/(...)/i`) et `cap > 0` → `leverage <= cap`
- `rounding.mode` (string) : `ceil` | `floor` | `round` (défaut `ceil`)

Par `TradeEntryRequestBuilder` (sizing/notionnel/TP) :

- `timeframe_multipliers` (map tf→float) : modifie `riskPct` et `rMultiple` (voir section “Multiplicateurs timeframe”)

Clés présentes dans certains YAML mais **ignorées** par le code actuel :

- `mode` (ex: `dynamic_from_risk`) : lu mais non branché
- `rounding.precision` : non utilisé (le levier final est un `int`)
- `confidence_multiplier.*` : non utilisé
- `conviction.*` : non utilisé

## `trade_entry.decision` (garde “post MTF”)

Clés utilisées :

- `allowed_execution_timeframes` (list de strings)
  - si liste vide/non définie → accepte tous les TF décidés par MTF
  - sinon : rejette si `executionTf` décidé n’est pas dans la liste

Clés présentes mais **non utilisées** par `TradingDecisionHandler` :

- `require_price_or_atr` (le code vérifie déjà “price OU atr” sans lire ce flag)

Source : `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`

## `trade_entry.market_entry` (bascule limit → market)

Clés utilisées par `TradeEntryRequestBuilder` :

- `enabled` (bool) : active la bascule
- `allowed_execution_timeframes` (list) : filtre d’activation par TF (vide = tous)
- `adx_min_1h` (float|null) : si défini, exige `metrics['adx_1h'] >= adx_min_1h`

Notes :

- `max_slippage_bps` est loggé mais **non appliqué** dans la décision.
- le builder force `orderType='market'` si conditions OK, sinon garde `order_type` des defaults.

Source : `trading-app/src/TradeEntry/Builder/TradeEntryRequestBuilder.php`

## `trade_entry.fallback_end_of_zone` (fin de zone → taker)

Clés utilisées (via `TradeEntryConfig::getFallbackEndOfZoneConfig`) :

- `enabled` (bool, défaut `true`)
- `ttl_threshold_sec` (int, défaut `25`)
- `max_spread_bps` (float, défaut `8`)
- `only_if_within_zone` (bool, défaut `true`)
- `taker_order_type` (string, défaut `market`)
  - si `market` : `TradeEntryService` force `order_mode=1`
  - sinon : le plan reste un limit (le code ne valide pas l’énumération)
- `max_slippage_bps` (float, défaut `10`)

Source : `trading-app/src/TradeEntry/Execution/ExecutionBox.php`

## `trade_entry.post_validation.entry_zone` et `trade_entry.entry.entry_zone`

La zone est calculée par `EntryZoneCalculator` avec fusion :

- `post_validation.entry_zone` (base)
- `entry.entry_zone` (override prioritaire)

Clés lues (toutes optionnelles) :

- `pivot_tf` (string) : timeframe pour VWAP/SMA21 (défaut `'5m'`)
- `offset_atr_tf` (string) : timeframe ATR (défaut = `pivot_tf`)
- `k_atr` (float) ou alias `offset_k` (float) : largeur relative à ATR (défaut `0.35`)
- `w_min` (float) : **demi-largeur** minimale relative au pivot (défaut `0.0005`)
- `w_max` (float) ou alias `max_deviation_pct` (float) : **demi-largeur** maximale relative (défaut `0.0100`)
- `from` (string) : **sélection du pivot** (`vwap` | `sma21` | `ma21`) — voir détail ci-dessous
- `vwap_anchor` (bool) : fallback uniquement si `from` absent/non reconnu (défaut `true`)
- `asym_bias` (float) : clamp dans `[0.0 .. 0.95]` (défaut `0.0`)
- `quantize_to_exchange_step` (bool, défaut `true`) : quantize des bornes si `pricePrecision` connu
- `ttl_sec` (int, défaut `240`) : TTL stocké dans l’objet `EntryZone`

### Sélection du pivot : `from` / `vwap_anchor` (comportement exact)

Le champ `from` est normalisé côté code par `trim()` + `lowercase`.  
Le système ne “valide” pas strictement la string, mais **seules certaines valeurs ont un effet fonctionnel**.

Valeurs fonctionnelles reconnues :

- `from: 'sma21'` : essaie **SMA21**, puis fallback **VWAP**
- `from: 'ma21'` : alias strict de `sma21` (même comportement)
- `from: 'vwap'` : essaie **VWAP**, puis fallback **SMA21**

Si `from` est absent ou non reconnu :

- `vwap_anchor: true` (défaut) : essaie **VWAP**, puis fallback **SMA21**
- `vwap_anchor: false` : essaie **SMA21**, puis fallback **VWAP**

Notes importantes :

- `vwap_anchor` est **ignoré** dès que `from` est reconnu (`vwap|sma21|ma21`).
- Si ni VWAP ni SMA21 n’est disponible (ou valeurs ≤0 / non finies) → la zone devient **ouverte** (`min=-∞`, `max=+∞`) avec `rationale='open zone (no pivot)'`.
- Les valeurs sont lues depuis `IndicatorProviderInterface::getListPivot(symbol, tf=pivot_tf)` :
  - VWAP : `indicators['vwap']`
  - SMA21 : `indicators['sma'][21]`

Que peut-on mettre dans `from` ?

- Recommandé :
  - `vwap` : ancrage “volume-weighted” (si VWAP dispo), fallback SMA21
  - `sma21` : ancrage “mean reversion” sur MA21, fallback VWAP
  - `ma21` : alias de `sma21` (préférer `sma21` pour éviter l’ambiguïté)
- Toute autre valeur (ex: `ema21`, `hl2`, `median`) : **aucun effet**, traité comme “absent” → `vwap_anchor` décide de l’ordre.

Exemple (micro scalper) :

```yaml
trade_entry:
  entry:
    entry_zone:
      from: 'sma21' # SMA21 d'abord, puis VWAP si SMA21 indisponible
  post_validation:
    entry_zone:
      vwap_anchor: false # sans effet ici, car `from` est reconnu
```

Clarification `max_deviation_pct` dans `entry_zone` :

- `trade_entry.*.post_validation.entry_zone.max_deviation_pct` / `trade_entry.*.entry.entry_zone.max_deviation_pct` est un **alias de** `w_max` (borne max de demi-largeur de zone).
- Ne pas confondre avec :
  - `trade_entry.defaults.zone_max_deviation_pct` : garde “zone utilisable vs prix de référence” (sinon `entry_zone.rejected_by_deviation`)
  - `trade_entry.defaults.max_deviation_pct` : clamp final de l’entrée autour du mark (dans `OrderPlanBuilder`)

Remarque :

- si pivot indisponible ou largeur invalide → zone “ouverte” (`min=-∞`, `max=+∞`) (n’empêche pas l’exécution).

Source : `trading-app/src/TradeEntry/EntryZone/EntryZoneCalculator.php`

## Blocs présents mais non consommés (état actuel)

Ces blocs peuvent exister dans les YAML mais ne sont pas lus par le code actuel :

- `trade_entry.entry.*` (budget, quantization, slippage_guard_bps, spread_guard_bps) hors `entry.entry_zone`
- `trade_entry.post_validation.idempotency.*` (non utilisé ; l’idempotence clientOrderId est générée par `IdempotencyPolicy`)
