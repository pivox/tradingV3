# Signature des événements WebSocket - Ordres BitMart Futures V2

## Structure générale

Les événements WebSocket pour les ordres sont reçus sur le canal privé `futures/order` après authentification.

### Format brut BitMart

```json
{
  "group": "futures/order",
  "data": [
    {
      "action": 2,
      "order": {
        "order_id": "123456789",
        "client_order_id": "CLIENT_ORDER_123",
        "symbol": "BTCUSDT",
        "side": 1,
        "type": "limit",
        "price": "50000.0",
        "size": "10",
        "state": 2,
        "deal_size": "5",
        "deal_avg_price": "50025.0",
        "deal_notional": "250125.0",
        "leverage": "10",
        "open_type": "isolated",
        "position_mode": 2,
        "update_time": 1640995200000
      }
    }
  ]
}
```

## Champs de l'événement

### Niveau racine

| Champ | Type | Description |
|-------|------|-------------|
| `group` | string | Toujours `"futures/order"` pour les ordres |
| `data` | array | Tableau d'événements d'ordre |

### Événement d'ordre (`data[]`)

| Champ | Type | Description |
|-------|------|-------------|
| `action` | integer | Type d'action (voir Actions ci-dessous) |
| `order` | object | Objet contenant les détails de l'ordre |

### Objet `order`

| Champ | Type | Description | Exemple |
|-------|------|-------------|---------|
| `order_id` | string | Identifiant unique de l'ordre BitMart | `"123456789"` |
| `client_order_id` | string | Identifiant client (peut être vide) | `"CLIENT_ORDER_123"` |
| `symbol` | string | Symbole du contrat | `"BTCUSDT"` |
| `side` | integer | Côté de l'ordre (voir Sides ci-dessous) | `1` |
| `type` | string | Type d'ordre | `"limit"`, `"market"` |
| `price` | string | Prix de l'ordre (string décimal) | `"50000.0"` |
| `size` | string | Taille de l'ordre (nombre de contrats) | `"10"` |
| `state` | integer | État de l'ordre (voir States ci-dessous) | `2` |
| `deal_size` | string | Taille exécutée (nombre de contrats) | `"5"` |
| `deal_avg_price` | string | Prix moyen d'exécution | `"50025.0"` |
| `deal_notional` | string | Valeur notionnelle exécutée | `"250125.0"` |
| `leverage` | string | Levier utilisé | `"10"` |
| `open_type` | string | Type d'ouverture | `"isolated"`, `"cross"` |
| `position_mode` | integer | Mode de position | `1` (hedge), `2` (one-way) |
| `update_time` | integer | Timestamp de mise à jour (millisecondes) | `1640995200000` |

## Actions (`action`)

Les actions représentent le type d'événement sur l'ordre :

| Valeur | Code | Description |
|--------|------|-------------|
| `1` | `MATCH_DEAL` | Ordre exécuté (match) |
| `2` | `SUBMIT_ORDER` | Ordre soumis |
| `3` | `CANCEL_ORDER` | Ordre annulé |
| `4` | `LIQUIDATE_CANCEL_ORDER` | Ordre annulé par liquidation |
| `5` | `ADL_CANCEL_ORDER` | Ordre annulé par ADL (Auto-Deleveraging) |
| `6` | `PART_LIQUIDATE` | Liquidation partielle |
| `7` | `BANKRUPTCY_ORDER` | Ordre de faillite |
| `8` | `PASSIVE_ADL_MATCH_DEAL` | Match ADL passif |
| `9` | `ACTIVE_ADL_MATCH_DEAL` | Match ADL actif |

## Sides (`side`)

Les valeurs de `side` représentent la direction et le type d'opération :

| Valeur | Description |
|--------|-------------|
| `1` | Open Long (Achat pour ouvrir position longue) |
| `2` | Close Long (Vente pour fermer position longue) |
| `3` | Close Short (Achat pour fermer position courte) |
| `4` | Open Short (Vente pour ouvrir position courte) |

## States (`state`)

Les états de l'ordre :

| Valeur | Code | Description |
|--------|------|-------------|
| `1` | `APPROVAL` | En attente d'approbation |
| `2` | `CHECK` | En vérification |
| `4` | `FINISH` | Terminé (exécuté ou annulé) |

## Format normalisé (après traitement)

Après normalisation dans `OrderWebhookController::normalizeEvents()`, les événements sont transformés en :

