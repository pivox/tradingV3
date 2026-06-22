# TradingV3 — Relecture de l’issue #173 et découpage recommandé

**Date de relecture : 22 juin 2026**  
**Issue concernée :** [#173 — Roadmap globale TradingV3](https://github.com/pivox/tradingV3/issues/173)

## 1. Décision

L’issue #173 contient trop de sujets pour rester une checklist technique détaillée.

Elle doit devenir une **issue parent de pilotage**, courte et stable, qui contient uniquement :

- la vision du projet ;
- les invariants à ne jamais casser ;
- l’état synthétique des grands chantiers ;
- l’ordre de priorité ;
- les liens vers les issues filles ;
- les critères permettant de clôturer la roadmap.

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
5. seulement ensuite ajuster la fréquence ou ouvrir de nouveaux exchanges.

La comparaison entre `regular`, `scalper` et `scalper_micro` doit reposer sur :

- le PnL net ;
- l’expectancy nette ;
- le profit factor ;
- le max drawdown ;
- le `pnl_R` ;
- le MFE et le MAE ;
- la durée moyenne ;
- les coûts d’exécution ;
- un forward test suffisamment long.

---

## 3. Invariants

- Aucune position sans stop-loss automatique immédiatement attaché.
- Le levier est dérivé du risque, du stop et des limites de l’exchange ; il n’est jamais choisi arbitrairement.
- Aucune bascule live sans runtime-check validé.
- Aucune EntryZone desserrée sans preuve PnL.
- Toute PR doit rester atomique, testée et traçable.
- Les analyses doivent partir de `position_trade_analysis`, après vérification de sa fiabilité.
- Les résultats doivent inclure les frais, le spread et le slippage.
- OKX et Hyperliquid restent en dry-run tant que leur branchement runtime complet n’est pas validé.
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
| Front Ops | Symfony/Twig et React existent | Décider quelle surface devient canonique avant d’étendre les écrans |
| Bitmart | Provider runtime historique | Le conserver temporairement et inventorier ses dépendances |
| Fake/Paper | Filet de sécurité | Le rendre représentatif avant les migrations exchange |
| OKX | Adapter/dry-run | Ne pas le considérer prêt au live |
| Hyperliquid | Adapter/dry-run | Ne pas le considérer prêt au live |
| Analytics | Baseline complète non finalisée | Priorité « bad trades first » |
| Backtesting net | À compléter | Intégrer frais, spread, slippage, funding et qualité des fills |

### État de la série orchestration

Lors de la dernière relecture :

- les lots UI, Temporal, sécurité, audit, tests et nettoyage étaient majoritairement fusionnés ;
- la PR [#181](https://github.com/pivox/tradingV3/pull/181) reste une référence historique, mais son périmètre incomplet est désormais repris par [#189](https://github.com/pivox/tradingV3/issues/189) et [#190](https://github.com/pivox/tradingV3/issues/190) ;
- le lineage complet par `set_id`, profil, exchange et trade est suivi dans #189 ;
- la fiabilité de `position_trade_analysis` et du PnL net est suivie dans #190 ;
- la recette de bout en bout sur la stack réelle est suivie dans [#188](https://github.com/pivox/tradingV3/issues/188).

L’état d’avancement doit désormais être maintenu dans les issues de suivi plutôt que dupliqué dans #173.

---

## 5. Ce qui doit rester dans l’issue #173

L’issue parent doit rester limitée aux blocs suivants :

### Vision

Construire un moteur de trading modulaire, observable et testable, en séparant :

- stratégie ;
- orchestration ;
- runtime applicatif ;
- providers exchange ;
- analytics ;
- backtesting ;
- Front Ops.

### État global

Utiliser uniquement quatre statuts :

- `À faire` ;
- `En cours` ;
- `Bloqué` ;
- `Terminé`.

### Priorités

Afficher seulement les priorités P0 à P4 décrites plus bas.

### Liens

Chaque chantier doit pointer vers une issue fille et, si nécessaire, vers un document dans `docs/`.

---

## 6. Ce qui doit sortir de l’issue #173

Les éléments suivants rendent l’issue illisible et doivent être déplacés :

- l’historique complet des PR ;
- les détails fichier par fichier ;
- les payloads JSON complets ;
- les scénarios détaillés de tests ;
- les plans de migration exchange ;
- les spécifications complètes des écrans ;
- les détails des couches YAML ;
- les règles complètes de backtesting ;
- les longues checklists runtime ;
- les décisions React contre Symfony/Twig ;
- les investigations sur les trades perdants.

---

## 7. Issues de suivi créées ou réutilisées

Les lots ci-dessous possèdent désormais une issue GitHub dédiée. Les issues existantes #132 et #133 sont réutilisées afin d’éviter les doublons.

| Domaine | Issue | Statut de création |
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
| Readiness OKX dry-run | [#197 — TV3-EXCHANGE-003](https://github.com/pivox/tradingV3/issues/197) | Créée |
| Readiness Hyperliquid dry-run | [#198 — TV3-EXCHANGE-004](https://github.com/pivox/tradingV3/issues/198) | Créée |

### Orchestration et observabilité

#### [#188 — TV3-ORCH-001 : Recette runtime de l’orchestrateur Python](https://github.com/pivox/tradingV3/issues/188)

Objectif : valider l’orchestrateur sur la stack réelle.

Critères principaux :

- migrations appliquées ;
- dashboards et sets réels ;
- appels dry-run vers Symfony ;
- parallélisme borné ;
- reprise après crash ;
- redémarrage de container ;
- replay ;
- audit ;
- métriques ;
- rollback testé ;
- vérification dans Temporal UI.

#### [#189 — TV3-DATA-001 : Lineage complet run, set et trade](https://github.com/pivox/tradingV3/issues/189)

Propager et persister :

- `run_id` ;
- `dashboard_id` ;
- `set_id` ;
- `mtf_profile` ;
- `exchange` ;
- `market_type` ;
- `symbol` ;
- identifiant du trade ou de la position.

#### [#190 — TV3-DATA-002 : Fiabiliser `position_trade_analysis`](https://github.com/pivox/tradingV3/issues/190)

Vérifier notamment :

- le rapprochement entrée/clôture par `trade_id` ou `position_id` ;
- l’absence de doublons ;
- la cohérence des événements partiels ;
- la définition contractuelle de `pnl_usdt` ;
- la présence des frais, du spread, du slippage et du funding.

### Analytics et stratégie

#### [#132 — Baseline « bad trades first »](https://github.com/pivox/tradingV3/issues/132)

Produire une baseline séparée pour :

- `regular` ;
- `scalper` ;
- `scalper_micro`.

Mesurer au minimum :

- winrate ;
- expectancy nette ;
- profit factor ;
- max drawdown ;
- `pnl_R` ;
- MFE/MAE ;
- durée ;
- coûts ;
- causes récurrentes de pertes.

#### [#191 — TV3-BACKTEST-001 : Backtesting net réaliste](https://github.com/pivox/tradingV3/issues/191)

Inclure :

- frais maker/taker ;
- spread ;
- slippage ;
- funding ;
- partial fills ;
- rejet d’ordre ;
- fallback maker/taker ;
- time-stop ;
- TP/SL ;
- liquidation guard.

Aucun résultat de backtest ne doit être inventé ou extrapolé sans données.

### Effective Config

#### [#133 — Effective Config en couches et branchement runtime](https://github.com/pivox/tradingV3/issues/133)

Architecture cible :

```text
base
+ mode
+ exchange
+ mode_exchange
+ env
= effective config
```

Le branchement doit être progressif, avec possibilité de comparer ancien et nouveau comportement.

#### [#192 — TV3-CONFIG-002 : Effective Config Viewer](https://github.com/pivox/tradingV3/issues/192)

Afficher :

- les couches chargées ;
- les valeurs surchargées ;
- la provenance de chaque valeur ;
- la config finale utilisée ;
- les écarts avec la config attendue ;
- le lien avec le run et le trade.

### Front Ops

#### [#193 — TV3-FRONT-001 : Décider la surface Front Ops canonique](https://github.com/pivox/tradingV3/issues/193)

Décider explicitement entre :

- Symfony/Twig comme front principal ;
- React comme front principal ;
- migration progressive ;
- répartition fonctionnelle clairement définie.

Aucun gros chantier d’écran ne doit démarrer avant cette décision.

#### [#194 — TV3-FRONT-002 : Roadmap Front Ops orientée investigation](https://github.com/pivox/tradingV3/issues/194)

Prioriser les parcours permettant de comprendre :

- pourquoi un run a échoué ;
- pourquoi un symbole a été ignoré ;
- pourquoi un trade a été ouvert ;
- pourquoi il a perdu ;
- quelle config effective a été utilisée ;
- quels coûts ont dégradé le résultat.

### Exchanges

#### [#195 — TV3-EXCHANGE-001 : Inventaire Bitmart](https://github.com/pivox/tradingV3/issues/195)

Inventorier :

- services ;
- providers ;
- commandes ;
- workflows ;
- paramètres ;
- credentials ;
- tests ;
- comportements spécifiques ;
- dépendances cachées.

#### [#196 — TV3-EXCHANGE-002 : Readiness Fake/Paper](https://github.com/pivox/tradingV3/issues/196)

Vérifier que Fake/Paper représente suffisamment :

- les statuts d’ordre ;
- les partial fills ;
- les erreurs ;
- les timeouts ;
- les frais ;
- le slippage ;
- les positions ;
- les SL/TP.

#### [#197 — TV3-EXCHANGE-003 : Readiness OKX dry-run](https://github.com/pivox/tradingV3/issues/197)

Le lot doit couvrir :

- provider bundle ;
- signature ;
- permissions ;
- précision ;
- rate limits ;
- runtime-check ;
- dry-run de bout en bout ;
- tests d’intégration ;
- rollback.

#### [#198 — TV3-EXCHANGE-004 : Readiness Hyperliquid dry-run](https://github.com/pivox/tradingV3/issues/198)

Le lot doit couvrir :

- wallet et signature ;
- environnement d’exécution ;
- précision ;
- limites ;
- risk guards ;
- runtime-check ;
- dry-run de bout en bout ;
- tests d’intégration ;
- rollback.

---

## 8. Ordre de priorité recommandé

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

### P2 — Backtesting net

1. Construire le moteur et le modèle de coûts dans [#191](https://github.com/pivox/tradingV3/issues/191).
2. Rejouer chaque profil séparément.
3. Comparer expectancy, drawdown et profit factor.
4. Préparer un forward test d’au moins 500 trades lorsque les données le permettent.

### P3 — Config effective et Front Ops

1. Brancher l’Effective Config progressivement via [#133](https://github.com/pivox/tradingV3/issues/133).
2. Ajouter le viewer via [#192](https://github.com/pivox/tradingV3/issues/192).
3. Décider la surface canonique via [#193](https://github.com/pivox/tradingV3/issues/193).
4. Construire la roadmap d’investigation via [#194](https://github.com/pivox/tradingV3/issues/194).

### P4 — Nouveaux exchanges

1. Inventorier Bitmart via [#195](https://github.com/pivox/tradingV3/issues/195).
2. Stabiliser Fake/Paper via [#196](https://github.com/pivox/tradingV3/issues/196).
3. Valider OKX en dry-run via [#197](https://github.com/pivox/tradingV3/issues/197).
4. Valider Hyperliquid en dry-run via [#198](https://github.com/pivox/tradingV3/issues/198).
5. N’envisager le live qu’après runtime-check, recette et rollback validés.

---

## 9. Corps court proposé pour l’issue #173

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
- Les résultats incluent frais, spread et slippage.
- Bitmart reste en place tant qu’un remplacement opérationnel n’est pas validé.
- OKX et Hyperliquid restent en dry-run avant readiness complète.

## État global

- Orchestrateur Python / Temporal : **en finalisation runtime**.
- Lineage et analytics : **à fiabiliser**.
- TradingCore : **fondations présentes, branchement incomplet**.
- Effective Config : **resolver présent, intégration runtime à poursuivre**.
- Front Ops : **surface canonique à décider**.
- Analytics « bad trades first » : **priorité produit**.
- Backtesting net : **à compléter**.
- OKX / Hyperliquid : **dry-run uniquement**.
- Bitmart : **legacy encore nécessaire**.

## Priorités

1. Fiabilité orchestration et données.
2. Baseline des pertes par profil.
3. Backtesting net.
4. Effective Config et Front Ops.
5. Readiness des nouveaux exchanges.

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

`docs/roadmap/tradingv3-roadmap-v2.md`

## Critère de clôture

Cette issue sera clôturée lorsque :

- le pipeline cible sera validé de bout en bout ;
- les données PnL seront fiables ;
- une baseline nette sera disponible pour les trois profils ;
- le backtesting net sera opérationnel ;
- la config effective sera observable ;
- la stratégie Front Ops sera tranchée ;
- au moins un chemin exchange cible sera validé hors live puis en live contrôlé.
```

---

## 10. Sources projet et suivi

### Pilotage

- [Issue #173](https://github.com/pivox/tradingV3/issues/173)
- [PR #187 — Documentation roadmap V2](https://github.com/pivox/tradingV3/pull/187)

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
- [#197 — Readiness OKX dry-run](https://github.com/pivox/tradingV3/issues/197)
- [#198 — Readiness Hyperliquid dry-run](https://github.com/pivox/tradingV3/issues/198)

### PR et documents historiques

- [Issue #173](https://github.com/pivox/tradingV3/issues/173)
- [PR #141 — Architecture TradingCore](https://github.com/pivox/tradingV3/pull/141)
- [PR #142 — Effective Config Resolver](https://github.com/pivox/tradingV3/pull/142)
- [PR #155 — Orchestrateur Python / Temporal](https://github.com/pivox/tradingV3/pull/155)
- [PR #168 — Cockpit orchestration](https://github.com/pivox/tradingV3/pull/168)
- [PR #169 — Preview des sets](https://github.com/pivox/tradingV3/pull/169)
- [PR #172 — Lecture du payload effectif](https://github.com/pivox/tradingV3/pull/172)
- [PR #181 — Corrélation outcome/PnL](https://github.com/pivox/tradingV3/pull/181)

## Conclusion

L’issue #173 doit devenir un **index de pilotage**, pas une documentation exhaustive.

La structure cible est :

```text
Issue parent #173
    -> vision, invariants, état global et priorités

Documents dans docs/
    -> décisions et descriptions durables

Issues de suivi #132, #133 et #188 à #198
    -> critères d’acceptation détaillés et découpage PR atomique

PR
    -> implémentation et tests
```
