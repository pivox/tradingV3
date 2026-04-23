# PNL-04 — Ajout d'un cushion de prix lors du fallback market (end-of-zone)

**Epic :** Amélioration de la courbe P&L
**Priorité :** Haute
**Effort estimé :** M
**Dépendances :** aucune

---

## Contexte (PO)

Quand un ordre limit bascule en market (zone expirée ou spread trop large via
`applyEndOfZoneFallback`), l'ordre est soumis au prix de marché courant sans
validation du slippage effectif. Le TP et le SL ont été calculés sur le prix
limit d'origine. Si le fill market est 10-20 bps moins favorable, le TP effectif
se retrouve réduit d'autant — sans que personne ne le sache, car aucun log ne
compare les deux prix.

---

## Analyse technique (Architecte)

**Localisation :** `ExecutionBox.executeMarketOrder()` L.568-710.

Pour les ordres market, le TP/SL est calculé **après** le fill via
`fetchPositionEntryPrice()` — c'est correct. Le problème survient quand le prix
de fill réel s'écarte trop du prix limit planifié : le TP/SL est recalculé sur
une base dégradée sans que la position soit rejetée.

**Correction en 3 étapes :**

1. Après récupération du `entryPrice` réel (L.662), calculer le slippage effectif :
   ```
   slippage_bps = abs(fill_price - planned_price) / planned_price × 10 000
   ```

2. Si `slippage_bps > max_acceptable_slippage_bps` (config
   `fallback_end_of_zone.max_slippage_bps`) :
   - Ne pas attacher TP/SL
   - Fermer la position immédiatement en market
   - Logguer `execution.market_order.slippage_abort` avec le slippage mesuré

3. Logguer systématiquement `planned_entry_price`, `actual_fill_price`,
   `slippage_bps` quel que soit le résultat.

**Valeurs `max_slippage_bps` recommandées par profil :**

| Profil | Valeur suggérée |
|--------|----------------|
| scalper_micro | 8 bps |
| scalper | 15 bps |
| regular | 20 bps |

**Fichiers concernés :**

| Fichier | Localisation |
|---------|-------------|
| `src/TradeEntry/Execution/ExecutionBox.php` | L.620-710 |
| `src/TradeEntry/Service/TpSlTwoTargetsService.php` | Aucun changement direct |
| `config/app/trade_entry.*.yaml` | `fallback_end_of_zone.max_slippage_bps` |

---

## Critères d'acceptance (PO)

- [ ] Chaque fill sur ordre market loggue `planned_entry_price`,
      `actual_fill_price`, `slippage_bps`
- [ ] Si `slippage_bps > max_slippage_bps`, la position est fermée immédiatement
      avec log `execution.market_order.slippage_abort` contenant le slippage mesuré
- [ ] Le TP/SL est toujours basé sur le prix réel du fill (jamais sur le prix
      limit planifié)
- [ ] `max_slippage_bps` est configurable indépendamment par profil
- [ ] Test unitaire : fill dans tolérance → TP/SL posés normalement ;
      fill hors tolérance → position fermée, pas de TP/SL soumis
