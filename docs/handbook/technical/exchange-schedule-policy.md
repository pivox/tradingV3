# Exchange schedule policy

## Objectif

Cette page définit la politique des déclenchements par exchange, market type et profil.

Historiquement, la politique portait surtout sur les schedules Temporal. La cible retenue déplace l'orchestration vers une API Python : Temporal reste un cron basique qui appelle `/orchestrator/run`, tandis que les sets et les appels parallèles sont pilotés par l'API Python.

Cette page évite qu'un nouveau déclenchement rende OKX ou Hyperliquid live avant validation complète.

## Règle générale

Un déclenchement doit déclarer explicitement :

- exchange ;
- market type ;
- profil ;
- environnement ;
- mode dry-run ou live ;
- quota de contrats ;
- garde-fous rate limits ;
- audit attendu ;
- statut readiness.

## Statuts

| Statut | Sens |
|---|---|
| `simulation_allowed` | Autorisé seulement avec Fake/Paper. |
| `dry_run_allowed` | Autorisé sans ordre live, après validation des garde-fous. |
| `legacy_allowed` | Autorisé uniquement parce que le runtime historique en dépend. |
| `live_forbidden` | Interdit en live. |
| `live_candidate` | Potentiellement live plus tard, après PR dédiée. |

## Couche unique de garde-fous live (orchestrateur)

Côté `python-orchestrator`, la décision « ce set peut-il s'exécuter en live ? »
est centralisée en **un seul module fail-closed**, `app/services/live_guard.py`
(`assess_live(...) -> LiveDecision`). La persistance des sets
(`assert_set_persistable`), les sets en mémoire (`assert_live_allowed`) et le
runner (`_dispatch_set`) délèguent tous à cette source unique — il n'y a plus de
politique live dupliquée. La décision distingue explicitement :

- **interdictions permanentes** : OKX et Hyperliquid live restent interdits
  **même interrupteur d'activation activé et même exchange allow-listé** (garde
  jamais relâchée, normalisation casse/espaces incluse) ;
- **verrou transitoire** : un interrupteur d'activation explicite
  `ORCHESTRATION_LIVE_ENABLED` (défaut **OFF**) + une allow-list
  `ORCHESTRATION_LIVE_EXCHANGES` (défaut **vide**, au plus `bitmart`, + `fake` en
  simulation). Tant que l'interrupteur est OFF, **tout** set `dry_run=false` est
  skippé fail-closed, comme avant ;
- **prérequis runtime** : même live autorisé, un set n'est dispatché que si le
  snapshot d'état ouvert est présent (sinon skip `open_state_unavailable`), et
  l'override run-level `{"dry_run": true}` force toujours le dry (prééminence
  sécurité).

Le live reste **désactivé par défaut** dans la config livrée : l'interrupteur
rend l'activation *possible, explicite, auditable et testée*, sans la réactiver.

## Politique par exchange

| Exchange | Simulation | Dry-run | Live | Commentaire |
|---|---:|---:|---:|---|
| Fake / Paper | Oui | Oui | Non | Filet de sécurité et gateway de test. |
| OKX | Oui via Fake/Paper | Oui après runtime-check | Non | Live interdit dans les PRs de préparation. |
| Hyperliquid | Oui via Fake/Paper | Oui après runtime-check | Non | Live interdit dans les PRs de préparation. |
| Bitmart legacy | Non cible | Legacy seulement | Legacy seulement | À retirer plus tard, sans casser l'existant. |

## Cible avec API Python

Le déclenchement cible n'est plus "un schedule Temporal par exchange/profil". La cible devient :

```text
Temporal schedule unique
→ activity minimale
→ POST /orchestrator/run
→ API Python lit les sets actifs
→ API Python lance les appels Symfony en parallèle
```

Les sets Python deviennent la déclaration opérationnelle. Chaque set doit porter au minimum :

```yaml
set_id: bitmart_regular_live_top_30
enabled: true
action: mtf_run
exchange: bitmart
market_type: perpetual
mtf_profile: regular
environment: mainnet
dry_run: false
workers: 1
sync_tables: false
contracts_limit: 30
priority: 10
```

