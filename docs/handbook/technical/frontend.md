# Frontend et Interfaces Ops

## Interface Ops Twig

La surface Ops recente est servie par Symfony:

| Route | Controleur | Role |
| --- | --- | --- |
| `/app` | `FrontController` | Cockpit. |
| `/app/risk` | `FrontRiskController` | Synthese risque. |
| `/app/decisions` | `FrontDecisionController` | Liste decisions. |
| `/app/decisions/{decisionKey}` | `FrontDecisionController` | Detail decision. |
| `/app/investigate` | `FrontInvestigationController` | Investigation. |
| `/app/system` | `FrontSystemController` | Sante systeme. |
| `/app/temporal` | `FrontTemporalController` | Resume Temporal. |
| `/app/config` | `FrontConfigController` | Configuration. |

Templates: `trading-app/templates/front`.
Assets: `trading-app/public/front/app.css` et `trading-app/public/front/app.js`.

## Web Symfony historique

Les templates sous `trading-app/templates` exposent encore des ecrans:

- audit MTF;
- locks/switches/states MTF;
- indicators;
- provider contracts/klines;
- signals;
- reporting;
- order submit;
- websocket.

Ils utilisent Bootstrap via `trading-app/templates/base.html.twig`.

## React legacy

L'application React dans `frontend/src` contient environ 9.6k lignes.

Routes principales:

- dashboard;
- dashboard MTF;
- recherche globale;
- graphiques;
- contrats;
- positions;
- pipeline;
- signaux;
- klines et missing klines;
- MTF state/audit/switch/locks;
- snapshots indicateurs;
- validation cache;
- configurations;
- comptes exchange;
- health monitoring;
- runtime history.

Le client HTTP central est `frontend/src/services/api.js`, configure par `frontend/src/config.js`.

## Statut de maintenance

- Les nouvelles vues operationnelles doivent privilegier `/app/*` sauf decision contraire.
- Le React legacy reste documente pour comprendre les endpoints consommes et les ecrans encore disponibles.
- Toute suppression frontend doit verifier les routes exposees, les liens nginx et les usages humains.
