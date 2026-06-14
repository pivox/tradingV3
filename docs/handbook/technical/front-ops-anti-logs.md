# Front Ops anti-logs

## Statut

Document de travail initial. Cette page sert de base produit et technique pour faire évoluer le front TradingV3 vers une console d'exploitation réellement utilisée au quotidien.

L'objectif n'est pas de créer un front esthétique ou exhaustif. L'objectif est de remplacer progressivement les recherches dans les logs par des écrans d'investigation, de sécurité et de décision.

## Problème à résoudre

Historiquement, l'exploitation de TradingV3 s'est surtout faite par lecture des logs : `mtf-runner.log`, `order-journey*.log`, logs provider, logs Temporal, exports SQL ponctuels, etc.

Des écrans existent déjà, mais ils n'ont pas toujours été utilisés comme point d'entrée opérationnel. Le risque à éviter est donc clair : construire de nouveaux écrans qui ne répondent pas aux vraies questions de debugging et qui finissent ignorés au profit des logs.

## Principe directeur

Chaque écran doit satisfaire au moins une des quatre fonctions suivantes :

1. aider à décider ;
2. aider à investiguer ;
3. aider à sécuriser le bot ;
4. aider à améliorer le PnL net.

Si un écran ne remplit aucun de ces rôles, il ne doit pas être prioritaire.

Règle produit : chaque fois qu'une action humaine nécessite d'ouvrir un fichier log, il faut identifier l'écran qui devrait répondre plus vite à cette question.

## Positionnement cible

Le front doit devenir une console Ops orientée investigation :

```text
Signal -> Décision -> Plan d'ordre -> Exécution -> Position -> Sortie -> Analyse PnL
```

Il ne doit pas seulement afficher des compteurs globaux. Il doit permettre de remonter la chaîne causale :

```text
Pourquoi ce symbole a été refusé ?
Pourquoi ce trade a été ouvert ?
Pourquoi l'ordre n'a pas été soumis ?
Pourquoi ce trade a perdu ?
Est-ce un problème de stratégie, de risque, de config, d'exécution ou d'exchange ?
```

## Surface actuelle à conserver comme base

La surface Symfony/Twig `/app/*` est la base à privilégier pour les nouveaux écrans Ops :

| Route | Rôle actuel |
| --- | --- |
| `/app` | Cockpit |
| `/app/risk` | Synthèse risque |
| `/app/decisions` | Liste des décisions |
| `/app/decisions/{decisionKey}` | Détail d'une décision |
| `/app/investigate` | Investigation |
| `/app/system` | Santé système |
| `/app/temporal` | Résumé Temporal |
| `/app/config` | Configuration |

Le React legacy reste utile comme source d'inspiration ou inventaire d'endpoints, mais les nouvelles vues opérationnelles doivent d'abord enrichir `/app/*` sauf décision contraire.

## Écrans cibles

### P0 — Utilité immédiate

#### 1. Trading Cockpit

But : savoir en moins de 30 secondes si le système est exploitable.

Contenu attendu :

- mode global : dry-run / live ;
- exchange actif ;
- market type ;
- profil actif : `regular`, `scalper`, `scalper_micro`, etc. ;
- positions ouvertes ;
- ordres ouverts ;
- trades du jour ;
- PnL du jour ;
- daily loss cap restant ;
- dernier run MTF ;
- erreurs bloquantes ;
- statut workers Messenger ;
- statut Temporal ;
- statut provider / WebSocket / REST.

Décisions permises :

- continuer à laisser tourner ;
- passer en dry-run ;
- suspendre un exchange ou un profil ;
- investiguer le dernier run ;
- investiguer une position ouverte.

#### 2. Run Timeline

But : remplacer la lecture du log MTF pour comprendre un cycle complet.

Vue attendue :

```text
Run ID
├── symboles analysés
├── symboles exclus
├── symboles rejetés
├── symboles validés
├── décisions envoyées
├── ordres créés
├── erreurs
└── durée par étape
```

Chaque symbole doit être cliquable :

