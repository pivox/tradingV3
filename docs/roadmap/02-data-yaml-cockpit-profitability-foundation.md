# TradingV3 — Vague 2 — Data, YAML, Cockpit & Profitability Foundation

**Issue parent :** #173  
**Dépendance :** vague 1 suffisamment stabilisée en dry-run/demo-testnet  
**Objectif :** préparer la base data/config/ops qui permettra de décider par données, pas par intuition.

Cette vague ne cherche pas à optimiser une stratégie. Elle construit la fondation pour mesurer correctement les modes `regular`, `scalper` et `scalper_micro`.

## Cap produit

TradingV3 doit être piloté par :

- moins de mauvais trades ;
- expectancy nette positive ;
- frais, spread, slippage et funding intégrés ;
- analyse via `position_trade_analysis` ;
- refus de desserrer les EntryZones sans preuve PnL.

## Règles permanentes

- Ne pas ajouter de fréquence.
- Ne pas ajouter de capital réel.
- Ne pas activer mainnet.
- Ne pas supprimer Bitmart brutalement.
- Ne pas créer un cockpit décoratif : chaque écran doit remplacer une recherche manuelle dans les logs.
- Toute config effective doit être traçable : source files, overrides, hash, env, mode, exchange.
- Toute métrique PnL doit préciser brut/net et qualité des données.
- Toute PR doit être atomique, testée, documentée et rollbackable.
- Surveiller la PR jusqu’à validation Codex ; corriger les retours pertinents ; marquer résolu ; compresser le contexte si 80%.

## PR proposées

### Bloc 2A — `position_trade_analysis` et vérité PnL

- [ ] PTA-001 — Inventaire de `position_trade_analysis` : colonnes, sources, hypothèses, trous de données.
- [ ] PTA-002 — Vérifier le rapprochement entrée/sortie et documenter les cas ambigus.
- [ ] PTA-003 — Ajouter/normaliser `pnl_R`, `MFE_R`, `MAE_R`, holding time et drawdown par trade.
- [ ] PTA-004 — Ajouter les coûts nets : fees, spread, slippage, funding si applicable.
- [ ] PTA-005 — Ajouter un rapport par mode/exchange/symbol/env.
- [ ] PTA-006 — Ajouter un rapport mauvais trades : entrée tardive, SL trop serré, TP irréaliste, EntryZone faible, coût excessif.
- [ ] PTA-007 — Ajouter un export JSON/CSV redacted pour analyse offline.
- [ ] PTA-008 — Documenter les critères “donnée fiable / donnée incomplète / résultat non certifié”.

### Bloc 2B — YAML layered resolver

- [ ] YAML-001 — ADR structure cible : `base + mode + exchange + mode_exchange + env = effective config`.
- [ ] YAML-002 — Inventaire des YAML actuels `regular`, `scalper`, `scalper_micro`, Bitmart, OKX, Hyperliquid.
- [ ] YAML-003 — Implémenter resolver en lecture seule avec provenance, hash et ordre de merge.
- [ ] YAML-004 — Exposer l’effective config via commande CLI ou endpoint read-only.
- [ ] YAML-005 — Ajouter validation fail-closed : types, bornes, champs inconnus, env incohérent.
- [ ] YAML-006 — Migrer Bitmart en compatibilité progressive sans casser le runtime.
- [ ] YAML-007 — Préparer overlays OKX demo et Hyperliquid testnet sans activation mutative.
- [ ] YAML-008 — Ajouter documentation opérateur et exemples de config effective.

### Bloc 2C — Cockpit opérateur / front ops

- [ ] OPS-001 — Décider surface canonique cockpit : Symfony/Twig existant, React, ou séparation progressive.
- [ ] OPS-002 — Écran runtime-check : état exchange/env/mode, readiness, raisons de blocage.
- [ ] OPS-003 — Écran kill switch : global, exchange, env, mode, état et audit.
- [ ] OPS-004 — Écran effective config viewer : fichiers chargés, overrides, hash, risk effectif.
- [ ] OPS-005 — Écran exchange health : public/private read, stale data, rate limit, erreurs normalisées.
- [ ] OPS-006 — Écran positions/orders demo-testnet : positions, SL attaché, cancels, incidents.
- [ ] OPS-007 — Écran trade lifecycle : décision → order plan → ordre → fill → SL/TP → clôture.
- [ ] OPS-008 — Écran bad trades : lecture exploitable de `position_trade_analysis`.
- [ ] OPS-009 — Écran comparaison modes : regular/scalper/scalper_micro sur expectancy nette.
- [ ] OPS-010 — Runbook front ops : quelles actions faire avant d’ouvrir les logs.

### Bloc 2D — Recette et gouvernance data

- [ ] DATA-001 — Data quality flags : données manquantes, stale, approximées, non certifiées.
- [ ] DATA-002 — Lineage `run_id`, `set_id`, exchange, symbol, mode jusqu’au trade analysé.
- [ ] DATA-003 — Baseline analytics report avant toute optimisation.
- [ ] DATA-004 — Tests de non-régression sur les métriques critiques.
- [ ] DATA-005 — Gate “pas d’optimisation sans baseline”.

## Ordre recommandé

```text
PTA-001 → PTA-004
YAML-001 → YAML-005
OPS-001 → OPS-005
DATA-001 → DATA-003
PTA-005 → PTA-008
YAML-006 → YAML-008
OPS-006 → OPS-010
DATA-004 → DATA-005
```

## Prompt type

```text
Tu travailles sur le repo pivox/tradingV3.
Langue : français.

Objectif : créer la PR <ID> de la vague 2 Data/YAML/Cockpit/Profitability Foundation.

Contraintes :
- PR atomique.
- Aucune activation mainnet.
- Aucun ordre réel.
- Aucun tuning stratégie.
- La vérité de décision doit passer par position_trade_analysis.
- Les résultats incomplets doivent rester flagged, jamais certifiés.
- Les YAML doivent être traçables et rollbackables.
- Les écrans cockpit doivent aider l’exploitation, pas remplacer les tests.
- Surveiller la PR jusqu’à validation Codex.
- Traiter les retours pertinents et marquer résolu.
- Si contexte 80%, compresser en gardant objectif, fichiers modifiés, décisions, tests, retours ouverts, risques, rollback, prochaine action.

Tests attendus selon périmètre :
- PHPUnit ciblé ;
- PHPStan ciblé si logique PHP ;
- lint YAML si config ;
- mkdocs build --strict si docs ;
- git diff --check.
```

## Critère de sortie vague 2

La vague 2 est terminée si TradingV3 permet de répondre clairement :

- quelle config effective a produit une décision ;
- quel run/set/mode/exchange/symbol a produit un trade ;
- quel PnL net est mesuré ;
- pourquoi un trade est classé mauvais ;
- quel mode est meilleur ou pire sur expectancy nette, pas seulement winrate.
