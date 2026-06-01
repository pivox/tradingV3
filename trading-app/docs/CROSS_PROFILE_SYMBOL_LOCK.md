# Cross-Profile Symbol Lock

## Problème

La matrice runtime `exchange × market_type × mtf_profile` permet de lancer plusieurs profils MTF en parallèle. L'idempotence par `decision_key` protège un même profil, mais elle inclut `strategy_profile`; deux profils peuvent donc produire deux clés différentes pour le même symbole.

Exemple dangereux :

```text
bitmart:perpetual:BTCUSDT:1m:...:long:scalper:v1
bitmart:perpetual:BTCUSDT:1m:...:long:scalper_micro:v1
```

## Règle

Pour un même `exchange + market_type + symbol`, une seule ouverture peut être active.

La clé globale est :

```text
exchange:market_type:symbol
```

Exemples :

```text
bitmart:perpetual:BTCUSDT
okx:perpetual:ETHUSDT
hyperliquid:perpetual:SOLUSDT
```

## Implémentation

La table `symbol_execution_lock` porte le verrou global.

Un lock actif est une ligne avec `released_at IS NULL`. PostgreSQL impose un index unique partiel sur `(exchange, market_type, symbol)` pour garantir l'atomicité côté DB.

`OrderIntentManager::reserveIntent()` tente de réserver le lock dans la même transaction que la création de l'`OrderIntent`. Si un lock actif existe déjà, l'intention est refusée avec :

```text
cross_profile_symbol_locked
```

Le payload de skip expose :

```json
{
  "reason": "cross_profile_symbol_locked",
  "lock": {
    "exchange": "bitmart",
    "market_type": "perpetual",
    "symbol": "BTCUSDT",
    "current_profile": "scalper_micro",
    "blocking_profile": "scalper",
    "blocking_order_intent_id": 123,
    "blocking_decision_key": "bitmart:perpetual:BTCUSDT:1m:..."
  }
}
```

## Libération

Le lock est libéré automatiquement quand l'`OrderIntent` passe en :

- `FAILED`
- `CANCELLED`

Il est aussi libéré sur `PositionClosedEvent` pour le même `exchange + market_type + symbol`, à condition qu'aucune position ouverte locale et qu'aucun ordre ouvert local ne reste sur ce symbole.

Le lock reste actif quand l'intention est `SENT`, car un ordre ou une position peut encore exister.

Un lock expiré peut être repris seulement si :

- il n'y a aucune position ouverte locale pour ce symbole ;
- il n'y a aucun ordre ouvert local pour ce symbole ;
- l'`OrderIntent` propriétaire n'est plus dans un statut actif.

## Commandes

Lister les locks actifs :

```bash
php bin/console app:symbol-lock:list
php bin/console app:symbol-lock:list --exchange=bitmart --market-type=perpetual
php bin/console app:symbol-lock:list --symbol=BTCUSDT
```

Libérer un lock après investigation :

```bash
php bin/console app:symbol-lock:release BTCUSDT \
  --exchange=bitmart \
  --market-type=perpetual \
  --reason=manual_investigation
```

La commande refuse si une position ouverte ou un ordre ouvert existe. Après investigation manuelle uniquement :

```bash
php bin/console app:symbol-lock:release BTCUSDT \
  --exchange=bitmart \
  --market-type=perpetual \
  --reason=manual_investigation \
  --force
```

Les releases forcées sont logguées avec le préfixe `force:`.

## Procédure d'incident

1. Lister le lock :

   ```bash
   php bin/console app:symbol-lock:list --symbol=BTCUSDT
   ```

2. Vérifier les positions et ordres ouverts côté exchange et BDD.

3. Si aucune exposition n'existe, libérer sans `--force`.

4. Si la BDD locale indique encore une position ouverte mais que l'exchange confirme l'absence d'exposition, documenter l'investigation puis utiliser `--force`.

## Lien avec Temporal

Avant d'activer deux schedules live sur le même exchange, par exemple :

```text
cron-mtf-bitmart-scalper-1m
cron-mtf-bitmart-scalper-micro-1m
```

vérifier que cette migration est appliquée et que `app:symbol-lock:list` fonctionne dans le container Symfony.
