# `position_trade_analysis` consumer cutover v1 -> v2

Issue #190 bascule les consommateurs applicatifs vers la surface versionnee
`position_trade_analysis_v2` sans supprimer la vue historique `position_trade_analysis`.
La bascule est progressive, read-only et reversible.

## Inventaire consommateurs

| Consommateur | Etat | Decision |
|---|---|---|
| `GET /api/positions/analysis` | Lit deja `position_trade_analysis_v2` via `PositionTradeAnalysisReaderInterface`. | Reste v2. |
| Orchestrateur Python `/runs/{run_id}/outcome` | Consomme l'API Symfony outcome. | Reste v2 indirectement. |
| Page Twig `/reporting/position-trade-analysis` | Ancien consommateur direct v1. | Migree via `PositionTradeAnalysisReportingService`. |
| Commande `app:position-trade-analysis:backfill-divergence` | Compare explicitement v1 et v2. | Reste double lecture intentionnelle. |
| Entite/repository v1 | Necessaires au rollback et a l'observation. | Conserves. |
| SQL direct applicatif | Aucun autre consommateur runtime identifie hors backfill. | Rien a migrer dans ce lot. |
| Exports | Aucun export dedie identifie pour cette surface. | Non applicable. |

## Switch

La source par defaut du reporting web est configuree par :

```bash
POSITION_TRADE_ANALYSIS_REPORTING_SOURCE=v2
```

Valeurs supportees :

- `v2` : source primaire certifiee, par defaut ;
- `v1` : rollback explicite vers la vue historique ;
- `dual` : lit v1 et v2, affiche v2, et expose les compteurs de divergence.

La page web accepte aussi `?source=v1|v2|dual` pour forcer une lecture ponctuelle sans
changer la configuration du deploiement.

## Regles PnL

- Les lignes v2 affichent `pnl_usdt` uniquement quand `hasCertifiedNetPnl()` est vrai.
- Une ligne v2 incomplete garde `recorded_pnl_usdt` et `estimated_net_pnl_usdt` visibles comme
  valeurs non certifiees, mais le net certifie reste vide.
- Les lignes v1 sont marquees `legacy_v1` et `legacy_recorded_pnl`.
- Les flags de qualite v2 restent visibles ; le reporting ajoute `net_pnl_not_certified`,
  `close_unmatched` et `cost_<status>` quand la ligne n'est pas certifiee.

## Rollback

Rollback rapide :

```bash
POSITION_TRADE_ANALYSIS_REPORTING_SOURCE=v1
php bin/console cache:clear
```

Le rollback ne modifie aucune donnee. Il ne reconstruit pas les relations et ne change pas les
strategies, MTF, EntryZone, Risk/Leverage, SL/TP ou guards live.

Si `v2` est indisponible, la page echoue explicitement avec une erreur de source au lieu de
presenter un resultat vide. Le fallback vers v1 doit etre volontaire.

## Double lecture et divergences

Le mode `dual` compare les lignes par `entry_event_id` uniquement :

- `common_rows` : lignes presentes dans les deux vues ;
- `divergent_rows` : ecart PnL au-dela de l'epsilon technique ;
- `v1_only_rows` / `v2_only_rows` : lignes presentes dans une seule vue.

La comparaison est separee de la pagination d'affichage : les lignes affichees restent bornees
par la limite UI, tandis que les compteurs v1/v2 sont calcules sur une fenetre deterministe
`entryTime DESC` limitee a 5000 lignes. Au-dela de cette limite, relancer le dry-run backfill
paginated reste la source d'audit exhaustive.

Pour les lignes v2 certifiees, la comparaison utilise `net_pnl_usdt`; sinon elle utilise
`recorded_pnl_usdt`. Aucune divergence n'est resolue arbitrairement dans l'UI.

## Retrait v1

La vue v1 et son repository doivent rester disponibles tant que #190 n'a pas valide :

- un rapport dry-run v1/v2 sur fenetres historiques reelles ;
- l'absence de divergence bloquante ;
- la disponibilite de rollback pendant la periode d'observation ;
- la migration ou justification de tous les consommateurs runtime.

La suppression de v1 demande une PR separee et une validation explicite.
