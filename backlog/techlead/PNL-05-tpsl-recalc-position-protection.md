# PNL-05 — Protection du recalcul TP/SL contre la dégradation de positions en cours de fermeture

**Epic :** Amélioration de la courbe P&L
**Priorité :** Haute
**Effort estimé :** M
**Dépendances :** OBS-01

---

## Contexte (PO)

Toutes les 3 minutes, `MtfRunnerService` recalcule les TP/SL de toutes les
positions ouvertes. Le service annule **tous** les ordres TP existants avant d'en
reposer de nouveaux. Si un TP1 était en cours de fill au moment exact du recalcul,
l'annulation supprime l'ordre avant qu'il soit totalement exécuté, et la
demi-position qui aurait été clôturée profitable reste ouverte au-delà du TP
initial.

Scénario concret de perte :
```
T0     : Position ouverte, TP1=105, SL=98
T3min  : Recalculation déclenchée, prix courant=104
         → Annulation TP1@105
         → Nouveau TP1 calculé depuis prix=104 → posé à 106
T3min+ : Le marché redescend → position fermée sur SL
         → Trade qui aurait été gagnant devient perdant
```

---

## Analyse technique (Architecte)

**Localisation :** `TpSlTwoTargetsService` L.500-597 (annulation) +
`MtfRunnerService.processTpSlRecalculation()` L.965.

**Trois guards de protection à implémenter :**

### Guard 1 — Cooldown d'âge de position

Si la position a été ouverte depuis moins de `min_position_age_sec`, ne pas
déclencher le recalcul. Évite le recalcul immédiat sur une position qui vient
d'ouvrir et dont les TP/SL initiaux n'ont pas encore eu le temps de se placer.

### Guard 2 — Proximité du TP

Si `abs(current_price - tp1_price) / tp1_price < proximity_threshold`, ne pas
recalculer — le prix est en approche du TP, laisser se fermer naturellement.

### Guard 3 — Protection des TP partiellement fillés

Avant d'annuler les TP existants, fetcher leur état via le provider. Si un TP
est en état `partially_filled` (i.e. `filled_qty > 0`), ne pas l'annuler.

**Configuration à ajouter** dans `trade_entry.*.yaml` :

```yaml
tp_sl_recalc:
    min_position_age_sec: 180       # Guard 1
    tp_proximity_skip_pct: 0.003    # Guard 2 — 0.3%
    skip_if_tp_partially_filled: true  # Guard 3
```

**Fichiers concernés :**

| Fichier | Action |
|---------|--------|
| `src/MtfRunner/Service/MtfRunnerService.php` | L.965-1090 — guards 1 et 2 |
| `src/TradeEntry/Service/TpSlTwoTargetsService.php` | L.500-597 — guard 3 |
| `config/app/trade_entry.scalper_micro.yaml` | Section `tp_sl_recalc` |
| `config/app/trade_entry.scalper.yaml` | Section `tp_sl_recalc` |
| `config/app/trade_entry.regular.yaml` | Section `tp_sl_recalc` |

---

## Critères d'acceptance (PO)

- [ ] Un recalcul sur une position ouverte depuis < 180s est skippé avec log
      `tpsl_recalc.skipped_too_young`
- [ ] Un recalcul sur une position dont le prix courant est à < 0.3% du TP1
      est skippé avec log `tpsl_recalc.skipped_tp_proximity`
- [ ] Un TP en état `partially_filled` n'est pas annulé — log
      `tpsl_recalc.tp_partial_fill_preserved`
- [ ] Les trois guards sont configurables indépendamment par profil
- [ ] Test de régression : un recalcul normal (position âgée, prix loin du TP)
      fonctionne sans changement de comportement
- [ ] Test unitaire pour chacun des 3 guards (skip + log attendu)