`sync_tables: false` est obligatoire pour les sets préparés `mtf_run` lorsque les contrats ont déjà été rafraîchis via le flux explicite front/API Python. Tant que Symfony ne sait pas honorer ce champ, ces sets ne doivent pas être considérés comme prêts pour l'orchestration parallèle.

Ce format est indicatif pour la documentation. Il décrit la cible fonctionnelle, pas une implémentation déjà livrée.

## Cadence

La cadence 1 minute est sensible.

Avant d'autoriser une cadence 1m pour un dashboard ou un set, il faut valider :

- rate limits exchange ;
- coûts de requêtes REST ;
- WebSocket privé stable ;
- idempotence ;
- lock cross-profile ;
- audit complet (la piste d'audit structurée minimale est livrée par OBS-001 ;
  les métriques par set restent à venir, cf. OBS-002) ;
- dry-run stable ;
- absence de double soumission ;
- SL attaché immédiatement ;
- logs exploitables ;
- dernier JSON conservé.

## Concurrence

La concurrence est pilotée par l'API Python, pas par les workers Symfony.

Règles cibles :

- `workers=1` côté Symfony au début ;
- `sync_tables=false` pour les sets préparés ;
- concurrence globale bornée côté API Python ;
- pas deux appels live incompatibles sur le même symbole ;
- pas de parallélisation illimitée ;
- pas de live orchestré sans idempotence et locks.

## Fake / Paper

Fake/Paper est autorisé pour :

- simulation ;
- dry-run ;
- validation d'OrderPlan ;
- validation d'ExecutionPort ;
- replay ;
- backtesting ;
- contrôle des invariants.

Fake/Paper ne doit jamais envoyer d'ordre live.

## OKX

OKX peut avoir des sets dry-run seulement si :

- runtime-check OK ;
- credentials disponibles hors Git ;
- dry-run explicitement activé ;
- live explicitement désactivé ;
- audit actif ;
- Fake/Paper disponible comme fallback.

OKX live est interdit tant qu'une PR dédiée de readiness live n'a pas été validée.

## Hyperliquid

Hyperliquid peut avoir des sets dry-run seulement si :

- runtime-check OK ;
- credentials disponibles hors Git ;
- environnement test/mainnet clarifié ;
- dry-run explicitement activé ;
- live explicitement désactivé ;
- audit actif ;
- Fake/Paper disponible comme fallback.

Hyperliquid live est interdit tant qu'une PR dédiée de readiness live n'a pas été validée.

## Bitmart legacy

Bitmart peut rester schedulé ou orchestré uniquement si le runtime historique en dépend encore.

Règles :

- ne pas créer de nouveaux chemins Bitmart live sans idempotence et locks ;
- documenter les déclenchements legacy existants ;
- retirer Bitmart après inventaire ;
- ne pas casser `mtf:run`, `POST /api/mtf/run` ou le déclenchement Temporal pendant la transition.

## Gates avant ajout d'un nouveau set

Avant tout nouveau set, vérifier :

1. la gateway est listée dans la readiness matrix ;
2. le runtime-check est disponible ;
3. l'exchange n'est pas live par défaut ;
4. le profil est explicitement autorisé ;
5. la cadence est justifiée ;
6. les rate limits sont connus ;
7. l'audit minimal est actif (**outillé depuis OBS-001** : piste structurée JSON
   line corrélée par `run_id` sur le logger `orchestrator.audit`, émise au fil du
   run par `POST /orchestrator/run` — cf. `docs/handbook/technical/python-orchestrator.md`,
   §*Observabilité / Audit des runs (OBS-001)*) ;
8. Fake/Paper fallback existe ;
9. la PR reste atomique ;
10. les invariants trading ne sont pas cassés.

## Hors-scope des PRs de préparation

Les PRs de préparation ne doivent pas :

- créer de chemin live OKX ;
- créer de chemin live Hyperliquid ;
- augmenter la cadence pour chercher plus de trades ;
- contourner le runtime-check ;
- désactiver le dry-run sans validation ;
- modifier les stratégies pour forcer des trades ;
- desserrer les EntryZones ;
- modifier le levier.
