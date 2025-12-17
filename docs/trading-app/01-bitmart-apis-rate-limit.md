# BitMart — URLs, endpoints, throttling et backoff

## URLs (base URIs)

Les clients HTTP Symfony de `trading-app` utilisent :

- **Base REST (public)** : variable d’env `BITMART_PUBLIC_API_URL` (paramètre `bitmart_public_base_uri`)  
  Source : `trading-app/config/services.yaml` + `trading-app/config/packages/framework.yaml`
- **Base REST (private)** : variable d’env `BITMART_PRIVATE_API_URL` (paramètre `bitmart_private_base_uri`)  
  Source : `trading-app/config/services.yaml` + `trading-app/config/packages/framework.yaml`

Valeurs par défaut présentes dans `.env` à la racine du repo :

- `BITMART_PUBLIC_API_URL=https://api-cloud-v2.bitmart.com`
- `BITMART_PRIVATE_API_URL=https://api-cloud-v2.bitmart.com`

WebSocket (private) utilisé par d’autres composants de `trading-app` :

- `BITMART_WS_PRIVATE_URL=wss://ws-manager-compress.bitmart.com/api?protocol=1.1` (voir `.env` racine)

## Endpoints REST utilisés (chemins)

### Public (Bitmart futures v2)
Implémentation : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPublic.php`

- `GET /system/time`
- `GET /contract/public/kline`
- `GET /contract/public/depth`
- `GET /contract/public/market-trade`
- `GET /contract/public/markprice-kline`
- `GET /contract/public/leverage-bracket`
- `GET /contract/public/details`

### Private (Bitmart futures v2)
Implémentation : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPrivate.php`

- `GET  /contract/private/assets-detail`
- `GET  /contract/private/position-v2`
- `GET  /contract/private/get-open-orders`
- `GET  /contract/private/current-plan-order`
- `POST /contract/private/modify-plan-order`
- `GET  /contract/private/order-detail`
- `GET  /contract/private/order-history`
- `GET  /contract/private/trades`
- `GET  /contract/private/transaction-history`
- `POST /contract/private/submit-order`
- `POST /contract/private/cancel-order`
- `POST /contract/private/cancel-all-orders`
- `POST /contract/private/cancel-all-after`
- `GET  /contract/private/fee-rate`
- `POST /contract/private/submit-leverage`

## Throttling “temps d’attente entre URLs”

### 1) Throttle legacy (fallback global)
Implémentation : `trading-app/src/Provider/Bitmart/Http/throttleBitmartRequestTrait.php`

- `THROTTLE_SECONDS = 0.2` → **minimum 200ms entre deux requêtes** (mécanisme legacy conservé).

### 2) Throttle bucketisé (mécanisme principal)
Implémentation : `trading-app/src/Provider/Bitmart/Http/throttleBitmartRequestTrait.php`

Principe :

- Chaque endpoint est associé à un **bucket** (ex: `PUBLIC_KLINE`, `PRIVATE_POSITION`).
- Le throttle applique une **sliding window** (`limit` requêtes sur `windowSec` secondes).
- L’état est stocké localement :
  - `var/bitmart/throttle/bucket_<bucket>.json` (dans `trading-app`)
- Un lock Symfony sérialise les accès au bucket :
  - lock key : `bitmart.throttle.<bucketKey>`

Comportement d’attente :

- Si `count(req_ts) >= limit`, on calcule `sleepSec = (oldest + windowSec) - now` et on dort.
- Le sommeil est fait **par petits incréments** et borné :
  - `usleep(min(sleep, 2_000_000))` → **max 2 secondes** par cycle, puis re‑évaluation.

### 3) Exploitation des headers de rate-limit BitMart
Implémentation : `trading-app/src/Provider/Bitmart/Http/throttleBitmartRequestTrait.php`

Headers lus (si présents) :

- `X-BM-RateLimit-Limit`
- `X-BM-RateLimit-Reset` (interprété comme “window seconds” ; le code calcule `reset_ts = now + windowSec`)
- `X-BM-RateLimit-Remaining`

Règle conservatrice :

- si le rate indiqué par headers est **plus restrictif** (en “req/s”) que l’ENV/default, le throttle prend le couple header.

### 4) Overrides via variables d’environnement
Implémentation : `trading-app/src/Provider/Bitmart/Http/throttleBitmartRequestTrait.php`

Formats acceptés :

- `BITMART_RATE_<BUCKET>=<limit>/<windowSec>` (ex: `BITMART_RATE_PUBLIC_KLINE=12/2`)
- ou bien :
  - `BITMART_RATE_<BUCKET>_LIMIT=<int>`
  - `BITMART_RATE_<BUCKET>_WINDOW=<float>`
- Defaults de groupe :
  - `BITMART_RATE_PUBLIC_DEFAULT=...`
  - `BITMART_RATE_PRIVATE_DEFAULT=...`

### 5) Specs par défaut (rateSpec)

Public : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPublic.php`

- Tous les endpoints publics → **12 requêtes / 2 secondes** (par défaut), bucket variant par endpoint :
  - `PUBLIC_KLINE`, `PUBLIC_DEPTH`, `PUBLIC_DETAILS`, `PUBLIC_MARKPRICE_KLINE`, `PUBLIC_LEVERAGE_BRACKET`, `PUBLIC_MARKET_TRADE`, `PUBLIC_SYSTEM_TIME`.

Private : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPrivate.php`

- Default : 12 / 2s si non mappé
- Mappings notables :
  - `PRIVATE_POSITION` : 6 / 2s
  - `PRIVATE_GET_OPEN_ORDERS` : 50 / 2s
  - `PRIVATE_GET_PLAN_ORDERS` : 50 / 2s
  - `PRIVATE_SUBMIT_ORDER` : 24 / 2s
  - `PRIVATE_SUBMIT_LEVERAGE` : 24 / 2s
  - `PRIVATE_CANCEL_ALL_ORDERS` : 2 / 2s
  - `PRIVATE_CANCEL_ALL_AFTER` : 2 / 2s
  - `PRIVATE_TRADE_FEE_RATE` : 2 / 2s

## Backoff sur HTTP 429 (Too Many Requests)

Public/Private appliquent un retry sur 429 :

- `MAX_RETRIES = 3`
- Attente calculée par `computeBackoffUs()` :
  1. `Retry-After` (secondes) si présent → converti en µs (cap à 2000ms)
  2. sinon si état bucket contient `reset_ts` (calculé depuis `X-BM-RateLimit-Reset`) → attente jusqu’au reset (cap 2000ms)
  3. sinon fallback exponentiel + jitter :
     - `base = 300ms * 2^(attempt-1)` (cap 2000ms)
     - `jitter = [0 .. 25% de base]`

## Comportements spécifiques privés

### cancel-all-after programmé automatiquement
Implémentation : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPrivate.php`

- `submitOrder()` peut programmer un `cancel-all-after` :
  - via champ payload `cancel_after_timeout` (retiré du payload envoyé à BitMart)
  - ou via `DEFAULT_CANCEL_AFTER_SECONDS = 120` (si > 0)
- `cancelAllAfter(symbol, timeoutSeconds)` normalise :
  - `timeoutSeconds < 0` → 0
  - `0 < timeoutSeconds < 5` → 5
  - pas de clamp supérieur dans le code (commentaire indique une limite BitMart).

