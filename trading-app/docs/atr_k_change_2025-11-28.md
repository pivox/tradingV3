# Changement `atr_k` – 28 novembre 2025

## Contexte

- Application : `trading-app` (bot MTF / scalper).
- Avant ce changement :
  - Profil `scalper` utilisait déjà `atr_k: 2`.
  - Profils `default` (`trade_entry.yaml`) et `regular` utilisaient `atr_k: 1.5`.
  - Côté R-multiple actuel : `scalper` est sur `r_multiple: 1.5`, alors que `default` / `regular` sont sur `r_multiple: 2.0`.
- Objectif : homogénéiser la logique de stop loss ATR pour tous les modes basés sur `stop_from: 'atr'` et documenter l’impact attendu.

## Changement effectué (2025-11-28, UTC)

- Fichiers modifiés :
  - `config/app/trade_entry.yaml` : `atr_k` passé de `1.5` à `2`.
  - `config/app/trade_entry.regular.yaml` : `atr_k` passé de `1.5` à `2`.
  - `config/app/trade_entry.scalper.yaml` : déjà `atr_k: 2` (inchangé, confirmé comme référence).

- Nouveau comportement théorique pour les stops basés sur ATR :
  - Pour un **long** :
    - Stop brut ATR : `SL_ATR_raw = entry - 2 * ATR`.
    - Stop final : `SL = entry - max(2 * ATR, 0.5% * entry)` (arrondi au tick).
  - Pour un **short** :
    - Stop brut ATR : `SL_ATR_raw = entry + 2 * ATR`.
    - Stop final : `SL = entry + max(2 * ATR, 0.5% * entry)` (arrondi au tick).
  - La garde globale `MIN_STOP_DISTANCE_PCT = 0.005` (0.5 %) reste en place pour tous les modes.

## Ce qu’il faut faire (run / monitoring)

- **En exploitation / replay récent** :
  - Vérifier que les trades MTF (scalper, regular, default) utilisent bien `stop_from: 'atr'` dans la config de mode.
  - Observer sur une journée :
    - Distance moyenne entre `entry` et `stop` (en %) par trade.
    - Distance moyenne entre `entry` et `take_profit` (en %).
    - Nombre de trades qui touchent le SL vs TP.
  - Surveiller surtout les symboles volatils (shitcoins, microcaps) où l’ATR est gros : l’effet de `atr_k = 2` y est le plus visible.
  - **Script d’analyse SL** : utiliser `scripts/analyze_sl_distances.php` sur les logs positions (niveau debug) :
    - exemple : `php scripts/analyze_sl_distances.php var/log/positions-2025-11-28.log`
    - le script exploite les événements `order_plan.sl_atr_candidate`, `order_plan.sl_pivot_candidate`, `order_plan.stop_min_distance_adjusted`, `order_plan.sl_final` pour reconstruire les distances SL et vérifier les gardes (0.5 %, tick, etc.).

- **En analyse / backtest BDD** :
  - Extraire quelques trades typiques (via `trade_lifecycle_event` + `positions` + logs `positions`) avant/après ce changement :
    - comparer la distance SL/TP en %,
    - comparer le WR (win-rate) et le PnL net sur un échantillon cohérent.
  - Si besoin, tester offline des variantes `atr_k` (1.5, 1.8, 2.0) sur les mêmes runs en recalculant les stops à partir d’ATR dans la BDD.

## À quoi s’attendre

- **Stops plus larges** (quand `2 * ATR > 0.5 % * entry`) :
  - Les SL sont plus éloignés qu’avec `atr_k = 1.5`.
  - Moins de SL déclenchés par du bruit / petits spikes autour du niveau d’entrée.
  - Taille de position légèrement plus petite (risk_usdt constant, distance SL plus grande).

- **Take profit plus ambitieux en termes de prix** :
  - Avec `r_multiple = 1.5` (profil `scalper`), la distance TP ≈ `1.5 * |entry - stop|` → ≈ `3 * ATR` dans la zone où l’ATR domine.
  - Avec `r_multiple = 2.0` (profils `default` / `regular`), la distance TP ≈ `2 * |entry - stop|` → ≈ `4 * ATR`.
  - Certains trades qui auraient atteint un TP plus proche (avec `atr_k` plus petit) pourront ne pas atteindre TP avec `atr_k = 2`.

- **Profil global attendu** :
  - Moins de micro-pertes (SL très serrés) sur les mouvements bruités.
  - Des gains potentiels plus “propres”, sur des moves plus larges.
  - PnL par trade gagnant en “R” ne change pas (toujours ~1.5R), mais la fréquence des TP vs SL peut évoluer.

## Notes pour un agent IA / futur debug

- Si des trades sont bloqués au niveau du builder avec `reason=atr_required_but_invalid` (`order_journey.preconditions.blocked`), ce n’est **pas** lié à `atr_k`, mais à l’absence d’ATR valide lorsque `stop_from='atr'`.
- Pour modifier la “sévérité” globale des stops :
  - `atr_k` contrôle la profondeur relative (en ATR).
  - `MIN_STOP_DISTANCE_PCT` dans `OrderPlanBuilder` contrôle la distance minimale en % (0.5 % par défaut).
- Ce fichier documente l’état au **28/11/2025** et doit être mis à jour si `atr_k` ou la garde globale changent à nouveau.
