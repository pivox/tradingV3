# Intégration Balance Signal - WS Worker

## Vue d'ensemble

Cette documentation décrit l'intégration entre le `ws-worker` et le `trading-app` pour la synchronisation en temps réel du solde USDT depuis BitMart.

### Architecture

```
BitMart WebSocket (futures/asset:USDT)
    ↓
ws-worker/BalanceWorker
    ↓ (HTTP POST avec HMAC)
trading-app/BalanceSignalController
    ↓
BalanceSignalService
    ↓
Logs & Persistence (futur)
```

## Composants

### Côté ws-worker

1. **BalanceWorker** (`ws-worker/src/Worker/BalanceWorker.php`)
   - Écoute le canal WebSocket `futures/asset:USDT`
   - Filtre uniquement les assets USDT
   - Log les changements de balance
   - Envoie les signaux vers trading-app

2. **BalanceSignalDispatcher** (`ws-worker/src/Balance/BalanceSignalDispatcher.php`)
   - Envoie les requêtes HTTP POST vers trading-app
   - Signature HMAC SHA256 pour authentification
   - Retry automatique avec backoff exponentiel
   - Logs des échecs dans un fichier

3. **BalanceSignalFactory** (`ws-worker/src/Balance/BalanceSignalFactory.php`)
   - Crée les signaux à partir des événements BitMart
   - Valide et normalise les données

### Côté trading-app

1. **BalanceSignalController** (`src/Controller/Api/BalanceSignalController.php`)
   - Endpoint POST `/api/ws-worker/balance`
   - Validation de la signature HMAC
   - Validation du timestamp (max 60s de skew)
   - Validation du payload

2. **WorkerBalanceSignalDto** (`src/Domain/Trading/Balance/Dto/WorkerBalanceSignalDto.php`)
   - DTO readonly pour typer les données
   - Validation stricte des champs requis
   - Conversion des types

3. **BalanceSignalService** (`src/Domain/Trading/Balance/BalanceSignalService.php`)
   - Traitement des signaux de balance
   - Logging structuré
   - Méthodes utilitaires (hasMinimumBalance, getFrozenPercentage)

## Configuration

### Variables d'environnement

#### ws-worker (.env)

```env
# URL de base de trading-app
TRADING_APP_BASE_URI=http://trading-app:8080

# Endpoint pour les signaux de balance
TRADING_APP_BALANCE_SIGNAL_PATH=/api/ws-worker/balance

# Secret partagé pour HMAC (doit être identique dans trading-app)
TRADING_APP_SHARED_SECRET=your-secure-secret-here

# Configuration des tentatives
TRADING_APP_REQUEST_TIMEOUT=2.0
TRADING_APP_SIGNAL_MAX_RETRIES=5

# Fichier de log des échecs
TRADING_APP_BALANCE_FAILURE_LOG=var/balance-signal-failures.log
```

#### trading-app (.env)

```env
# Secret partagé pour HMAC (doit être identique dans ws-worker)
WS_WORKER_SHARED_SECRET=your-secure-secret-here
```

## Format des données

### Payload du signal

```json
{
  "asset": "USDT",
  "available_balance": "10000.50",
  "frozen_balance": "500.00",
  "equity": "10500.50",
  "unrealized_pnl": "100.00",
  "position_deposit": "400.00",
  "bonus": "0.00",
  "timestamp": "2025-11-01T12:00:00+00:00",
  "trace_id": "a1b2c3d4e5f6...",
  "retry_count": 0,
  "payload_version": "1.0",
  "context": {
    "source": "bitmart_ws_worker",
    "raw_data": {
      "currency": "USDT",
      ...
    }
  }
}
```

### Headers HTTP

```
Content-Type: application/json
X-WS-Worker-Timestamp: 1698854400000
X-WS-Worker-Signature: a1b2c3d4e5f6789...
```

### Calcul de la signature

```
signature = HMAC-SHA256(timestamp + "\n" + body, shared_secret)
```

## Sécurité

### Validation HMAC

1. Le ws-worker calcule une signature HMAC SHA256 du payload
2. La signature inclut le timestamp pour éviter les replay attacks
3. Le trading-app vérifie :
   - Présence des headers `X-WS-Worker-Timestamp` et `X-WS-Worker-Signature`
   - Fraîcheur du timestamp (max 60 secondes de décalage)
   - Validité de la signature HMAC

### Protection contre les replay attacks

- Le timestamp doit être récent (< 60 secondes)
- Chaque signal a un `trace_id` unique
- Les signaux dupliqués peuvent être détectés

## Utilisation

### Démarrer le ws-worker

```bash
cd ws-worker
php bin/console ws:run
```

