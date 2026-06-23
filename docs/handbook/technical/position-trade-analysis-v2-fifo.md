# `position_trade_analysis_v2` — appariement FIFO

La vue `position_trade_analysis_v2` reste read-only et versionnée. Depuis la migration
`Version20260623010000`, l'appariement entrée/clôture ne repose plus sur deux rangs
indépendants `ROW_NUMBER()` entrée N = clôture N.

## Contrat

Le matching se fait uniquement dans une venue exacte :

- `symbol`
- `exchange`
- `market_type`

Les clés exactes sont essayées dans cet ordre :

1. `internal_trade_id`
2. `trade_id` legacy documenté
3. `position_id` effectif, incluant le pont `trade_id -> position_id` via `position_opened`

Une clé utilisée dans une passe ne peut plus être consommée par une passe suivante. Une clôture
ne peut donc jamais fermer deux entrées.

## Algorithme

Pour chaque clé + venue + run admissible, la migration construit un flux chronologique :

1. événements `order_submitted` triés par `happened_at`;
2. événements `position_closed` triés par `effective_close_time`;
3. `effective_close_time = extra.close_time` si exploitable, sinon `happened_at`;
4. une CTE récursive maintient la file des entrées ouvertes;
5. une clôture ferme uniquement la plus ancienne entrée ouverte;
6. une clôture sans entrée ouverte reste orpheline et ne décale aucune entrée suivante.

Séquence attendue :

```text
E1, C1, Cx, E2, C3
```

Résultat :

```text
E1 -> C1
Cx -> orpheline, non exposée par la vue entry-based
E2 -> C3
```

## Garde run

Une clôture avec `run_id` non nul ne peut fermer que les entrées du même run. Une clôture sans
`run_id` reste utilisable pour le flux live uniquement si une seule valeur de run candidate
existe pour la clé + venue ; sinon elle reste non appariée.

## Complexité et index

Le parcours récursif est séquentiel dans chaque groupe clé + venue + run après tri et n'introduit
pas de produit cartésien entre entrées et clôtures. La file d'entrées ouvertes est portée par un
tableau PostgreSQL ; son coût dépend donc de la profondeur d'entrées ouvertes simultanées dans un
groupe, sans scan exponentiel attendu pour les flux observés. La migration ajoute des index
additifs sur `trade_lifecycle_event` pour les clés exactes utilisées par la vue :

- `internal_trade_id`
- `position_id`
- `extra->>'trade_id'`
- `extra->>'position_id'`

Commande d'audit recommandée sur une base PostgreSQL dédiée :

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT *
FROM position_trade_analysis_v2
WHERE run_id = '<run_id>'
ORDER BY entry_time;
```

Le résultat doit être relu pour vérifier que le coût est dominé par les tris/groupes attendus,
pas par un produit cartésien ou une explosion récursive.
