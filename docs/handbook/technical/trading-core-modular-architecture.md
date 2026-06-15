# Architecture Trading Core modulaire

## Statut

Document de decision pour la PR draft d'architecture.

Cette page precise l'architecture retenue pour faire evoluer TradingV3 sans repartir sur une refonte trop large. Elle complete la page d'architecture cible platform en ramenant la priorite sur le coeur de decision trading : MTF, entree, risque, levier, SL/TP, execution et evaluation.

## Decision retenue

La meilleure cible court/moyen terme est une ancienne architecture simplifiee, enrichie par des frontieres de modules strictes et par les protections modernes deja introduites dans TradingV3.

```text
ancienne architecture lisible
+ separation Runner / MTF / TradeEntry / Risk / Execution
+ contrats de module pour communiquer
+ runtime-check exchange
+ lock cross-profile symbole
+ adapters exchange
= TradingCore modulaire
```

Cette option est preferable a une migration immediate vers des microservices complets ou vers une plateforme multi-CEX/multi-DEX trop large. La logique trading doit d'abord prouver son expectancy nette et reduire les mauvais trades.

## Scope exchange cible

Bitmart est considere comme un provider historique/legacy a retirer de la cible. Il peut rester present temporairement dans le code existant pour compatibilite runtime, mais il ne doit plus etre le gateway cible de la nouvelle architecture.

Exchanges a conserver dans la cible :

```text
OKX
Hyperliquid
Fake / Paper exchange
```

Tout nouveau design TradingCore, ExecutionPort ou Gateway doit donc eviter de prendre Bitmart comme reference principale.

## Principe fondamental

```text
TradingCore ne depend de rien.
Symfony depend de TradingCore.
Les adapters exchange dependent de TradingCore.
Le backtesting depend de TradingCore.
TradingCore ne depend ni de Symfony, ni de Temporal, ni d'un exchange concret.
```

## Flux cible

```text
Runner
  -> MTF Validation
  -> Trade Decision
  -> Entry Zone
  -> Risk / Leverage / SLTP
  -> Order Plan
  -> Execution Port
  -> Exchange Adapter
  -> Audit / Evaluation
```

Le flux reste proche de l'architecture historique Runner -> Validator -> Decision -> TradeEntry, mais chaque responsabilite devient explicite et testable.

## Entrypoints runtime actuels a preserver

La refonte TradingCore ne doit pas oublier les deux chemins d'execution existants :

```text
CLI complete execution:
php bin/console mtf:run

HTTP complete execution:
POST /api/mtf/run
```

La commande `mtf:run` sert de declenchement CLI complet via le runner. La route `POST /api/mtf/run` est le declenchement HTTP principal utilise par l'application et par les workflows Temporal.

Le Temporal scheduler lance des workflows periodiques qui appellent cette route HTTP. La cadence actuelle peut etre d'une minute, par exemple via un cron `*/1 * * * *` sur un couple exchange/profile.

Dans la cible, ces entrypoints restent des adaptateurs d'application :

```text
CLI mtf:run
  -> RunTradingCycleUseCase
  -> TradingCore

Temporal schedule every minute
  -> POST /api/mtf/run
  -> RunTradingCycleUseCase
  -> TradingCore
```

La refonte ne doit donc pas supprimer ces surfaces. Elle doit seulement les rendre plus minces : elles orchestrent, mais ne portent pas la logique de validation, de risque, d'entree, de SL/TP ou d'execution.

## Frontieres des modules

### Runner

Responsabilite : orchestrer un cycle de trading.

Le Runner doit :

- charger le profil demande ;
- charger l'univers d'instruments ;
- lancer la validation MTF ;
- transmettre les candidats valides au module d'entree ;
- collecter les resultats ;
- journaliser le cycle.

Le Runner ne doit pas :

- contenir les regles RSI/MACD/EMA ;
- calculer le levier ;
- calculer les SL/TP ;
- connaitre les payloads OKX, Hyperliquid ou Fake/Paper.

### MTF Validation

Responsabilite : decider si le contexte de marche est compatible avec un trade.

Le module MTF doit :

- charger les regles du profil ;
- construire le contexte multi-timeframe ;
- appliquer les conditions long/short ;
- selectionner le timeframe d'execution ;
- produire un resultat explicite.

Sortie attendue :

```text
MtfValidationResult
- profile
- instrument
- direction
- status: ready | rejected
- execution_timeframe
- validated_timeframes
- rejected_by
- metadata
```

