# TradingV3 — Vague 3 — MTF Backstaging Config Mining

**Issue parent :** #173  
**Dépendance :** vague 2 utilisable : `position_trade_analysis`, effective config, coûts nets, lineage  
**Objectif :** optimiser les configs par mode via backstaging chronologique, puis valider hors période.

Cette vague sert à augmenter la probabilité de rentabilité en cherchant des configs robustes par mode, pas en ajoutant des trades.

## Définition

Dans TradingV3, le backstaging signifie :

```text
rejouer l’historique chronologiquement
→ tester des configs candidates par mode
→ enregistrer signaux, trades, no-trades et échecs
→ calculer MFE/MAE/pnl_R/frais/spread/slippage
→ classer les configs
→ figer les meilleures
→ valider hors période / walk-forward
→ exporter des YAML candidates ou rejeter le mode
```

Ce n’est pas :

```text
chercher un trade gagnant dans le passé
→ remonter pour trouver le 4H/1H/15m qui l’explique
→ fabriquer une config qui colle au résultat connu
```

## Règles anti-biais

- À chaque instant `T`, n’utiliser que les bougies clôturées avant `T`.
- Le futur ne sert qu’à mesurer le résultat après coup, jamais à décider l’entrée.
- Les no-trades doivent être stockés.
- Les contextes valides sans confirmation doivent être stockés.
- Les signaux perdants doivent être stockés.
- La période de discovery ne doit pas servir de validation finale.
- Une config optimisée doit être figée avant out-of-sample.
- Le scoring doit pénaliser l’overfitting, le drawdown, la dépendance à un symbole ou à une semaine.

## Modes à optimiser séparément

### `regular`

- Contexte probable : 4H + 1H.
- Setup probable : 15m.
- Trigger probable : 5m.
- Objectif : peu de trades, mouvements propres, drawdown limité.

### `scalper`

- Contexte probable : 1H + 15m.
- Setup probable : 5m.
- Trigger probable : 1m.
- Objectif : trades courts mais encore robustes aux coûts.

### `scalper_micro`

- Contexte probable : 15m + 5m.
- Setup/trigger probable : 1m.
- Objectif : vérifier s’il mérite d’exister ; rejet si frais/spread/slippage détruisent l’edge.

## PR proposées

### Bloc 3A — Modèle et moteur backstaging

- [ ] BSM-001 — ADR MTF Backstaging Config Mining : termes, biais, discovery, validation.
- [ ] BSM-002 — Modèle `BackstageRun`, `BackstageCandidate`, `BackstageResult`, `BackstageNoTrade`.
- [ ] BSM-003 — Générateur de configs candidates à partir des YAML effectifs.
- [ ] BSM-004 — Builder contexte MTF configurable par mode.
- [ ] BSM-005 — Moteur de simulation chronologique sans look-ahead.
- [ ] BSM-006 — Stockage des no-trades et contextes invalidés.
- [ ] BSM-007 — Replay déterministe d’une fenêtre historique.
- [ ] BSM-008 — Tests anti-look-ahead et fixtures minimales.

### Bloc 3B — Mesures et scoring

- [ ] BSM-009 — Calcul MFE/MAE/pnl_R par candidat.
- [ ] BSM-010 — Intégrer fees/spread/slippage/funding dans le scoring net.
- [ ] BSM-011 — Score de robustesse : expectancy nette, profit factor, drawdown, stabilité.
- [ ] BSM-012 — Pénalités : trop de trades, dépendance à un symbole, dépendance à un outlier.
- [ ] BSM-013 — Rapport par mode/config/symbol/timeframe/régime marché.
- [ ] BSM-014 — Export CSV/JSON redacted des résultats.

### Bloc 3C — Optimisation par mode

