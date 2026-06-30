# TradingV3 — Roadmap V2 parent et vagues de pilotage

**Date de relecture initiale :** 22 juin 2026  
**Mise à jour :** 30 juin 2026  
**Issue parent :** [#173 — Roadmap globale TradingV3](https://github.com/pivox/tradingV3/issues/173)  
**PR de structuration des vagues :** [#244 — docs: add TradingV3 roadmap waves](https://github.com/pivox/tradingV3/pull/244)

Ce document est l’index parent de la roadmap V2.

Il ne doit pas devenir une checklist exhaustive. Les critères détaillés, plans de migration, prompts de PR et décisions longues doivent vivre dans les documents de vagues, les issues filles et les PR atomiques.

---

## 1. Décision

L’issue #173 doit rester une **issue parent de pilotage**, courte et stable, contenant uniquement :

- la vision du projet ;
- les invariants à ne jamais casser ;
- l’état synthétique des grands chantiers ;
- l’ordre de priorité ;
- les liens vers les issues filles ;
- les liens vers les documents de roadmap ;
- les critères permettant de clôturer ou de réorienter la roadmap.

Les détails doivent être répartis dans :

1. les documents Markdown versionnés dans `docs/roadmap/` ;
2. les issues techniques atomiques ;
3. les PR qui implémentent chaque issue.

---

## 2. Cap produit à conserver

TradingV3 ne doit plus être piloté par l’objectif **« plus de trades »**.

La priorité est :

1. réduire les mauvais trades ;
2. fiabiliser la donnée d’analyse ;
3. obtenir une expectancy nette positive ;
4. intégrer les frais, le spread, le slippage et le funding ;
5. seulement ensuite ajuster la fréquence, ouvrir de nouveaux exchanges ou envisager une phase live contrôlée.

La comparaison entre `regular`, `scalper` et `scalper_micro` doit reposer sur :

- le PnL net ;
- l’expectancy nette ;
- le profit factor ;
- le max drawdown ;
- le `pnl_R` ;
- le MFE et le MAE ;
- la durée moyenne ;
- les coûts d’exécution ;
- un forward test ou shadow test suffisamment long.

---

## 3. Invariants

- Aucune position sans stop-loss automatique immédiatement attaché.
- Le levier est dérivé du risque, du stop et des limites de l’exchange ; il n’est jamais choisi arbitrairement.
- Aucune bascule live sans runtime-check validé.
- Aucune EntryZone desserrée sans preuve PnL nette.
- Toute PR doit rester atomique, testée et traçable.
- Les analyses doivent partir de `position_trade_analysis`, après vérification de sa fiabilité.
- Les résultats doivent inclure les frais, le spread, le slippage et le funding si applicable.
- OKX et Hyperliquid ne sont pas prêts au trading réel.
- OKX/Hyperliquid doivent suivre la séquence : dry-run local → demo/testnet mutatif contrôlé → shadow/readiness → décision go/no-go.
- Toute écriture mainnet reste interdite tant qu’une readiness dédiée et une décision humaine explicite ne sont pas validées.
- Aucun secret mainnet ne doit être demandé, stocké, loggé ou documenté dans les vagues demo/testnet.
- Bitmart ne doit pas être supprimé tant qu’une solution de remplacement n’est pas réellement opérationnelle.

---

## 4. État synthétique du projet

| Domaine | État synthétique | Décision |
|---|---|---|
| Orchestrateur Python | La majorité des lots d’orchestration ont été livrés | Terminer par une recette runtime réelle, pas uniquement des tests isolés |
| Temporal | La cible est un cron simple appelant l’orchestrateur Python | Ne pas réintroduire l’orchestration multi-target dans Temporal |
| Corrélation des données | `run_id` est traité, mais le lineage complet reste à fiabiliser | Propager `dashboard_id`, `set_id`, profil, exchange et marché jusqu’aux trades |
| `position_trade_analysis` | Vue centrale pour l’analyse | Vérifier le rapprochement entrée/sortie et la définition exacte du PnL net |
| TradingCore | Fondations présentes | Le branchement runtime complet reste à terminer |
| Effective Config | Resolver en couches présent | Le connecter progressivement au runtime et rendre la config effective observable |
| Front Ops | Symfony/Twig et React existent | Décider la surface canonique avant d’étendre les écrans |
| Bitmart | Provider runtime historique | Le conserver temporairement et inventorier ses dépendances |
| Fake/Paper | Filet de sécurité | Le rendre représentatif avant les migrations exchange |
| OKX | Intégration demo-testnet en cours | Dry-run local puis OKX Demo Trading contrôlé, jamais mainnet |
| Hyperliquid | Intégration testnet en cours | Dry-run local puis Hyperliquid testnet contrôlé, jamais mainnet |
| Analytics | Baseline complète non finalisée | Priorité « bad trades first » |
| Backtesting net | À compléter | Intégrer frais, spread, slippage, funding et qualité des fills |
| Backstaging MTF | Nouveau chantier de validation configs | Optimiser les configs par mode sans look-ahead puis valider hors période |
| Shadow production | Nouveau chantier post-config | Tester l’edge sans capital réel avant toute décision live |

---

## 5. Roadmap par vagues

Les 4 fichiers suivants sont les documents opérationnels de la roadmap.

### Vague 1 — OKX / Hyperliquid demo-testnet

Document : [`01-okx-hyperliquid-demo-testnet.md`](./01-okx-hyperliquid-demo-testnet.md)

Objectif : fiabiliser OKX demo et Hyperliquid testnet sans aucune écriture mainnet.

Décision produit :

- `dry_run=true` reste la première étape ;
- `dry_run=false` peut être autorisé uniquement sur `environment=demo|testnet`, avec gates explicites ;
- le mainnet reste interdit ;
- aucun secret mainnet n’est attendu.

### Vague 2 — Data, YAML, Cockpit & Profitability Foundation

Document : [`02-data-yaml-cockpit-profitability-foundation.md`](./02-data-yaml-cockpit-profitability-foundation.md)

Objectif : construire la base data/config/ops nécessaire pour décider par données.

Axes principaux :

- fiabiliser `position_trade_analysis` ;
- intégrer frais, spread, slippage et funding ;
- brancher le YAML layered resolver ;
- rendre l’effective config observable ;
- industrialiser le cockpit opérateur ;
- tracer le lineage run/set/mode/exchange/symbol jusqu’au trade.

### Vague 3 — MTF Backstaging Config Mining

Document : [`03-mtf-backstaging-config-mining.md`](./03-mtf-backstaging-config-mining.md)

Objectif : optimiser les configs par mode via backstaging chronologique, puis valider hors période.

Axes principaux :

- backstaging sans look-ahead ;
- stockage des no-trades et des échecs ;
- optimisation séparée de `regular`, `scalper`, `scalper_micro` ;
- scoring net avec MFE, MAE, `pnl_R`, frais, spread et slippage ;
- market regime classifier ;
- walk-forward / out-of-sample validation ;
- export YAML candidate/validated/rejected.

### Vague 4 — Shadow, Capital Protection & Live-readiness

Document : [`04-shadow-capital-protection-live-readiness.md`](./04-shadow-capital-protection-live-readiness.md)

Objectif : vérifier que l’edge survit aux conditions de marché réelles sans capital réel, puis préparer la gouvernance de risque.

Axes principaux :

- shadow production ;
- fills réalistes ;
- spread/slippage réels ou pessimistes ;
- capital protection layer ;
- mainnet-readiness sans activation mainnet ;
- go/no-go pilot contrôlé éventuel ;
- production risk governance ;
- scaling contrôlé uniquement si l’edge est prouvé.

---

## 6. Issues de suivi créées ou réutilisées

| Domaine | Issue | Statut |
|---|---|---|
| Recette runtime orchestrateur | [#188 — TV3-ORCH-001](https://github.com/pivox/tradingV3/issues/188) | Créée |
| Lineage run → set → trade | [#189 — TV3-DATA-001](https://github.com/pivox/tradingV3/issues/189) | Créée |
| Fiabilité `position_trade_analysis` | [#190 — TV3-DATA-002](https://github.com/pivox/tradingV3/issues/190) | Créée |
| Baseline « bad trades first » | [#132 — Rapport PnL](https://github.com/pivox/tradingV3/issues/132) | Réutilisée |
| Backtesting net | [#191 — TV3-BACKTEST-001](https://github.com/pivox/tradingV3/issues/191) | Créée |
| Effective Config en couches | [#133 — Config YAML layered](https://github.com/pivox/tradingV3/issues/133) | Réutilisée |
| Effective Config Viewer | [#192 — TV3-CONFIG-002](https://github.com/pivox/tradingV3/issues/192) | Créée |
| Décision Front Ops | [#193 — TV3-FRONT-001](https://github.com/pivox/tradingV3/issues/193) | Créée |
| Roadmap Front Ops investigation | [#194 — TV3-FRONT-002](https://github.com/pivox/tradingV3/issues/194) | Créée |
| Inventaire Bitmart | [#195 — TV3-EXCHANGE-001](https://github.com/pivox/tradingV3/issues/195) | Créée |
| Readiness Fake/Paper | [#196 — TV3-EXCHANGE-002](https://github.com/pivox/tradingV3/issues/196) | Créée |
| Readiness OKX dry-run/demo | [#197 — TV3-EXCHANGE-003](https://github.com/pivox/tradingV3/issues/197) | Créée |
| Readiness Hyperliquid dry-run/testnet | [#198 — TV3-EXCHANGE-004](https://github.com/pivox/tradingV3/issues/198) | Créée |

Les nouvelles vagues issues de #244 peuvent ensuite donner lieu à des issues filles supplémentaires, notamment pour :

- Vague 2 : `position_trade_analysis`, YAML layered resolver, cockpit opérateur ;
- Vague 3 : MTF Backstaging Config Mining et optimisation par mode ;
- Vague 4 : Shadow Production, Capital Protection et Live-readiness.

---

## 7. Ordre de priorité recommandé

### P0 — Fiabiliser l’exécution et la donnée

1. Finaliser le lineage complet via [#189](https://github.com/pivox/tradingV3/issues/189).
2. Fiabiliser `position_trade_analysis` et le PnL net via [#190](https://github.com/pivox/tradingV3/issues/190).
3. Exécuter la recette runtime via [#188](https://github.com/pivox/tradingV3/issues/188).
4. Tester reprise, replay, idempotence et rollback dans #188.
5. Ne basculer aucun legacy avant validation de ces trois issues.

### P1 — Comprendre les pertes

1. Produire la baseline par profil dans [#132](https://github.com/pivox/tradingV3/issues/132).
2. Classer les causes de trades perdants.
3. Vérifier l’impact réel des frais et du slippage à partir des données certifiées de #190.
4. Proposer des corrections atomiques, sans tuning de fréquence prématuré.

### P2 — Backtesting net et fondations profitabilité

1. Construire le moteur et le modèle de coûts dans [#191](https://github.com/pivox/tradingV3/issues/191).
2. Rejouer chaque profil séparément.
3. Comparer expectancy, drawdown et profit factor.
4. Préparer un forward test ou shadow test lorsque les données le permettent.
5. Exécuter la vague 2 pour fiabiliser les métriques, YAML et cockpit.

### P3 — Config effective, Front Ops et backstaging

1. Brancher l’Effective Config progressivement via [#133](https://github.com/pivox/tradingV3/issues/133).
2. Ajouter le viewer via [#192](https://github.com/pivox/tradingV3/issues/192).
3. Décider la surface canonique via [#193](https://github.com/pivox/tradingV3/issues/193).
4. Construire la roadmap d’investigation via [#194](https://github.com/pivox/tradingV3/issues/194).
5. Exécuter la vague 3 MTF Backstaging Config Mining.

### P4 — Exchanges demo/testnet puis shadow/readiness

1. Inventorier Bitmart via [#195](https://github.com/pivox/tradingV3/issues/195).
2. Stabiliser Fake/Paper via [#196](https://github.com/pivox/tradingV3/issues/196).
3. Valider OKX en dry-run puis demo trading contrôlé via [#197](https://github.com/pivox/tradingV3/issues/197) et la vague 1.
4. Valider Hyperliquid en dry-run puis testnet contrôlé via [#198](https://github.com/pivox/tradingV3/issues/198) et la vague 1.
5. N’envisager le mainnet qu’après runtime-check, recette, rollback, shadow et go/no-go explicite.

### P5 — Shadow, capital protection et décision business

1. Exécuter la vague 4 Shadow Production.
2. Ajouter le capital protection layer.
3. Préparer la mainnet-readiness sans activation.
4. Produire un go/no-go pilot contrôlé.
5. Décider : source rentable, pause, refonte, ou projet R&D/portfolio.

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
- OKX et Hyperliquid suivent : dry-run local → demo/testnet mutatif contrôlé → shadow/readiness → go/no-go.
- Aucune écriture mainnet sans décision humaine explicite.

## État global

- Orchestrateur Python / Temporal : **en finalisation runtime**.
- Lineage et analytics : **à fiabiliser**.
- TradingCore : **fondations présentes, branchement incomplet**.
- Effective Config : **resolver présent, intégration runtime à poursuivre**.
- Front Ops : **surface canonique à décider**.
- Analytics « bad trades first » : **priorité produit**.
- Backtesting net : **à compléter**.
- OKX / Hyperliquid : **demo/testnet uniquement, jamais mainnet**.
- Backstaging MTF : **nouveau chantier config mining**.
- Shadow / Capital Protection : **nouveau chantier pre-live**.
- Bitmart : **legacy encore nécessaire**.

## Priorités

1. Fiabilité orchestration et données.
2. Baseline des pertes par profil.
3. Backtesting net et coûts réels.
4. Effective Config et Front Ops.
5. OKX/Hyperliquid demo-testnet.
6. MTF Backstaging Config Mining.
7. Shadow Production et Capital Protection.
8. Décision go/no-go business.

## Suivi

Les critères détaillés sont suivis dans :

- orchestration : #188 ;
- lineage et données : #189, #190 ;
- analytics : #132 ;
- backtesting : #191 ;
- configuration : #133, #192 ;
- Front Ops : #193, #194 ;
- exchanges : #195, #196, #197, #198.

La roadmap détaillée est maintenue dans :

- `docs/roadmap/tradingv3-roadmap-v2.md` ;
- `docs/roadmap/01-okx-hyperliquid-demo-testnet.md` ;
- `docs/roadmap/02-data-yaml-cockpit-profitability-foundation.md` ;
- `docs/roadmap/03-mtf-backstaging-config-mining.md` ;
- `docs/roadmap/04-shadow-capital-protection-live-readiness.md`.

## Critère de clôture

Cette issue sera clôturée lorsque :

- le pipeline cible sera validé de bout en bout ;
- les données PnL seront fiables ;
- une baseline nette sera disponible pour les trois profils ;
- le backtesting net sera opérationnel ;
- la config effective sera observable ;
- la stratégie Front Ops sera tranchée ;
- OKX/Hyperliquid seront validés hors mainnet ;
- au moins une config par mode aura un verdict : validated, candidate, rejected ou disabled ;
- une décision go/no-go sera possible sur shadow/pilot contrôlé.
```

---

## 9. Sources projet et suivi

### Pilotage

- [Issue #173](https://github.com/pivox/tradingV3/issues/173)
- [PR #187 — Documentation roadmap V2](https://github.com/pivox/tradingV3/pull/187)
- [PR #244 — Roadmap waves](https://github.com/pivox/tradingV3/pull/244)

### Documents de vagues

- [`01-okx-hyperliquid-demo-testnet.md`](./01-okx-hyperliquid-demo-testnet.md)
- [`02-data-yaml-cockpit-profitability-foundation.md`](./02-data-yaml-cockpit-profitability-foundation.md)
- [`03-mtf-backstaging-config-mining.md`](./03-mtf-backstaging-config-mining.md)
- [`04-shadow-capital-protection-live-readiness.md`](./04-shadow-capital-protection-live-readiness.md)

### Issues de suivi

- [#188 — Recette runtime orchestrateur](https://github.com/pivox/tradingV3/issues/188)
- [#189 — Lineage run → set → trade](https://github.com/pivox/tradingV3/issues/189)
- [#190 — Fiabilité position_trade_analysis](https://github.com/pivox/tradingV3/issues/190)
- [#132 — Baseline PnL / bad trades first](https://github.com/pivox/tradingV3/issues/132)
- [#191 — Backtesting net](https://github.com/pivox/tradingV3/issues/191)
- [#133 — Effective Config en couches](https://github.com/pivox/tradingV3/issues/133)
- [#192 — Effective Config Viewer](https://github.com/pivox/tradingV3/issues/192)
- [#193 — Décision Front Ops](https://github.com/pivox/tradingV3/issues/193)
- [#194 — Roadmap Front Ops investigation](https://github.com/pivox/tradingV3/issues/194)
- [#195 — Inventaire Bitmart](https://github.com/pivox/tradingV3/issues/195)
- [#196 — Readiness Fake/Paper](https://github.com/pivox/tradingV3/issues/196)
- [#197 — Readiness OKX dry-run/demo](https://github.com/pivox/tradingV3/issues/197)
- [#198 — Readiness Hyperliquid dry-run/testnet](https://github.com/pivox/tradingV3/issues/198)

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

L’issue #173 doit rester un **index de pilotage**, pas une documentation exhaustive.

La structure cible est :

```text
Issue parent #173
    -> vision, invariants, état global et priorités

Documents dans docs/roadmap/
    -> décisions durables, vagues et plans de PR

Issues de suivi #132, #133 et #188 à #198
    -> critères d’acceptation détaillés et découpage atomique

PR
    -> implémentation, tests, documentation et rollback
```

Le cap final reste :

```text
moins de mauvais trades
+ expectancy nette positive
+ sécurité d’exécution
+ preuve par position_trade_analysis
+ validation config par mode
+ shadow avant capital réel
```
