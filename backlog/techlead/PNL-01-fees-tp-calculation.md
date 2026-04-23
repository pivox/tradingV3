# PNL-01 — Intégrer les frais d'exchange dans le calcul du TP

**Epic :** Amélioration de la courbe P&L
**Priorité :** Critique
**Effort estimé :** M
**Dépendances :** aucune

---

## Contexte (PO)

Chaque trade paie ~0.05% à l'entrée et ~0.05% à la sortie, soit 0.10%
aller-retour. Avec un `r_multiple` à 1.3, le TP cible couvre théoriquement 1.3×
le risque, mais net des frais, le gain effectif est réduit. Sur des petits R
(scalper_micro avec `atr_k: 4.0`), les frais peuvent représenter 10 à 20% du
gain brut attendu. C'est une fuite systématique présente sur **100% des trades**.

---

## Analyse technique (Architecte)

**Localisation de la correction :** `TakeProfitCalculator.php` méthode
`fromRMultiple()` L.24-35.

Formule actuelle :

```php
$tp = $side === Side::Long
    ? $entry + $rMultiple * $riskUnit
    : $entry - $rMultiple * $riskUnit;
```

Le TP doit être ajusté pour que le gain net couvre R fois le risque **après frais**.
La correction consiste à déplacer le seuil de rentabilité :

```
fee_cost      = entry × (fee_entry_rate + fee_exit_rate)
tp_adjusted   = tp_theoretical ± fee_cost
```

**Design :**

1. Créer un `FeeConfig` value object `(maker_fee_rate: float, taker_fee_rate: float)`
   injecté depuis la config exchange
2. `TakeProfitCalculator` reçoit `FeeConfig` en paramètre optionnel
   (rétrocompatibilité garantie : comportement inchangé si absent)
3. Appliquer l'ajustement dans `fromRMultiple()` et `fromPivotConservative()`
4. `PositionSizer.fromRiskAndDistance()` ne change pas — le sizing reste basé
   sur le risque brut ; c'est uniquement le prix cible TP qui est repoussé
5. Ajouter `expected_fee_usdt` dans le log `build_order_plan.ready`

**Fichiers concernés :**

| Fichier | Action |
|---------|--------|
| `src/TradeEntry/RiskSizer/TakeProfitCalculator.php` | Ajout param FeeConfig + formule |
| `src/TradeEntry/OrderPlan/OrderPlanBuilder.php` | Injection FeeConfig |
| `src/TradeEntry/Service/TpSlTwoTargetsService.php` | Même correction L.179 |
| `config/app/trade_entry.scalper_micro.yaml` | Ajout section `fees:` |
| `config/app/trade_entry.scalper.yaml` | Ajout section `fees:` |
| `config/app/trade_entry.regular.yaml` | Ajout section `fees:` |

**Structure YAML cible :**

```yaml
fees:
    maker_rate: 0.0005   # 0.05%
    taker_rate: 0.0005   # 0.05%
```

---

## Critères d'acceptance (PO)

- [ ] Pour un trade long avec `entry=100`, `stop=98`, `r=1.3`, `fee_rate=0.001`,
      le TP calculé est strictement supérieur à 102.6 (valeur brute sans frais)
- [ ] Le champ `expected_fee_usdt` est présent dans chaque log `build_order_plan.ready`
- [ ] Sans `FeeConfig` configuré, le comportement est identique à aujourd'hui
      (aucune régression)
- [ ] Test unitaire couvrant 3 cas : long avec frais, short avec frais,
      sans frais configuré
- [ ] Les 3 profils YAML ont une section `fees` documentée avec les taux Bitmart
