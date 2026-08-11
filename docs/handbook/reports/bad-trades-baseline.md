# Baseline bad trades #132

Ce rapport fixe la methode de baseline factuelle pour les trades perdants ou destructeurs d'edge sur les modes modernes `day_trading`, `scalping` et `micro_scalping`.

Statut au 26 juin 2026 : l'extraction de production n'a pas pu etre executee localement, car Docker/PostgreSQL n'etait pas disponible. Aucun chiffre de trading reel n'est donc invente dans cette page. L'evidence locale est conservee dans `reports/evidence/bad-trades-baseline-local-blocked-2026-06-26.json`.

## Source canonique

La population de base vient de `position_trade_analysis_v2`, unique autorite de certification. Les metriques nettes utilisent uniquement les lignes certifiees par cette vue :

- `analysis_status = matched_closed` ;
- `close_match_status = matched` ;
- `cost_completeness = complete` ;
- `pnl_quality_flags = []` ;
- `position_fully_closed = true` ;
- `canonical_net_pnl_usdt` et `canonical_realized_net_pnl_r` non nuls.

Les lignes `partial`, `unknown`, `unmatched`, legacy ambigues, a couts incomplets ou avec flags qualite sont segmentees et exclues des ratios de winrate, expectancy, profit factor, drawdown et simulation.

## Reproduction

Exporter les donnees :

```bash
psql "$DATABASE_URL" \
  -v from_ts='2026-01-01 00:00:00+00' \
  -v to_ts='2026-12-31 23:59:59+00' \
  -f docs/handbook/reports/queries/bad-trades-baseline-v2.sql \
  > /tmp/bad-trades-baseline-v2.csv
```

Generer le rapport :

```bash
python3 trading-app/scripts/bad_trades_baseline.py \
  --input /tmp/bad-trades-baseline-v2.csv \
  --output-md docs/handbook/reports/bad-trades-baseline.generated.md \
  --output-json docs/handbook/reports/evidence/bad-trades-baseline.generated.json \
  --seed 132 \
  --monte-carlo-runs 1000
```

## Axes couverts

Le script produit les agregats par mode, setup, side canonique, symbole, timeframe et cellule de certification exacte. Il calcule count, population certifiee, winrate, Wilson 95 %, expectancy nette, profit factor, max drawdown, R net moyen/median, MFE/MAE, duree, couts et causes de perte candidates. La liquidite maker/taker est explicitement `unavailable_not_exposed_by_position_trade_analysis_v2` : une colonne absente ne devient jamais un faux zero.

Une cellule est le tuple exact `paper_network x market_data_venue x mode_id x setup_id x canonical_side`. Elle doit contenir au moins 50 trades certifies pour contribuer a un agregat ou a une simulation. Ce minimum global ne peut pas etre abaisse par l'API Python ni par la CLI ; `--min-cell-size` permet uniquement de demander un seuil superieur.

L'export peut enrichir le diagnostic, sans modifier la certification fournie par v2 :

- `order_intent` via `internal_trade_id` et la provenance canonique exacte, uniquement si le match est unique ;
- `trade_zone_events` via le `decision_key` canonique de v2 et le scope exact exchange/market/symbol/timeframe, uniquement si le match est unique.

L'export ne lit ni ne re-agrege directement `fill_cost_ledger`. Il ne recalcule aucun cout et ne retire aucun flag qualite. `certification_source` vaut `v2` uniquement pour une ligne deja certifiee par la vue ; sinon la colonne reste vide. `recorded_pnl_usdt` et `estimated_net_pnl_usdt` restent auditables, mais ne remplacent jamais un net canonique absent.

Aucun rapprochement par symbole seul ou fenetre temporelle n'est utilise. Les conflits d'identifiants restent visibles avec `identifier_conflict`.

## Simulation

La simulation `compounding OFF` utilise 100 trades, capital initial 100 USDT, Monte Carlo deterministe et les PnL nets certifies. La simulation `compounding ON` reste marquee `not_computable_missing_risk_policy` tant que l'export ne contient pas l'equity ou la fraction de risque reelle par trade. `risk_usdt_at_entry` seul ne suffit pas pour reconstruire une capitalisation composee fiable.

## Interpretation

Les causes suffixees `_candidate` sont des correlations a valider, pas des preuves causales. `costs_destroy_edge` est factuel lorsque le PnL brut est positif et le PnL net certifie est negatif ou nul.

Points non couverts tant que l'extraction reelle n'a pas ete executee :

- population certifiee des trois modes modernes sur une periode donnee ;
- classement final des causes frequentes ;
- child issues quantifies par frequence ;
- decision de modification strategy/YAML, explicitement hors scope de ce rapport.

## Commandes de validation locales

```bash
python3 -m pytest trading-app/tests/scripts/test_bad_trades_baseline.py -q
python3 -m py_compile trading-app/scripts/bad_trades_baseline.py
python3 trading-app/scripts/bad_trades_baseline.py \
  --input trading-app/tests/fixtures/bad_trades_baseline_sample.csv \
  --output-md /tmp/bad-trades-baseline-sample.md \
  --output-json /tmp/bad-trades-baseline-sample.json \
  --seed 132 \
  --monte-carlo-runs 20
python3 -m json.tool docs/handbook/reports/evidence/bad-trades-baseline-local-blocked-2026-06-26.json
python3 -m mkdocs build --strict
git diff --check
```
