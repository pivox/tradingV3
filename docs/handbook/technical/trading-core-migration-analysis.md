# Analyse de migration Trading Core

## Statut

Analyse complementaire a la PR draft d'architecture.

Cette page explique comment passer de l'architecture actuelle vers le TradingCore modulaire sans casser les entrypoints existants, sans modifier les YAML et sans changer le comportement live.

## Objectif de l'analyse

L'objectif est de separer clairement :

```text
ce qui existe deja dans TradingV3
ce qui doit rester stable
ce qui doit etre extrait
ce qui doit etre remplace
ce qui ne doit pas etre fait trop tot
```

Le but n'est pas de coder maintenant. Le but est de reduire le risque des futures PR canoniques.

## Rappel des contraintes non negociables

```text
- mtf:run doit continuer a lancer une execution complete.
- POST /api/mtf/run doit rester la route HTTP principale.
- Temporal doit continuer a appeler /api/mtf/run.
- Le dry-run doit rester disponible.
- Aucun trade live sans SL automatique immediatement attache.
- Aucun levier arbitraire.
- Aucune EntryZone desserree sans preuve PnL.
- Aucune activation live OKX/Hyperliquid sans runtime-check OK.
- Bitmart est legacy a retirer, mais pas brutalement dans les PR d'architecture.
- Fake/Paper doit devenir le filet de securite de test avant live.
```

## Architecture actuelle simplifiee

L'architecture actuelle peut etre lue comme :

```text
Temporal schedule
  -> POST /api/mtf/run
  -> RunnerController
  -> MtfRunnerService
  -> MtfValidatorService / Core
  -> TradingDecisionHandler
  -> TradeEntryService
  -> OrderPlan / Execution
  -> Exchange adapter / provider
  -> Orders / positions / lifecycle events
```

Et cote CLI :

```text
php bin/console mtf:run
  -> MtfRunCommand
  -> MtfRunnerService
  -> meme pipeline que l'API
```

La cible ne doit pas supprimer ce modele. Elle doit le rendre plus propre.

## Architecture cible simplifiee

La cible doit etre :

```text
Application entrypoint
  -> RunTradingCycleUseCase
  -> TradingCore modules
  -> ExecutionPort
  -> Gateway cible
  -> Audit / Evaluation
```

Avec :

```text
TradingCore
  - MTF Validation
  - Entry
  - Risk
  - Leverage
  - SL/TP
  - OrderPlan
  - Evaluation
```

Et :

```text
Infrastructure
  - Symfony Controller
  - Symfony Command
  - Temporal worker / schedule
  - Doctrine
  - Messenger
  - OKX gateway
  - Hyperliquid gateway
  - Fake/Paper gateway
```

## Cartographie actuel vers cible

| Actuel | Cible | Strategie |
| --- | --- | --- |
| `RunnerController` | Adaptateur HTTP vers `RunTradingCycleUseCase` | Garder la route, amincir le controleur. |
| `MtfRunCommand` | Adaptateur CLI vers `RunTradingCycleUseCase` | Garder la commande, amincir la commande. |
| Temporal activity HTTP | Adaptateur externe vers `/api/mtf/run` | Garder le POST HTTP, stabiliser payload exchange/profile. |
| `MtfRunnerService` | Application Runner mince | Extraire resolution symboles, sync exchange, filtrage, reporting. |
| `MtfValidatorService/Core` | `TradingCore/Mtf` | Stabiliser `MtfValidationResult`. |
| `TradingDecisionHandler` | Boundary MTF -> Entry | Remplacer progressivement par DTOs explicites. |
| `TradeEntryService` | Entry + Risk + SLTP + Execution separes | Extraire module par module. |
| `EntryZoneCalculator` | `TradingCore/Entry` | Garder logique, rendre resultat explicite et auditable. |
| Risk/leverage disperse | `TradingCore/Risk` | Extraire `PositionSizer`, `LeverageCalculator`, `LiquidationGuard`. |
| Calcul SL/TP | `TradingCore/SlTp` | Refuser tout plan sans protection. |
| Execution actuelle | `ExecutionPort` | Garder compatibilite puis brancher gateways cible. |
| Bitmart provider | Legacy a retirer | Inventorier puis remplacer. |
| OKX/Hyperliquid adapters | Gateways cible | Stabiliser dry-run/runtime-check avant live. |
| Fake exchange | Gateway de test canonique | Prioritaire avant live OKX/Hyperliquid. |
| `position_trade_analysis` | Source Analytics | Mesurer avant d'optimiser. |

## Analyse par domaine

### 1. Entrypoints

Les entrypoints sont des surfaces d'exploitation. Ils ne doivent pas disparaitre.

A conserver :