- [ ] MODE-REG-001 — Définir l’espace de recherche `regular`.
- [ ] MODE-REG-002 — Backstaging `regular` et short-list configs candidates.
- [ ] MODE-REG-003 — Validation out-of-sample `regular`.
- [ ] MODE-REG-004 — Export `regular_config_candidate.yaml` ou rejet documenté.
- [ ] MODE-SCALP-001 — Définir l’espace de recherche `scalper`.
- [ ] MODE-SCALP-002 — Backstaging `scalper` et short-list configs candidates.
- [ ] MODE-SCALP-003 — Validation out-of-sample `scalper`.
- [ ] MODE-SCALP-004 — Export `scalper_config_candidate.yaml` ou rejet documenté.
- [ ] MODE-MICRO-001 — Définir l’espace de recherche `scalper_micro` avec spread/frais stricts.
- [ ] MODE-MICRO-002 — Backstaging `scalper_micro` et décision de survie.
- [ ] MODE-MICRO-003 — Validation out-of-sample ou rejet définitif.

### Bloc 3D — Régimes marché et no-trade

- [ ] REGIME-001 — Classifier simple : trend_up, trend_down, range, high_volatility, low_volatility, chop.
- [ ] REGIME-002 — Associer chaque résultat à un régime marché.
- [ ] REGIME-003 — Rapport expectancy par mode/config/régime.
- [ ] ENTRY-001 — Entry quality score configurable.
- [ ] ENTRY-002 — No-trade threshold par mode.
- [ ] ENTRY-003 — Rapport trades évités vs trades pris.

### Bloc 3E — Walk-forward et décision

- [ ] WFO-001 — Découpage train/test/out-of-sample configurable.
- [ ] WFO-002 — Walk-forward runner déterministe.
- [ ] WFO-003 — Rapport stabilité inter-fenêtres.
- [ ] WFO-004 — Go/no-go config report par mode.
- [ ] WFO-005 — Export YAML suggested_config avec statut `candidate`, `validated`, `rejected`.

## Critères d’optimisation

Ne jamais choisir la config au PnL max seul. Le score doit favoriser :

- expectancy_R nette positive ;
- profit factor robuste ;
- drawdown acceptable ;
- résultats hors période cohérents ;
- MFE/MAE sain ;
- coûts d’exécution supportables ;
- peu de dépendance à un seul symbole ou outlier ;
- capacité à dire no-trade.

Exemple de verdict attendu :

```yaml
regular:
  status: validated_candidate
  best_config: regular_v07
  expectancy_R_net: 0.16
  profit_factor: 1.23
  drawdown_R: acceptable

scalper:
  status: candidate
  best_config: scalper_v03
  expectancy_R_net: 0.05
  warning: fragile_after_fees

scalper_micro:
  status: rejected
  reason: spread_and_fees_destroy_edge
```

## Prompt type

```text
Tu travailles sur le repo pivox/tradingV3.
Langue : français.

Objectif : créer la PR <ID> de la vague 3 MTF Backstaging Config Mining.

Contraintes :
- PR atomique.
- Aucun mainnet.
- Aucun ordre réel.
- Aucune config promue sans validation hors période.
- Aucun look-ahead : n’utiliser que les données disponibles à T pour décider.
- Enregistrer les no-trades, les échecs et les signaux perdants.
- Mesurer en net : fees, spread, slippage, funding si applicable.
- Comparer les modes sur expectancy nette, pas winrate seul.
- Surveiller la PR jusqu’à validation Codex.
- Corriger les retours pertinents, répondre et marquer comme résolu.
- Si contexte 80%, compresser en gardant objectif, fichiers modifiés, décisions, tests, retours ouverts, risques, rollback, prochaine action.

Tests attendus :
- tests anti-look-ahead ;
- tests déterminisme replay ;
- tests scoring ;
- tests YAML si config ;
- mkdocs build --strict si docs ;
- git diff --check.
```

## Critère de sortie vague 3

La vague 3 est terminée quand chaque mode a un verdict :

- `validated_candidate` ;
- `candidate_needs_shadow` ;
- `rejected` ;
- `disabled_until_new_evidence`.

Aucune config ne doit être considérée prête pour capital réel sans vague 4.
