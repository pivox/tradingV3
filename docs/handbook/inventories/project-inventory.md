# Inventaire de Couverture

## Racine du depot

| Chemin | Role documentaire |
| --- | --- |
| `README.md` | Point d'entree projet, doit pointer vers ce handbook. |
| `agents.md`, `CLAUDE.md` | Instructions IA et contexte operationnel. |
| `docker-compose.yml` | Stack operationnelle globale. |
| `trading-app/` | Backend Symfony. |
| `cron_symfony_mtf_workers/` | Workers Temporal Python. |
| `frontend/` | React legacy. |
| `docs/handbook/` | Documentation canonique Markdown. |
| `docs/site/` | Documentation HTML generee. |
| `investigation/` | Exports et analyses locales. |

## Backend Symfony

| Dossier | Couverture |
| --- | --- |
| `Command` | Commandes d'exploitation, audit, export et maintenance. |
| `Config` | Providers de configuration applicative. |
| `Contract` | DTOs et interfaces stables entre modules. |
| `Controller` | APIs HTTP et pages web historiques. |
| `Entity` | Entites Doctrine transverses. |
| `Exchange` | Abstraction multi-exchange. |
| `Front` | Interface Ops Twig. |
| `Indicator` | Conditions, providers et snapshots. |
| `MtfRunner` | Orchestration du run. |
| `MtfValidator` | Validation multi-timeframe. |
| `Provider` | Acces marche et Bitmart. |
| `Runtime` | Locks, switches, audit, cache. |
| `Signal` | Pipeline signaux historique. |
| `TradeEntry` | Plans d'ordre, risque et execution. |
| `Trading` | Stockage et analyse positions. |
| `WebSocket` | Services WS. |

## Fichiers Markdown remplaces

Les documents suivants sont remplaces par le handbook et doivent etre supprimes:

| Ancien fichier | Remplacement |
| --- | --- |
| `trading-app/docs/README.md` | `index.md`, `architecture.md` |
| `trading-app/docs/INDICATOR_SWITCH_SYSTEM.md` | `technical/backend-modules.md`, `technical/configuration.md` |
| `trading-app/docs/API_REFERENCE_INDICATOR_SWITCH.md` | `technical/interfaces.md` |
| `trading-app/docs/MIGRATION_GUIDE_INDICATOR_SWITCH.md` | `technical/configuration.md` |
| `trading-app/docs/TROUBLESHOOTING_INDICATOR_SWITCH.md` | `runbooks/investigation.md` |
| `trading-app/docs/ANALYSE_TRADING_YML.md` | `technical/configuration.md` |
| `trading-app/docs/MIGRATION_CONFIG_YAML.md` | `technical/configuration.md` |
| `trading-app/docs/RAPPORT_CONFIGURATIONS_NON_UTILISEES.md` | `technical/configuration.md` |
| `trading-app/docs/ANALYSE_PROBLEMES_POSITIONS_SL.md` | `functional/trade-entry.md`, `runbooks/investigation.md` |
| `trading-app/docs/CHANGELOG_LEVERAGE_DYNAMIC_ROLLBACK.md` | `functional/trade-entry.md` |
| `trading-app/docs/ETUDE_CHANGEMENT_CONTEXT_EXECUTION.md` | `functional/mtf-validation.md` |
| `trading-app/docs/INVESTIGATION_NO_ORDER_PLACED.md` | `runbooks/investigation.md` |
| `trading-app/docs/MTF_PERFORMANCE_ANALYSIS.md` | `functional/mtf-run.md`, `runbooks/operations.md` |
| `trading-app/docs/BUGS_ATR_STOP_LOSS.md` | `functional/trade-entry.md`, `runbooks/investigation.md` |

## Documents conserves comme references specialisees

Les documents existants non supprimes restent utiles s'ils ne contredisent pas le code courant:

- analyses scalper et pertes;
- runbooks MTF audit;
- docs exchange API-first;
- docs cross-profile symbol lock;
- docs zone autotune;
- docs Temporal dans `cron_symfony_mtf_workers/`;
- specs/plans Superpowers dans `docs/superpowers/`.

## Regle de mise a jour

Tout changement affectant un entrypoint, un message Messenger, une config YAML, une entite persistante ou un flux TradeEntry doit mettre a jour une page du handbook et regenerer `docs/site/`.
