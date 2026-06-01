# Design API-11 - Matrice runtime Exchange/Profile

## Contexte

Issue : https://github.com/pivox/tradingV3/issues/126

Le code multi-exchange contient déjà les briques communes :

- `App\Common\Enum\Exchange` déclare `bitmart`, `binance`, `fake`, `hyperliquid` et `okx`.
- `App\Common\Enum\MarketType` déclare `perpetual` et `spot`.
- Les adapters exchange OKX et Hyperliquid existent pour les marchés perpetual.
- Le worker Temporal sait déjà transmettre `exchange`, `market_type` et `mtf_profile` via `MtfJob`.

La couche scheduling runtime reste implicite. Les scripts Temporal actuels omettent parfois `exchange` et `market_type`, ce qui retombe sur `bitmart/perpetual`, ou codent le profil en dur. Le script `scalper_micro` force aussi un payload non dry-run dans le code, ce qui est dangereux pour un profil expérimental.

API-11 introduit une matrice runtime explicite :

```text
exchange x market_type x mtf_profile
```

L'objectif est que chaque nouveau schedule multi-exchange soit explicite, inspectable et protégé contre une activation live accidentelle, sans exiger que tout le runtime provider OKX/Hyperliquid soit prêt avant de pouvoir créer des schedules en dry-run.

## Décisions

L'approche validée est un gestionnaire de schedules Temporal générique avec diagnostic runtime Symfony.

1. Les schedules `dry_run=true` sont autorisés même si le runtime n'est pas totalement prêt.
2. Les schedules live sont bloqués tant que les guardrails et le diagnostic Symfony ne passent pas.
3. Le nouveau script appelle le diagnostic Symfony via Docker Compose.
4. `dry_run=true` est le défaut pour tous les exchanges, y compris BitMart.
5. `schedule_id` et `workflow_id` sont générés par défaut depuis exchange, profil et cadence, avec override possible par CLI.
6. Les scripts existants restent inchangés et sont documentés comme legacy.

## Requête Runner Symfony

`MtfRunnerRequestDto::normalizeExchange()` utilisera l'enum `Exchange` au lieu d'un `match` codé en dur :

```php
private static function normalizeExchange(string $value): Exchange
{
    $normalized = strtolower(trim($value));

    return Exchange::tryFrom($normalized)
        ?? throw new \InvalidArgumentException(sprintf('Unsupported exchange "%s"', $value));
}
```

Tous les exchanges actuellement déclarés dans `App\Common\Enum\Exchange` seront acceptés :

```text
bitmart
binance
fake
hyperliquid
okx
```

Les appels existants qui omettent `exchange` et `market_type` restent compatibles. API-11 ne supprime pas le fallback legacy vers `bitmart/perpetual`; elle garantit seulement que les nouveaux schedules de matrice envoient un payload explicite.

La normalisation `market_type` reste inchangée et continue d'accepter :

```text
perpetual
perp
future
futures
spot
```

## Script Temporal générique

Un nouveau script sera ajouté :

```text
cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py
```

Il supportera :

```bash
create
status
pause
resume
delete
```

Arguments principaux :

```bash
--exchange=bitmart|binance|okx|hyperliquid|fake
--market-type=perpetual|spot
--profile=regular|scalper|scalper_micro
--workers=1..N
--dry-run=true|false
--cron="*/1 * * * *"
--schedule-id=<custom-schedule-id>
--workflow-id=<custom-workflow-id>
--dry-run-schedule
```

`--dry-run` contrôle le payload MTF envoyé à `/api/mtf/run`.

`--dry-run-schedule` prévisualise la création du schedule Temporal sans créer ni modifier de schedule.

Le script construit toujours un job explicite :

```json
{
  "url": "http://trading-app-nginx:80/api/mtf/run",
  "workers": 4,
  "dry_run": true,
  "mtf_profile": "scalper",
  "exchange": "okx",
  "market_type": "perpetual"
}
```

