# Exchange readiness matrix

## Objectif

Cette page définit l’état de readiness des gateways exchange dans TradingV3.

Elle ne rend aucun exchange prêt au live. Elle sert à éviter les confusions entre :

- ce qui existe déjà dans le code ;
- ce qui est utilisable en dry-run ;
- ce qui reste legacy ;
- ce qui est interdit avant validation runtime.

## Statuts canoniques

| Statut | Sens |
|---|---|
| `target_test_gateway` | Gateway de test/simulation utilisable pour valider le pipeline sans live. |
| `target_dry_run_only` | Gateway cible future, mais uniquement dry-run/runtime-check pour l’instant. |
| `legacy_runtime_only` | Présent parce que le runtime historique en dépend encore, mais à retirer plus tard. |
| `not_ready` | Ne doit pas être utilisé par le runtime. |

## Contrat runtime COMMON-003

`COMMON-003` ajoute un contrat PHP pur dans `App\Exchange\Readiness` :

- `ExchangeReadinessLevel` ;
- `ExchangeReadinessInput` ;
- `ExchangeReadinessReport` ;
- `ExchangeReadinessEvaluator` ;
- `ExchangeRuntimeCheckInterface`.

Ce contrat ne fait aucun appel REST, WebSocket, Doctrine, Messenger ou Temporal. Les
implementations OKX et Hyperliquid futures devront alimenter `ExchangeReadinessInput`
avec des preuves deja collectees ou des fixtures de test, puis retourner le meme
`ExchangeReadinessReport`.

Le rapport expose une forme stable pour le cockpit :

- `exchange` ;
- `market_type` ;
- `environment` ;
- `ready_level` ;
- `public_connectivity` ;
- `private_read_connectivity` ;
- `private_observability` ;
- `instruments_loaded` ;
- `metadata_valid` ;
- `precision_valid` ;
- `account_readable` ;
- `permissions_read` ;
- `permissions_trade` ;
- `mainnet_write_guard` ;
- `demo_testnet_write_guard` ;
- `stop_loss_capability` ;
- `kill_switch` ;
- `allowed_symbols` ;
- `allowed_markets` ;
- `max_notional` ;
- `config_hash` ;
- `blocking_errors` ;
- `warnings`.

Les champs `blocking_errors` et `warnings` sont serialises avec redaction defensive
des messages contenant des termes de secret (`api_key`, `secret`, `private_key`,
`passphrase`, `authorization`, `cookie`, `token`, `signature`, `credentials`).
Les implementations ne doivent pas y placer de payload brut.

## Niveaux readiness COMMON-003

| Niveau | Sens | Ecriture exchange |
|---|---|---|
| `not_ready` | Un pre-requis bloquant manque. | Non |
| `public_read_only` | Connectivite publique, instruments et metadata exploitables. | Non |
| `private_read_only` | Lecture privee/account possible en plus du public. | Non |
| `local_dry_run_ready` | Config et guards suffisants pour construire une simulation locale auditable. | Non |
| `demo_testnet_candidate` | Candidat demo/testnet avec SL capability, mais ecriture non autorisee. | Non |
| `demo_testnet_enabled` | Ecriture demo/testnet autorisee par guards explicites. | Demo/testnet uniquement |

`mainnet_ready` et `live_ready` sont interdits dans cette serie et ne sont pas des
valeurs de l'enum.

Regles fail-closed :

- absence d'instruments charges => `not_ready` avec `instruments_not_loaded` ;
- absence de guard mainnet => `not_ready` avec `mainnet_write_guard_missing` ;
- absence de `stop_loss_capability` => impossible d'atteindre `demo_testnet_candidate` ;
- kill switch actif => impossible d'atteindre `demo_testnet_enabled` ;
- `demo_testnet_enabled` exige aussi `demo_testnet_write_enabled=true`,
  `permissions_trade=true`, whitelist symbole/marche et `max_notional`.

Les configs effectives OKX demo et Hyperliquid testnet livrees par `COMMON-002`
peuvent produire un rapport `demo_testnet_candidate` en fixture si les signaux
public/private read sont verts. Elles restent non mutatives par defaut :
`demo_testnet_write_enabled=false` et `kill_switch_enabled=true`.

## Matrice synthétique

| Exchange | Statut cible | Runtime actuel | Dry-run autorisé | Live autorisé | Rôle |
|---|---|---:|---:|---:|---|
| Fake / Paper | `target_test_gateway` | À valider | Oui | Non | Gateway de sécurité pour tests, simulation, replay, OrderPlan et ExecutionPort. |
| OKX | `target_dry_run_only` | À valider | Oui, après runtime-check | Non | Gateway cible future, jamais live tant que la readiness complète n’est pas prouvée. |
| Hyperliquid | `target_dry_run_only` | À valider | Oui, après runtime-check | Non | Gateway cible future, jamais live tant que la readiness complète n’est pas prouvée. |
| Bitmart | `legacy_runtime_only` | Oui, historique | Oui si déjà supporté | Legacy uniquement | À conserver tant que le runtime en dépend, puis retirer par PR dédiée. |