Le module MTF ne doit jamais ouvrir de position.

### Entry

Responsabilite : transformer une decision MTF valide en candidat d'entree.

Le module Entry doit :

- calculer l'EntryZone ;
- verifier prix courant, spread et slippage ;
- refuser les entrees hors zone ;
- produire un `TradeCandidate`.

Il ne doit pas recalculer la validation MTF.

### Risk

Responsabilite : accepter, reduire ou refuser un candidat.

Le module Risk doit :

- appliquer le risque par trade ;
- calculer la taille ;
- calculer le levier derive ;
- appliquer les caps profil, symbole et exchange ;
- verifier le risque de liquidation ;
- bloquer les plans incoherents.

Invariants :

```text
- Le levier est derive du risque et du stop.
- Le levier n'est jamais arbitraire.
- Une seule source de verite doit definir le risque effectif.
```

### SL/TP

Responsabilite : proteger le trade avant exposition live.

Le module SL/TP doit :

- calculer le stop-loss ;
- calculer TP1/TP2 ;
- calculer le R-multiple attendu ;
- verifier le R net apres frais, spread et slippage ;
- refuser si le plan ne respecte pas le minimum attendu.

Invariant :

```text
Aucune position ne doit etre ouverte sans stop-loss automatique immediatement attache.
```

### Execution

Responsabilite : executer un plan deja valide.

Le module Execution doit :

- recevoir un `OrderPlan` ;
- appliquer l'idempotence ;
- choisir limit/market/IOC selon la politique d'execution ;
- appeler un port exchange ;
- confirmer que les protections sont posees ;
- produire un `ExecutionResult`.

Execution ne decide pas si le setup est bon. Elle execute uniquement un plan valide.

### Audit et Evaluation

Responsabilite : verifier si le systeme gagne vraiment.

Le module Evaluation doit mesurer :

- winrate ;
- expectancy nette ;
- profit factor ;
- max drawdown ;
- pnl_R ;
- MFE ;
- MAE ;
- holding time ;
- frais ;
- spread ;
- slippage ;
- performance par profil, instrument, timeframe et exchange.

## Contrats de module, pas contrats de paire

Dans cette architecture, le mot contrat designe la communication entre modules. Il ne doit pas designer une paire ou un symbole de trading.

Pour eviter la confusion :

```text
Paire / symbole tradeable -> Instrument ou TradableSymbol
Contrat de communication -> Port, Interface, Command, Result, DTO
```

Exemples de ports :

```text
MarketDataPort
IndicatorSnapshotPort
MtfValidationPort
EntryZonePort
RiskSizingPort
ExecutionPort
AuditPort
BacktestDataPort
```

## Structure de code cible

```text
src/
  TradingCore/
    Shared/
      ValueObject/
      Dto/
      Port/

    Strategy/
      StrategyProfile.php
      StrategyConfig.php

    Mtf/
      Port/
        MtfValidatorInterface.php
      Dto/
        MtfValidationRequest.php
        MtfValidationResult.php
      Service/
        MtfValidationEngine.php
        ExecutionTimeframeSelector.php

    Entry/
      Port/
        EntryZoneCalculatorInterface.php
      Dto/
        EntryZone.php
        TradeCandidate.php
      Service/
        EntryZoneCalculator.php

    Risk/
      Port/
        PositionSizerInterface.php
      Service/
        RiskManager.php
        PositionSizer.php
        LeverageCalculator.php
        LiquidationGuard.php

    SlTp/
      Service/
        StopLossCalculator.php
        TakeProfitCalculator.php

    Execution/
      Port/
        ExchangeExecutionPort.php
      Dto/
        OrderPlan.php
        ExecutionResult.php

    Evaluation/
      Backtest/
      Metrics/
      Simulation/

  Application/
    Runner/
      RunTradingCycleUseCase.php
    Decision/
      TradingDecisionDispatcher.php

  Infrastructure/
    Symfony/
    Doctrine/
    Messenger/
    Temporal/
    Exchange/
      Okx/
      Hyperliquid/
      Fake/
```

## Schema cible