L'URL par défaut vient de la variable existante `MTF_WORKERS_URL`, avec fallback sur `http://trading-app-nginx:80/api/mtf/run`.

## Génération des IDs

Si l'appelant ne fournit pas d'IDs, le script les génère.

Exemples :

```text
cron */1 * * * * + okx + scalper
schedule_id = cron-mtf-okx-scalper-1m
workflow_id = mtf-okx-scalper-runner

cron */5 * * * * + bitmart + regular
schedule_id = cron-mtf-bitmart-regular-5m
workflow_id = mtf-bitmart-regular-runner
```

Le suffixe de cadence est dérivé des expressions cron courantes :

```text
*/1 * * * * -> 1m
*/5 * * * * -> 5m
*/15 * * * * -> 15m
0 * * * * -> 1h
```

Pour les expressions cron non standard, le script utilise un suffixe déterministe et sanitizé afin que les IDs générés restent stables et valides.

## Commande Symfony de diagnostic runtime

Une commande Symfony sera ajoutée :

```bash
php bin/console app:exchange:runtime-check okx perpetual
```

Le script l'appellera par défaut via Docker Compose :

```bash
docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check okx perpetual
```

La commande émet des champs texte stables :

```text
Exchange: okx
Market type: perpetual
Adapter: found
Provider bundle: missing
Credentials: missing
REST: unknown
Private WS: unsupported
Live trading: disabled
Recommended dry_run: true
Schedule ready: no
```

Contrôles :

- Parser `Exchange` et `MarketType`.
- Vérifier qu'un `ExchangeAdapterInterface` existe pour le couple.
- Vérifier qu'un `ExchangeProviderBundle` existe pour le couple.
- Inspecter les credentials et flags live/demo/mainnet pour OKX et Hyperliquid.
- Rapporter le support private WebSocket depuis les capabilities de l'adapter.
- Rapporter REST en `ok`, `unknown` ou `failed`; le check ne doit pas avoir d'effet de bord de trading.
- Déduire `Recommended dry_run` et `Schedule ready`.

`Schedule ready: yes` signifie qu'un schedule peut s'exécuter de bout en bout sans dépendre du fallback legacy BitMart. Cela ne signifie pas que le trading live doit être activé automatiquement.

## Guardrails live

Le script Python applique des règles différentes pour les payloads dry-run et live.

Pour `dry_run=true` :

- La création du schedule est autorisée même si `Schedule ready: no`.
- Le script affiche un warning clair indiquant que le schedule est valide côté Temporal mais peut échouer côté runtime Symfony.
- Ce comportement est volontaire pour le rollout OKX/Hyperliquid, où la matrice Temporal peut être créée avant la readiness complète des providers.

Pour `dry_run=false` :

- La création est refusée si le diagnostic runtime Symfony échoue.
- La création est refusée si `Schedule ready: no`.
- La création est refusée si les credentials manquent.
- La création est refusée si le live trading est disabled.
- OKX et Hyperliquid restent opt-in uniquement pour le live.

Contrôles live OKX :

```text
OKX_ENV défini
OKX_API_KEY présent
OKX_API_SECRET présent
OKX_API_PASSPHRASE présent
OKX_DEMO_TRADING_ENABLED=true en mode demo trading
OKX_LIVE_ENABLED=true en mode live
adapter présent
provider bundle présent
market_type supporté
```

Contrôles live Hyperliquid :

```text
HYPERLIQUID_ENV défini
HYPERLIQUID_ACCOUNT_ADDRESS présent
HYPERLIQUID_PRIVATE_KEY présent pour le trading signé
HYPERLIQUID_MAINNET_ENABLED=true en mode mainnet
client signé disponible avant trading live
private WebSocket non annoncé comme enabled s'il est unsupported
capacité de réconciliation REST disponible
adapter présent
provider bundle présent
market_type supporté
```

BitMart passe aussi par défaut en `dry_run=true`; un schedule BitMart live exige `--dry-run=false` explicite et un runtime check passant.

## Scripts legacy

Ces scripts restent inchangés :

