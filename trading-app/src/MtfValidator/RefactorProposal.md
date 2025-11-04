# Proposition de refonte du module MTF Validator

## Objectifs
- Séparer strictement les contrats exposés aux consommateurs des DTOs internes.
- Réduire le couplage fort entre orchestration, cache, persistance et décision de trading.
- Introduire un pipeline de validation multi-timeframe extensible et testable.
- Consolider l'observabilité (audit, métriques, logs) autour d'agrégateurs dédiés.

## Vue d'ensemble
```
Contract Layer      Application Layer                   Infrastructure Layer
----------------    ----------------------------------  -----------------------------------
Controller/CLI ---> RunCoordinator -------------------> Cache, Snapshot, Metrics adapters
                     |                                   |         |
                     +--> TimeframePipeline ------------> Timeframe processors
                     |        |
                     |        +--> TradingDecisionService -> TradeEntryService
                     |
                     +--> DtoConverters
```

## Découpage proposé

### 1. Contrats & conversions
- Conserver `Contract/MtfValidator/MtfValidatorInterface` comme façade publique.
- Ajouter `Service/Dto/InternalMtfRunDto.php`, `InternalRunSummaryDto.php`, `InternalTimeframeResultDto.php` pour véhiculer les données côté application.
- Créer `Service/Dto/ContractMappers` avec des méthodes statiques `fromContract` / `toContract` (pas de dépendances Symfony) utilisées par `MtfRunService`.
- Adapter `Controller/MtfController` et commandes CLI pour ne manipuler que les DTO contractuels.

### 2. Orchestration applicative
- Introduire `Service/Application/RunCoordinator` chargé de préparer le contexte (`ValidationContextDto`, options de run, journalisation initiale).
- `RunCoordinator` orchestre :
  1. `ContextValidator` (vérifie symboles, feature flags, disponibilité data).
  2. `TimeframePipeline` (détail ci-dessous).
  3. `TradingDecisionService` (décision finale + dispatch vers services externes).
- `RunCoordinator` ne connaît que des interfaces (cache, persistance, audit, trading) fournies via autowiring.

### 3. Pipeline multi-timeframe
- `Service/Timeframe/TimeframePipeline` reçoit une collection ordonnée de `TimeframeProcessorInterface`.
- Chaque processeur expose `supports(Timeframe, ProcessingContextDto)` et `process(...)`.
- `ProcessingContextDto` transporte : symbol, options (`forceRun`, `skipContextValidation`), résultats cumulés, références cache/snapshot.
- Stratégies de sélection : `TimeframeSelectionStrategyInterface` (configurable via `MtfValidationConfig`).
- Les processeurs utilisent un `TimeframeCachePortInterface` et `SnapshotPortInterface` (infrastructure) pour gérer cache/persistance.

### 4. Infrastructure dédiée
- `Service/Infrastructure/Cache/TimeframeCacheAdapter` implémentant `TimeframeCachePortInterface` (redis, ttl, invalidation par symbol/timeframe).
- `Service/Infrastructure/Persistence/SnapshotPersisterAdapter` implémentant `SnapshotPortInterface`.
- `Service/Infrastructure/Observability/RunMetricsAggregator` fournissant `audit()` et `recordJourney()`.
- `Service/Infrastructure/Support/KlineTimeNormalizer` pour mutualiser la normalisation des timestamps.

### 5. Décision de trading
- `Service/Decision/TradingDecisionService` encapsule :
  - Construction des commandes `TradeEntryRequestDto`.
  - Vérification des switches (maintenance, marché fermé, etc.).
  - Déclenchement des audits/métriques via l'agrégateur.
- `TradingDecisionHandler` devient un adaptateur très fin (mapping DTO pipeline -> service décision).

### 6. Tests & documentation
- `tests/MtfValidator/Dto/InternalMtfRunDtoTest.php` : conversions contractuelles.
- `tests/MtfValidator/Application/RunCoordinatorTest.php` : orchestration avec doublures.
- `tests/MtfValidator/Timeframe/TimeframePipelineTest.php` : ordre des processeurs, gestion cache/forceRun.
- `tests/MtfValidator/Decision/TradingDecisionServiceTest.php` : scénarios décisionnels.
- Mise à jour `docs/mtf-validator/README.md` avec diagramme et guide de migration.

## Étapes de migration
1. Implémenter les DTO internes et convertisseurs sans toucher aux contrats publics.
2. Introduire progressivement le pipeline et adapter `MtfService` -> `RunCoordinator`.
3. Brancher les adaptateurs infrastructurels et supprimer les dépendances directes depuis l'orchestrateur historique.
4. Mettre en place la nouvelle chaîne de décision et les agrégateurs de métriques.
5. Couvrir par des tests unitaires/intégrations, puis activer le nouveau flux via feature flag.

## Impacts attendus
- Responsabilités clairement isolées, facilitant extension de nouveaux timeframes.
- Réduction du code procédural dans `MtfService` et meilleure testabilité.
- Observabilité centralisée, limitant la duplication des audits/metrics.
- Alignement avec les bonnes pratiques Symfony (autowiring explicite, DTOs immutables).