```text
php bin/console mtf:run
POST /api/mtf/run
Temporal schedule -> /api/mtf/run
```

Evolution cible :

```text
Entrypoint
  -> Request normalisee
  -> RunTradingCycleUseCase
  -> TradingCore
```

Risque principal : casser les scripts Temporal ou les habitudes d'exploitation.

Mitigation : toute PR Runner doit tester la commande CLI et la route HTTP en dry-run.

### 2. Runner

Le Runner doit devenir un orchestrateur fin.

Responsabilites a extraire :

```text
SymbolUniverseResolver
OpenActivityFilter
ExchangeStateSynchronizer
MtfExecutionDispatcher
PostRunProjectionDispatcher
RunResultAssembler
```

Risque principal : extraire trop de choses dans une seule PR.

Mitigation : deux PRs Runner seulement : extraction 1 pour symboles/filtrage, extraction 2 pour sync/reporting.

### 3. MTF

Le MTF doit rester responsable de la validation du setup, pas de l'execution.

Contrat cible :

```text
MtfValidationResult
- profile
- instrument
- direction
- status
- execution_timeframe
- validated_timeframes
- rejected_by
- metadata
```

Risque principal : modifier accidentellement la logique de validation.

Mitigation : mapper d'abord l'existant vers un DTO sans changer les conditions.

### 4. EntryZone

EntryZone doit etre un module testable et auditable.

A journaliser systematiquement :

```text
entry_price
zone_low
zone_high
zone_dev_pct
zone_max_dev_pct
spread_bps
slippage_bps
reason_if_rejected
```

Risque principal : desserrer les zones pour obtenir plus de trades.

Mitigation : toute modification de seuil doit attendre l'Analytics baseline et le backtest net.

### 5. Risk et Leverage

Le risk doit devenir la source de verite du risque effectif.

Questions a resoudre :

```text
risk.fixed_risk_pct est-il prioritaire ?
defaults.risk_pct_percent est-il legacy ?
comment appliquer max_loss_pct ?
comment appliquer les caps exchange/profil/symbole ?
```

Risque principal : conserver deux sources de risque contradictoires.

Mitigation : PR 07 doit identifier la source effective, meme si la valeur finale reste a valider.

### 6. SL/TP et LiquidationGuard

Le systeme ne doit jamais produire un plan executable sans SL.

A verifier avant execution :

```text
stop_loss present
stop_loss sur taille complete
distance stop coherent
R attendu coherent
liquidation distance suffisante
TP coherent apres frais/spread/slippage
```

Risque principal : ouvrir une position puis echouer a poser la protection.

Mitigation : `OrderPlan` invalide si SL absent ; Position Manager plus tard pour detecter/reparer.

### 7. ExecutionPort

Execution doit recevoir un plan deja valide.

Le port ne doit pas connaitre les regles MTF ou les raisons de strategie.

Contrat minimal :

```text
ExecutionPort::execute(OrderPlan): ExecutionResult
```

Risque principal : laisser fuiter les payloads exchange dans le coeur trading.

Mitigation : DTOs internes stables, mapping uniquement dans les gateways.

### 8. Gateways cible

Gateways a garder :

```text
Fake / Paper
OKX
Hyperliquid
```

Ordre recommande :

```text
1. Fake/Paper stable
2. OKX dry-run stable
3. Hyperliquid dry-run stable
4. seulement ensuite discussion live
```

Bitmart :

```text
legacy
inventaire obligatoire
suppression separee
pas de suppression dans les PR coeur trading
```

Risque principal : supprimer Bitmart avant que Fake/OKX/Hyperliquid soient utilisables.

Mitigation : PR 16 inventaire, PR 17 suppression uniquement apres preconditions.

### 9. Temporal

Temporal doit rester externe a la logique trading.

Il doit :

```text
- construire un payload ;
- appeler /api/mtf/run ;
- stocker/logguer le resultat ;
- gerer les schedules.
```

Il ne doit pas :

```text
- connaitre les regles MTF ;
- calculer le risque ;
- choisir le levier ;
- connaitre les payloads exchange.
```

Risque principal : changer le payload HTTP sans mettre a jour les schedules.

Mitigation : PR 13 dediee aux schedules exchange/profile.

### 10. Analytics

Analytics doit arriver avant toute optimisation de frequence.

Metriques minimales :

```text
winrate
expectancy nette
profit factor
max drawdown
pnl_R
MFE
MAE
holding time
fees
spread
slippage
```

Risque principal : continuer a piloter par nombre de trades ou winrate seul.

Mitigation : aucune PR de strategie/parametres sans rapport Analytics.

## Matrice de dependances

