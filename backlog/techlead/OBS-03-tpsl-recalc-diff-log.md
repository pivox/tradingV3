# OBS-03 — Log diff avant/après dans `TpSlTwoTargetsService` lors d'annulation

**Epic :** Observabilité & Traçabilité
**Priorité :** Moyenne
**Effort estimé :** XS
**Dépendances :** OBS-01

---

## Contexte (PO)

Quand le service annule les TP/SL existants et en repose de nouveaux, les logs
actuels montrent uniquement les nouvelles valeurs (`sl`, `tp1`, `tp2`). En cas de
recalcul défavorable, il est impossible de savoir ce qui a été annulé et quel a
été l'impact potentiel sur le P&L non réalisé.

---

## Analyse technique (Architecte)

Dans `TpSlTwoTargetsService`, avant l'annulation des ordres existants (L.500-597),
les prix actuels des ordres TP/SL sont disponibles. Il faut les capturer avant
suppression et les inclure dans le log post-soumission.

**Structure de log cible :**

```json
{
  "event": "tpsl.recalc.diff",
  "decision_key": "recalc:te:BTCUSDT:a1b2c3",
  "previous": { "sl": 98.50, "tp1": 102.00, "tp2": 104.00 },
  "new":      { "sl": 97.80, "tp1": 101.20, "tp2": 103.50 },
  "delta_tp1_pct": -0.0078,
  "delta_sl_pct": -0.0072
}
```

**Règle de niveau de log :**

- `delta_tp1_pct < 1%` → niveau `INFO`
- `delta_tp1_pct >= 1%` → niveau `WARNING`
- Aucun ordre existant trouvé → flag `first_attach: true`, niveau `INFO`

**Fichiers concernés :**

| Fichier | Localisation |
|---------|-------------|
| `src/TradeEntry/Service/TpSlTwoTargetsService.php` | L.500-597 — bloc d'annulation |

---

## Critères d'acceptance (PO)

- [ ] Chaque appel au recalcul TP/SL produit un message `tpsl.recalc.diff`
      contenant les champs `previous` et `new`
- [ ] Les champs `delta_tp1_pct` et `delta_sl_pct` sont présents et calculés
      correctement (valeur relative, signée)
- [ ] Si aucun ordre existant n'est trouvé à annuler, `previous` est `null`
      et `first_attach: true` est présent
- [ ] Le niveau du log respecte la règle : `INFO` si delta < 1%, `WARNING` sinon
