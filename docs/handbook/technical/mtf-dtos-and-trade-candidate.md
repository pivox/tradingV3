# DTOs MTF et TradeCandidate

## Objectif

PR05 introduit des DTOs explicites pour clarifier la frontiere entre le runner,
la validation MTF, la decision de trading et le futur `TradeCandidate`.

Cette PR est preparatoire. Elle ne branche pas ces DTOs au runtime et ne change
pas le comportement de `mtf:run`, de `POST /api/mtf/run`, de Temporal, de
Messenger ou de TradeEntry.

## Pourquoi ces DTOs

Le pipeline actuel transporte plusieurs formats historiques :

| Frontiere | Format actuel |
| --- | --- |
| Runner -> MTF | `App\Contract\MtfValidator\Dto\MtfRunRequestDto` |
| MTF service -> Core | `App\Contract\MtfValidator\Dto\MtfRunDto` |
| Core -> Runner/Dispatcher | `App\Contract\MtfValidator\Dto\MtfResultDto` |
| Messenger -> Decision | `App\MtfValidator\Service\Dto\SymbolResultDto` |
| Decision -> TradeEntry | `TradeEntryRequest` construit par `TradeEntryRequestBuilder` |

Ces formats restent valides. PR05 ajoute une couche de lecture cible dans
`App\TradingCore` pour rendre explicites les champs qui seront consommes par les
prochaines extractions.

## DTOs ajoutes

### `App\TradingCore\Mtf\Dto\MtfValidationRequest`

Representation explicite d'une demande de validation MTF :

- `symbol` et `instrument` ;
- `profile` ;
- `exchange` ;
- `marketType` ;
- `requestedTimeframe` ;
- `direction` si disponible ;
- `dryRun` ;
- `forceRun` ;
- `forceTimeframeCheck` ;
- `metadata`.

Un mapper statique depuis `MtfRunDto` existe pour documenter la compatibilite
avec le format legacy sans remplacer l'appel runtime.

### `App\TradingCore\Mtf\Dto\MtfValidationResult`

Representation cible du resultat MTF :

- `symbol` et `instrument` ;
- `profile` ;
- `exchange` ;
- `marketType` ;
- `status` (`READY` ou `REJECTED` dans les mappings MTF) ;
- `direction` ;
- `executionTimeframe` ;
- `validatedTimeframes` ;
- `rejectedTimeframes` ;
- `rejectedBy` ;
- `score` et `confidence` si presents ;
- `rawLegacyPayload` ;
- `metadata`.

`rawLegacyPayload` est conserve volontairement pour ne pas casser les
consommateurs array-based pendant la migration.

### `App\TradingCore\Mtf\Dto\ValidatedTimeframe`

Representation d'une decision par timeframe :

- timeframe ;
- phase (`context` ou `execution`) ;
- signal ;
- validite ;
- raison de rejet optionnelle ;
- regles passees ;
- regles echouees ;
- metadata.

### `App\TradingCore\Mtf\Dto\MtfRejectionReason`

Representation minimale d'une raison de rejet :

- reason ;
- timeframe ;
- phase ;
- regles echouees ;
- metadata.

### `App\TradingCore\Decision\Dto\TradeCandidate`

Representation cible du signal admissible pour les prochains modules :

- `symbol` et `instrument` ;
- `profile` ;
- `exchange` ;
- `marketType` ;
- `direction` ;
- `executionTimeframe` ;
- `signalTime` ;
- `validationResult` ;
- `entryContext` minimal ;
- `dryRun` ;
- `metadata`.

Un `TradeCandidate` n'est construit que pour un resultat `READY` avec direction
et timeframe d'execution presents.

## Mappers ajoutes

### `App\TradingCore\Mtf\Mapper\MtfValidationResultMapper`

Ce mapper sait lire :

- `MtfResultDto` ;
- un payload legacy array issu du runner ou d'un worker.

Il preserve :

- le statut `READY` ;
- le statut `REJECTED` ;
- `execution_tf` / `executionTimeframe` ;
- `rejected_by` ;
- metadata ;
- payload legacy brut.

### `App\TradingCore\Decision\Mapper\TradeCandidateMapper`

Ce mapper sait construire un `TradeCandidate` depuis :

- `MtfValidationResult` ;
- `SymbolResultDto` legacy.

Le mapper retourne `null` quand le resultat n'est pas candidat a l'entree
(`REJECTED`, direction absente ou timeframe d'execution absente). Il ne lance
pas TradeEntry et ne modifie aucun side effect.

## Ce qui reste legacy

Les chemins runtime restent inchanges :

```text
MtfRunnerService
  -> MtfValidatorService
  -> MtfValidatorCoreService
  -> MtfTradeDecisionDispatcher
  -> MtfTradingDecisionMessageHandler
  -> TradingDecisionHandler
  -> TradeEntryRequestBuilder
  -> TradeEntryService
```

Les classes suivantes restent la source effective du runtime :

- `MtfRunRequestDto` ;
- `MtfRunDto` ;
- `MtfResultDto` ;
- `SymbolResultDto` ;
- `TradeEntryRequest`.

PR05 ne remplace pas ces classes dans le flux d'execution.

## Usage futur

Les prochaines PR pourront utiliser ces DTOs pour extraire progressivement :

- PR06 : EntryZone, en consommant `TradeCandidate.entryContext` et
  `MtfValidationResult` ;
- PR07 : Risk / Leverage, en recevant un candidat deja qualifie ;
- PR08 : SLTP / LiquidationGuard, en travaillant sur un contrat plus explicite
  que les arrays legacy ;
- PR09 : OrderPlan / ExecutionPort, sans exposer les details MTF au port
  d'execution.

Chaque branchement futur devra avoir sa propre PR, ses tests de non-regression
et une verification explicite du payload `/api/mtf/run`.

## Non branche dans PR05

PR05 ne branche pas :

- `MtfValidationRequest` dans `MtfValidatorService` ;
- `MtfValidationResult` dans `MtfRunnerService` ;
- `TradeCandidate` dans `TradingDecisionHandler` ;
- `TradeCandidate` dans `TradeEntryService` ;
- `EffectiveTradingConfigResolver` dans le runtime.

PR05 ne modifie pas :

- les regles MTF ;
- `READY` / `REJECTED` runtime ;
- EntryZone ;
- Risk / Leverage ;
- SL / TP ;
- Temporal ;
- les schedules ;
- les YAML strategie ;
- Bitmart, OKX ou Hyperliquid live.

## Tests

Tests ajoutes :

- `tests/TradingCore/Mtf/MtfValidationRequestTest.php` ;
- `tests/TradingCore/Mtf/MtfValidationResultMapperTest.php` ;
- `tests/TradingCore/Decision/TradeCandidateMapperTest.php`.

Ils couvrent :

- construction DTO ;
- mapping depuis `MtfResultDto` ;
- mapping depuis payload legacy ;
- preservation de `READY` ;
- preservation de `REJECTED` ;
- preservation de `rejected_by` ;
- preservation de `execution_tf` ;
- preservation de metadata ;
- preservation du payload legacy brut ;
- absence de `TradeCandidate` pour un resultat rejete.

## Garantie runtime

Cette PR ajoute uniquement des classes preparatoires et leurs tests.

Aucun entrypoint, controller, commande, schedule, worker, handler Messenger,
service TradeEntry ou YAML strategie n'est modifie. La structure de reponse
`/api/mtf/run` reste inchangee.
