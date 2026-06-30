# TradingV3 — Roadmap V2 — Pilotage global et vagues de suite

**Date de relecture initiale :** 22 juin 2026  
**Mise à jour :** 30 juin 2026  
**Issue parent :** [#173 — Roadmap globale TradingV3](https://github.com/pivox/tradingV3/issues/173)

Ce document reste le **point d’entrée roadmap V2**. Il ne doit pas contenir tous les détails de chaque PR. Les détails opérationnels sont maintenant séparés en documents de vagues versionnés dans `docs/roadmap/`.

---

## 1. Décision

L’issue #173 doit rester une **issue parent de pilotage**, courte et stable.

Elle doit contenir uniquement :

- la vision du projet ;
- les invariants à ne jamais casser ;
- l’état synthétique des grands chantiers ;
- l’ordre de priorité ;
- les liens vers les issues filles et les documents de vagues ;
- les critères permettant de clôturer ou réorienter la roadmap.

Les critères d’acceptation détaillés, plans de migration, historiques de PR et décisions d’architecture doivent vivre dans :

1. des documents Markdown versionnés dans `docs/` ;
2. des issues techniques atomiques ;
3. les PR qui implémentent chaque issue.

---

## 2. Cap produit à conserver

TradingV3 ne doit plus être piloté par l’objectif « plus de trades ».

La priorité est :

1. réduire les mauvais trades ;
2. fiabiliser la donnée d’analyse ;
3. obtenir une expectancy nette positive ;
4. intégrer les frais, le spread, le slippage et le funding ;
5. valider les configs par mode ;
6. seulement ensuite ajuster la fréquence, ouvrir de nouveaux exchanges ou envisager un pilot réel contrôlé.

La comparaison entre `regular`, `scalper` et `scalper_micro` doit reposer sur :

- le PnL net ;
- l’expectancy nette ;
- le profit factor ;
- le max drawdown ;
- le `pnl_R` ;
- le MFE et le MAE ;
- la durée moyenne ;
- les coûts d’exécution ;
- la stabilité hors période d’optimisation ;
- un shadow/forward test suffisamment long.

---

## 3. Invariants

- Aucune position sans stop-loss automatique immédiatement attaché.
- Le levier est dérivé du risque, du stop et des limites de l’exchange ; il n’est jamais choisi arbitrairement.
- Aucune bascule live sans runtime-check validé.
- Aucune EntryZone desserrée sans preuve PnL.
- Toute PR doit rester atomique, testée et traçable.
- Les analyses doivent partir de `position_trade_analysis`, après vérification de sa fiabilité.
- Les résultats doivent inclure les frais, le spread, le slippage et le funding si applicable.
- OKX et Hyperliquid ne doivent jamais être considérés prêts au mainnet par défaut.
- OKX/Hyperliquid suivent désormais la logique : dry-run d’abord, puis exécution mutative uniquement sur OKX demo / Hyperliquid testnet après gates dédiés.
- Aucun secret mainnet ne doit être demandé, stocké, loggé ou documenté.
- Bitmart ne doit pas être supprimé tant qu’une solution de remplacement réellement opérationnelle n’est pas validée.

---

## 4. État synthétique du projet

| Domaine | État synthétique | Décision |
|---|---|---|
| Orchestrateur Python | Base avancée, recette runtime à conserver | Ne pas réintroduire d’orchestration multi-target dans Temporal |
| Temporal | Cron simple vers orchestrateur | Garder Temporal comme déclencheur, pas comme moteur trading |
| Corrélation des données | Lineage à fiabiliser jusqu’au trade | Propager `dashboard_id`, `set_id`, profil, exchange et symbol |
| `position_trade_analysis` | Vue centrale d’analyse | La fiabiliser avant toute décision stratégique |
| TradingCore | Fondations présentes | Continuer le branchement runtime sans casser legacy |
| Effective Config | Resolver en couches présent | Le connecter au runtime et le rendre observable |
| Front Ops | Symfony/Twig et React existent | Industrialiser un cockpit opérateur utile, pas décoratif |
| Bitmart | Provider runtime historique | Le conserver temporairement et inventorier ses dépendances |
| Fake/Paper | Filet de sécurité | Le rendre représentatif avant migrations exchange |
| OKX | Intégration demo-testnet en cours | Dry-run puis OKX demo mutatif contrôlé uniquement après gates |
| Hyperliquid | Intégration testnet en cours | Dry-run puis testnet mutatif contrôlé uniquement après gates |
| Analytics | Baseline complète non finalisée | Priorité « bad trades first » |
| Backtesting / Backstaging | À structurer | Intégrer coûts, no-trades, out-of-sample et anti-look-ahead |
| Shadow / Live-readiness | Non démarré | Préparer sans activation mainnet |

---

## 5. Vagues roadmap à suivre

### Vague 1 — OKX / Hyperliquid demo-testnet

Document : [`docs/roadmap/01-okx-hyperliquid-demo-testnet.md`](01-okx-hyperliquid-demo-testnet.md)

Objectif : finaliser l’intégration OKX demo et Hyperliquid testnet sans aucune écriture mainnet.

Statut : en cours avancé.

Déjà livré dans la série récente :

- `COMMON-001` à `COMMON-006` ;
- `OKX-001` à `OKX-009` ;
- `HL-001` à `HL-007`.

Restant principal :

- `HL-008` à `HL-011` ;
- `DEMO-001` à `DEMO-006` ;
- `OKX-010` ;
- `HL-012`.

Critère de sortie : OKX demo et Hyperliquid testnet sont sûrs, observables, rollbackables, et ne peuvent pas basculer en mainnet par erreur.

### Vague 2 — Data, YAML, Cockpit & Profitability Foundation

Document : [`docs/roadmap/02-data-yaml-cockpit-profitability-foundation.md`](02-data-yaml-cockpit-profitability-foundation.md)

Objectif : construire la base de décision data/config/ops avant toute optimisation.

Axes :

- fiabiliser `position_trade_analysis` ;
- intégrer frais, spread, slippage, funding ;
- structurer le YAML layered resolver : `base + mode + exchange + mode_exchange + env` ;
- afficher l’effective config ;
- industrialiser le cockpit opérateur ;
- tracer run/set/mode/exchange/symbol jusqu’au trade.

Critère de sortie : TradingV3 sait dire quelle config a produit quelle décision et quel PnL net fiable.

### Vague 3 — MTF Backstaging Config Mining

Document : [`docs/roadmap/03-mtf-backstaging-config-mining.md`](03-mtf-backstaging-config-mining.md)

Objectif : optimiser les configs par mode via backstaging chronologique, sans look-ahead.

Axes :

- générer des configs candidates par mode ;
- rejouer l’historique chronologiquement ;
- enregistrer signaux, échecs, no-trades ;
- calculer MFE/MAE/pnl_R/coûts nets ;
- valider hors période / walk-forward ;
- exporter des YAML candidates ou rejeter le mode.

Critère de sortie : chaque mode reçoit un statut `validated_candidate`, `candidate_needs_shadow`, `rejected` ou `disabled_until_new_evidence`.

### Vague 4 — Shadow, Capital Protection & Live-readiness

Document : [`docs/roadmap/04-shadow-capital-protection-live-readiness.md`](04-shadow-capital-protection-live-readiness.md)

Objectif : vérifier que l’edge survit aux conditions réelles avant toute décision de capital réel.

Axes :

- shadow production ;
- fills réalistes ;
- spread/orderbook/slippage réels ou pessimistes ;
- capital protection layer ;
- mainnet-readiness sans activation ;
- go/no-go pilot éventuel ;
- production risk governance ;
- scaling contrôlé uniquement si preuves suffisantes.

Critère de sortie : TradingV3 produit une décision claire : go small pilot, continuer shadow, rejeter config/mode, ou refondre la stratégie.

---

## 6. Issues de suivi historiques créées ou réutilisées

Ces issues restent utiles, mais elles sont maintenant regroupées dans les vagues ci-dessus.

| Domaine | Issue | Usage actuel |
|---|---|---|
| Recette runtime orchestrateur | [#188 — TV3-ORCH-001](https://github.com/pivox/tradingV3/issues/188) | Fondation vague 2 |
| Lineage run → set → trade | [#189 — TV3-DATA-001](https://github.com/pivox/tradingV3/issues/189) | Fondation vague 2 |
| Fiabilité `position_trade_analysis` | [#190 — TV3-DATA-002](https://github.com/pivox/tradingV3/issues/190) | Fondation vague 2 |
| Baseline « bad trades first » | [#132 — Rapport PnL](https://github.com/pivox/tradingV3/issues/132) | Profitability foundation |
| Backtesting net | [#191 — TV3-BACKTEST-001](https://github.com/pivox/tradingV3/issues/191) | Précurseur vague 3 |
| Effective Config en couches | [#133 — Config YAML layered](https://github.com/pivox/tradingV3/issues/133) | Vague 2 |
| Effective Config Viewer | [#192 — TV3-CONFIG-002](https://github.com/pivox/tradingV3/issues/192) | Vague 2 |
| Décision Front Ops | [#193 — TV3-FRONT-001](https://github.com/pivox/tradingV3/issues/193) | Vague 2 |
| Roadmap Front Ops investigation | [#194 — TV3-FRONT-002](https://github.com/pivox/tradingV3/issues/194) | Vague 2 |
| Inventaire Bitmart | [#195 — TV3-EXCHANGE-001](https://github.com/pivox/tradingV3/issues/195) | Vague 1/2 |
| Readiness Fake/Paper | [#196 — TV3-EXCHANGE-002](https://github.com/pivox/tradingV3/issues/196) | Vague 1 |
| Readiness OKX dry-run | [#197 — TV3-EXCHANGE-003](https://github.com/pivox/tradingV3/issues/197) | Remplacée/étendue par vague 1 |
| Readiness Hyperliquid dry-run | [#198 — TV3-EXCHANGE-004](https://github.com/pivox/tradingV3/issues/198) | Remplacée/étendue par vague 1 |

---

## 7. Ordre de priorité recommandé

### P0 — Terminer l’exécution demo/testnet sûre

1. Finaliser les PR restantes Hyperliquid dry-run/testnet.
2. Finaliser les fixtures et recettes double exchange.
3. Produire le rapport final demo/testnet.
4. Autoriser `dry_run=false` uniquement sur OKX demo / Hyperliquid testnet si les gates sont validés.
5. Garder mainnet impossible.

### P1 — Fiabiliser la donnée et la config

1. Fiabiliser `position_trade_analysis`.
2. Assurer le lineage run/set/mode/exchange/symbol/trade.
3. Brancher l’effective config progressivement.
4. Exposer l’effective config et les runtime gates dans le cockpit.

### P2 — Comprendre les pertes

1. Produire la baseline par profil.
2. Classer les causes de trades perdants.
3. Vérifier l’impact réel des frais, spread, slippage et funding.
4. Proposer des corrections atomiques, sans tuning de fréquence prématuré.

### P3 — Optimiser les configs par mode

1. Construire le MTF Backstaging Config Mining.
2. Optimiser `regular`.
3. Optimiser `scalper`.
4. Valider ou rejeter `scalper_micro`.
5. Valider hors période / walk-forward.

### P4 — Shadow et live-readiness sans activation

1. Shadow production.
2. Fills réalistes.
3. Capital protection layer.
4. Mainnet-readiness sans secrets mainnet.
5. Go/no-go pilot éventuel.

---

## 8. Corps court proposé pour l’issue #173

Le contenu suivant peut remplacer le corps trop détaillé de l’issue parent.

```md
# TradingV3 — Roadmap globale

## Objectif

Construire un moteur de trading modulaire, observable et testable, piloté par la réduction des mauvais trades et l’amélioration de l’expectancy nette.

## Invariants

- Aucun trade sans SL immédiatement attaché.
- Le levier est dérivé du risque et du stop.
- Aucune activation live sans runtime-check.
- Aucune EntryZone desserrée sans preuve PnL.
- Les résultats incluent frais, spread, slippage et funding si applicable.
- Bitmart reste en place tant qu’un remplacement opérationnel n’est pas validé.
- OKX/Hyperliquid : dry-run d’abord, puis demo/testnet mutatif uniquement après gates dédiés.
- Aucun secret mainnet dans l’application.

## État global

- Vague 1 — OKX/Hyperliquid demo-testnet : en cours avancé.
- Vague 2 — Data/YAML/Cockpit/Profitability Foundation : à faire.
- Vague 3 — MTF Backstaging Config Mining : à faire.
- Vague 4 — Shadow/Capital Protection/Live-readiness : à faire.

## Priorités

1. Terminer l’exécution demo/testnet sûre.
2. Fiabiliser la donnée et la config effective.
3. Comprendre les pertes et produire une baseline nette.
4. Optimiser les configs par mode via backstaging + out-of-sample.
5. Passer en shadow production avant tout pilot réel.

## Documents de vagues

- Vague 1 : `docs/roadmap/01-okx-hyperliquid-demo-testnet.md`
- Vague 2 : `docs/roadmap/02-data-yaml-cockpit-profitability-foundation.md`
- Vague 3 : `docs/roadmap/03-mtf-backstaging-config-mining.md`
- Vague 4 : `docs/roadmap/04-shadow-capital-protection-live-readiness.md`

## Critère de clôture

Cette roadmap sera clôturée lorsque :

- l’exécution demo/testnet sera sûre et rollbackable ;
- les données PnL seront fiables ;
- une baseline nette sera disponible pour les trois profils ;
- les configs par mode auront un verdict validé/rejeté ;
- le shadow test aura produit un go/no-go clair ;
- toute décision de pilot réel sera séparée, manuelle et protégée.
```

---

## 9. Sources projet et suivi

### Pilotage

- [Issue #173](https://github.com/pivox/tradingV3/issues/173)
- [PR #187 — Documentation roadmap V2](https://github.com/pivox/tradingV3/pull/187)
- [PR #244 — Roadmap waves](https://github.com/pivox/tradingV3/pull/244)

### Documents de vagues

- [`01-okx-hyperliquid-demo-testnet.md`](01-okx-hyperliquid-demo-testnet.md)
- [`02-data-yaml-cockpit-profitability-foundation.md`](02-data-yaml-cockpit-profitability-foundation.md)
- [`03-mtf-backstaging-config-mining.md`](03-mtf-backstaging-config-mining.md)
- [`04-shadow-capital-protection-live-readiness.md`](04-shadow-capital-protection-live-readiness.md)

### PR et documents historiques

- [PR #141 — Architecture TradingCore](https://github.com/pivox/tradingV3/pull/141)
- [PR #142 — Effective Config Resolver](https://github.com/pivox/tradingV3/pull/142)
- [PR #155 — Orchestrateur Python / Temporal](https://github.com/pivox/tradingV3/pull/155)
- [PR #168 — Cockpit orchestration](https://github.com/pivox/tradingV3/pull/168)
- [PR #169 — Preview des sets](https://github.com/pivox/tradingV3/pull/169)
- [PR #172 — Lecture du payload effectif](https://github.com/pivox/tradingV3/pull/172)
- [PR #181 — Corrélation outcome/PnL](https://github.com/pivox/tradingV3/pull/181)

---

## 10. Conclusion

`docs/roadmap/tradingv3-roadmap-v2.md` reste l’index de pilotage durable.

La structure cible est :

```text
Issue parent #173
    -> vision, invariants, état global et priorités

Roadmap V2
    -> index durable, ordre des vagues, liens de pilotage

Documents de vagues
    -> critères détaillés, PR proposées, prompts types

Issues de suivi
    -> critères d’acceptation détaillés et découpage PR atomique

PR
    -> implémentation, docs, tests et preuves
```

Le cap final reste :

```text
moins de mauvais trades
+ expectancy nette positive
+ configs validées par mode
+ exécution sûre
+ capacité à dire no-go
```
