# Order Flow — Tracking & TP Pivot Rules

## 1. Parcours Signal → Ordre

Consulte `var/log/order-journey-YYYY-MM-DD.log*` pour suivre un symbol. Chaque étape porte `decision_key`.

```
signal_ready → trade_request.built → trade_entry.dispatch
  → preflight (fetch carnet + balance, diff mark/bid-ask)
  → plan.start → plan_builder.entry_selected (entry à ≥1 tick dans le spread)
  → plan_builder.model_ready (entry/stop/TP/size/leverage)
  → trade_entry.execution_complete (order_id Bitmart)
```

Si la zone MTF est trop éloignée, on log `plan_builder.zone_ignored`. Pour les LIMIT validées, on retrouve l’entrée dans `order_plan.model_ready` (positions.log) et l’ID exchange dans `execution_complete`.

## 2. Paramètres utiles (cfg YAML)

- `risk_pct_percent` (cfg MTF) = 5 → le Request transporte le risk en fraction (0.05).  
- `inside_ticks` (défaut 1), `max_deviation_pct` (~0.5 %), `implausible_pct` (~2 %), `zone_max_deviation_pct` (~0.7 %) : contrôlent la proximité au mark.
- `tp_policy`, `tp_buffer_pct`, `tp_buffer_ticks`, `tp_min_keep_ratio`, `tp_max_extra_r` pilotent le cadrage TP/pivots.

## 3. Logique du TP (baseline k·R + pivot)

### 3.1 R multiples

1R = distance entry-stop.  
Long : `R = Entry - Stop > 0`, `TP_kR_th = Entry + k·R`.  
Short : `R = Stop - Entry > 0`, `TP_kR_th = Entry - k·R`.  
k∈{1.5, 2.0…}. Cette cible pure R-multiple sert de référence.

### 3.2 Pivots classiques

Calculés sur session précédente (H,L,C) :  
`PP = (H+L+C)/3`, `R1 = 2·PP - L`, `S1 = 2·PP - H`, `R2 = PP + (H-L)`, `S2 = PP - (H-L)` (etc.).  
Ils sont publiés en cache/DB pour la journée.

### 3.3 Règle TP = max(k·R, pivot structurant)

*Long* :
1. Liste des résistances croissantes {PP (si Entry < PP), R1, R2, …}.  
2. TP théorique = `Entry + k·R`.  
3. Mode conservateur : prendre le premier pivot ≥ TP_théorique (sinon aggressive = garder k·R si R2 trop loin).  
4. Appliquer buffer (ex -0.1 % ou -1 tick) sous ce pivot, quantifier.  
5. Garde-fou : ne pas descendre sous `tp_min_keep_ratio * k·R`. Sinon revenir à TP_théorique.

*Short* : symétrique (pivots {PP si Entry > PP, S1, S2…}, buffer +, garde-fou).

### 3.4 Implémentation

- Exposer les pivots dans `PreflightReport` (PP, R1/S1, R2/S2...).  
- `TradeEntryRequest` comprend `tpPolicy`, `tpBufferPct`, `tpMinKeepRatio`.  
- `OrderPlanBuilder` calcule d’abord `TP_kR_th` puis l’aligne sur pivot ou garde k·R selon la politique.

## 4. Exemple

Long 2R : Entry=100, Stop=98 → `R=2`, `TP_2R_th=104`.  
Pivots : PP=101.5, R1=103.2, R2=104.8.  
Politique `pivot_conservative`, buffer -0.1 %.  
→ pivot >= 104 : R2=104.8 → TP=104.8*(1-0.001)=104.6952 (quantifié). Ratio réel ≈2.35R.

## 5. À retenir

- LIMIT se positionnent maintenant à l’intérieur du spread (`inside_ticks`).  
- Les tailles sont arrondies aux contrats (plus de `computeLeverage(int)` crash).  
- Nouveau mode TP pivot offrira des objectifs cohérents : R-multiple + niveau de marché.  
- Pour analyser une commande : `grep "mtf:SYMBOL" var/log/order-journey-info-XXXX.log`.
