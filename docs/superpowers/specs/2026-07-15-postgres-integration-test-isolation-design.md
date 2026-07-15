# PostgreSQL integration test isolation

## Contexte

Deux suites d'integration construisent puis suppriment des tables et vues PostgreSQL
reelles. La configuration locale `trading-app/.env.test` pointait vers la base de
developpement `trading_app`. Leur `tearDown()` a donc supprime
`trade_lifecycle_event`, `indicator_snapshots`, `position_trade_analysis` et
`position_trade_analysis_v2`, alors que les migrations restaient marquees executees.

## Decision

Ajouter une garde partagee dans l'espace de tests. Avant toute connexion destructive,
elle extrait le nom de base du DSN PostgreSQL et refuse toute base dont le nom n'est
pas `test` ou ne se termine pas par `_test`. Le message d'erreur ne contient jamais le
DSN ni les credentials.

Les deux suites destructives appellent cette garde avant `DriverManager::getConnection()`.
Les DSN non PostgreSQL continuent d'etre ignores selon le comportement existant.

## Configuration locale

Creer une base locale `trading_app_test`, migrer son schema, puis faire pointer le
fichier ignore `trading-app/.env.test` vers cette base. Aucun credential n'est ajoute
au depot.

## Restauration

La base de developpement ne contient actuellement aucune ligne dans les tables de
trading auditees. Son schema manquant est restaure sans suppression : une base
temporaire est creee, toutes les migrations y sont appliquees, puis seuls les objets
absents sont exportes en schema-only et appliques a `trading_app`.

## Verification

- tests unitaires de la garde : base `_test` acceptee, base de developpement refusee,
  message redacted ;
- deux suites PostgreSQL destructives executees contre `trading_app_test` ;
- presence des tables et vues restaurees dans `trading_app` ;
- migrations Doctrine au dernier niveau dans les deux bases ;
- PHPStan cible, lint container et `git diff --check`.

## Non-objectifs

- aucune modification fonctionnelle du trading ;
- aucun ordre demo, testnet ou mainnet ;
- aucune modification de strategie, risque, SL/TP ou runtime exchange.