### S'abonner au solde USDT

```bash
curl -X POST http://localhost:8089/balance/subscribe
```

### Vérifier le statut

```bash
curl http://localhost:8089/status | jq
```

Réponse :
```json
{
  "is_running": true,
  "private_ws_connected": true,
  "authenticated": true,
  "balance_subscribed": true,
  "balance": {
    "currency": "USDT",
    "available_balance": "10000.50",
    "frozen_balance": "500.00",
    "equity": "10500.50",
    ...
  }
}
```

### Tester l'endpoint trading-app

```bash
cd trading-app
./scripts/test_balance_endpoint.sh
```

Ce script teste :
- ✅ Signal valide avec signature correcte
- ✅ Signal sans signature (rejet attendu)
- ✅ Signal avec signature invalide (rejet attendu)
- ✅ Payload invalide (rejet attendu)

## Monitoring

### Logs côté ws-worker

```bash
# Voir les logs du BalanceWorker
docker-compose logs -f ws-worker | grep ws-balance

# Voir les logs du dispatcher
docker-compose logs -f ws-worker | grep BalanceSignalDispatcher

# Consulter le fichier des échecs
cat ws-worker/var/balance-signal-failures.log | jq
```

### Logs côté trading-app

```bash
# Voir les logs du contrôleur
docker-compose logs -f trading-app | grep BalanceSignal

# Voir tous les signaux reçus
docker-compose logs -f trading-app | grep "Received balance update"
```

## Gestion des erreurs

### Retry automatique (ws-worker)

Le `BalanceSignalDispatcher` implémente un mécanisme de retry avec backoff exponentiel :

- Tentative 0 : immédiat
- Tentative 1 : 5 secondes
- Tentative 2 : 15 secondes
- Tentative 3 : 45 secondes
- Tentative 4 : 120 secondes
- Tentative 5 : 120 secondes

Après 5 tentatives, le signal est loggé dans `var/balance-signal-failures.log`.

### Codes d'erreur HTTP

| Code | Raison | Action |
|------|--------|--------|
| 202 | Signal accepté | Success |
| 400 | Payload invalide | Vérifier le format du JSON |
| 401 | Signature invalide ou manquante | Vérifier WS_WORKER_SHARED_SECRET |
| 500 | Erreur de traitement | Consulter les logs trading-app |

## Évolutions futures

### Phase 1 (Actuelle) ✅
- ✅ Réception des signaux de balance
- ✅ Validation et logging
- ✅ Tests automatisés

### Phase 2 (À venir)
- 🔲 Créer une entité `AccountBalance` 
- 🔲 Persister les snapshots de balance en BDD
- 🔲 API GET `/api/balance/current` pour récupérer le balance actuel
- 🔲 API GET `/api/balance/history` pour l'historique

### Phase 3 (À venir)
- 🔲 Alertes si le balance passe sous un seuil
- 🔲 Calcul de métriques (PnL daily, ROI, etc.)
- 🔲 Dashboard de visualisation du balance
- 🔲 Événements Symfony pour notifier les autres services

### Phase 4 (À venir)
- 🔲 Prédiction de marge disponible pour les futures positions
- 🔲 Suggestions d'ajustement de levier
- 🔲 Risk management automatique

## Exemples d'implémentation

### Vérifier si le balance est suffisant avant de trader

```php
use App\Domain\Trading\Balance\Dto\WorkerBalanceSignalDto;
use App\Domain\Trading\Balance\BalanceSignalService;

// Dans votre service de trading
public function canOpenPosition(float $requiredMargin): bool
{
    $lastBalance = $this->getLastBalanceSignal();
    
    if ($lastBalance === null) {
        return false; // Pas de données de balance disponibles
    }
    
    return $this->balanceSignalService->hasMinimumBalance(
        $lastBalance, 
        $requiredMargin
    );
}
```

### Calculer le pourcentage du balance gelé

```php
$frozenPercent = $this->balanceSignalService->getFrozenPercentage($signal);

if ($frozenPercent > 80.0) {
    $this->logger->warning('Plus de 80% du balance est gelé', [
        'frozen_percent' => $frozenPercent,
    ]);
}
```

## Support

En cas de problème :

1. Vérifier que le `WS_WORKER_SHARED_SECRET` est identique dans les deux applications
2. Vérifier les logs du ws-worker et du trading-app
3. Tester avec le script `test_balance_endpoint.sh`
4. Consulter les fichiers de logs des échecs

Pour plus d'informations, consulter :
- `ws-worker/README_BITMART.md` - Documentation du ws-worker
- `trading-app/docs/` - Documentation du trading-app

