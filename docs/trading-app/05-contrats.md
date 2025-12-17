# Contrats — synchronisation, sélection, validation d’éligibilité

## Données contrat persistées

Entity : `trading-app/src/Provider/Entity/Contract.php`  
Repository : `trading-app/src/Provider/Repository/ContractRepository.php`

Les champs critiques utilisés fonctionnellement pour la sélection :

- `symbol`
- `status` (attendu : `Trading`)
- `quote_currency` (attendu : `USDT` par défaut)
- `turnover_24h` (liquidité)
- `open_timestamp` (âge minimal)
- `expire_timestamp` (optionnel selon config)

## Source BitMart (contrats)

REST public : `GET /contract/public/details`  
Client : `trading-app/src/Provider/Bitmart/Http/BitmartHttpClientPublic.php`

## Sélection des contrats actifs (contract validation)

La sélection “tradable” est effectuée par :

- `ContractRepository::findActiveContracts(profile)`
  - s’appuie sur `findSymbolsMixedLiquidity(profile)`

### Config de sélection (mtf_contracts)

Provider de config :

- `trading-app/src/Config/MtfContractsConfigProvider.php`

Résolution du fichier :

- si profil fourni : `config/app/mtf_contracts.<profile>.yaml` (si existe)
- sinon fallback : `config/app/mtf_contracts.yaml`

Fichiers présents :

- `trading-app/config/app/mtf_contracts.yaml`
- `trading-app/config/app/mtf_contracts.scalper_micro.yaml`

### Filtres appliqués (SQL)

Source : `trading-app/src/Provider/Repository/ContractRepository.php` (`findSymbolsMixedLiquidity`)

Critères :

- `status = <cfg.filters.status>` (défaut `Trading`)
- `quote_currency = <cfg.filters.quote_currency>` (défaut `USDT`)
- `turnover_24h >= <cfg.filters.min_turnover>`
- “not expired” (si `require_not_expired=true`) :
  - `expire_timestamp IS NULL` ou `= 0` ou `> nowForExpire`
  - `nowForExpire` est calculé en secondes ou ms selon `expire_unit`
- “âge minimal” via `open_timestamp` :
  - si `max_age_hours > 0` :
    - calcule `openTsMin = now - max_age_hours` (en sec ou ms selon `open_unit`)
    - filtre `open_timestamp < openTsMin`
    - donc : **exclut les contrats listés trop récemment**
- Exclusions (veto) :
  - table `blacklisted_contract` : exclut symboles non expirés
  - table `mtf_switch` : exclut si switch “OFF” actif

### Buckets TOP / MID et limites

Toujours dans `findSymbolsMixedLiquidity` :

- TOP : `turnover_24h > mid_max_turnover` (tri `order.top`, limite `top_n`)
- MID : `turnover_24h BETWEEN min_turnover AND mid_max_turnover` (tri `order.mid`, limite `mid_n`)
- résultat final = `TOP UNION ALL MID` puis `unique()`

Conséquence :

- un contrat est “validé” (= éligible) si et seulement si :
  - il passe les filtres (statut, quote, turnover, âge, expiration),
  - il n’est pas blacklisté,
  - il n’est pas désactivé par `mtf_switch`,
  - il appartient à un bucket (TOP/MID) non désactivé via `top_n`/`mid_n`.