```plantuml
@startuml
actor Scheduler
actor CLI

package "Application" {
  class RunTradingCycleUseCase
}

package "TradingCore" {
  class MtfValidationEngine
  class ExecutionTimeframeSelector
  class EntryZoneCalculator
  class RiskManager
  class LeverageCalculator
  class LiquidationGuard
  class StopLossCalculator
  class TakeProfitCalculator
  class OrderPlanBuilder
}

package "Ports" {
  interface MarketDataPort
  interface IndicatorSnapshotPort
  interface ExchangeExecutionPort
  interface AuditPort
}

package "Infrastructure" {
  class OkxAdapter
  class HyperliquidAdapter
  class FakeExchangeAdapter
  class DoctrineAuditStore
}

Scheduler --> RunTradingCycleUseCase
CLI --> RunTradingCycleUseCase

RunTradingCycleUseCase --> MtfValidationEngine
MtfValidationEngine --> ExecutionTimeframeSelector
RunTradingCycleUseCase --> EntryZoneCalculator
RunTradingCycleUseCase --> RiskManager
RiskManager --> LeverageCalculator
RiskManager --> LiquidationGuard
RiskManager --> StopLossCalculator
RiskManager --> TakeProfitCalculator
RunTradingCycleUseCase --> OrderPlanBuilder
OrderPlanBuilder --> ExchangeExecutionPort

ExchangeExecutionPort <|.. OkxAdapter
ExchangeExecutionPort <|.. HyperliquidAdapter
ExchangeExecutionPort <|.. FakeExchangeAdapter
AuditPort <|.. DoctrineAuditStore
@enduml
```

## Ce qu'il faut garder de l'architecture actuelle

- `ExchangeAdapterInterface` ou son equivalent comme port d'execution.
- Registry d'adapters exchange.
- Runtime-check avant activation live.
- Cross-profile symbol lock.
- Fake exchange pour tests et simulation.
- Separation des profils YAML.
- Audit et observabilite.
- Les entrypoints `mtf:run` et `POST /api/mtf/run`, en les rendant plus minces.
- Le Temporal scheduler qui declenche `/api/mtf/run`, notamment sur cadence minute quand necessaire.

## Ce qu'il faut retirer ou eviter comme cible

- Bitmart comme gateway cible.
- Bitmart comme modele de reference pour les DTOs metier.
- Les payloads exchange dans Strategy, MTF, Risk ou SL/TP.
- La dependance Symfony dans le coeur trading.
- Le risk management distribue dans plusieurs services.
- Messenger obligatoire entre tous les modules internes.
- Le terme `Contract` pour les instruments.

## Migration progressive

### Phase 1 — Documentation

Documenter les frontieres sans changer le runtime.

### Phase 2 — Ports et DTOs

Introduire les premiers ports et DTOs du coeur trading sans modifier les services existants.

### Phase 3 — Anti-corruption adapters

Brancher les services Symfony existants sur les nouveaux ports via des adapters.

### Phase 4 — Extraction du risque

Extraire d'abord :

- `PositionSizer` ;
- `LeverageCalculator` ;
- `LiquidationGuard` ;
- `StopLossCalculator` ;
- `TakeProfitCalculator`.

### Phase 5 — Backtesting net

Brancher la logique extraite sur le backtesting avant tout changement live.

### Phase 6 — Retrait progressif Bitmart

Ne pas supprimer Bitmart brutalement dans une PR documentaire. Le retrait doit passer par une migration separee :

- marquer Bitmart comme legacy ;
- retirer Bitmart des configs cibles ;
- verifier les usages runtime restants ;
- remplacer les exemples et tests par OKX, Hyperliquid ou Fake ;
- supprimer le code Bitmart seulement quand aucun flux live/test n'en depend.

## Non-objectifs

- Ne pas reecrire TradingV3 dans une seule PR.
- Ne pas migrer en microservices.
- Ne pas activer OKX/Hyperliquid live dans cette PR.
- Ne pas ajouter de DEX live.
- Ne pas modifier les YAML.
- Ne pas desserrer les EntryZones.
- Ne pas augmenter le nombre de trades.
- Ne pas supprimer le code Bitmart dans cette PR documentaire.
- Ne pas supprimer les entrypoints actuels `mtf:run` et `POST /api/mtf/run`.

## Definition of done pour cette etape

- La cible TradingCore modulaire est documentee.
- Les frontieres Runner / MTF / Entry / Risk / SLTP / Execution / Evaluation sont explicites.
- Les contrats de module sont distingues des instruments tradeables.
- Bitmart est marque comme legacy a retirer, pas comme gateway cible.
- OKX, Hyperliquid et Fake/Paper restent les gateways cibles.
- Les entrypoints runtime actuels sont preserves dans la cible : CLI `mtf:run`, route `POST /api/mtf/run`, Temporal scheduler.
- La migration proposee est progressive.
- Aucun comportement runtime n'est modifie.
