# Contrat `internal_trade_id`

`internal_trade_id` est la clé interne stable d'un trade logique. Elle est indépendante des identifiants exchange et sert à relier l'intention, l'ordre soumis, l'ouverture, la clôture et l'analyse outcome sans approximation par symbole ou horodatage.

## Création

- La clé est créée une seule fois lorsque le trade logique est matérialisé côté MTF.
- Pour le flux MTF actuel, le `trade_id` interne existant devient aussi `internal_trade_id`.
- La clé est persistée dans `trade_lineage` au moment de la réservation de `OrderIntent`, avant la soumission effective.
- En compatibilité legacy, les champs restent nullable et aucun backfill heuristique n'est réalisé.

## Persistance

La table `trade_lineage` porte le mapping exact :

- `internal_trade_id`
- `order_intent_id`
- `client_order_id`
- `exchange_order_id`
- `position_id`
- `run_id`, `correlation_run_id`, `orchestration_run_id`, `orchestration_set_id`, `orchestration_dashboard_id`
- `exchange`, `market_type`, `symbol`, `side`, `profile`, `origin`
- `replay_of_run_id`, `replay_of_correlation_id`, `attempt_number`, `config_hash`

`trade_lifecycle_event.internal_trade_id` est une colonne additive indexée. La même valeur reste aussi dans `extra.internal_trade_id` pour les consommateurs existants ; `extra.trade_id` est conservé pour la vue `position_trade_analysis_v2`.

Depuis le lot DATA-001 #189, le contexte complet est porté par `LineageContext` puis persisté en colonnes dédiées sur `order_intent`, `trade_lineage` et `trade_lifecycle_event`. `extra` reste un snapshot de compatibilité, pas la source principale.

## Résolution

Ordre autorisé :

1. clé interne déjà présente dans le message ou l'événement ;
2. mapping exact par `client_order_id` dans la même venue ;
3. mapping exact par `exchange_order_id` dans la même venue ;
4. mapping exact par `position_id` dans la même venue, uniquement si ce `position_id` a déjà été persisté ;
5. sinon `unmatched`.

La venue est toujours `exchange + market_type`. Les résolutions par symbole seul, symbole + side, timestamp, ou première clôture suivante sont interdites.
Si un identifiant exact non unique produit plusieurs mappings dans la même venue, la résolution reste `unmatched` : le système ne choisit jamais silencieusement un candidat.

L'API read-only `GET /api/lineage/v1/search` expose ce comportement aux clients : une ambiguite persistante dans une meme venue retourne `identifier_conflict`, tandis que deux venues differentes peuvent reutiliser le meme identifiant si `exchange + market_type` sont fournis explicitement. Voir [API read-only de lineage](lineage-read-api.md).

## Couverture actuelle

- `order_submitted` reçoit `internal_trade_id`, `trade_id`, `run_id`, set/dashboard/profil et venue.
- `LimitFillWatchMessage` conserve le contexte lifecycle pour les ordres LIMIT.
- La synchronisation REST enrichit `position_opened` et `position_closed` uniquement si un identifiant exact est disponible dans le payload provider ou déjà persisté.
- Fake/Paper expose `last_order_id`, ce qui permet la résolution exacte via `exchange_order_id`.
- Bitmart conserve `client_order_id` côté ordre et les champs bruts de position sont préservés ; sans identifiant exact dans le payload position, l'événement reste volontairement `unmatched`.