## Matrice détaillée

| Capability | Fake / Paper | OKX | Hyperliquid | Bitmart legacy |
|---|---|---|---|---|
| Adapter présent | À valider | À valider | À valider | Historique |
| Provider bundle présent | À valider | À valider | À valider | Historique |
| Credentials attendus | Non, sauf paramètres de simulation | Oui | Oui | Oui tant que legacy |
| Runtime-check obligatoire | Oui | Oui | Oui | Oui si maintenu |
| WebSocket public | Non requis au début | À valider | À valider | Historique |
| WebSocket privé | Non requis au début | Bloquant avant live | Bloquant avant live | Historique |
| Balance fetch | Simulé | Bloquant avant live | Bloquant avant live | Historique |
| Position fetch | Simulé | Bloquant avant live | Bloquant avant live | Historique |
| Order placement | Simulé | Dry-run uniquement | Dry-run uniquement | Legacy |
| Order cancel | Simulé | Dry-run uniquement | Dry-run uniquement | Legacy |
| SL/TP attach | Simulé et vérifiable | Bloquant avant live | Bloquant avant live | Obligatoire |
| Liquidation guard | Testable | Bloquant avant live | Bloquant avant live | Obligatoire |
| Reconciliation | Testable | Bloquant avant live | Bloquant avant live | Obligatoire |
| Audit/logging | Obligatoire | Obligatoire | Obligatoire | Obligatoire |
| Temporal schedule | Simulation seulement | Dry-run seulement | Dry-run seulement | Legacy uniquement |

## Fake / Paper

Fake / Paper doit devenir la gateway de sécurité canonique.

Elle doit permettre de valider :

- `OrderPlan` ;
- `ExecutionPort` ;
- idempotence ;
- SL obligatoire ;
- liquidation guard ;
- audit minimal ;
- dry-run ;
- backtesting/replay ;
- compatibilité des entrypoints `mtf:run` et `POST /api/mtf/run`.

Fake / Paper doit être disponible avant toute expérimentation sérieuse OKX ou Hyperliquid.

## OKX

OKX est une gateway cible, mais son statut reste `target_dry_run_only`.

OKX ne doit pas passer live tant que les points suivants ne sont pas prouvés :

- credentials présents et valides ;
- runtime-check OK ;
- WebSocket privé OK ;
- balance fetch OK ;
- position fetch OK ;
- order placement dry-run OK ;
- order cancel dry-run OK ;
- SL/TP attach testé ;
- reconciliation testée ;
- audit minimal complet ;
- schedule Temporal explicitement autorisé ;
- fallback Fake/Paper disponible.

## Hyperliquid

Hyperliquid est une gateway cible, mais son statut reste `target_dry_run_only`.

Hyperliquid ne doit pas passer live tant que les points suivants ne sont pas prouvés :

- credentials présents et valides ;
- runtime-check OK ;
- WebSocket public/privé ou mécanisme équivalent validé ;
- balance fetch OK ;
- position fetch OK ;
- order placement dry-run OK ;
- order cancel dry-run OK ;
- SL/TP attach ou équivalent testé ;
- liquidation guard adapté au modèle Hyperliquid ;
- reconciliation testée ;
- audit minimal complet ;
- schedule Temporal explicitement autorisé ;
- fallback Fake/Paper disponible.

## Bitmart legacy

Bitmart reste `legacy_runtime_only`.

Règles :

- ne pas supprimer Bitmart dans les PRs de préparation ;
- ne pas prendre Bitmart comme modèle cible pour les DTOs métier ;
- ne pas baser la future architecture TradingCore sur les payloads Bitmart ;
- retirer Bitmart uniquement après inventaire et PR dédiée ;
- ne pas casser le runtime existant tant qu’il dépend de Bitmart.

## Règles de décision

Un exchange peut être déclaré candidat au live uniquement si :

1. Fake/Paper est stable ;
2. la config effective est résolue et auditée ;
3. les runtime gates sont toutes vertes ;
4. les schedules sont explicitement autorisés ;
5. le dry-run a produit des traces exploitables ;
6. `position_trade_analysis` ou une source équivalente permet de mesurer les résultats ;
7. aucun invariant trading n’est cassé.

## Invariants

- Aucune position sans stop-loss automatique immédiatement attaché.
- Aucun levier arbitraire : le levier doit rester dérivé du risque, du stop et des caps exchange.
- Aucune bascule live sans runtime-check OK.
- OKX/Hyperliquid restent dry-run jusqu’à validation explicite.
- Fake/Paper doit rester disponible comme filet de sécurité.
- Bitmart reste legacy tant que le runtime en dépend.
- Aucune EntryZone ne doit être desserrée sans preuve PnL nette.
