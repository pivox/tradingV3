# TradingV3 Handbook

Cette documentation est la source de vérité technique et fonctionnelle du dépôt `tradingV3`.

Elle décrit le système tel qu'il est organisé dans le code:

- une application Symfony 7.1 dans `trading-app/`;
- un moteur de validation multi-timeframe avec orchestration HTTP/CLI;
- un module TradeEntry qui transforme une décision en plan d'ordre;
- des workers Symfony Messenger pour projection, décision et surveillance d'ordres;
- des workers Temporal Python dans `cron_symfony_mtf_workers/`;
- une interface Ops Twig récente et une application React historique.

## Sources principales

| Domaine | Sources |
| --- | --- |
| Backend Symfony | `trading-app/src`, `trading-app/config`, `trading-app/migrations` |
| Profils MTF | `trading-app/src/MtfValidator/config/validations.*.yaml` |
| TradeEntry | `trading-app/config/app/trade_entry*.yaml`, `trading-app/src/TradeEntry` |
| Exchanges | `trading-app/src/Exchange`, `trading-app/src/Provider` |
| Workers Messenger | `trading-app/config/packages/messenger.yaml` |
| Temporal | `cron_symfony_mtf_workers` |
| Frontend React | `frontend/src` |
| Ops Twig | `trading-app/src/Front`, `trading-app/templates/front` |

## Convention documentaire

- Le Markdown dans `docs/handbook/` est la source lisible par l'IA et par les developpeurs.
- Le HTML est genere avec MkDocs dans `docs/site/`.
- Les graphes Mermaid documentent les piles d'execution et les objets qui transitent entre composants.
- Les documents historiques non alignes avec le code courant sont supprimes apres migration des informations utiles.

## Demarrage documentation

```bash
python3 -m pip install -r requirements-docs.txt
python3 -m mkdocs build --strict
python3 -m mkdocs serve
```

Le build statique produit `docs/site/index.html`.
