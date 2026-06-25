# API read-only de lineage

Cette surface permet de naviguer dans le lineage persistant sans reconstruire les relations par symbole, side ou fenetre temporelle.

Controleur Symfony : `App\Trading\Controller\Api\LineageReadApiController`.

## Principes

- Lecture seule uniquement.
- Un seul identifiant exact par requete.
- Aucun fallback par symbole seul.
- Aucun rapprochement par fenetre temporelle.
- `client_order_id`, `exchange_order_id` et `position_id` exigent toujours `exchange` et `market_type`.
- Les identifiants ambigus dans une meme venue retournent `409 identifier_conflict`.
- Les reponses n'exposent pas `extra`, `raw_inputs`, `validation_errors` ni payload provider brut.
- Chaque item expose toujours `completeness_status` et `quality_flags`.
- Pagination bornee : `limit` est force entre 1 et 100, `offset >= 0`.

## Endpoints

### Recherche

```text
GET /api/lineage/v1/search
```

Parametres acceptes, un seul a la fois :

- `orchestration_run_id`
- `correlation_run_id`
- `orchestration_set_id`
- `orchestration_dashboard_id`
- `internal_trade_id`
- `internal_position_id`
- `order_intent_id`
- `client_order_id` avec `exchange` + `market_type`
- `exchange_order_id` avec `exchange` + `market_type`
- `position_id` avec `exchange` + `market_type`

Parametres de pagination :

- `limit` : defaut 100, maximum 100.
- `offset` : defaut 0.

Exemples :

```bash
curl -s 'http://localhost:8082/api/lineage/v1/search?orchestration_run_id=run_dashA_20260617T083000Z&limit=50' | jq .
curl -s 'http://localhost:8082/api/lineage/v1/search?orchestration_set_id=set-scalper-micro-bitmart&limit=25&offset=0' | jq .
curl -s 'http://localhost:8082/api/lineage/v1/search?internal_trade_id=trade_01HZ...' | jq .
curl -s 'http://localhost:8082/api/lineage/v1/search?order_intent_id=12345' | jq .
curl -s 'http://localhost:8082/api/lineage/v1/search?position_id=987654&exchange=bitmart&market_type=perpetual' | jq .
curl -s 'http://localhost:8082/api/lineage/v1/search?exchange_order_id=abc123&exchange=okx&market_type=perpetual' | jq .
```

Reponse :

```json
{
  "pagination": {
    "limit": 50,
    "offset": 0,
    "total": 1,
    "has_more": false
  },
  "data": [
    {
      "completeness_status": "complete",
      "quality_flags": [],
      "lineage": {
        "internal_trade_id": "trade_01HZ...",
        "order_intent_id": 12345,
        "client_order_id": "cid-...",
        "exchange_order_id": "abc123",
        "position_id": "987654",
        "orchestration_run_id": "run_dashA_20260617T083000Z",
        "correlation_run_id": "run_dashA_20260617T083000Z",
        "orchestration_set_id": "set-scalper-micro-bitmart",
        "orchestration_dashboard_id": "dash-main",
        "exchange": "bitmart",
        "market_type": "perpetual",
        "symbol": "BTCUSDT",
        "origin": "orchestrator"
      },
      "order_intent": {
        "id": 12345,
        "status": "SENT",
        "client_order_id": "cid-...",
        "exchange_order_id": "abc123"
      },
      "lifecycle_events": [
        {
          "event_type": "position_closed",
          "internal_trade_id": "trade_01HZ...",
          "exchange_order_id": "abc123",
          "position_id": "987654",
          "happened_at": "2026-06-25T10:00:00+00:00"
        }
      ],
      "lifecycle_events_pagination": {
        "limit": 100,
        "total": 3,
        "has_more": false
      }
    }
  ],
  "filters": {
    "identifier": "internal_trade_id",
    "value": "trade_01HZ...",
    "exchange": null,
    "market_type": null
  }
}
```

### Detail complet

```text
GET /api/lineage/v1/{internal_trade_id}
```

Retourne le premier item de la recherche par `internal_trade_id`.

### Evenements lifecycle

```text
GET /api/lineage/v1/{internal_trade_id}/events
```

Retourne uniquement les evenements lifecycle associes et conserve `completeness_status` + `quality_flags`.

## Statuts de completude

Priorite de calcul :

| Statut | Signification |
| --- | --- |
| `identifier_conflict` | L'identifiant exact correspond a plusieurs lineages dans la meme venue. |
| `legacy` | Origine legacy, sans contexte orchestrateur complet invente artificiellement. |
| `missing_order_intent` | Aucun `OrderIntent` persistant n'est lie au lineage. |
| `missing_exchange_order_id` | Aucun identifiant d'ordre exchange n'est persiste. |
| `missing_position_id` | Aucun identifiant de position interne ou exchange n'est persiste. |
| `missing_close_event` | Aucun evenement `position_closed` associe n'est visible. |
| `partial` | Le lineage existe mais un champ structurant de contexte est absent. |
| `unmatched` | Un evenement exact existe mais aucun `trade_lineage` persistant ne le relie. |
| `complete` | OrderIntent, ordre exchange, position et cloture sont tous relies par identifiants exacts. |

`quality_flags` contient le ou les marqueurs qui expliquent le statut.

## Erreurs

```json
{
  "error": {
    "code": "missing_identifier",
    "message": "Provide exactly one lineage identifier query parameter."
  }
}
```

Codes principaux :

- `400 missing_identifier`
- `400 multiple_identifiers`
- `400 missing_venue`
- `404 lineage_not_found`
- `409 identifier_conflict` avec `completeness_status=identifier_conflict` et `quality_flags=["identifier_conflict"]`

## Redaction

Les reponses ne serialisent jamais :

- `trade_lifecycle_event.extra`
- `order_intent.raw_inputs`
- `order_intent.validation_errors`
- payload provider brut
- secret ou credential exchange

Les evenements lifecycle sont exposes via une whitelist de champs structurants seulement.
