# OBS-01 — Propagation du `decision_key` dans le recalcul TP/SL

**Epic :** Observabilité & Traçabilité
**Priorité :** Haute
**Effort estimé :** S
**Dépendances :** aucune

---

## Contexte (PO)

Quand un recalcul TP/SL est déclenché toutes les 3 minutes par `MtfRunnerService`,
les logs générés sont orphelins : impossible de savoir à quel ordre d'entrée ils
appartiennent. Un opérateur qui surveille une position ne peut pas corréler
`[MTF Runner] TP/SL recalculation: completed` avec le `te:BTCUSDT:a1b2c3` initial.

---

## Analyse technique (Architecte)

Le problème est localisé dans `MtfRunnerService.php` ~L.1068.
Le `TpSlTwoTargetsRequest` est construit sans decision_key de l'entrée originale :

```php
// Actuel — decision_key généré à la volée, sans lien avec l'entrée
$result = $this->tpSlService->__invoke($tpSlRequest, 'mtf_runner_' . time());
```

La `Position` entity en base contient un champ `payload` (JSON) peuplé à l'ouverture
dans `TradeEntryService`. Le `decision_key` d'entrée doit y être stocké pour être
récupérable lors des recalculs.

**Chemin de correction :**

1. À l'ouverture (`TradeEntryService` ~L.307), persister `decision_key` dans
   `Position.payload['trade_entry_decision_key']`
2. Dans `MtfRunnerService.processTpSlRecalculation()`, lire
   `Position.payload['trade_entry_decision_key']`
3. Passer cette valeur comme `$decisionKey` à `TpSlTwoTargetsService.__invoke()`
4. Préfixer avec le type d'appel : `recalc:{original_key}` pour distinguer
   entrée vs recalcul dans les logs

**Fichiers concernés :**

| Fichier | Localisation |
|---------|-------------|
| `src/TradeEntry/Service/TradeEntryService.php` | ~L.307 — persistance du decision_key |
| `src/MtfRunner/Service/MtfRunnerService.php` | ~L.1068 — lecture et transmission |
| `src/Entity/Position.php` | payload JSON schema |

---

## Critères d'acceptance (PO)

- [ ] Dans les logs `positions`, chaque message de recalcul TP/SL contient un champ
      `decision_key` de la forme `recalc:te:{symbol}:{hex}`
- [ ] On peut filtrer `grep "recalc:te:BTCUSDT:a1b2c3"` et retrouver toute la chaîne :
      entrée → recalcul(s) → fermeture
- [ ] Un recalcul sans `decision_key` en base loggue un warning explicite
      `tpsl_recalc.missing_origin_key` mais ne plante pas
- [ ] Test unitaire vérifiant que le `decision_key` stocké à l'entrée est retrouvé
      au recalcul