| PR | Depend de | Bloque |
| --- | --- | --- |
| PR 01 Effective config | PR 00 | PR 02, PR 11, PR 12, PR 13 |
| PR 02 Exchange readiness | PR 01 | PR 11, PR 12, PR 17 |
| PR 03 Runner extraction 1 | PR 00 | PR 04 |
| PR 04 Runner extraction 2 | PR 03 | PR 13 |
| PR 05 DTOs MTF/TradeCandidate | PR 00 | PR 06, PR 07, PR 09 |
| PR 06 EntryZone | PR 05 | PR 14, PR 15 |
| PR 07 Risk/Leverage | PR 05 | PR 08, PR 09 |
| PR 08 SLTP/LiquidationGuard | PR 07 | PR 09, PR 14 |
| PR 09 OrderPlan/ExecutionPort | PR 08 | PR 10, PR 11, PR 12 |
| PR 10 Fake/Paper | PR 09 | PR 11, PR 12, PR 15, PR 17 |
| PR 11 OKX dry-run | PR 02, PR 09, PR 10 | future OKX live |
| PR 12 Hyperliquid dry-run | PR 02, PR 09, PR 10 | future Hyperliquid live |
| PR 13 Temporal schedules | PR 01, PR 04 | multi-exchange schedules |
| PR 14 Analytics baseline | PR 06, PR 08 | PR 15, tuning strategie |
| PR 15 Backtesting net | PR 10, PR 14 | toute optimisation strategie |
| PR 16 Bitmart inventory | PR 02 | PR 17 |
| PR 17 Bitmart removal | PR 10, PR 11, PR 12, PR 16 | suppression legacy |

## Chemin critique recommande

Le chemin le plus prudent est :

```text
PR 00
-> PR 01
-> PR 03
-> PR 04
-> PR 05
-> PR 06
-> PR 07
-> PR 08
-> PR 09
-> PR 10
-> PR 14
-> PR 15
```

Ensuite seulement :

```text
PR 11 OKX
PR 12 Hyperliquid
PR 13 Temporal schedules exchange/profile
PR 16 Bitmart inventory
PR 17 Bitmart removal
```

Pourquoi cet ordre :

- Fake/Paper donne une execution sans risque live ;
- Analytics/Backtesting donnent une preuve avant optimisation ;
- OKX/Hyperliquid peuvent etre stabilises sans live ;
- Bitmart n'est retire qu'apres securisation de la cible.

## Anti-patterns a eviter

```text
- supprimer Bitmart avant Fake/Paper stable ;
- activer OKX/Hyperliquid live dans une PR de refactor ;
- melanger extraction Runner et changement YAML ;
- changer le risk en meme temps que le leverage ;
- desserrer EntryZone sans PnL net ;
- creer un OrderRequest universel qui force CEX et DEX dans le meme modele ;
- faire porter au Runner des decisions Risk ou Execution ;
- faire dependre TradingCore de Symfony, Doctrine ou Temporal ;
- utiliser le nombre de trades comme objectif principal.
```

## Questions ouvertes a traiter avant implementation lourde

1. Quelle source de risque est canonique : `risk.fixed_risk_pct` ou `defaults.risk_pct_percent` ?
2. `TradeEntry` doit-il rester un nom de module ou etre decoupe en Entry, Risk, SLTP, Execution ?
3. Faut-il creer `Instrument` avant de renommer les usages de `Contract` ?
4. Quel niveau minimal de Fake/Paper est suffisant pour valider un cycle complet ?
5. Quel format pour `EffectiveTradingConfig` doit etre expose dans le front Ops ?
6. Comment representer les frais, spread et slippage dans les backtests ?
7. Quand considerer OKX/Hyperliquid prets pour un forward test non-live ?
8. Quelle strategie exacte pour le retrait Bitmart : archive, suppression directe, ou module legacy disabled ?

## Definition of ready pour commencer les PR de code

Avant PR 01, il faut :

```text
- merger ou valider la PR 00 documentation ;
- confirmer que Bitmart est bien legacy a retirer ;
- confirmer OKX / Hyperliquid / Fake-Paper comme gateways cible ;
- confirmer que mtf:run et /api/mtf/run restent les entrypoints ;
- confirmer que le premier objectif est la config effective, pas l'execution live.
```

## Conclusion

La migration doit etre conduite comme une extraction progressive, pas comme une reecriture.

La bonne trajectoire est :

```text
Preserver les entrypoints.
Rendre le Runner mince.
Stabiliser les contrats internes.
Isoler Entry, Risk, SLTP et Execution.
Utiliser Fake/Paper comme filet de securite.
Mesurer en Analytics/Backtesting.
Stabiliser OKX et Hyperliquid en dry-run.
Retirer Bitmart seulement a la fin.
```
