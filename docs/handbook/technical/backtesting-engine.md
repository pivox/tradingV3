# Backtesting net deterministe

## Statut

ADR et contrats de donnees v1 pour #191.

Ce lot ne livre pas encore un moteur Backtrader executable. Il fixe la frontiere
de contrat entre les futurs composants :

```text
Dataset Builder
  -> Effective Config snapshot
  -> TradingCore adapter
  -> Backtrader adapter
  -> Execution simulator
  -> Net cost model
  -> Backtest ledger
  -> Metrics and statistical validation
```

Cette separation evite de recopier arbitrairement la strategie dans Python. Les
regles de trading restent portees par TradingCore ou par un adapter explicite et
testable.

## Decision

Le backtesting #191 sera implemente en lots atomiques. Le premier lot livre :

- des contrats Pydantic immuables dans `python-orchestrator/app/backtesting/contracts.py` ;
- des tests unitaires verrouillant les invariants minimaux ;
- cette page d'architecture.

Backtrader sera branche derriere ces contrats dans une PR suivante. Les resultats
de backtest ne seront jamais presentes comme preuve live.

## Invariants verrouilles par les contrats v1

### Dataset versionne

`DatasetDescriptor` identifie un jeu de donnees par :

- `dataset_id` ;
- source ;
- exchange ;
- market type ;
- symboles ;
- timeframes ;
- periode UTC ;
- ranges manquants ;
- flags qualite ;
- `build_version` ;
- checksum `sha256`.

La periode doit etre bornee (`end_at > start_at`) et les runs ne peuvent pas
sortir des bornes du dataset.

### Config effective

`EffectiveConfigSnapshot` capture :

- profil (`regular`, `scalper`, `scalper_micro`) ;
- hash `sha256` ;
- version de contrat ;
- couches chargees ;
- configuration effective serialisee.

Un run backtest ne peut utiliser qu'une config dont le profil correspond au
profil du run.

La configuration effective est gelee recursivement a la creation du snapshot :
un dictionnaire source mute apres coup ne peut pas modifier les parametres du
run ni invalider silencieusement `config_hash`.

### Reproductibilite

`BacktestRunRequest` porte :

- dataset ;
- config ;
- profil execute ;
- symboles et timeframes inclus dans le dataset ;
- periode ;
- commit Git ;
- version moteur ;
- seed ;
- version du modele de cout ;
- politique intra-bougie.

Le fingerprint de reproductibilite est un hash canonique des inputs. A inputs
identiques, le fingerprint doit rester identique.

Tous les timestamps des contrats sont UTC-aware. Une date naive ou dans un autre
offset est rejetee par validation Pydantic avant toute comparaison de bornes.

### Politique intra-bougie

La politique par defaut est conservatrice :

```text
conservative_stop_first
```

Les autres modes admissibles sont :

```text
path_from_lower_timeframe
reject_ambiguous_trade
```

Le mode optimiste `tp_first` n'existe pas dans le contrat v1.

### Ledger simule

`BacktestTradeLedgerEntry` represente un trade simule execute. Il exige :

- un `initial_stop` positif ;
- un stop long sous l'entree ;
- un stop short au-dessus de l'entree ;
- des couts nets explicites (`fee_usdt`, `spread_cost_usdt`, `slippage_cost_usdt`,
  `funding_usdt`, borrow/liquidation si applicables) ;
- le commit Git, le dataset et le hash de config.

Un trade simule sans SL est invalide. Les signaux non executes seront modelises
dans un contrat separe lors du lot execution simulator.

## Relation avec #132

#132 reste ouverte tant que le vrai jeu de donnees n'a pas produit de baseline
quantifiee exploitable. Cela ne bloque pas ce lot de contrats #191 : le moteur est
prepare maintenant, puis les couts et la calibration seront compares a la baseline
reelle lorsque les donnees seront disponibles.

## Hors scope de ce lot

- execution Backtrader ;
- dataset builder ;
- adapter TradingCore ;
- simulation maker/taker ;
- partial fills ;
- funding historique ;
- rapports de metrics ;
- simulation 100 trades ;
- validation statistique.

Ces elements restent dans #191 et doivent etre livres par PRs suivantes avec
tests golden dedies.

## Validation locale

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_contracts.py -q
```
