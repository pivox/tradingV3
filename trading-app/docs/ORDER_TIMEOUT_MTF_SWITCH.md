# Gestion du Timeout des Ordres et du MtfSwitch

## Vue d'ensemble

Ce document explique le mécanisme automatique de timeout des ordres et de gestion du MtfSwitch.

## Problématique

Lorsqu'un ordre est placé sur l'exchange :
1. Le système MTF désactive le symbole pour **4 heures** (via `MtfSwitch`)
2. Cela empêche toute nouvelle tentative d'entrée immédiate
3. Si l'ordre n'est pas rempli et est annulé après 2 minutes, le symbole reste bloqué pendant 4 heures
4. Le système perd donc des opportunités potentielles sur ce symbole

## Solution implémentée

### Architecture

```
[Order Placed]
      |
      v
[MtfSwitch OFF 4h] ────────────────┐
      |                             │
      v                             │
[Messenger: DelayStamp 120s]       │
      |                             │
      v (après 120s)                │
[CancelOrderMessageHandler]        │
      |                             │
      ├─> [Check Order Status]      │
      |                             │
      ├─> [If PENDING → Cancel]     │
      |                             │
      └─> [If Cancelled → Switch OFF 15m] ← Écrase les 4h
                                    │
                                    v
                              [Réactivation après 15m]
```

### Composants

#### 1. ExecutionBox
- Place l'ordre sur l'exchange
- Programme un message `CancelOrderMessage` avec `DelayStamp` de 120 secondes
- Désactive le `MtfSwitch` pour 4 heures

#### 2. CancelOrderMessageHandler
Exécuté après 120 secondes :
- Vérifie le statut de l'ordre
- Si l'ordre est `FILLED` ou `PARTIALLY_FILLED` : ne fait rien
- Si l'ordre est `PENDING` ou `SUBMITTED` : 
  - Annule l'ordre
  - **Réactive le MtfSwitch avec un délai réduit de 15 minutes**
- Si l'ordre est déjà `CANCELLED`/`EXPIRED`/`REJECTED` : ne fait rien

#### 3. MtfSwitchRepository
Gère les états du MtfSwitch :
- `turnOffSymbolFor4Hours()` : Désactive pour 4h (ordre initial)
- `turnOffSymbolForDuration()` : Désactive pour une durée personnalisée (15m après annulation)
- Les switches expirés sont automatiquement réactivés

## Configuration

### Variables d'environnement

```bash
# Durée du timeout avant annulation automatique (en secondes)
# Défaut: 120 secondes (2 minutes)
TRADE_ENTRY_ORDER_TIMEOUT_SECONDS=120

# Durée de désactivation du MtfSwitch après annulation d'ordre
# Défaut: 15m (au lieu de 4h)
ORDER_TIMEOUT_SWITCH_DURATION=15m
```

### Formats supportés pour la durée

- `15m` : 15 minutes
- `1h` : 1 heure
- `30m` : 30 minutes
- `90m` : 90 minutes (converti automatiquement en 1h 30m)

## Logs

### Positions Log (`var/log/positions-YYYY-MM-DD.log`)

```json
{
  "message": "trade_entry.timeout.cancel_attempt",
  "symbol": "BTCUSDT",
  "exchange_order_id": "3000237115743112",
  "client_order_id": "MTF_BTC_20231102_123456",
  "decision_key": "mtf:BTCUSDT:a1b2c3",
  "cancelled": true
}

{
  "message": "trade_entry.timeout.switch_released",
  "symbol": "BTCUSDT",
  "duration": "15m",
  "reason": "order_cancelled_reduced_cooldown"
}
```

### Order Journey Log (`var/log/order-journey-YYYY-MM-DD.log`)

```json
{
  "message": "order_journey.timeout.cancel_attempt",
  "symbol": "BTCUSDT",
  "decision_key": "mtf:BTCUSDT:a1b2c3",
  "cancelled": true,
  "reason": "timeout_triggered_cancel"
}

{
  "message": "order_journey.timeout.switch_released",
  "symbol": "BTCUSDT",
  "duration": "15m",
  "reason": "order_cancelled_reduced_cooldown"
}
```

## Vérification du fonctionnement

### 1. Vérifier que le worker Messenger tourne