```text
BTCUSDT -> pourquoi refusé ?
ETHUSDT -> pourquoi validé ?
SOLUSDT -> pourquoi ordre non soumis ?
```

Données utiles :

- `run_id` ;
- `profile` ;
- `exchange` ;
- `market_type` ;
- `summary_by_tf` ;
- `rejected_by` ;
- `last_validated` ;
- `orders_placed` ;
- timings par étape.

#### 3. Decision Detail

But : comprendre une décision sans ouvrir les logs.

Contenu attendu :

- decision key ;
- symbole ;
- side long/short ;
- profil ;
- exchange ;
- market type ;
- timeframe d'exécution ;
- contexte par timeframe ;
- conditions validées ;
- conditions échouées ;
- raison finale : `READY`, `INVALID`, `SKIPPED`, etc. ;
- prix au moment du signal ;
- ATR ;
- RSI ;
- MACD ;
- VWAP ;
- entry zone ;
- lien vers plan d'ordre si généré ;
- lien vers trade lifecycle si ordre soumis ;
- lien vers logs bruts seulement en dernier recours.

Question principale : pourquoi cette décision est-elle arrivée à ce résultat ?

#### 4. Trade Lifecycle

But : comprendre toute la vie d'un trade.

Timeline cible :

```text
Signal validé
-> Risk validé
-> Order plan construit
-> Levier soumis
-> Ordre soumis
-> Fill partiel/complet
-> SL/TP attachés
-> TP1 touché ou non
-> SL déplacé ou non
-> Position fermée
-> PnL final
```

Chaque étape doit afficher :

- timestamp ;
- statut ;
- payload métier ;
- payload exchange ;
- erreur éventuelle ;
- corrélation `run_id` / `decision_key` / `client_order_id` / `exchange_order_id` ;
- lien vers la donnée brute si nécessaire.

Règle non négociable : toute position live doit afficher immédiatement si un SL automatique est attaché. Une position sans SL visible doit être considérée comme une alerte critique.

#### 5. Position & Risk

But : écran de sécurité.

Contenu attendu :

- positions ouvertes ;
- taille ;
- levier ;
- marge ;
- prix d'entrée ;
- mark price ;
- liquidation estimée ;
- SL attaché : oui/non ;
- TP attaché : oui/non ;
- distance au SL ;
- distance à la liquidation ;
- risque USDT réel ;
- exposition par exchange ;
- exposition par symbole corrélé ;
- daily loss cap ;
- positions en anomalie.

Décisions permises :

- fermer manuellement une position ;
- forcer une resynchronisation ;
- suspendre le trading live ;
- identifier une position non protégée.

### P1 — Multi-exchange / multi-DEX

#### 6. Exchange Runtime Matrix

But : voir quels exchanges peuvent être utilisés, en dry-run ou en live.

Exemple cible :

| Exchange | Market | Provider | Credentials | WS | REST | Live ready | Dry-run |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Bitmart | Perpetual | OK | OK | OK | OK | Yes | No |
| OKX | Perpetual | Partial | Missing | ? | ? | No | Yes |
| Hyperliquid | Perpetual | Partial | Missing | ? | ? | No | Yes |
| Uniswap | DEX | Planned | Wallet ? | n/a | RPC ? | No | Yes |

Actions attendues :

- lancer un runtime-check ;
- visualiser credentials manquants ;
- vérifier provider bundle ;
- vérifier schedules Temporal ;
- vérifier guardrails live ;
- comprendre pourquoi `dry_run=false` est refusé.

#### 7. Effective Config

But : arrêter d'investiguer les YAML à la main.

Affichage cible :

```text
base.yaml
+ mode/scalper_micro.yaml
+ exchange/bitmart.yaml
+ override/scalper_micro.bitmart.yaml
+ env/prod.yaml
= effective config
```

Champs critiques à afficher :

- risk pct ;
- budget ;
- stop policy ;
- ATR policy ;
- leverage policy ;
- max leverage ;
- entry zone ;
- fallback taker ;
- market entry ;
- fees/slippage ;
- allowed execution timeframes ;
- runtime exchange ;
- live/dry-run guardrails.

