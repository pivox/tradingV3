# Plan global des PR canoniques Trading Core

## Statut

Plan de migration canonique pour faire evoluer TradingV3 vers le TradingCore modulaire sans casser le runtime actuel.

Ce document decoupe la trajectoire en petites PR relisibles, testables et mergeables. Chaque PR doit rester atomique, avec un scope clair, un hors-scope explicite, des tests attendus et des criteres d'acceptation verifiables.

## Objectif global

Construire progressivement une architecture :

```text
Runner mince
-> MTF Validation
-> Entry
-> Risk / Leverage / SLTP
-> OrderPlan
-> ExecutionPort
-> Gateway OKX / Hyperliquid / Fake-Paper
-> Audit / Evaluation
```

Contraintes permanentes :

- conserver les entrypoints `php bin/console mtf:run` et `POST /api/mtf/run` ;
- conserver le Temporal scheduler qui appelle `/api/mtf/run` ;
- ne pas modifier le comportement live sans PR dediee ;
- ne pas augmenter le nombre de trades ;
- ne pas desserrer les EntryZones ;
- ne pas casser les YAML existants ;
- garder Bitmart comme legacy jusqu'a une PR de retrait dediee ;
- garder OKX, Hyperliquid et Fake/Paper comme gateways cible.

## Mode operatoire canonique

Chaque PR doit suivre ce cycle :

```text
1. Creer une branche courte et explicite.
2. Implementer un seul objectif.
3. Ajouter ou adapter les tests du scope uniquement.
4. Mettre a jour la documentation si une frontiere change.
5. Pousser la PR en draft.
6. Lancer la revue Codex/Claude.
7. Corriger uniquement les remarques du scope.
8. Merger seulement si les tests et criteres d'acceptation passent.
9. Passer a la PR suivante.
```

Regle importante : si une fenetre de travail est saturee, compacter le contexte puis reprendre a la PR suivante sans melanger deux PRs.

## Format canonique d'une PR

Chaque PR doit avoir cette structure :

```markdown
## Objectif

Une phrase claire.

## Scope

- changements inclus ;
- fichiers/modules concernes ;
- comportement attendu.

## Hors-scope

- ce qui ne doit pas etre modifie ;
- ce qui sera traite dans une autre PR.

## Tests

Commandes lancees ou a lancer.

## Risques

Risques runtime, config, exchange, data ou trading.

## Criteres d'acceptation

Checklist verifiable avant merge.
```

## Regle de taille des PR

Une PR canonique doit respecter ces criteres :

```text
- un seul objectif principal ;
- un nombre limite de fichiers touches ;
- aucun melange refactor + changement de strategie ;
- aucun changement runtime non documente ;
- tests unitaires ou de non-regression quand du code est touche ;
- documentation mise a jour si une frontiere change.
```

## Gates de securite

Aucune PR suivante ne doit etre mergee si elle casse :

- `mtf:run` ;
- `POST /api/mtf/run` ;
- le dry-run ;
- le Temporal scheduler qui appelle `/api/mtf/run` ;
- l'attachement obligatoire du SL ;
- l'idempotence ;
- l'audit minimal ;
- la possibilite de revenir a une execution sans live via Fake/Paper.

## Sequence canonique

### PR 00 — Architecture cible et plan canonique

Objectif : poser la direction et le vocabulaire.

Scope :

- documenter TradingCore modulaire ;
- documenter le statut exchange : Bitmart legacy, OKX/Hyperliquid/Fake-Paper cible ;
- documenter les entrypoints a preserver ;
- documenter ce plan de PR.

Hors-scope :

- aucun changement runtime ;
- aucun YAML modifie ;
- aucune suppression Bitmart.

Tests :

```bash
python3 -m mkdocs build --strict
```

Critere d'acceptation :

- la PR reste documentation only ;
- la navigation handbook expose les pages d'architecture et le plan canonique.

Statut : PR draft courante.

---

### PR 01 — EffectiveTradingConfigResolver

Objectif : creer une source de verite de config effective sans casser les YAML existants.

Scope :

- introduire `EffectiveTradingConfigResolver` ;
- lire les YAML actuels par compatibilite ;
- preparer la structure cible : `base + mode + exchange + mode_exchange + env` ;
- exposer les champs utiles : profil, exchange, market type, risk, leverage, entry zone, execution, fees ;
- ajouter tests de resolution.

Hors-scope :

- ne pas changer les valeurs YAML ;
- ne pas changer le comportement de trading ;
- ne pas supprimer les providers actuels.

Tests attendus :

```bash
php bin/phpunit tests/Config
php bin/console app:validate:mtf-config
```

