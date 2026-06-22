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
- la PR [#181](https://github.com/pivox/tradingV3/pull/181), liée à la corrélation outcome/PnL, restait le principal point à finaliser ou à rebaser ;
- même après cette PR, le lineage complet par `set_id` et profil devait encore être traité séparément ;
- une recette de bout en bout sur la stack réelle restait nécessaire.

Les statuts de PR doivent être revérifiés avant mise à jour définitive de #173.

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

## 7. Issues filles recommandées

### Orchestration et observabilité

#### ORCH-001 — Recette runtime de l’orchestrateur Python

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

#### OBS-001 — Lineage complet run, set et trade

Propager et persister :

- `run_id` ;
- `dashboard_id` ;
- `set_id` ;
- `mtf_profile` ;
- `exchange` ;
- `market_type` ;
- `symbol` ;
- identifiant du trade ou de la position.

#### OBS-002 — Fiabiliser `position_trade_analysis`

Vérifier notamment :

- le rapprochement entrée/clôture par `trade_id` ou `position_id` ;
- l’absence de doublons ;
- la cohérence des événements partiels ;
- la définition contractuelle de `pnl_usdt` ;
- la présence des frais, du spread, du slippage et du funding.

### Analytics et stratégie

#### ANALYTICS-001 — Baseline « bad trades first »

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

#### BACKTEST-001 — Backtesting net réaliste

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

#### CONFIG-001 — Brancher l’Effective Config au runtime

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

#### CONFIG-002 — Effective Config Viewer

Afficher :

- les couches chargées ;
- les valeurs surchargées ;
- la provenance de chaque valeur ;
- la config finale utilisée ;
- les écarts avec la config attendue ;
- le lien avec le run et le trade.

### Front Ops

#### FRONT-001 — Décider la surface Front Ops canonique

Décider explicitement entre :

- Symfony/Twig comme front principal ;
- React comme front principal ;
- migration progressive ;
- répartition fonctionnelle clairement définie.

Aucun gros chantier d’écran ne doit démarrer avant cette décision.

#### FRONT-002 — Roadmap Front Ops orientée investigation

Prioriser les parcours permettant de comprendre :

- pourquoi un run a échoué ;
- pourquoi un symbole a été ignoré ;
- pourquoi un trade a été ouvert ;
- pourquoi il a perdu ;
- quelle config effective a été utilisée ;
- quels coûts ont dégradé le résultat.

### Exchanges

#### EXCHANGE-001 — Inventaire Bitmart

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

#### EXCHANGE-002 — Readiness Fake/Paper

Vérifier que Fake/Paper représente suffisamment :

- les statuts d’ordre ;
- les partial fills ;
- les erreurs ;
- les timeouts ;
- les frais ;
- le slippage ;
- les positions ;
- les SL/TP.

#### EXCHANGE-003 — Readiness OKX dry-run

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

#### EXCHANGE-004 — Readiness Hyperliquid dry-run

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

1. Finaliser la corrélation outcome/PnL.
2. Ajouter le lineage complet `run -> set -> trade`.
3. Fiabiliser `position_trade_analysis`.
4. Exécuter la recette runtime de l’orchestrateur.
5. Tester reprise, replay et rollback.

### P1 — Comprendre les pertes

1. Produire la baseline par profil.
2. Classer les causes de trades perdants.
3. Vérifier l’impact réel des frais et du slippage.
4. Proposer des corrections atomiques.

### P2 — Backtesting net

1. Construire le modèle de coûts.
2. Rejouer chaque profil séparément.
3. Comparer expectancy, drawdown et profit factor.
4. Préparer un forward test d’au moins 500 trades lorsque les données le permettent.

### P3 — Config effective et Front Ops

1. Brancher l’Effective Config progressivement.
2. Ajouter le viewer.
3. Décider React contre Symfony/Twig.
4. Construire les écrans d’investigation prioritaires.

### P4 — Nouveaux exchanges

1. Inventorier Bitmart.
2. Stabiliser Fake/Paper.
3. Valider OKX en dry-run.
4. Valider Hyperliquid en dry-run.
5. N’envisager le live qu’après runtime-check et rollback validés.

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

Les critères détaillés sont suivis dans les issues filles ORCH, OBS, ANALYTICS, BACKTEST, CONFIG, FRONT et EXCHANGE.

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

## 10. Sources projet

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

Issues filles
    -> critères d’acceptation atomiques

PR
    -> implémentation et tests
```
