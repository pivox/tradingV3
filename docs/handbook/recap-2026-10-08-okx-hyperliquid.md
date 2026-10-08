# Récap 2026-10-08 — passage à OKX + Hyperliquid

Ce document résume ce qui a été livré le 2026-10-08, ce qui reste à faire et les décisions prises. Il couvre les PR #440 à #443, toutes mergées dans `main` en `0bd97ac2`.

## Objectif

La plateforme doit tourner sur **OKX et Hyperliquid uniquement**. Bitmart est abandonné. La feuille de route suit cet ordre, chaque étape conditionnant la suivante :

1. L'application tourne sur les deux plateformes. **Fait.**
2. Backtesting sur les deux plateformes. **Outil prêt.**
3. Amélioration des configs de profil. **À faire par le propriétaire.**
4. Démo OKX et testnet Hyperliquid, puis mainnet. **Code prêt côté OKX, lot 1 côté HL. Rien d'activé.**

## Ce qui a été mergé

| PR | Branche | Contenu |
|---|---|---|
| #443 | `wip/paper-132-replay-scale` → `main` | Travail Paper #132 (replay, certification, captures), script de backtest, docs |
| #440 | `feat/remove-bitmart` → WIP | Suppression de Bitmart, OKX par défaut |
| #441 | `feat/okx-010-demo-orders` → Bitmart | Écriture d'ordres OKX en démo (OKX-010, lots 1 et 2) |
| #442 | `feat/hl-302-testnet-readiness` → Bitmart | Readiness testnet Hyperliquid (#302, lot 1) |

Chaque PR est passée par deux cycles de relecture Codex avec corrections, puis par une CI entièrement verte. Avant le merge, une porte locale a aussi été passée sur l'arbre combiné : `lint:container`, phpstan, et les suites que la CI ne lance pas.

### Paper #132 (certification)

- Deux captures publiques de 24 h sont disponibles : OKX `run14` (4,41 M événements) et Hyperliquid `run15` (1,49 M événements). Elles sont figées et en lecture seule sous `tradingV3-private/paper-market-data/`.
- Le pipeline capture → replay → certification fonctionne de bout en bout sur les deux plateformes.
- **Résultat : 0 trade dans les 12 cellules** (6 par plateforme) avec les specs 1.1.0. Le seuil de certification est de 50 trades par cellule. Le propriétaire a accepté ce résultat tel quel : pas de tuning pour forcer la certification, pas de capture plus longue, pas de seuil abaissé.

### Suppression de Bitmart (#440)

- Supprimés : le bundle provider, l'agent WebSocket, les webhooks et le chemin d'exécution legacy (`ExecutionBox::execute`). Les commandes `bitmart:*` deviennent `provider:*`.
- **OKX est la plateforme par défaut** quand aucune n'est précisée. Une valeur explicite inconnue, par exemple `bitmart`, échoue avec `UnsupportedExchangeException` et la liste des valeurs acceptées.
- Les lignes historiques `exchange='bitmart'` sont conservées. Elles sont lues en mode tolérant : la ligne est ignorée et un warning est loggé.
- La migration `Version20261008120000` passe 14 valeurs par défaut de colonne à `'okx'`, sans backfill. **Elle reste à exécuter sur chaque base.**
- `docker-compose` transmet maintenant les variables `OKX_*`, `HYPERLIQUID_*` et `DEMO_TRADING_ENABLED` à `trading-app-php` et aux 3 workers Messenger. Tous les flags `*_ENABLED` valent 0 par défaut.
- `python-orchestrator` refuse les nouveaux sets `bitmart` (422). Les sets existants sont affichés comme non exécutables (`runnable=false`).
- `/api/klines` accepte `start` et `end`, et le dashboard pagine par fenêtres de 500 bougies.

### OKX démo (#441, OKX-010)

- Chaque écriture OKX passe par `OkxDemoGuardedExchangeAdapter`. L'écriture est refusée sauf si `OKX_ENV=demo`, `OKX_SIMULATED_TRADING=1`, `OKX_DEMO_TRADING_ENABLED=1`, `DEMO_TRADING_ENABLED=1` et `OKX_LIVE_ENABLED=0`, et si la garde d'endpoint REST et le kill switch sont OK.
- Chaque écriture a un type :

  | Type | Exemples | Exemptions |
  |---|---|---|
  | `entry` | nouvelle position | aucune |
  | `take_profit` | TP | aucune |
  | `protective` | SL, fermeture d'urgence, annulation du reste de notre entrée | fraîcheur du WebSocket privé, `max_notional` |

  Une écriture `protective` doit être `reduce-only`. Elle reste toujours soumise aux 5 flags, à la garde d'endpoint et au trip durable.
- **Trip durable opérateur** : le fichier `var/okx-demo-execution.quarantine` bloque toutes les écritures, y compris le SL. Il se déclenche aussi tout seul si l'audit après écriture échoue (`audit_after_failed`). Seul un opérateur peut le lever.
- Notional = quantité × taille de contrat (`ctVal`) × prix. Plafond démo : `app.okx_demo_max_notional = 1000` (valeur provisoire).
- Une entrée exige une preuve de stop, sinon elle est refusée avec `stop_loss_required`. Un échec de levier bloque l'entrée (`leverage_setup_failed`).
- Ordres limite GTC / maker : ils restent ouverts et sont surveillés sur `order_timeout`. Quand l'ordre est rempli, le watcher pose le SL puis les deux TP. Le fill est attribué par une baseline de position. À l'expiration du TTL, l'ordre est annulé, et le reste n'est protégé qu'une fois confirmé inactif.
- Les fills OKX portent `quantity_unit=contracts` et `contract_value` (via `OkxContractValueResolver`).
- Vérification sans rien envoyer : `php bin/console app:exchange:runtime-check okx perpetual --json`.

