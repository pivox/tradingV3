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
