# EntryZone module

## Objectif

PR06 introduit un module `App\TradingCore\Entry` explicite, testable et
auditable pour documenter la frontiere EntryZone.

Cette PR est volontairement preparatoire. Elle ne branche pas le nouveau module
au runtime TradeEntry et ne modifie pas les seuils existants.

## Role de l'EntryZone

L'EntryZone represente la fenetre de prix dans laquelle une entree peut rester
coherente avec le signal MTF et le contexte de marche.

La logique runtime existante couvre notamment :

- le choix d'un pivot, le plus souvent VWAP ou SMA21 ;
- une largeur derivee de l'ATR ;
- les bornes `w_min` et `w_max` ;
- le TTL de la zone ;
- la quantification aux pas exchange ;
- le test du prix candidat dans la zone ;
- le calcul `zone_dev_pct` ;
- la comparaison a `zone_max_dev_pct` ;
- la raison de rejet, notamment `zone_far_from_market`,
  `entry_not_within_zone` et `skipped_out_of_zone`.

## Ce que PR06 ajoute

Nouveau namespace :

```text
App\TradingCore\Entry
```

DTOs et enum :

- `Dto\EntryZoneRequest` ;
- `Dto\EntryZone` ;
- `Dto\EntryZoneDecision` ;
- `Enum\EntryZoneStatus`.

Services et mapper :

- `Service\EntryZoneCalculator` ;
- `Service\EntryZoneGuard` ;
- `Mapper\LegacyEntryZoneMapper`.

Le calculateur pur formalise :

- `center` depuis VWAP ou prix de reference ;
- demi-largeur ATR via `k_atr` ou `offset_k` ;
- clamp par `w_min` et `w_max` ;
- quantification optionnelle par `tick_size` ;
- metadata de profil, exchange, market type, direction, timeframe,
  spread et slippage.

Le guard formalise :

- acceptation si le prix candidat est dans la zone ;
- calcul `zone_dev_pct` contre le prix de reference ;
- normalisation de `zone_max_dev_pct` quand une valeur percentuelle legacy est
  fournie ;
- conservation de `reason_if_rejected`.

Le mapper legacy permet de lire `App\TradeEntry\Dto\EntryZone` sans changer les
bornes, le TTL ou les metadata.

## Ce qui reste legacy

Les chemins runtime restent inchanges :

```text
TradingDecisionHandler
  -> TradeEntryRequestBuilder
  -> TradeEntryService
  -> BuildOrderPlan
  -> App\TradeEntry\EntryZone\EntryZoneCalculator
  -> OrderPlanBuilder
```

Les classes suivantes restent la source effective du runtime :

- `App\TradeEntry\EntryZone\EntryZoneCalculator` ;
- `App\TradeEntry\EntryZone\EntryZoneFilters` ;
- `App\TradeEntry\Dto\EntryZone` ;
- `App\TradeEntry\Workflow\BuildOrderPlan` ;
- `App\TradeEntry\OrderPlan\OrderPlanBuilder` ;
- `App\TradeEntry\Service\TradeEntryService`.

PR06 ne remplace pas ces classes dans le flux d'execution.

## Metriques a logger

Le module cible rend explicites les champs qui doivent rester auditables :

| Champ | Usage |
| --- | --- |
| `entry_price` | Prix candidat ou prix final d'entree. |
| `zone_low` | Borne basse de la zone. |
| `zone_high` | Borne haute de la zone. |
| `zone_dev_pct` | Distance relative entre la zone et le prix de reference. |
| `zone_max_dev_pct` | Seuil runtime autorise. |
| `spread_bps` | Spread observe si disponible. |
| `slippage_bps` | Slippage estime si disponible. |
| `reason_if_rejected` | Raison de rejet normalisee. |

Ces champs ne sont pas rebranches dans PR06. Ils documentent le contrat cible
pour les extractions suivantes.

## Pourquoi aucun desserrage

Le risque principal est d'augmenter le nombre de trades en modifiant
involontairement les zones d'entree.

PR06 ne modifie pas :

- les YAML `trade_entry*.yaml` ;
- `w_min` ;
- `w_max` ;
- `zone_max_deviation_pct` ;
- `max_deviation_pct` ;
- la selection du pivot runtime ;
- les conditions MTF ;
- les decisions READY / REJECTED.

Toute optimisation ou modification de seuil doit attendre une baseline
analytics et un backtest net.

## Lien avec analytics et backtesting

Le module `TradingCore\Entry` prepare les prochaines etapes :

- consommer `TradeCandidate.entryContext` ;
- exposer une decision EntryZone stable pour Risk / Leverage ;
- fournir un contrat plus simple au futur module SLTP ;
- rendre `zone_dev_pct`, `zone_max_dev_pct` et `reason_if_rejected`
  comparables entre live, dry-run, replay et backtesting.

Le branchement runtime devra etre traite dans une PR separee avec tests de
non-regression sur `TradeEntry`, `OrderPlan` et le payload `/api/mtf/run`.

## Tests

Tests ajoutes :

- `tests/TradingCore/Entry/EntryZoneCalculatorTest.php` ;
- `tests/TradingCore/Entry/EntryZoneGuardTest.php` ;
- `tests/TradingCore/Entry/LegacyEntryZoneMapperTest.php`.

Ils couvrent :

- calcul d'une zone autour du pivot VWAP ;
- respect de `w_min` ;
- respect de `w_max` ;
- quantification par `tick_size` ;
- calcul de `zone_dev_pct` ;
- acceptation d'un prix dans la zone ;
- rejet d'un prix hors zone sans desserrer `zone_max_dev_pct` ;
- conservation de `reason_if_rejected` ;
- mapping legacy sans changement de bornes ni metadata.

## Non branche dans PR06

PR06 ne branche pas :

- `EntryZoneRequest` dans `TradingDecisionHandler` ;
- `EntryZoneCalculator` TradingCore dans `BuildOrderPlan` ;
- `EntryZoneGuard` dans `OrderPlanBuilder` ;
- `LegacyEntryZoneMapper` dans `TradeEntryService` ;
- `EffectiveTradingConfigResolver` dans le runtime.

PR06 ne modifie pas :

- `mtf:run` ;
- `POST /api/mtf/run` ;
- Temporal ;
- les schedules ;
- les regles MTF ;
- Risk / Leverage ;
- SL / TP ;
- ExecutionPort ;
- Bitmart, OKX ou Hyperliquid live.