Critere d'acceptation :

- pour `regular`, `scalper`, `scalper_micro`, on peut afficher une config effective stable ;
- la config effective porte l'exchange cible, mais Bitmart reste seulement compatibilite legacy.

---

### PR 02 — Exchange target readiness matrix

Objectif : rendre explicite l'etat des gateways cible.

Scope :

- ajouter une matrice `OKX / Hyperliquid / Fake-Paper / Bitmart legacy` ;
- documenter ce qui est pret, dry-run, incomplet, ou a retirer ;
- brancher ou ajuster `app:exchange:runtime-check` si necessaire ;
- ajouter une commande ou vue de diagnostic si elle n'existe pas deja.

Hors-scope :

- pas d'activation live OKX/Hyperliquid ;
- pas de suppression Bitmart.

Tests attendus :

```bash
php bin/console app:exchange:runtime-check okx perpetual
php bin/console app:exchange:runtime-check hyperliquid perpetual
php bin/console app:exchange:runtime-check fake perpetual
```

Critere d'acceptation :

- chaque gateway cible a un statut visible : ready, dry-run-only, missing credentials, unsupported, legacy.

---

### PR 03 — Runner mince, extraction 1

Objectif : alleger le runner sans changer le flux.

Scope :

- extraire `SymbolUniverseResolver` ;
- extraire `OpenActivityFilter` ;
- garder `mtf:run` et `POST /api/mtf/run` identiques cote utilisateur ;
- ajouter tests unitaires des nouveaux services.

Hors-scope :

- pas de changement de validation MTF ;
- pas de changement TradeEntry ;
- pas de changement de schedule Temporal.

Tests attendus :

```bash
php bin/phpunit tests/MtfRunner
php bin/console mtf:run --dry-run=1
```

Critere d'acceptation :

- meme payload en entree ;
- meme forme de reponse ;
- runner plus lisible et moins responsable.

---

### PR 04 — Runner mince, extraction 2

Objectif : sortir la synchronisation exchange et l'assemblage de reponse.

Scope :

- extraire `ExchangeStateSynchronizer` ;
- extraire `RunResultAssembler` ;
- isoler la projection post-run ;
- ajouter tests unitaires.

Hors-scope :

- pas de modification des regles MTF ;
- pas de modification du risk ;
- pas de changement exchange live.

Tests attendus :

```bash
php bin/phpunit tests/MtfRunner
php bin/console mtf:run --dry-run=1
```

Critere d'acceptation :

- le runner orchestre, mais ne contient plus les details de synchronisation et de reporting.

---

### PR 05 — DTOs coeur trading : MtfValidationResult et TradeCandidate

Objectif : stabiliser les contrats internes entre MTF et Entry.

Scope :

- introduire `MtfValidationResult` ;
- introduire `TradeCandidate` ;
- mapper la sortie existante du MTF vers ces DTOs ;
- documenter les champs obligatoires.

Hors-scope :

- pas de changement de strategie ;
- pas de changement des YAML ;
- pas d'ouverture d'ordre differente.

Tests attendus :

```bash
php bin/phpunit tests/MtfValidator
php bin/phpunit tests/TradeEntry
```

Critere d'acceptation :

- MTF ne depend pas de TradeEntry concret ;
- Entry recoit un contrat stable.

---

### PR 06 — EntryZone module

Objectif : isoler le calcul et la validation EntryZone.

Scope :

- extraire/normaliser `EntryZoneCalculator` ;
- introduire un DTO `EntryZone` ;
- introduire un resultat `EntryDecision` ou `EntryZoneCheckResult` ;
- journaliser clairement `zone_dev_pct`, `zone_max_dev_pct`, `skipped_out_of_zone`.

Hors-scope :

- ne pas desserrer les zones ;
- ne pas modifier les seuils ;
- ne pas changer maker/taker.

Tests attendus :

```bash
php bin/phpunit tests/TradeEntry/EntryZone
```

Critere d'acceptation :

- une entree hors zone est refusee avec raison explicite ;
- les metriques de zone sont auditables.

---

### PR 07 — Risk module : PositionSizer et LeverageCalculator

Objectif : creer une source de verite pour taille, risque et levier.

Scope :

- extraire `PositionSizer` ;
- extraire `LeverageCalculator` ;
- garantir que le levier est derive du risque et du stop ;
- identifier la source effective du risque (`fixed_risk_pct` vs `risk_pct_percent`) ;
- ajouter tests de calcul.

Hors-scope :

- ne pas changer le risque effectif en production ;
- ne pas modifier les caps ;
- ne pas modifier les YAML.

