# PNL-02 — Relever le `r_multiple` à 1.8 minimum dans scalper_micro et scalper

**Epic :** Amélioration de la courbe P&L
**Priorité :** Critique
**Effort estimé :** XS
**Dépendances :** aucune

---

## Contexte (PO)

Le breakeven mathématique d'un système de trading est `win_rate_min = 1 / (1 + R)`.

| R | Breakeven brut | Breakeven après frais (0.10% A/R) |
|---|---|---|
| 1.3 | 43.5% | ~47% |
| 1.8 | 35.7% | ~38% |
| 2.0 | 33.3% | ~36% |

En scalping haute fréquence, maintenir un win rate stable au-dessus de 47% est
extrêmement difficile. Le système est structurellement sous pression à R=1.3.
À R=1.8, le breakeven descend à ~38% — nettement plus réaliste.

Ce ticket est un **changement de configuration pure**, sans modification de code.
Il peut être mergé en production immédiatement.

---

## Analyse technique (Architecte)

Changements YAML uniquement :

| Fichier | Paramètre | Valeur actuelle | Valeur cible |
|---------|-----------|-----------------|--------------|
| `trade_entry.scalper_micro.yaml` | `defaults.r_multiple` | 1.3 | 1.8 |
| `trade_entry.scalper_micro.yaml` | `defaults.tp1_r` | 1.2 | 1.5 |
| `trade_entry.scalper.yaml` | `defaults.r_multiple` | 1.3 | 1.8 |
| `trade_entry.scalper.yaml` | `defaults.tp1_r` | 1.0 | 1.4 |

**Invariant à respecter :** `tp1_r < r_multiple` en toutes circonstances.

**Impact à surveiller :** un R plus élevé repousse le TP, ce qui peut réduire le
taux de fill sur des symboles peu volatils. Surveiller le ratio
`orders_placed / symbols_validated` pendant les 48h suivant le déploiement.

**Fichiers concernés :**

| Fichier | Localisation |
|---------|-------------|
| `config/app/trade_entry.scalper_micro.yaml` | section `defaults` |
| `config/app/trade_entry.scalper.yaml` | section `defaults` |

---

## Critères d'acceptance (PO)

- [ ] `r_multiple >= 1.8` dans les deux profils en production
- [ ] `tp1_r < r_multiple` dans les deux profils (cohérence interne)
- [ ] Le version tag des deux fichiers YAML est incrémenté
- [ ] Sur les 48h post-déploiement, le ratio `orders_placed / symbols_validated`
      ne baisse pas de plus de 15% (surveillance manuelle)
- [ ] Sur les trades fermés dans les 48h, le `pnl_R` moyen dans les logs
      `POSITION_CLOSED` est >= 1.4 (vs < 1.0 constaté actuellement)
- [ ] Aucune augmentation du taux de rejet `entry_not_within_zone`
      (le TP plus loin ne doit pas affecter la zone d'entrée)
