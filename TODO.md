# TODO

- Configurer un contact point Grafana pour l'alerte "Positions - New Entry Detected" (runs in Alerting UI).
- Lancer `php bin/console doctrine:migrations:migrate` dans `trading-app` pour créer `contract_cooldown` et `order_lifecycle`.
- Reconfigurer `bitmart-ws-forwarder` pour poster les évènements vers `/api/orders/events` du trading-app.
- Vérifier les logs applicatifs afin de confirmer que l'ATR 1m et les protections (SL/TP) sont bien enregistrés après chaque remplissage.


MTF – Synthèse validations (audit)
- UI: surligner en vert la cellule correspondant au dernier kline fermé basé sur open_time (OK, via candle_close_ts → open_time).
- UI: afficher l’intervalle « open → close UTC » dans chaque cellule (success et failed) (OK).
- UI: afficher l’ID d’audit cliquable (ouvre la modale détails) dans chaque cellule + ID du Ready 1m (OK).
- UI: tri prioritaire sur: nb cellules vertes (kline courant) > nb TF à jour > nb validations > plus récent (OK).
- API: `event_ts` summary basé strictement sur `candle_close_ts` (fallback `details.kline_time`), plus de fallback `created_at` (OK).
- API: exposer `audit_id` côté `timeframes[tf]` et `ready` (OK).
- API: exposer `kline_id` (ID klines) lorsque possible (calculé via (symbol, timeframe, open_time)) (OK côté repo; UI non affiché).
- DB: ajouter `kline_id` (nullable) dans `mtf_audit` + FK vers `klines(id)` (à faire, migration à écrire).
- DB: backfill `kline_id` via UPDATE en joignant `klines` sur (symbol, timeframe, open_time = candle_close_ts - durée_TF) (à faire).
- Backend: lors de la création d’un audit, renseigner `kline_id` si la bougie existe (à faire).
- UI: option afficher `kline_id` sous l’ID d’audit dans la Synthèse (à décider).



# ce qui est fait 
migration 

# codex conversatio history 
- position codex resume 0199eef0-132c-7903-83cd-56c3162970b2

---

# BUG: EntityManager is closed - order_journey.symbol_processor.failed

## Analyse de la cause racine

**Problème identifié** : Chaîne d'erreur en cascade

1. `processTimeframeInternal()` dans `BaseTimeframeService` appelle `getKlines()` (ligne 95)
2. Si une exception Doctrine se produit (ex: timeout DB, connexion perdue), Doctrine ferme automatiquement l'EntityManager
3. Le catch block (ligne 242) appelle `auditStep()` (ligne 250) pour logger l'erreur
4. `auditStep()` utilise l'EntityManager (ligne 372-373) qui est maintenant fermé
5. Nouvelle exception "EntityManager is closed" qui masque l'exception originale

**Problèmes identifiés** :
- `auditStep()` dans `BaseTimeframeService` (ligne 372-373) : utilise l'EntityManager sans protection
- `getOrCreateForSymbol()` dans `MtfStateRepository` (ligne 28) : flush() échoue si EntityManager est fermé
- `updateState()` dans `BaseTimeframeService` (ligne 274) : appelle `getOrCreateForSymbol()` qui peut échouer
- `flush()` dans `MtfService::processSymbol()` (lignes 549, 948) : utilisé après des opérations qui peuvent avoir échoué

**L'erreur est pertinente** : elle masque l'exception originale et empêche l'audit.

## Plan d'action

### Actions à réaliser

1. **Protéger `auditStep()` dans `BaseTimeframeService`**
   - Fichier: `trading-app/src/MtfValidator/Service/Timeframe/BaseTimeframeService.php`
   - Ligne 371-374 : ajouter un try-catch autour de l'utilisation de l'EntityManager
   - Logger un warning si l'audit ne peut pas être persisté (best-effort)

2. **Protéger `getOrCreateForSymbol()` dans `MtfStateRepository`**
   - Fichier: `trading-app/src/Repository/MtfStateRepository.php`
   - Ligne 27-28 : ajouter un try-catch autour du flush()
   - Gérer gracieusement le cas où l'EntityManager est fermé

3. **Protéger les `flush()` dans `MtfService::processSymbol()`**
   - Fichier: `trading-app/src/MtfValidator/Service/MtfService.php`
   - Ligne 549 : protéger flush() après updateState()
   - Ligne 948 : protéger flush() après les updateState()
   - Protéger les appels auditStep() après une exception potentielle

4. **Améliorer la gestion d'erreur dans `SymbolProcessor`**
   - Fichier: `trading-app/src/MtfValidator/Service/SymbolProcessor.php`
   - Distinguer les exceptions Doctrine des autres exceptions
   - Préserver l'exception originale dans les logs

### Critères de réussite

- ✅ Plus d'erreur "EntityManager is closed" dans les logs
- ✅ Les exceptions originales sont préservées et loggées correctement
- ✅ L'audit fonctionne en best-effort même si l'EntityManager est fermé
- ✅ Pas de régression dans le traitement des symboles

### Fichiers à modifier

- [x] `trading-app/src/MtfValidator/Service/Timeframe/BaseTimeframeService.php` [EDIT]
- [x] `trading-app/src/Repository/MtfStateRepository.php` [EDIT]
- [x] `trading-app/src/MtfValidator/Service/MtfService.php` [EDIT]
- [x] `trading-app/src/MtfValidator/Service/SymbolProcessor.php` [EDIT] (optionnel, amélioration)

### Statut
- 🔍 Analyse terminée
- ✅ Implémentation effectuée (best-effort, EM fermé géré sans masquer l'exception d'origine)
