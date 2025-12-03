# Analyse des Pertes - Profil Scalper

**Date:** 2025-11-24  
**Profil:** `trade_entry.scalper.yaml`

## Résumé Exécutif

Toutes les positions fermées affichées dans l'interface sont en perte. Cette analyse identifie les causes probables et propose des solutions.

---

## Configuration Actuelle (Scalper)

### Paramètres Clés

```yaml
r_multiple: 1.5          # TP = 1.5x la distance du SL
tp1_r: 1.3               # TP1 = 1.3x la distance du SL
atr_k: 1.5               # Stop Loss = ATR * 1.5
risk_pct_percent: 2.0    # Risque de base 2%
fixed_risk_pct: 2.5      # Risque fixe par trade 2.5%
```

### Analyse des Logs (2025-11-24)

D'après les logs `positions-2025-11-24.log`, voici des exemples de plans d'ordre générés :

#### Exemple 1: ETHUSDT (Short)
- Entry: 2823.48
- Stop: 2837.66
- TP: 2793.93
- **Distance SL:** (2837.66 - 2823.48) / 2823.48 = **0.50%**
- **Distance TP:** (2823.48 - 2793.93) / 2823.48 = **1.05%**
- **R-multiple réel:** 1.05 / 0.5 = **2.1** (supérieur à config 1.5)

#### Exemple 2: BNBUSDT (Short)
- Entry: 847.52
- Stop: 851.77
- TP: 839.02
- **Distance SL:** (851.77 - 847.52) / 847.52 = **0.50%**
- **Distance TP:** (847.52 - 839.02) / 847.52 = **1.00%**
- **R-multiple réel:** 1.0 / 0.5 = **2.0**

#### Exemple 3: LTCUSDT (Short)
- Entry: 83.55
- Stop: 83.97
- TP: 82.71
- **Distance SL:** (83.97 - 83.55) / 83.55 = **0.50%**
- **Distance TP:** (83.55 - 82.71) / 83.55 = **1.00%**
- **R-multiple réel:** 1.0 / 0.5 = **2.0**

### Observations

1. **Stops Loss cohérents:** Tous les SL sont à ~0.5% de l'entrée (ATR * 1.5)
2. **R-multiple réel > config:** Les TP calculés donnent un R-multiple de 2.0-2.1, supérieur à la config 1.5
3. **Toutes les positions en perte:** Indique un **winrate très faible** (< 40%)

---

## Causes Probables

### 1. Winrate Insuffisant pour R-Multiple 1.5

**Mathématique:**
- Pour un R-multiple de 1.5, le winrate minimum requis est: `1 / (1 + 1.5) = 40%`
- Si le winrate réel est < 40%, toutes les positions seront en perte sur le long terme

**Hypothèse:** Le winrate actuel est probablement < 30-35%, ce qui explique les pertes systématiques.

### 2. Stops Loss Potentiellement Trop Serrés

**Problème:**
- ATR * 1.5 peut être insuffisant sur timeframes courts (1m, 5m)
- Le bruit du marché peut facilement déclencher le SL avant que le mouvement ne se développe
- Buffer pivot de 0.3% peut être insuffisant

**Exemple:**
- Si ATR = 0.33% du prix, SL = 0.33% * 1.5 = 0.5%
- Sur un timeframe 1m, une oscillation normale peut toucher 0.5%

### 3. Entrées en Fin de Zone

**Configuration:**
```yaml
zone_max_deviation_pct: 0.02  # 2% de déviation max
k_atr: 0.24                    # Zone = VWAP ± (ATR * 0.24)
```

**Problème:**
- Les entrées peuvent être trop tardives (prix déjà monté/descendu)
- Le momentum initial est déjà épuisé
- Plus de risque de retournement immédiat

### 4. Filtres MTF Peu Stricts

**Configuration actuelle:**
- Timeframes courts (1m, 5m) acceptés
- Conditions de validation relativement permissives
- Pas de filtre de tendance forte obligatoire sur 1m

**Impact:**
- Beaucoup de faux signaux
- Entrées dans des conditions de marché défavorables

---

## Solutions Proposées

### Solution 1: Augmenter le R-Multiple (RECOMMANDÉ)

**Changement:**
```yaml
r_multiple: 2.0    # Au lieu de 1.5
tp1_r: 1.8         # Au lieu de 1.3
```

**Avantages:**
- Réduit le winrate minimum requis à 33.3% (au lieu de 40%)
- Plus de marge pour les pertes
- Meilleur ratio risque/récompense

**Inconvénients:**
- Moins de trades gagnants (mais plus rentables)
- TP plus éloignés, donc moins de trades qui atteignent le TP

### Solution 2: Élargir les Stops Loss

