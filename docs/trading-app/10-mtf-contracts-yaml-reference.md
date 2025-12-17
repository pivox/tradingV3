# Référence YAML — `mtf_contracts*.yaml` (sélection de contrats)

Cette page documente **les clés, types, valeurs acceptées, valeurs par défaut et comportements** des fichiers :

- `trading-app/config/app/mtf_contracts.yaml` (fallback)
- `trading-app/config/app/mtf_contracts.<profile>.yaml` (profils)

## Résolution du fichier (profile → YAML)

Le fichier est résolu par `MtfContractsConfigProvider` :

- si `profile` est fourni et que `mtf_contracts.<profile>.yaml` existe → il est utilisé
- sinon fallback : `mtf_contracts.yaml`

Source : `trading-app/src/Config/MtfContractsConfigProvider.php`

## Structure racine

Clés racine attendues :

- `version` (string) : utilisé pour invalider le cache interne (reload si version change).
- bloc principal (au choix) :
  - `mtf_contracts:` (format historique), ou
  - `contracts:` (format supporté), ou
  - structure “plate” (fallback si aucun des deux n’est présent)

Source : `trading-app/src/Config/MtfContractsConfig.php`

## `*.selection` (bloc de sélection)

### `selection.enabled` (bool)

Présent dans les YAML, mais **non consommé** par les requêtes SQL actuelles : la sélection applique toujours les filtres/limits si la méthode appelée les utilise.

### `selection.filters` (filtres)

Ces champs sont injectés dans les requêtes :

- `status` (string, défaut `'Trading'`)
  - comparé à `contracts.status`

- `quote_currency` (string, défaut `'USDT'`)
  - comparé à `contracts.quote_currency`

- `min_turnover` (float, défaut `500000`)
  - comparé à `contracts.turnover_24h` via `turnover_24h >= min_turnover`

- `mid_max_turnover` (float, défaut `2000000`)
  - utilisé uniquement dans la sélection “mixte” TOP+MID :
    - TOP : `t24 > mid_max_turnover`
    - MID : `t24 BETWEEN min_turnover AND mid_max_turnover`

- `require_not_expired` (bool, défaut `true`)
  - si `false` : pas de contrainte d’expiration
  - si `true` : garde si `expire_timestamp` est `NULL`, `0`, ou `> now`

- `expire_unit` (string, défaut variable selon méthode)
  - valeurs fonctionnelles : `ms` ou “autre”
  - si `expire_unit == 'ms'` → comparaison avec `now` en **millisecondes**
  - sinon → comparaison avec `now` en **secondes**

- `open_unit` (string, défaut `'s'` dans la sélection mixte)
  - valeurs fonctionnelles : `ms` ou “autre”
  - utilisé uniquement dans la sélection “mixte” (voir plus bas)

- `max_age_hours` (int, défaut `880`)
  - utilisé pour filtrer `open_timestamp` par rapport à une borne `now - max_age_hours`
  - attention : **le comparateur n’est pas identique selon la méthode** (voir “Détails par méthode”)

Sources :

- `trading-app/src/Provider/Repository/ContractRepository.php`
- `trading-app/src/Config/MtfContractsConfig.php`

### `selection.limits` (limites)

- `top_n` (int, défaut `0`) : nombre max de contrats TOP
- `mid_n` (int, défaut `0`) : nombre max de contrats MID

Si `top_n <= 0` et `mid_n <= 0` : la méthode “mixte” retourne `[]`.

### `selection.order` (tri)

Utilisé uniquement dans la sélection “mixte” :

- `order.top` (string, défaut `'DESC'`) : valeurs acceptées `ASC` | `DESC` (sinon fallback)
- `order.mid` (string, défaut `'ASC'`) : valeurs acceptées `ASC` | `DESC` (sinon fallback)

## Détails par méthode (effet exact des filtres)

Le repository offre deux chemins :

### 1) Sans limites (`findAllActiveSymbolsWithoutLimits`)

Utilisé quand `ignoreLimits=true` dans `allActiveSymbolNames()`.

Filtres appliqués :

- `status`, `quote_currency`, `min_turnover`, expiration (`require_not_expired`, `expire_unit`)
- `max_age_hours` :
  - calcule `openTsMinSec = now - max_age_hours`
  - puis filtre : `open_timestamp > openTsMinSec` (en **secondes**)
  - note : `open_unit` n’est pas appliqué dans ce chemin

Source : `trading-app/src/Provider/Repository/ContractRepository.php`

### 2) Mixte TOP+MID (`findSymbolsMixedLiquidity`)

Utilisé par défaut dans `allActiveSymbolNames()`.

Filtres appliqués :

- `status`, `quote_currency`, `min_turnover`, expiration (`require_not_expired`, `expire_unit`)
- `max_age_hours` + `open_unit` :
  - calcule `boundarySec = now - max_age_hours`
  - puis `openTsMin = boundarySec * 1000` si `open_unit == 'ms'` sinon `boundarySec`
  - puis filtre : `open_timestamp < openTsMin`

Puis split :

- TOP (si `top_n > 0`) : `t24 > mid_max_turnover`, tri `order.top`, `LIMIT top_n`
- MID (si `mid_n > 0`) : `t24 BETWEEN min_turnover AND mid_max_turnover`, tri `order.mid`, `LIMIT mid_n`

Source : `trading-app/src/Provider/Repository/ContractRepository.php`

## Champs de refresh/cache

- `version` (racine) : si la version change, `MtfContractsConfig` recharge le fichier.
- `selection.refresh_interval_minutes` (int, défaut `60`) : méthode disponible (`getRefreshInterval()`), mais **aucun appelant** ne l’utilise actuellement.