### Hyperliquid testnet (#442, #302 lot 1)

- La readiness est indexée par identité et versionnée : `config/trading/env/testnet.yaml` (v1.0.0, hashée en sha256). Elle n'autorise que `BTCUSDT`, avec `max_notional: 25`, pour les 6 identités 1.1.0. Pour tout ce qui n'est pas du testnet (identité non listée, hash invalide), elle est fail-closed.
- Les écritures `HyperliquidExchangeAdapter` exigent une preuve émise par une gate dégagée. La preuve est signée en HMAC avec un secret propre à la gate, porte son périmètre (symboles, côté, notional) et expire au bout de 60 s. Le kill switch est revérifié à chaque écriture. **Le flux MTF ne peut pas écrire sur Hyperliquid.**
- Smoke opérateur : `php bin/console app:hyperliquid:testnet:smoke --mode-id=… --mode-version=… --setup-id=… --setup-version=… --side=…`. Il affiche le verdict de chaque condition de la gate et n'envoie rien tant que `HYPERLIQUID_TESTNET_TRADING_ENABLED=0`.
- `docs/handbook/decision-hl-302-testnet-readiness.md` est un **BROUILLON** à valider par le propriétaire. Il remplace DEMO-005.

### Backtesting

```bash
scripts/backtest-profile.sh --venue=okx|hyperliquid \
  --mode-id=scalping --mode-version=1.1.0 \
  --setup-id=scalping.pullback.long --setup-version=1.1.0 --side=long \
  [--configuration=/abs/override.json] [--keep-db] [--refresh-receipt]
```

- Le script rejoue une cellule (`runtime-check` → `replay`) sur un Postgres jetable `backtest-pg-*`, qu'il crée puis détruit. Il exporte ensuite via `docs/handbook/reports/queries/bad-trades-baseline-v2.sql`.
- Les résultats sont écrits dans `tradingV3-private/backtests/<run>/` et les receipts mis en cache dans `tradingV3-private/backtest-receipts/`.
- Durées mesurées en contention : replay HL ≈ 46 min, replay OKX ≈ 103 min. Le receipt n'est calculé qu'une fois (29 min pour HL, 65 min pour OKX). Une cellule rejoue toujours le dataset entier.
- Runbook : `docs/handbook/backtesting-okx-hl.md`.

## Ce qui reste à faire

### Bloquant pour trader en démo

1. **Étape 3, l'itération sur les profils.** Avec les specs 1.1.0, le backtest donne 0 trade. Une démo opérationnelle ne déclencherait donc rien. Pour modifier un profil, il faut créer une nouvelle version, par exemple 1.2.0 : les contrats de setup sont hashés et immuables. On la backteste ensuite avec `scripts/backtest-profile.sh`.
2. **Identifiants.**
   - OKX : confirmer que les clés `OKX_DEMO_API_*` ont été créées en mode démo-trading.
   - Hyperliquid : `.env` ne contient aucune ligne `HYPERLIQUID_*`. Il manque l'adresse du compte testnet, l'adresse de l'agent et le token du signer sidecar.
3. **Base de données** : exécuter `Version20261008120000`. Pour HL, il faut aussi les migrations `Version20260712120000` et `Version20260712230000`.
4. **Activation**, sur décision explicite du propriétaire uniquement :
   - OKX : lancer le worker WebSocket privé, puis passer `OKX_DEMO_TRADING_ENABLED=1` et `DEMO_TRADING_ENABLED=1`.
   - HL : passer `HYPERLIQUID_TESTNET_TRADING_ENABLED=1`.

### Suites techniques

- HL lot 2 : une capacité d'exécution `Testnet` dédiée (le smoke demande aujourd'hui `Paper`), puis le branchement du flux MTF sur le port testnet.
- Valeurs à fixer par le propriétaire : `app.okx_demo_max_notional`, et dans l'enveloppe testnet HL les symboles et le `max_notional`.
- Le formulaire manuel `OrderController` ne pose plus de TP preset.
- Le schedule cron `manage_scalper_micro_schedule.py` poste sans préciser de plateforme, donc vers OKX (verrouillé par `OKX_LIVE_ENABLED=0`).
- `FakeOnlyExchangeCallAudit` garde la clé `bitmart` à cause d'un contrat de payload avec `python-orchestrator`.
- La PR #438 est restée ouverte alors que tous ses commits sont dans `main`. Elle peut être fermée.

### Tests rouges connus (déjà rouges avant ces PR)

- `SymbolLockCommandTest::testPositionClosedEventReleasesSymbolLock`
- `ExchangeReconciliationServiceTest::testScenarioSnapshotCompletionRejects…`
- `TradeEntryBoxTest` et `TradeEntryIntegrationTest` (classe `TradeEntryBox` inexistante)
- Tests qui exigent Redis, Python FastAPI (golden scenario) ou une base Postgres isolée

phpstan reste à 1832 erreurs : c'est de la dette existante, et ces PR n'ajoutent aucun nouveau type d'erreur.

## Décisions prises

- Pas de tuning pour passer la certification #132. Tuner les profils dans le backtest est en revanche voulu : c'est l'étape 3.
- OKX par défaut en l'absence de plateforme ; une plateforme inconnue doit échouer.
- Les écritures protectrices passent malgré un flux WebSocket périmé et au-delà du plafond de notional. Elles ne passent jamais malgré un flag désactivé ou un trip opérateur.
- Les TP sont posés dès qu'un ordre limite au repos est rempli.
- Le mainnet n'est pas couvert par ces travaux. Il demandera une décision explicite après des résultats démo.