#### 8. Gateway Health CEX/DEX

But : distinguer les problèmes stratégie des problèmes infrastructure exchange.

Pour CEX :

- REST public ;
- REST privé ;
- WebSocket public ;
- WebSocket privé ;
- rate limits ;
- order book freshness ;
- position sync ;
- open orders sync.

Pour DEX :

- RPC health ;
- chain id ;
- wallet status ;
- balance native gas ;
- token allowance ;
- quote provider ;
- simulation tx ;
- gas estimate ;
- MEV / private relay readiness si utilisé.

### P2 — Amélioration trading

#### 9. Trade Analytics

But : piloter les évolutions par expectancy nette, pas par intuition.

Base recommandée : vue `position_trade_analysis`.

Filtres :

- période ;
- profil ;
- exchange ;
- market type ;
- symbole ;
- timeframe ;
- side ;
- raison d'entrée ;
- raison de sortie ;
- RSI range ;
- ATR range ;
- volume ratio ;
- entry zone deviation ;
- order type maker/taker.

Métriques :

- nombre de trades ;
- winrate ;
- expectancy nette ;
- profit factor ;
- moyenne `pnl_R` ;
- gain moyen ;
- perte moyenne ;
- MFE moyen ;
- MAE moyen ;
- holding time moyen ;
- frais estimés ;
- slippage estimé.

#### 10. Setup Failure Explorer

But : réduire les mauvais trades avant d'augmenter la fréquence.

Questions :

- quelles conditions échouent le plus souvent ?
- quels setups passent mais perdent ensuite ?
- quels filtres auraient évité les pertes ?
- quels symboles sont systématiquement mauvais ?
- quels timeframes produisent la meilleure expectancy ?

#### 11. Config Comparison

But : comparer deux configurations sans débat subjectif.

Exemples :

```text
scalper_micro actuel
vs
scalper_micro avec min_volume_ratio=1.3
```

```text
zone_max_dev_pct=0.02
vs
zone_max_dev_pct=0.03
```

Sortie attendue :

- différence de trades ;
- différence de winrate ;
- différence d'expectancy ;
- différence de drawdown ;
- différence de frais ;
- symboles impactés ;
- risques ajoutés.

#### 12. Backtest / Forward-test Viewer

But : rendre visibles les résultats de backtest et forward test.

Affichage :

- période ;
- config testée ;
- nombre de trades ;
- equity curve ;
- distribution `pnl_R` ;
- drawdown ;
- confidence interval ;
- résultats forward test ;
- comparaison avec live.

## Parcours d'investigation prioritaires

### Parcours A — Pourquoi aucun trade n'a été pris ?

```text
Cockpit
-> dernier Run Timeline
-> symboles rejetés
-> groupement par raison de rejet
-> Decision Detail d'un symbole représentatif
-> Effective Config si le rejet vient d'un seuil YAML
```

### Parcours B — Pourquoi ce trade a perdu ?

```text
Trade Analytics
-> trade perdant
-> Trade Lifecycle
-> Decision Detail
-> indicateurs à l'entrée
-> MFE/MAE
-> raison de sortie
-> Config Comparison pour tester le filtre correctif
```

### Parcours C — Est-ce sûr de passer un exchange live ?

```text
Exchange Runtime Matrix
-> Gateway Health
-> Effective Config
-> Risk screen
-> dry-run history
-> validation live guardrails
```

### Parcours D — Pourquoi un ordre n'a pas été soumis ?

```text
Run Timeline
-> Decision Detail READY
-> Trade Lifecycle / Order Plan
-> Risk decision
-> Execution decision
-> Provider response
```

## Données et contrats UI à prévoir

Les écrans doivent être alimentés par des Query services dédiés, pas par de la logique dans les controllers.

Exemples :

```text
CockpitSummaryQuery
RunTimelineQuery
DecisionDetailQuery
TradeLifecycleQuery
RiskExposureQuery
ExchangeRuntimeMatrixQuery
EffectiveConfigQuery
TradeAnalyticsQuery
SetupFailureQuery
```

