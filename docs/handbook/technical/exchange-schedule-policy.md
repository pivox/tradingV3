# Exchange schedule policy

## Objectif

Cette page définit la politique des schedules Temporal par exchange, market type et profil.

Elle évite qu’un nouveau schedule rende OKX ou Hyperliquid live avant validation complète.

## Règle générale

Un schedule doit déclarer explicitement :

- exchange ;
- market type ;
- profil ;
- mode dry-run ou live ;
- cadence ;
- garde-fous rate limits ;
- audit attendu ;
- statut readiness.

## Statuts schedule

| Statut | Sens |
|---|---|
| `simulation_allowed` | Autorisé seulement avec Fake/Paper. |
| `dry_run_allowed` | Autorisé sans ordre live, après runtime-check. |
| `legacy_allowed` | Autorisé uniquement parce que le runtime historique en dépend. |
| `live_forbidden` | Interdit en live. |
| `live_candidate` | Potentiellement live plus tard, après PR dédiée. |

## Politique par exchange

| Exchange | Schedule simulation | Schedule dry-run | Schedule live | Commentaire |
|---|---:|---:|---:|---|
| Fake / Paper | Oui | Oui | Non | Filet de sécurité et gateway de test. |
| OKX | Oui via Fake/Paper | Oui après runtime-check | Non | Live interdit dans les PRs de préparation. |
| Hyperliquid | Oui via Fake/Paper | Oui après runtime-check | Non | Live interdit dans les PRs de préparation. |
| Bitmart legacy | Non cible | Legacy seulement | Legacy seulement | À retirer plus tard, sans casser l’existant. |

## Cadence

La cadence 1 minute est sensible.

Avant d’autoriser une cadence 1m pour un exchange/profil, il faut valider :

- rate limits exchange ;
- coûts de requêtes REST ;
- WebSocket privé stable ;
- idempotence ;
- lock cross-profile ;
- audit complet ;
- dry-run stable ;
- absence de double soumission ;
- SL attaché immédiatement ;
- logs exploitables.

## Format cible de déclaration

Un schedule devrait pouvoir être décrit ainsi :

```yaml
exchange: okx
market_type: perpetual
profile: scalper
mode: dry_run
cadence: "*/1 * * * *"
runtime_check_required: true
live_enabled: false
audit_required: true
fallback_gateway: fake
```

Ce format est indicatif pour la documentation. Il ne branche aucun runtime dans cette PR.

## Fake / Paper

Fake/Paper est autorisé pour :

- simulation ;
- dry-run ;
- validation d’OrderPlan ;
- validation d’ExecutionPort ;
- replay ;
- backtesting ;
- contrôle des invariants.

Fake/Paper ne doit jamais envoyer d’ordre live.

## OKX

OKX peut avoir des schedules dry-run seulement si :

- runtime-check OK ;
- credentials disponibles hors Git ;
- dry-run explicitement activé ;
- live explicitement désactivé ;
- audit actif ;
- Fake/Paper disponible comme fallback.

OKX live est interdit tant qu’une PR dédiée de readiness live n’a pas été validée.

## Hyperliquid

Hyperliquid peut avoir des schedules dry-run seulement si :

- runtime-check OK ;
- credentials disponibles hors Git ;
- environnement test/mainnet clarifié ;
- dry-run explicitement activé ;
- live explicitement désactivé ;
- audit actif ;
- Fake/Paper disponible comme fallback.

Hyperliquid live est interdit tant qu’une PR dédiée de readiness live n’a pas été validée.

## Bitmart legacy

Bitmart peut rester schedulé uniquement si le runtime historique en dépend encore.

Règles :

- ne pas créer de nouveaux schedules Bitmart comme cible future ;
- documenter les schedules legacy existants ;
- retirer Bitmart après inventaire ;
- ne pas casser `mtf:run`, `POST /api/mtf/run` ou Temporal pendant la transition.

## Gates avant ajout d’un nouveau schedule

Avant tout nouveau schedule, vérifier :

1. la gateway est listée dans la readiness matrix ;
2. le runtime-check est disponible ;
3. l’exchange n’est pas live par défaut ;
4. le profil est explicitement autorisé ;
5. la cadence est justifiée ;
6. les rate limits sont connus ;
7. l’audit minimal est actif ;
8. Fake/Paper fallback existe ;
9. la PR reste atomique ;
10. les invariants trading ne sont pas cassés.

## Hors-scope des PRs de préparation

Les PRs de préparation ne doivent pas :

- créer de schedule live OKX ;
- créer de schedule live Hyperliquid ;
- augmenter la cadence pour chercher plus de trades ;
- contourner le runtime-check ;
- désactiver le dry-run ;
- modifier les stratégies pour forcer des trades ;
- desserrer les EntryZones ;
- modifier le levier.
