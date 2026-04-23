# OBS-02 — Corrélation `decision_key` ↔ événement `POSITION_CLOSED`

**Epic :** Observabilité & Traçabilité
**Priorité :** Haute
**Effort estimé :** S
**Dépendances :** OBS-01

---

## Contexte (PO)

L'événement `POSITION_CLOSED` dans `TradeLifecycleLoggerListener` contient le PnL
réalisé, les frais, MFE/MAE — c'est riche. Mais il n'a aucun lien avec le
`decision_key` de l'entrée. Résultat : on ne peut pas calculer le P&L réel d'une
décision de trading spécifique, ni savoir si un trade a bien suivi le plan initial.

---

## Analyse technique (Architecte)

`TradeLifecycleLoggerListener.onPositionClosed()` reçoit un `PositionClosedEvent`
qui contient le `positionId`. Ce `positionId` est la clé pour joindre avec la
`Position` entity dont le `payload` contiendra le `decision_key` après correction
d'OBS-01.

**Chemin de correction :**

1. Dans `TradeLifecycleLoggerListener.onPositionClosed()`, charger `Position`
   par `positionId`
2. Extraire `payload['trade_entry_decision_key']` si présent
3. L'injecter dans le champ `extra` de `logPositionClosed()` sous la clé
   `origin_decision_key`
4. S'assurer que le `TradeLifecycleEvent` entity persiste ce champ
   (colonne JSON `extra` déjà présente)

**Fichiers concernés :**

| Fichier | Localisation |
|---------|-------------|
| `src/Trading/Listener/TradeLifecycleLoggerListener.php` | ~L.168 |
| `src/Logging/TradeLifecycleLogger.php` | ~L.133 |
| `src/Repository/PositionRepository.php` | lookup par positionId |

---

## Critères d'acceptance (PO)

- [ ] Dans la table `trade_lifecycle_event`, chaque ligne `POSITION_CLOSED` contient
      `extra.origin_decision_key` si l'entrée a été tracée
- [ ] Une requête SQL
      `WHERE type='POSITION_CLOSED' AND extra->>'origin_decision_key' = 'te:BTCUSDT:a1b2c3'`
      retourne exactement 1 ligne
- [ ] Si la position n'a pas de `decision_key` en payload (positions pré-migration),
      le champ est `null` sans erreur
- [ ] Le dashboard frontend peut afficher `origin_decision_key` dans le détail
      d'un trade fermé
