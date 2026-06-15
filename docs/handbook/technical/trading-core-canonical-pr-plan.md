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
- garder OKX, Hyperliquid et Fake/Paper comme gateways cible ;
- garder `config_file/dev.env` et `config_file/prod.env` comme templates de noms de cles uniquement.

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
- la possibilite de revenir a une execution sans live via Fake/Paper ;
- la regle de non-commit des valeurs sensibles dans `config_file/*.env`.

## Sequence canonique

### PR 00 — Architecture cible, env templates et plan canonique

Objectif : poser la direction et le vocabulaire.

Scope :

- documenter TradingCore modulaire ;
- documenter le statut exchange : Bitmart legacy, OKX/Hyperliquid/Fake-Paper cible ;
- documenter les entrypoints a preserver ;
- ajouter `config_file/dev.env` et `config_file/prod.env` comme templates de noms de cles sans valeurs sensibles ;
- documenter ce plan de PR.

Hors-scope :

- aucun changement runtime ;
- aucun YAML modifie ;
- aucune suppression Bitmart ;
- aucune vraie valeur d'environnement dans Git.

Tests :

```bash
python3 -m mkdocs build --strict
```

Critere d'acceptation :

- la PR reste documentation only ;
- la navigation handbook expose les pages d'architecture et le plan canonique ;
- `config_file/dev.env` et `config_file/prod.env` contiennent uniquement des noms de cles.

Statut : PR draft courante.

---

### PR 01 — EffectiveTradingConfigResolver

Objectif : creer une source de verite de config effective sans casser les YAML existants.

Scope :

- introduire `EffectiveTradingConfigResolver` ;
- lire les YAML actuels par compatibilite ;
- preparer la structure cible : `base + mode + exchange + mode_exchange + env` ;
- utiliser `config_file/dev.env` et `config_file/prod.env` comme references de cles attendues ;
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
- la config effective porte l'exchange cible, mais Bitmart reste seulement compatibilite legacy ;
- les cles manquantes peuvent etre comparees aux templates `config_file/dev.env` et `config_file/prod.env`.

---

## PR suivantes

La suite des PR reste canonique et doit etre executee dans cet ordre. Les details de dependances, risques, anti-patterns et chemin critique sont documentes dans `technical/trading-core-migration-analysis.md`.

```text
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

## Prompt court par PR

Pour chaque PR, utiliser ce modele dans Codex CLI :

```text
Tu travailles sur TradingV3.
Objectif : realiser uniquement la PR XX du plan Trading Core canonique.
Lis docs/handbook/technical/trading-core-canonical-pr-plan.md et docs/handbook/technical/trading-core-migration-analysis.md.
Respecte strictement le scope, le hors-scope, les tests et les criteres d'acceptation.
Ne modifie pas les YAML ni le comportement live sauf si la PR le demande explicitement.
Garde mtf:run, POST /api/mtf/run et le dry-run fonctionnels.
Ne commit aucune vraie valeur dans config_file/*.env.
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
- la possibilite de revenir a une execution sans live via Fake/Paper ;
- la regle de non-commit des valeurs sensibles dans `config_file/*.env`.