Tests attendus :

```bash
php bin/phpunit tests/TradingCore/Risk
php bin/phpunit tests/TradeEntry/RiskSizer
```

Critere d'acceptation :

- aucun levier arbitraire ;
- une seule source de risque effective est identifiee ou marquee `a valider`.

---

### PR 08 — SL/TP module et LiquidationGuard

Objectif : isoler les protections et bloquer tout plan non protege.

Scope :

- extraire `StopLossCalculator` ;
- extraire `TakeProfitCalculator` ;
- introduire `LiquidationGuard` ;
- verifier que tout `OrderPlan` a un SL complet ;
- refuser un plan sans protection.

Hors-scope :

- pas de modification des ratios TP/SL ;
- pas de recalcul live different ;
- pas de trailing autonome encore.

Tests attendus :

```bash
php bin/phpunit tests/TradingCore/SlTp
php bin/phpunit tests/TradeEntry
```

Critere d'acceptation :

- impossible de produire un plan executable sans SL ;
- liquidation guard donne une raison de rejet claire.

---

### PR 09 — OrderPlan stable et ExecutionPort

Objectif : stabiliser le contrat entre planification et execution.

Scope :

- introduire `OrderPlan` stable ;
- introduire `ExecutionPort` ;
- mapper le flux existant vers le port ;
- garder l'adapter actuel en compatibilite.

Hors-scope :

- pas de changement live ;
- pas de routage multi-exchange intelligent ;
- pas de suppression Bitmart.

Tests attendus :

```bash
php bin/phpunit tests/TradingCore/Execution
php bin/phpunit tests/TradeEntry/Execution
```

Critere d'acceptation :

- Execution ne depend plus des details Strategy/MTF ;
- l'exchange est appele via un port.

---

### PR 10 — Fake / Paper gateway canonique

Objectif : avoir un gateway de test fiable avant OKX/Hyperliquid live.

Scope :

- stabiliser Fake/Paper comme gateway cible ;
- executer un `OrderPlan` en simulation ;
- produire fills, fees simules, slippage simule ;
- journaliser lifecycle.

Hors-scope :

- pas de live ;
- pas d'optimisation de strategie.

Tests attendus :

```bash
php bin/phpunit tests/Exchange/Fake
php bin/phpunit tests/TradingCore/Execution
```

Critere d'acceptation :

- un cycle complet peut etre rejoue sans exchange externe ;
- les resultats sont auditables.

---

### PR 11 — OKX gateway dry-run ready

Objectif : stabiliser OKX en gateway cible non-live.

Scope :

- verifier DTO -> payload OKX ;
- verifier runtime-check OKX ;
- supporter dry-run ;
- documenter credentials, URLs, demo/live flags ;
- ajouter tests sans live.

Hors-scope :

- pas de live OKX ;
- pas de schedule `dry_run=false`.

Tests attendus :

```bash
php bin/phpunit tests/Exchange/Okx
php bin/console app:exchange:runtime-check okx perpetual
```

Critere d'acceptation :

- OKX peut recevoir un plan en dry-run ;
- le runtime-check bloque clairement le live si non pret.

---

### PR 12 — Hyperliquid gateway dry-run ready

Objectif : stabiliser Hyperliquid en gateway cible non-live.

Scope :

- verifier asset resolver ;
- verifier action factory ;
- verifier runtime-check Hyperliquid ;
- supporter dry-run ;
- ajouter tests sans live.

Hors-scope :

- pas de live Hyperliquid ;
- pas de schedule `dry_run=false`.

Tests attendus :

```bash
php bin/phpunit tests/Exchange/Hyperliquid
php bin/console app:exchange:runtime-check hyperliquid perpetual
```

Critere d'acceptation :

- Hyperliquid peut recevoir un plan en dry-run ;
- le runtime-check bloque clairement le live si non pret.

---

### PR 13 — Temporal schedules exchange/profile preserves entrypoints

Objectif : aligner les schedules avec les gateways cible sans changer la route Symfony.

Scope :

- conserver `POST /api/mtf/run` ;
- conserver `mtf:run` ;
- utiliser `exchange`, `market_type`, `mtf_profile` dans les payloads ;
- preparer schedules OKX/Hyperliquid/Fake en dry-run ;
- documenter cadence minute.

Hors-scope :

- pas de live ;
- pas de suppression des anciens scripts tant que la migration n'est pas validee.

Tests attendus :

```bash
cd cron_symfony_mtf_workers
pytest
python scripts/manage_exchange_profile_schedule.py status --schedule-id=<id>
```

Critere d'acceptation :

