# PNL-06 — Alignement `initial_margin_usdt` / `fixed_usdt_if_available` dans scalper_micro

**Epic :** Amélioration de la courbe P&L
**Priorité :** Haute
**Effort estimé :** XS
**Dépendances :** aucune

---

## Contexte (PO)

Dans `trade_entry.scalper_micro.yaml`, deux paramètres définissent le budget mais
sont incohérents l'un avec l'autre :

- `defaults.initial_margin_usdt: 50` → utilisé pour calculer le risque, la taille
  et le levier dans `PositionSizer` et `DynamicLeverageService`
- `entry.budget.fixed_usdt_if_available: 77` → budget réel alloué à l'ordre

`OrderPlanBuilder` calcule la quantité pour risquer X% de 50 USDT. Mais l'ordre
est soumis avec jusqu'à 77 USDT de marge réelle. La position ouverte est **54%
plus grande** que ce que le calcul de risque prévoit. Chaque SL touché perd donc
1.54× le risque calculé — sans que l'opérateur en soit conscient.

---

## Analyse technique (Architecte)

**Localisation :** `config/app/trade_entry.scalper_micro.yaml`

```yaml
# Valeurs actuelles — incohérentes
defaults:
    initial_margin_usdt: 50.0          # ← base du calcul de risque

entry:
    budget:
        fixed_usdt_if_available: 77    # ← budget réel, 54% de plus
```

Dans `OrderPlanBuilder.php` L.55 :

```php
$availableBudget = min(max($req->initialMarginUsdt), max($pre->availableUsdt));
```

`initialMarginUsdt` vient des `defaults`, tandis que `fixed_usdt_if_available`
est lu dans un chemin différent pour déterminer combien allouer. L'incohérence
est une erreur de configuration, pas un bug de code.

**Deux options :**

| Option | Action | Recommandation |
|--------|--------|----------------|
| A (prudente) | Baisser `fixed_usdt_if_available` à 50 | **Recommandée** |
| B (agressive) | Monter `initial_margin_usdt` à 77 | Augmente le risque absolu |

Sur 50 USDT de marge avec `exchange_cap: 20`, le notionnel max est 1000 USDT —
suffisant pour passer les `min_size` des contrats Bitmart (vérifier les 140 top
contrats actifs).

**Fichiers concernés :**

| Fichier | Lignes concernées |
|---------|------------------|
| `config/app/trade_entry.scalper_micro.yaml` | `defaults.initial_margin_usdt` et `entry.budget.fixed_usdt_if_available` |

---

## Critères d'acceptance (PO)

- [ ] `initial_margin_usdt` == `fixed_usdt_if_available` dans
      `trade_entry.scalper_micro.yaml` après correction
- [ ] Le log `order_plan.budget_check` montre des valeurs cohérentes entre
      `initial_margin_usdt` et `available_budget` (écart < 5%)
- [ ] Sur les 10 prochains trades scalper_micro, la marge réelle de chaque
      position est ≤ `initial_margin_usdt + 5%` (tolérance quantization exchange)
- [ ] Le version tag du fichier YAML est incrémenté
- [ ] Vérification manuelle que les 140 contrats top peuvent être tradés avec
      50 USDT de marge (pas de rejet `min_size` en hausse)