```text
cron_symfony_mtf_workers/scripts/manage_mtf_workers_schedule.py
cron_symfony_mtf_workers/scripts/manage_scalper_micro_schedule.py
```

Ils seront documentés comme legacy. Ils peuvent rester utiles pour des déploiements existants, mais les nouveaux schedules multi-exchange doivent utiliser `manage_exchange_profile_schedule.py` parce qu'il envoie toujours `exchange`, `market_type` et `mtf_profile` explicitement.

## Matrice runtime recommandée

La documentation définira ces schedules recommandés :

```text
cron-mtf-bitmart-scalper-1m
cron-mtf-bitmart-scalper-micro-1m
cron-mtf-bitmart-regular-5m

cron-mtf-okx-scalper-1m
cron-mtf-okx-scalper-micro-1m
cron-mtf-okx-regular-5m

cron-mtf-hyperliquid-scalper-1m
cron-mtf-hyperliquid-scalper-micro-1m
cron-mtf-hyperliquid-regular-5m
```

Tous les exemples sont en `dry_run=true` par défaut. Les exemples OKX et Hyperliquid restent en dry-run tant que le diagnostic ne remonte pas un runtime prêt et que les flags live ne sont pas explicitement activés.

## Documentation

`cron_symfony_mtf_workers/README.md` sera mis à jour avec :

- Le nouveau script comme gestionnaire recommandé.
- La table de matrice runtime.
- Des exemples de commandes `create`, `status`, `pause`, `resume` et `delete`.
- La distinction entre `--dry-run` et `--dry-run-schedule`.
- Le statut legacy des anciens scripts.
- L'obligation que les nouveaux schedules de matrice ne dépendent jamais du fallback implicite BitMart.

Si le README devient trop dense, un runbook ciblé sous `docs/trading-app/` peut porter la matrice longue et la procédure d'activation, avec un lien depuis le README du worker.

## Tests

Tests PHP :

- Ajouter `trading-app/tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php`.
- Couvrir la normalisation exchange pour `bitmart`, `binance`, `fake`, `okx` et `hyperliquid`.
- Couvrir l'échec sur exchange non supporté.
- Couvrir les market types `perpetual`, `futures` et `spot`.

Tests Python :

- Tester la construction du payload OKX scalper.
- Tester la construction du payload BitMart scalper micro.
- Tester `dry_run=true` comme défaut.
- Tester la génération `schedule_id` et `workflow_id`.
- Tester que `dry_run=false` est refusé quand le diagnostic Symfony retourne `Schedule ready: no`.
- Tester que `dry_run=true` est autorisé avec warning quand le diagnostic Symfony retourne `Schedule ready: no`.

Commandes cibles :

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php

cd cron_symfony_mtf_workers
PYTHONPATH=$PWD pytest
```

## Hors scope

- Activer le trading live OKX ou Hyperliquid.
- Modifier la logique de validation MTF.
- Modifier l'entrée en position, SL/TP ou sizing.
- Refactorer les scripts de schedule legacy existants.
- Câbler complètement les provider bundles OKX/Hyperliquid, sauf changement minimal requis pour que le diagnostic reflète correctement l'état existant.
- Résoudre l'anti-doublon entre profils; ce point appartient à API-12.

## Correspondance acceptance criteria

- `MtfRunnerRequestDto` accepte tous les exchanges déclarés dans `Exchange`.
- Un script générique `manage_exchange_profile_schedule.py` existe.
- Le script générique crée des schedules Temporal avec `exchange`, `market_type` et `mtf_profile` explicites.
- Le script générique supporte `create`, `status`, `pause`, `resume` et `delete`.
- Chaque nouveau schedule est en `dry_run=true` par défaut.
- `dry_run=false` est bloqué tant que les guardrails live et le diagnostic Symfony ne passent pas.
- `app:exchange:runtime-check` existe.
- La documentation de matrice runtime est ajoutée.
- Les scripts existants sont documentés comme legacy.
- Les nouveaux schedules de matrice ne dépendent pas du fallback implicite BitMart.