- Temporal continue d'appeler `/api/mtf/run` ;
- les nouveaux couples exchange/profile sont representables.

---

### PR 14 — Analytics baseline et PnL net

Objectif : mesurer avant d'optimiser.

Scope :

- definir les metriques canoniques ;
- brancher `position_trade_analysis` ou son successeur ;
- produire expectancy nette par profil/exchange/setup ;
- inclure fees, spread, slippage, MFE, MAE, pnl_R, holding time.

Hors-scope :

- pas d'optimisation de strategie ;
- pas de changement de seuils.

Tests attendus :

```bash
php bin/phpunit tests/Analytics
```

Critere d'acceptation :

- un rapport peut expliquer pourquoi un profil gagne ou perd net ;
- aucune decision d'assouplissement n'est possible sans ces metriques.

---

### PR 15 — Backtesting net minimal

Objectif : valider les changements hors live.

Scope :

- creer un moteur de replay minimal ;
- utiliser les configs effectives ;
- simuler frais, spread, slippage ;
- produire winrate, expectancy nette, profit factor, max drawdown, pnl_R.

Hors-scope :

- pas de live ;
- pas de data science avancee ;
- pas de selection automatique de parametres.

Tests attendus :

```bash
php bin/phpunit tests/TradingCore/Evaluation
```

Critere d'acceptation :

- une modification de strategie peut etre comparee avant merge live.

---

### PR 16 — Bitmart removal inventory

Objectif : preparer le retrait Bitmart sans casse.

Scope :

- inventorier classes, services, configs, commandes, tests, docs et schedules qui dependent de Bitmart ;
- classer les usages : runtime critique, legacy, test, documentation ;
- produire un plan de suppression par etapes.

Hors-scope :

- ne pas supprimer encore le code ;
- ne pas changer le runtime.

Tests attendus :

```bash
grep -R "Bitmart\|bitmart" trading-app docs cron_symfony_mtf_workers
```

Critere d'acceptation :

- une future PR de suppression peut etre planifiee sans surprise.

---

### PR 17 — Bitmart removal execution

Objectif : supprimer Bitmart une fois les gateways cible pretes.

Preconditions :

- Fake/Paper operationnel ;
- OKX dry-run ready ;
- Hyperliquid dry-run ready ;
- runtime-checks OK ;
- aucun schedule critique ne depend de Bitmart ;
- documentation et tests remplaces.

Scope :

- supprimer les configs cible Bitmart ;
- supprimer les exemples Bitmart ;
- supprimer ou archiver le code Bitmart ;
- supprimer les commandes legacy Bitmart si remplacees.

Hors-scope :

- pas d'activation live OKX/Hyperliquid dans la meme PR ;
- pas de changement strategie.

Tests attendus :

```bash
php bin/phpunit
python3 -m mkdocs build --strict
```

Critere d'acceptation :

- le projet compile/teste sans Bitmart ;
- les gateways cible restent documentees et testables.

## Ordre recommande

```text
PR 00  Documentation architecture
PR 01  Effective config
PR 02  Exchange readiness matrix
PR 03  Runner extraction 1
PR 04  Runner extraction 2
PR 05  DTOs MTF / TradeCandidate
PR 06  EntryZone module
PR 07  Risk / Leverage
PR 08  SLTP / LiquidationGuard
PR 09  OrderPlan / ExecutionPort
PR 10  Fake-Paper gateway
PR 11  OKX dry-run
PR 12  Hyperliquid dry-run
PR 13  Temporal schedules exchange/profile
PR 14  Analytics baseline
PR 15  Backtesting net
PR 16  Bitmart removal inventory
PR 17  Bitmart removal execution
```

## Prompts courts par PR

Pour chaque PR, utiliser ce modele dans Codex CLI :

```text
Tu travailles sur TradingV3.
Objectif : realiser uniquement la PR XX du plan Trading Core canonique.
Lis docs/handbook/technical/trading-core-canonical-pr-plan.md.
Respecte strictement le scope, le hors-scope, les tests et les criteres d'acceptation.
Ne modifie pas les YAML ni le comportement live sauf si la PR le demande explicitement.
Garde mtf:run, POST /api/mtf/run et le dry-run fonctionnels.
A la fin, donne la liste des fichiers modifies et les tests lances.
```

## Regle de merge

Aucune PR suivante ne doit etre mergee si elle casse :

- `mtf:run` ;
- `POST /api/mtf/run` ;
- le dry-run ;
- l'attachement obligatoire du SL ;
- l'idempotence ;
- l'audit minimal ;
- la possibilite de revenir a une execution sans live via Fake/Paper.
