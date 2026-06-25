# Contrat de contexte de lineage persistant

`LineageContext` est le contexte typé qui relie un appel d'orchestration, un run MTF, une décision, un `OrderIntent`, le mapping `trade_lineage` et les `trade_lifecycle_event`.

## Origines

- `orchestrator` : contexte issu d'une requête orchestrateur validée.
- `legacy` : CLI, cron ou appel historique sans contexte. Aucun `set_id` ou `dashboard_id` n'est inventé.
- `manual` : lancement manuel explicite.
- `replay` : nouvelle tentative fonctionnelle liée à un run/correlation d'origine.

## Retry et replay

Un retry technique conserve le même `orchestration_run_id`, `correlation_run_id`, `orchestration_set_id` et `internal_trade_id` si le trade existe déjà.

Un replay fonctionnel doit utiliser `origin=replay`, incrémenter `attempt_number` et renseigner `replay_of_run_id` ou `replay_of_correlation_id`. Il ne doit pas se confondre avec une clé métier `symbol/timeframe/candle`.

## Champs structurés

Les champs utilisés pour filtrer, joindre ou auditer sont persistés en colonnes dédiées nullable pour compatibilité legacy :

- `orchestration_run_id`
- `correlation_run_id`
- `orchestration_set_id`
- `orchestration_dashboard_id`
- `internal_trade_id`
- `internal_position_id`
- `origin`
- `replay_of_run_id`
- `replay_of_correlation_id`
- `attempt_number`
- `config_hash`

`trade_lifecycle_event.extra` conserve un snapshot de compatibilité, mais n'est plus l'unique source pour ces champs.

## Validation

Le contexte refuse les contradictions vérifiables entre alias (`set_id` vs `orchestration_set_id`, `profile` vs `mtf_profile`, etc.). Symfony ne valide pas la relation set/dashboard contre la base orchestrateur tant qu'elle n'est pas accessible localement.

## API de lecture

La surface read-only `GET /api/lineage/v1/search` permet maintenant de lire le lineage par :

- `orchestration_run_id`
- `correlation_run_id`
- `orchestration_set_id`
- `orchestration_dashboard_id`
- `internal_trade_id`
- `internal_position_id`
- `order_intent_id`
- `client_order_id`
- `exchange_order_id`
- `position_id`

Les identifiants de venue exigent `exchange + market_type`. Les reponses exposent `completeness_status` et `quality_flags`, sans payload brut. Voir [API read-only de lineage](lineage-read-api.md).