```bash
docker-compose ps | grep messenger
# Devrait afficher: trading_app_messenger ... Up
```

### 2. Vérifier les logs en temps réel

```bash
# Logs du messenger
docker-compose logs -f trading-app-messenger

# Logs des positions
docker-compose exec trading-app-php tail -f var/log/positions-$(date +%Y-%m-%d).log | grep timeout

# Logs du journey
docker-compose exec trading-app-php tail -f var/log/order-journey-$(date +%Y-%m-%d).log | grep timeout
```

### 3. Vérifier l'état des MtfSwitch

```bash
# Via psql
docker-compose exec trading-app-db psql -U postgres -d trading_app -c \
  "SELECT switch_key, is_on, expires_at, description FROM mtf_switch WHERE switch_key LIKE 'SYMBOL:%' ORDER BY updated_at DESC LIMIT 10;"
```

### 4. Tester le flux complet

1. Placer un ordre via MTF
2. Attendre 2 minutes
3. Vérifier dans les logs que l'ordre est annulé
4. Vérifier que le MtfSwitch est réactivé avec une expiration de 15 minutes

```bash
# Exemple de commande de test
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{"symbols": ["BTCUSDT"], "workers": 1, "dry_run": false}'
```

## Avantages de cette approche

1. **Utilisation du Messenger existant** : Pas besoin de Temporal supplémentaire
2. **Configuration flexible** : Délais configurables via .env
3. **Optimisation des opportunités** : Réduction du temps de blocage de 4h à 15m après annulation
4. **Traçabilité complète** : Logs détaillés à chaque étape
5. **Gestion des erreurs** : Système robuste avec retry automatique

## Cas d'usage

### Scénario 1 : Ordre rempli rapidement
```
[00:00] Ordre placé → MtfSwitch OFF 4h
[00:01] Ordre rempli → FILLED
[02:00] Timeout atteint → Handler vérifie → Statut FILLED → Aucune action
[04:00] MtfSwitch se réactive automatiquement
```

### Scénario 2 : Ordre non rempli (annulation)
```
[00:00] Ordre placé → MtfSwitch OFF 4h
[02:00] Timeout atteint → Handler vérifie → Statut PENDING
[02:00] Ordre annulé → MtfSwitch écrasé à OFF 15m
[02:15] MtfSwitch se réactive → Nouvelle opportunité possible
```

### Scénario 3 : Ordre partiellement rempli
```
[00:00] Ordre placé → MtfSwitch OFF 4h
[01:30] Ordre partiellement rempli → PARTIALLY_FILLED
[02:00] Timeout atteint → Handler vérifie → Statut PARTIALLY_FILLED → Aucune action
[04:00] MtfSwitch se réactive automatiquement
```

## Maintenance

### Redémarrage du worker Messenger

```bash
docker-compose restart trading-app-messenger
```

### Vérifier la queue Redis

```bash
docker-compose exec redis redis-cli
> KEYS *
> LLEN messenger:order_timeout
```

### Forcer la réactivation d'un MtfSwitch

```bash
curl -X POST http://localhost:8082/api/mtf/switch/on \
  -H "Content-Type: application/json" \
  -d '{"symbol": "BTCUSDT"}'
```

## Références

- `CancelOrderMessageHandler.php` : Handler principal
- `ExecutionBox.php` : Programme le timeout
- `MtfSwitchRepository.php` : Gestion des switches
- `messenger.yaml` : Configuration du transport
- `docker-compose.yml` : Configuration du worker

## Questions fréquentes

**Q: Pourquoi 15 minutes après annulation ?**  
R: C'est un compromis entre laisser le marché se calmer et permettre de nouvelles opportunités rapidement.

**Q: Que se passe-t-il si le worker Messenger est arrêté ?**  
R: Les messages s'accumulent dans Redis. Quand le worker redémarre, ils sont traités dans l'ordre.

**Q: Peut-on modifier les durées sans redémarrer ?**  
R: Non, il faut redémarrer le container `trading-app-messenger` après modification du .env.

**Q: Que se passe-t-il si Bitmart rejette l'annulation ?**  
R: L'erreur est loggée mais le MtfSwitch n'est pas modifié (reste à 4h).

