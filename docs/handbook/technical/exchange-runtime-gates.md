# Exchange runtime gates

## Objectif

Cette page définit les gates minimales avant qu’un exchange puisse être utilisé par un flux runtime.

Elle s’applique aux gateways cibles :

- Fake / Paper ;
- OKX ;
- Hyperliquid ;
- Bitmart legacy tant qu’il reste en runtime.

## Principe

Un exchange ne doit jamais être activé en live par simple présence d’un adapter, d’un provider ou de credentials.

La bascule live doit être explicite, testée et traçable.

## Gates obligatoires avant tout live

| Gate | Description | Bloquant live |
|---|---|---:|
| Credentials présents | Les clés nécessaires sont disponibles hors Git. | Oui |
| Credentials valides | Les clés sont non vides (vérification de présence uniquement). `app:exchange:runtime-check` n’effectue pas de sonde REST ; la validité réelle doit être confirmée séparément par un appel authentifié. | Oui |
| Exchange explicitement autorisé | L’exchange est listé dans la config effective. | Oui |
| Market type explicitement autorisé | Le couple exchange/market type est supporté. | Oui |
| Dry-run validé | Les opérations critiques ont été testées sans live. | Oui |
| Runtime-check OK | La commande ou le service runtime-check confirme la disponibilité. | Oui |
| WebSocket privé OK | Les événements ordres/positions sont observables. | Oui pour live |
| Balance fetch OK | Le solde disponible est lisible. | Oui |
| Position fetch OK | Les positions ouvertes sont lisibles. | Oui |
| Order placement OK | Le placement d’ordre est validé en dry-run ou environnement test. | Oui |
| Order cancel OK | L’annulation est validée. | Oui |
| SL attaché | Le stop-loss automatique est attaché immédiatement. | Oui |
| TP/SL reconciliation | Les protections sont relues et reconciliées. | Oui |
| Liquidation guard OK | Le risque de liquidation est contrôlé. | Oui |
| Idempotence OK | Pas de double ordre pour la même décision. | Oui |
| Audit minimal OK | Les décisions, rejets, ordres et protections sont journalisés. | Oui |
| Temporal schedule autorisé | Le schedule déclare explicitement exchange/profile/mode. | Oui |
| Fallback Fake/Paper disponible | Possibilité de revenir à une exécution non-live. | Oui |

## Gates par environnement

### Dev

Dev doit rester permissif pour l’expérimentation, mais sans live implicite.

Règles :

- Fake/Paper autorisé ;
- OKX dry-run autorisé si runtime-check OK ;
- Hyperliquid dry-run autorisé si runtime-check OK ;
- Bitmart legacy seulement si nécessaire ;
- live interdit par défaut.

### Prod

Prod doit être strict.

Règles :

- aucun live sans validation explicite ;
- aucun live OKX/Hyperliquid dans les PRs de préparation ;
- aucun schedule live sans readiness complète ;
- toute activation live doit être une PR dédiée ;
- toute activation live doit être réversible.

## Gates spécifiques OKX

OKX reste `dry-run only` tant que les gates suivantes ne sont pas toutes validées :

- `OKX_DEMO_API_KEY` présent hors Git ;
- `OKX_DEMO_API_SECRET` présent hors Git ;
- `OKX_DEMO_API_PASSPHRASE` présent hors Git ;
- `OKX_SIMULATED_TRADING=1` explicite pour les requêtes privées demo ;
- environnement demo/sandbox clarifié ;
- API base URI validée ;
- WebSocket public validé ;
- WebSocket privé validé ;
- balance fetch validé ;
- position fetch validé ;
- order placement dry-run validé ;
- order cancel dry-run validé ;
- SL/TP attach validé ;
- reconciliation validée ;
- audit complet.

## Gates spécifiques Hyperliquid

Hyperliquid reste `dry-run only` tant que les gates suivantes ne sont pas toutes validées :

- clé privée présente hors Git ;
- account address présent hors Git ;
- environnement mainnet/testnet clarifié ;
- API base URI validée ;
- WebSocket ou mécanisme équivalent validé ;
- balance fetch validé ;
- position fetch validé ;
- order placement dry-run validé ;
- order cancel dry-run validé ;
- SL/TP ou mécanisme de protection équivalent validé ;
- liquidation guard adapté ;
- reconciliation validée ;
- audit complet.

## Gates spécifiques Fake / Paper

Fake/Paper ne doit jamais envoyer d’ordre live.

Il doit valider :

- construction d’un `OrderPlan` ;
- idempotence ;
- SL obligatoire ;
- TP/SL simulated attach ;
- liquidation guard ;
- frais simulés ;
- slippage simulé ;
- audit ;
- replay/backtesting.

## Gates spécifiques Bitmart legacy

Bitmart reste uniquement `legacy_runtime_only`.

Règles :

- ne pas supprimer tant que le runtime dépend de lui ;
- ne pas ajouter de nouvelle logique cible basée sur Bitmart ;
- ne pas utiliser Bitmart comme modèle des DTOs TradingCore ;
- inventorier ses dépendances avant suppression ;
- retirer uniquement par PR dédiée.

## Anti-patterns interdits

- Passer OKX live parce que les credentials existent.
- Passer Hyperliquid live parce que l’adapter compile.
- Ajouter un schedule Temporal live sans readiness complète.
- Brancher un exchange au runtime sans audit.
- Ouvrir une position sans SL attaché.
- Changer le levier pour compenser un problème d’exécution.
- Supprimer Bitmart avant Fake/Paper stable.
- Desserrer les EntryZones pour augmenter la fréquence sans preuve PnL nette.
