# Demo/Testnet Safety Envelope

## Objectif

Le safety envelope `COMMON-001` ajoute un contrat commun avant toute execution
mutative OKX demo ou Hyperliquid testnet.

Il ne branche aucun runtime, ne place aucun ordre et ne lit aucun secret. Il fournit
une decision pure et auditable pour verifier si une demande reste en simulation
locale, devient candidate demo/testnet, ou peut etre autorisee en demo/testnet par
une future PR dediee.

## Terminologie

| Terme | Sens |
|---|---|
| `local_dry_run` | Simulation locale. Aucun ordre exchange. |
| `demo` | Environnement OKX demo uniquement. |
| `testnet` | Environnement Hyperliquid testnet uniquement. |
| `mainnet` | Interdit en ecriture dans cette serie. |
| `dry_run=true` | Aucun ordre envoye a l'exchange. |
| `dry_run=false` | Ecriture demandee, autorisable uniquement en `demo` ou `testnet` si tous les guards passent. |

Le terme "live demo" ne signifie jamais mainnet. Dans TradingV3, il signifie
uniquement `dry_run=false` sur `environment=demo|testnet`, avec activation explicite
et guards complets.

## Contrat PHP

Le contrat est isole dans `App\TradingCore\Execution\Safety` :

- `ExchangeRuntimeEnvironment`
- `DemoTradingSafetyLevel`
- `DemoTradingSafetyPolicy`
- `DemoTradingSafetyDecision`
- `DemoTradingSafetyPolicyEvaluator`
- `DemoTradingMutationAttempt`
- `DemoTradingKillSwitchService`
- `DemoTradingKillSwitchDecision`
- `DemoTradingAuditSinkInterface`

`DemoTradingSafetyPolicyEvaluator` est pur :

- aucun appel HTTP ;
- aucun appel exchange ;
- aucun acces a Symfony, Doctrine, Messenger ou Temporal ;
- aucun secret requis ;
- aucun effet de bord runtime.

`DemoTradingKillSwitchService` est la facade runtime commune ajoutee par
COMMON-004. Elle combine :

- les switches d'environnement `DEMO_TRADING_ENABLED`,
  `OKX_DEMO_TRADING_ENABLED`, `HYPERLIQUID_TESTNET_TRADING_ENABLED` ;
- le `kill_switch_enabled` de la config effective ;
- la policy pure `DemoTradingSafetyPolicyEvaluator` ;
- un audit standardise via `DemoTradingAuditSinkInterface`.

Le service reste fail-closed : si l'audit ne peut pas etre ecrit, la decision
retournee est bloquee avec `audit_failed`.

## Niveaux de decision

| Niveau | Signification |
|---|---|
| `blocked` | La demande est refusee et expose `blocking_errors`. |
| `local_dry_run` | Simulation locale autorisee, sans ordre exchange. |
| `demo_testnet_candidate` | Environnement demo/testnet en dry-run, candidat a une future activation controlee. |
| `demo_testnet_enabled` | Ecriture demo/testnet autorisee par la policy. |

## Guards

Une demande d'ecriture est toute policy avec `dry_run=false`.

Guards invariants :

- `mainnet_write_enabled=true` bloque toujours ;
- `environment=mainnet` avec `dry_run=false` bloque toujours ;
- `environment=local_dry_run` avec `dry_run=false` bloque toujours.

Guards obligatoires pour `demo|testnet` avec `dry_run=false` :

- `DEMO_TRADING_ENABLED=true` ;
- le switch exchange cible actif (`OKX_DEMO_TRADING_ENABLED=true` pour OKX demo
  ou `HYPERLIQUID_TESTNET_TRADING_ENABLED=true` pour Hyperliquid testnet) ;
- `demo_testnet_write_enabled=true` ;
- `kill_switch_enabled=false` ;
- `require_stop_loss=true` ;
- `allowed_symbols` ou `allowed_markets` non vide ;
- `max_notional` positif, fini et renseigne ;
- `requested_symbol` ou `requested_market` present et autorise par la whitelist ;
- `requested_notional` positif, fini et inferieur ou egal a `max_notional` ;
- `stop_loss_present=true`.

Une policy avec seulement une configuration globale ne suffit donc pas pour obtenir
`demo_testnet_enabled`. Le niveau enabled prouve que la requete concrete comparee
a la policy respecte aussi la whitelist, le plafond notionnel et la presence SL.

Les erreurs sont explicites :

- `demo_trading_disabled`
- `okx_demo_trading_disabled`
- `hyperliquid_testnet_trading_disabled`
- `effective_kill_switch_enabled`
- `mainnet_write_enabled_must_remain_false`
- `mainnet_write_forbidden`
- `local_dry_run_cannot_write`
- `demo_testnet_write_not_enabled`
- `kill_switch_enabled`
- `stop_loss_required`
- `allowed_symbols_or_markets_required`
- `max_notional_required`
- `requested_notional_required`
- `max_notional_exceeded`
- `requested_symbol_or_market_required`
- `requested_symbol_or_market_not_allowed`
- `stop_loss_presence_required`
- `stop_loss_missing`
- `audit_failed`

## Audit COMMON-004

Toute tentative mutative controlee par `DemoTradingKillSwitchService` produit une
entree d'audit avec :

- `exchange`
- `environment`
- `mode`
- `profile`
- `symbol`
- `market`
- `notional`
- `client_order_id`
- `action`
- `allowed`
- `outcome`
- `reasons`
- `correlation_ids`
- `safety`
- `audit_context`

Les relations ambiguës ou blocages restent visibles dans `reasons`. Le service ne
resout pas arbitrairement une tentative bloquee.

## Redaction

`DemoTradingSafetyDecision::toRedactedArray()` expose un payload d'audit sans secret.
Les champs sensibles du `audit_context` sont remplaces par `[redacted]` si leur cle
contient notamment :

- `secret`
- `token`
- `api_key`
- `private_key`
- `passphrase`
- `password`
- `signature`

Les exemples, tests et docs ne contiennent pas de secrets reels.

## Hors scope

Cette PR ne fait pas :

- activation OKX demo ;
- activation Hyperliquid testnet ;
- branchement TradeEntry, MTF, Temporal ou Messenger ;
- appel REST/WS ;
- modification strategie, EntryZone, Risk/Leverage ou SL/TP metier ;
- suppression ou modification Bitmart legacy.

## Rollback

Rollback immediat demo/testnet : remettre `DEMO_TRADING_ENABLED=0`,
`OKX_DEMO_TRADING_ENABLED=0`, `HYPERLIQUID_TESTNET_TRADING_ENABLED=0` et
`trading.execution.kill_switch_enabled=true`, puis redemarrer les processus qui
lisent l'environnement si necessaire.

Rollback applicatif : supprimer l'usage futur du service ou revenir au commit
precedent. Comme COMMON-004 ne branche pas encore de runtime mutatif reel, son
rollback n'affecte pas les flux MTF, TradeEntry, OKX dry-run ou Hyperliquid
dry-run actuels.

Voir aussi : [Demo/Testnet Kill Switch Runbook](../runbooks/demo-testnet-kill-switch.md).