Chaque Query doit retourner un DTO stable avec `toArray()` pour Twig et API JSON.

## Critères d'acceptation généraux

- Un écran doit répondre à une question opérationnelle précise.
- Chaque écran doit afficher les identifiants de corrélation nécessaires : `run_id`, `decision_key`, `client_order_id`, `exchange_order_id`, `trade_id` si disponibles.
- Chaque vue d'investigation doit proposer un lien vers l'étape précédente et l'étape suivante du pipeline.
- Les logs bruts restent accessibles, mais ne doivent pas être le moyen principal de compréhension.
- Les écrans live doivent signaler clairement `dry-run` vs `live`.
- Une position live sans SL attaché doit être visible comme anomalie critique.
- Les écrans multi-exchange doivent afficher `exchange` et `market_type` explicitement.
- Les écrans DEX ne doivent pas réutiliser aveuglément le modèle d'ordre CEX : ils doivent tenir compte wallet, chain, RPC, gas, allowance, quote, simulation et transaction hash.

## Non-objectifs immédiats

- Ne pas reconstruire tout le front React maintenant.
- Ne pas créer de module auth dans cette phase.
- Ne pas ajouter d'actions dangereuses de trading manuel sans garde-fous.
- Ne pas chercher à remplacer tous les logs en une PR.
- Ne pas mélanger design visuel avancé et utilité opérationnelle : la priorité est la lisibilité et l'investigation.

## Découpage de PR proposé

### PR 1 — Documentation et cadrage

- Ajouter ce document.
- Ajouter les routes existantes dans la documentation.
- Définir les écrans P0/P1/P2.
- Valider les parcours d'investigation.

### PR 2 — Run Timeline minimal

- Créer Query `RunTimelineQuery`.
- Afficher les derniers runs.
- Grouper symboles par statut.
- Lier vers Decision Detail.

### PR 3 — Decision Detail enrichi

- Ajouter conditions validées/échouées.
- Ajouter contexte MTF par timeframe.
- Ajouter entry zone et indicateurs clés.
- Ajouter lien vers ordre/trade si disponible.

### PR 4 — Trade Lifecycle

- Construire timeline depuis `trade_lifecycle_event`.
- Corréler signal, ordre, position, sortie.
- Afficher SL/TP attachés.
- Signaler anomalies critiques.

### PR 5 — Position & Risk sécurité

- Enrichir `/app/risk`.
- Ajouter exposition réelle.
- Ajouter SL/TP status.
- Ajouter liquidation guard et daily cap.

### PR 6 — Exchange Runtime Matrix

- Afficher readiness par exchange/market/profile.
- Intégrer runtime-check.
- Afficher dry-run/live guardrails.

### PR 7 — Effective Config

- Implémenter l'affichage config effective.
- Expliquer les couches appliquées.
- Exposer les paramètres de risque/exécution réellement utilisés.

### PR 8 — Trade Analytics

- Construire les vues à partir de `position_trade_analysis`.
- Ajouter filtres profil/exchange/symbole/timeframe/side.
- Afficher expectancy, winrate, PnL R, MFE, MAE.

## Questions ouvertes pour itération

- Faut-il conserver uniquement Symfony/Twig ou préparer une future extraction front plus riche ?
- Quels logs doivent être convertis en événements structurés pour alimenter Trade Lifecycle ?
- Quelle est la source de vérité pour les runs : logs, tables MTF, Temporal history ou nouvelles projections ?
- Quels champs manquent dans `trade_lifecycle_event` pour reconstruire toute la timeline ?
- Quel niveau d'action manuelle autoriser depuis l'Ops front ?
- Comment représenter les exécutions DEX dans la même console sans forcer le modèle CEX ?

## Vision finale

Le front TradingV3 doit devenir l'outil principal d'exploitation du bot.

Succès attendu : lorsqu'un trade est refusé, exécuté ou perdant, l'utilisateur doit commencer par `/app`, pas par les logs.

```text
Logs = preuve brute et dernier recours.
Front Ops = compréhension, décision et sécurité.
```
