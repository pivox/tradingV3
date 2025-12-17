# Klines — récupération, cache, fraîcheur, gaps

## Sources et acteurs

- Fetch REST BitMart : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPublic.php`
- Provider klines (DB → API) : `trading-app/src/Provider/Bitmart/BitmartKlineProvider.php`
- Interface : `trading-app/src/Contract/Provider/KlineProviderInterface.php`

## Paramètres BitMart utilisés pour les klines

Endpoint : `GET /contract/public/kline`

Query envoyée :

- `symbol` : ex. `BTCUSDT`
- `step` : **minutes** (normalisé via `GranularityHelper::normalizeToMinutes`)
- `start_time` : timestamp **secondes** (UTC)
- `end_time` : timestamp **secondes** (UTC)

Source : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPublic.php` + `trading-app/src/Util/GranularityHelper.php`

## Normalisation du `step`

Le système accepte :

- un entier `step` (interprété comme minutes),
- ou une granularité “humaine” (ex: `1m`, `5m`, `15m`, `1h`, `4h`, `1d`, `1w`, etc.).

Mapping minutes (Futures V2) : `trading-app/src/Util/GranularityHelper.php`

Exemples :

- `1m` → 1
- `5m` → 5
- `1h` → 60
- `4h` → 240

## Fenêtrage temporel (start/end)

`BitmartHttpClientPublic::getFuturesKlines()` :

- `endTs` par défaut = `now()` (UTC, secondes)
- si `startTs` absent :
  - `startTs = endTs - (limit * stepSeconds)` où `stepSeconds = stepMinutes * 60`

## Suppression de la dernière bougie si elle n’est pas clôturée

Toujours dans `getFuturesKlines()` :

- On lit le timestamp d’ouverture de la dernière bougie (`lastTs`).
- `lastClose = lastOpen + stepMinutes`.
- Si `nowUtc < lastClose`, la dernière bougie est considérée “en cours” et **retirée** du dataset.

Conséquence fonctionnelle :

- les calculs d’indicateurs utilisent des bougies **clôturées** (pas de bougie partielle).

## Normalisation du format renvoyé

Le client REST normalise deux formats possibles :

- objets DTO (`KlineDto`) → conservés
- tableaux `[timestamp, open, high, low, close, volume]` → convertis en dictionnaire :
  - `timestamp`, `open_price`, `high_price`, `low_price`, `close_price`, `volume`, `source='REST'`

## Stratégie cache DB → API

`BitmartKlineProvider::getKlines(symbol, timeframe, limit)` :

1. Lit d’abord la DB via `KlineRepository->getKlines()`.
2. Si dataset considéré **fresh**, retourne la DB.
3. Sinon, fetch BitMart via `BitmartHttpClientPublic::getFuturesKlines(...)`, puis `upsertKlines(...)`, puis retourne les klines fetch.

### Critère “fresh”
`BitmartKlineProvider::isDatasetFresh()` :

Le dataset DB est “fresh” si :

- `count(klines) >= limit`
- la série est **continue** (pas de trou) :
  - pour chaque paire consécutive, `currentTs - nextTs == stepSeconds`
- le dernier `openTime` correspond à la **dernière bougie clôturée** attendue :
  - `expectedLastOpenTime(timeframe)` calcule :
    - `timestamp = floor(now/secondsByTf)*secondsByTf - secondsByTf`
    - donc la dernière *openTime* d’une bougie terminée.

## Détection de gaps

`BitmartKlineProvider::hasGaps()` et `getGaps()` :

- fenêtre : “7 jours en arrière” → `start = now - P7D`, `end = now`
- délègue à `KlineRepository->getMissingKlineChunks(...)`

## Commandes associées (CLI)

Exemples cités par le projet :

- `bin/console bitmart:fetch-klines BTCUSDT --timeframe=1h --limit=200`
- `bin/console bitmart:fetch-contracts`

Référence : `trading-app/README.md`