```php
[
    'action' => 2,                    // int
    'order_id' => '123456789',        // string
    'client_order_id' => 'CLIENT_ORDER_123', // string
    'symbol' => 'BTCUSDT',            // string
    'side' => 1,                      // int|null
    'type' => 'limit',                // string|null
    'state' => 2,                     // int
    'price' => '50000.0',             // string|null
    'size' => '10',                   // string|null
    'deal_avg_price' => '50025.0',    // string|null
    'deal_size' => '5',               // string|null
    'leverage' => '10',               // string|null
    'open_type' => 'isolated',        // string|null
    'position_mode' => 2,             // int|null
    'update_time_ms' => 1640995200000 // int|null
]
```

## Mapping vers FuturesOrder

Le service `FuturesOrderSyncService::syncOrderFromWebSocket()` mappe les champs WebSocket vers les champs de l'entité `FuturesOrder` :

| WebSocket | FuturesOrder | Notes |
|-----------|--------------|-------|
| `order_id` | `orderId` | Direct |
| `client_order_id` | `clientOrderId` | Direct |
| `symbol` | `symbol` | Direct, converti en majuscules |
| `side` | `side` | Direct (integer) |
| `type` | `type` | Direct, converti en lowercase |
| `state` | `status` | Mappé via `mapWebSocketStateToStatus()` |
| `price` | `price` | Direct (string) |
| `size` | `size` | Converti en integer |
| `deal_size` | `filledSize` | Converti en integer |
| `open_type` | `openType` | Direct, converti en lowercase |
| `position_mode` | `positionMode` | Direct (integer) |
| `leverage` | `leverage` | Converti en integer |
| `update_time_ms` | `updatedTime` | Direct (bigint) |

### Mapping des états

```php
state 1 (APPROVAL)  → status 'pending'
state 2 (CHECK)     → status 'pending'
state 4 (FINISH)    → status 'filled' (si deal_size > 0) ou 'cancelled'
```

## Exemple complet

### Événement WebSocket brut

```json
{
  "group": "futures/order",
  "data": [
    {
      "action": 2,
      "order": {
        "order_id": "987654321",
        "client_order_id": "MTF_ORDER_20250115_001",
        "symbol": "ETHUSDT",
        "side": 1,
        "type": "limit",
        "price": "3000.50",
        "size": "5",
        "state": 2,
        "deal_size": "0",
        "deal_avg_price": "0",
        "deal_notional": "0",
        "leverage": "20",
        "open_type": "isolated",
        "position_mode": 2,
        "update_time": 1705276800000
      }
    }
  ]
}
```

### Après normalisation

```php
[
    'action' => 2,
    'order_id' => '987654321',
    'client_order_id' => 'MTF_ORDER_20250115_001',
    'symbol' => 'ETHUSDT',
    'side' => 1,
    'type' => 'limit',
    'state' => 2,
    'price' => '3000.50',
    'size' => '5',
    'deal_avg_price' => '0',
    'deal_size' => '0',
    'leverage' => '20',
    'open_type' => 'isolated',
    'position_mode' => 2,
    'update_time_ms' => 1705276800000
]
```

### Entité FuturesOrder créée/mise à jour

```php
FuturesOrder {
    orderId: '987654321',
    clientOrderId: 'MTF_ORDER_20250115_001',
    symbol: 'ETHUSDT',
    side: 1,
    type: 'limit',
    status: 'pending',  // mappé depuis state 2
    price: '3000.50',
    size: 5,
    filledSize: 0,
    openType: 'isolated',
    positionMode: 2,
    leverage: 20,
    updatedTime: 1705276800000,
    rawData: { /* données complètes de l'événement */ }
}
```

## Notes importantes

1. **Champs optionnels** : Tous les champs de `order` peuvent être absents sauf `order_id` et `symbol` qui sont généralement présents.

2. **Types de données** : BitMart envoie les nombres comme strings pour éviter les problèmes de précision (ex: `"50000.0"` au lieu de `50000.0`).

3. **Timestamps** : Les timestamps sont en millisecondes (type `bigint`).

4. **Synchronisation** : La synchronisation dans `FuturesOrderSyncService` gère les valeurs manquantes avec des valeurs par défaut raisonnables.

5. **Idempotence** : Les ordres sont identifiés par `order_id` ou `client_order_id`. Si un ordre existe déjà, il est mis à jour plutôt que créé.