**Changement:**
```yaml
atr_k: 2.0         # Au lieu de 1.5
pivot_sl_buffer_pct: 0.005  # Au lieu de 0.003 (0.5% au lieu de 0.3%)
```

**Avantages:**
- Moins de SL déclenchés par le bruit
- Plus de marge pour les oscillations normales

**Inconvénients:**
- Risque par trade plus élevé
- Nécessite d'ajuster le position sizing

### Solution 3: Améliorer la Sélection des Entrées

**Changements:**
```yaml
# Reserrer les zones d'entrée
k_atr: 0.20        # Au lieu de 0.24 (zones plus précises)
zone_max_deviation_pct: 0.015  # Au lieu de 0.02 (moins de déviation)

# Ajouter des filtres MTF plus stricts
# (dans validations.yaml)
filters_mandatory:
  - adx_min_for_trend: 20  # Forcer tendance forte
  - volume_ratio_gt: 1.2       # Volume supérieur à la moyenne
```

**Avantages:**
- Meilleure qualité des entrées
- Winrate amélioré
- Moins de faux signaux

**Inconvénients:**
- Moins de trades (mais meilleure qualité)

### Solution 4: Combinaison (RECOMMANDÉ)

**Changements combinés:**
```yaml
# Risk/Reward
r_multiple: 2.0
tp1_r: 1.8

# Stop Loss
atr_k: 1.8         # Compromis entre 1.5 et 2.0
pivot_sl_buffer_pct: 0.004  # 0.4% au lieu de 0.3%

# Entry Zone
k_atr: 0.22        # Légèrement resserré
zone_max_deviation_pct: 0.018  # Légèrement resserré
```

---

## Plan d'Action Recommandé

### Phase 1: Ajustement Immédiat (R-Multiple)

1. **Augmenter R-multiple à 2.0**
   - Fichier: `config/app/trade_entry.scalper.yaml`
   - Ligne 24: `r_multiple: 2.0`
   - Ligne 25: `tp1_r: 1.8`

2. **Tester sur 20-30 trades**
   - Monitorer le winrate
   - Vérifier que les TP sont atteints plus souvent

### Phase 2: Ajustement des Stops (si Phase 1 insuffisant)

1. **Élargir légèrement les stops**
   - `atr_k: 1.8`
   - `pivot_sl_buffer_pct: 0.004`

2. **Monitorer les SL déclenchés**
   - Vérifier que moins de SL sont touchés prématurément

### Phase 3: Amélioration de la Sélection (si Phase 1+2 insuffisant)

1. **Reserrer les zones d'entrée**
2. **Ajouter des filtres MTF plus stricts**
3. **Réduire les timeframes acceptés** (ex: exclure 1m sauf conditions exceptionnelles)

---

## Métriques de Succès

### Objectifs

- **Winrate:** > 35% (minimum pour R-multiple 2.0)
- **PnL moyen:** > 0 USDT par trade
- **Ratio Wins/Losses:** > 0.5 (1 win pour 2 losses max)

### Monitoring

- Suivre le winrate sur 50+ trades
- Analyser les causes de perte (SL trop serré vs mauvais timing)
- Ajuster progressivement selon les résultats

---

## Notes Techniques

### Calcul R-Multiple

```
R-multiple = Distance TP / Distance SL

Exemple:
- Entry: 100
- SL: 99.5 (distance 0.5%)
- TP: 101.0 (distance 1.0%)
- R-multiple = 1.0 / 0.5 = 2.0
```

### Winrate Minimum Requis

```
Winrate_min = 1 / (1 + R-multiple)

Exemples:
- R-multiple 1.5 → Winrate min: 40%
- R-multiple 2.0 → Winrate min: 33.3%
- R-multiple 2.5 → Winrate min: 28.6%
```

### Impact du Winrate

Si winrate = 30% et R-multiple = 1.5:
- Espérance = (0.30 * 1.5) - (0.70 * 1.0) = 0.45 - 0.70 = **-0.25** (perte)

Si winrate = 30% et R-multiple = 2.0:
- Espérance = (0.30 * 2.0) - (0.70 * 1.0) = 0.60 - 0.70 = **-0.10** (perte, mais moins)

Si winrate = 35% et R-multiple = 2.0:
- Espérance = (0.35 * 2.0) - (0.65 * 1.0) = 0.70 - 0.65 = **+0.05** (profit)

---

## Conclusion

**Cause principale:** Winrate insuffisant (< 40%) pour le R-multiple actuel (1.5)

**Solution immédiate:** Augmenter R-multiple à 2.0 pour réduire le winrate minimum requis à 33.3%

**Solution long terme:** Améliorer la sélection des entrées pour augmenter le winrate réel à > 35%

















