# Cahier de spécifications fonctionnelles  
# Synchronisation des ordres ws-worker → trading-app

## 1. Contexte
- `ws-worker` est aujourd’hui responsable des souscriptions WebSocket Bitmart et, à court terme, de l’exécution effective des ordres principaux ainsi que des garde-fous (stop-loss / take-profit).
- `trading-app` (backend Symfony) porte la planification, le suivi métier (`OrderPlan`, `OrderLifecycle`, `Position`) et l’orchestration post-exécution.
- Problème actuel : lorsqu’un ordre est déclenché directement depuis `ws-worker`, le back-office `trading-app` n’est pas informé de façon synchrone. Les tables `order_plan`, `order_lifecycle` et `positions` ne sont mises à jour qu’a posteriori via les flux WebSocket Bitmart (et encore partiellement).
- Conséquence : absence de visibilité consolidée sur les ordres en cours, difficulté à lier un ordre exécuté par le worker à son plan initial, impossibilité de déclencher des règles de reprise (re-soumission SL/TP, cooldown, etc.) au moment opportun.

## 2. Objectifs produit
1. **Synchroniser en temps réel** chaque ordre placé par `ws-worker` avec `trading-app`.
2. **Assurer la traçabilité** complète d’un ordre (plan → soumission → exécution) pour l’audit et la prise de décision.
3. **Réduire les incohérences** entre les données locales (`trading-app`) et l’exchange Bitmart.
4. **Préparer l’automatisation** de scénarios (re-soumission SL/TP, gestion du cooldown, suivi des positions) dès la soumission de l’ordre.

## 3. Périmètre
### 3.1 Inclus
- Ordres « main » (entrée position), stop-loss et take-profit soumis par `ws-worker`.
- Création / mise à jour d’un enregistrement `OrderLifecycle` associé à l’ordre.
- Mise à jour de l’`OrderPlan` ayant conduit à la soumission (statut, métadonnées d’exécution, horodatages).
- Mise à jour préalable de la `Position` ciblée (création si nécessaire, enrichissement du payload).
- Gestion des rejets / retards côté `ws-worker` (mémorisation + retry) et accusé de réception `trading-app`.

### 3.2 Exclus
- Gestion des événements d’exécution reçus depuis le WebSocket Bitmart (reste couvert par les webhooks existants `/api/orders/events`, `/api/bitmart/positions`).
- Refonte du moteur de décision (signal, post-validation).
- Gestion des ordres autre que main/SL/TP (ordre d’annulation manuel, ordres ladder, …) ; ils pourront être spécifiés plus tard.

## 4. Acteurs & systèmes
| Acteur / Système | Rôle | Responsabilités principales |
|------------------|------|-----------------------------|
| `ws-worker` | Producteur d’évènements | Soumettre l’ordre à l’exchange, consolider la réponse, appeler l’API REST `trading-app` |
| Bitmart | Exchange externe | Retour succès/échec de la soumission, flux d’événements |
| `trading-app` | Consommateur / orchestrateur | Valider le payload, persister les entités impactées, renvoyer un ACK |
| Monitoring (Grafana/Logs) | Observabilité | Visualiser le déroulement complet d’un ordre |

## 5. Définitions & vocabulaire
- **Ordre principal** : ordre d’ouverture (maker ou market) qui crée une position.
- **Ordre de protection** : stop-loss (`SL`) ou take-profit (`TP`) rattaché à un ordre principal.
- **OrderPlan** : plan de trading produit par `trading-app` et stocké dans `order_plan`, contenant stratégie, risque, execution.
- **OrderLifecycle** : ligne de vie d’un ordre unique sur l’exchange (statut, action, payload).
- **Position** : état agrégé par symbole & sens (`LONG`/`SHORT`) de la position Bitmart, enrichi localement.
- **Signal REST** : appel HTTP de `ws-worker` → `trading-app` décrivant un ordre nouvellement soumis.
- **Kind** : type fonctionnel d’un ordre (`ENTRY`, `STOP_LOSS`, `TAKE_PROFIT`).

## 6. Parcours fonctionnels
### 6.1 Soumission d’un ordre principal
1. `ws-worker` soumet l’ordre à Bitmart et reçoit une réponse (succès / rejet).
2. En cas de succès, un signal REST est envoyé à `trading-app` dans les 300 ms suivant la réception de la réponse.
3. `trading-app` :
   - valide la structure et l’authenticité du message,
   - localise l’`OrderPlan` concerné (via `clientOrderId`, `runId`, ou un identifiant de plan fourni),
   - crée/actualise `OrderLifecycle` (statut initial `SUBMITTED`, `kind=ENTRY`),
   - enrichit l’`OrderPlan` (`status` → `EXECUTED`, `execJson` → details Bitmart, dates),
   - prépare/actualise la `Position` correspondante (`payload.pending_entry` avec taille target, prix, leverage).
4. `trading-app` retourne `HTTP 202` ou `HTTP 200` avec `{"status":"accepted","order_id":"..."}`.
5. `ws-worker` logge le succès ; en cas d’erreur >500 ou de timeout, il planifie des retries exponentiels et garde l’évènement dans un buffer.

### 6.2 Soumission d’un stop-loss ou take-profit
1. `ws-worker` soumet l’ordre (souvent immédiatement après l’ordre principal).
2. Pour chaque ordre de protection accepté :
   - envoi d’un signal REST (`kind=STOP_LOSS` ou `TAKE_PROFIT`),
   - `trading-app` lie l’ordre au même plan et au `OrderLifecycle` d’entrée (`parent_client_order_id`, `group_id`),
   - `OrderLifecycle` dédié est créé/mis à jour avec le `client_order_id` dérivé (`..._SL_...`, `..._TP_...`),
   - `OrderPlan.execJson.protections` est enrichi (ordre_id, prix, taille, statut),
   - la `Position.payload.protections` est actualisée.
3. `trading-app` répond comme pour un ordre principal.

### 6.3 Gestion des rejets immédiats
- Si Bitmart rejette l’ordre (erreur business), `ws-worker` NOTIFIE `trading-app` avec un `status=REJECTED` pour permettre :
  - le passage du plan en `FAILED` ou `CANCELLED`,
  - le déclenchement d’alertes ou fallback.
- `trading-app` conserve la trace du rejet (payload + code) dans `OrderLifecycle.payload.last_submission`.

### 6.4 Corrélation avec les webhooks existants
- Les webhooks `/api/orders/events` et `/api/bitmart/positions` restent la source de vérité sur l’état final (fill, cancel).
- Le nouveau signal REST doit garantir que `OrderLifecycle` existe déjà lorsque les webhooks postérieurs arrivent (permettant des updates cohérentes).
- Les statuts de `OrderPlan`/`Position` sont affinés ensuite par les webhooks (ex. passage `OPEN` → `CLOSED`).

## 7. Exigences fonctionnelles détaillées
| ID | Description | Priorité |
|----|-------------|----------|
| **F1** | `ws-worker` doit publier un signal REST pour chaque ordre accepté ou rejeté par Bitmart (main, SL, TP). | Haute |
| **F2** | Le signal doit être idempotent, identifié par `client_order_id` ou `order_id`. | Haute |
| **F3** | `trading-app` doit créer/mettre à jour l’`OrderLifecycle` correspondant et tracer `kind`, `status`, `last_submission`. | Haute |
| **F4** | Lors d’un ordre principal accepté, `OrderPlan.status` passe à `EXECUTED` et `execJson` enregistre l’horodatage et l’identifiant de l’ordre. | Haute |
| **F5** | Lors d’un ordre de protection accepté, `OrderPlan.execJson.protections` est complété (un enregistrement par ordre). | Haute |
| **F6** | `Position` est créée/ajustée si elle n’existe pas (statut par défaut `OPEN`/`CLOSED` selon `size` > 0, payload enrichi avec `pending_entry`). | Moyenne |
| **F7** | Un accusé de réception (ACK) est retourné à `ws-worker` avec la référence interne (`order_lifecycle_id`) pour faciliter les logs croisés. | Moyenne |
| **F8** | En cas d’erreur de validation (400), `ws-worker` logge et marque l’ordre avec `sync_status=FAILED`, sans retry automatique. | Moyenne |
| **F9** | En cas d’erreur serveur (5xx / timeout), `ws-worker` doit re-tenter au moins 5 fois (backoff exponentiel max 2 min). | Haute |
| **F10** | Tous les événements doivent être loggés et métésurés (`orders.sync.success`, `orders.sync.failure`). | Moyenne |

## 8. Données échangées (payload REST)
| Champ | Type | Obligatoire | Description |
|-------|------|-------------|-------------|
| `kind` | `ENTRY` \| `STOP_LOSS` \| `TAKE_PROFIT` | Oui | Typologie métier de l’ordre |
| `order_id` | string | Non (si order en attente) | Identifiant Bitmart (si connu immédiatement) |
| `client_order_id` | string | Oui | Identifiant unique côté client (`MTF_...`) |
| `symbol` | string | Oui | Symbole Bitmart (`BTCUSDT`) |
| `side` | string | Oui | Côté Bitmart (`buy_open_long`, `sell_close_long`, etc.) |
| `type` | string | Oui | `limit`, `market`, `stop_loss`, ... |
| `price` | string | Oui pour limit/SL/TP | Prix de déclenchement ou d’exécution |
| `size` | string | Oui | Quantité soumise |
| `leverage` | int/string | Optionnel | Lévérage en vigueur |
| `plan` | objet | Optionnel | `{id, uuid, run_id}` pour localiser l’OrderPlan |
| `position` | objet | Optionnel | `{symbol, side}` pour créer/mettre à jour la Position |
| `context` | objet | Oui | Données métiers (entry_price, stop_loss_price, take_profit_price, risk, strategy, timeframe) |
| `submitted_at` | ISO 8601 | Oui | Horodatage UTC de la soumission |
| `exchange_response` | objet | Oui | Payload brut retourné par Bitmart |
| `status` | `SUBMITTED` \| `REJECTED` | Oui | Statut initial du placement |
| `retry_count` | int | Non | Nombre de tentatives effectuées côté worker |

## 9. Règles de gestion & cohérence
- **R1** : l’idempotence se base sur `client_order_id`. Un même `client_order_id` reçu plusieurs fois met à jour le même `OrderLifecycle`.
- **R2** : si `order_id` absent (Bitmart renvoi différé), `trading-app` crée tout de même le `OrderLifecycle` et l’enrichira lors d’un prochain signal contenant l’`order_id` ou via webhook.
- **R3** : la cohérence `OrderPlan` ↔ `OrderLifecycle` est garantie en fournissant au minimum `plan.id` ou `plan.hash` dans le signal. Si absent et introuvable, le signal est refusé (400) pour un `ENTRY`.
- **R4** : les ordres de protection doivent référencer le `client_order_id` de l’ordre principal (`parent_client_order_id`) dans le payload pour permettre le regroupement.
- **R5** : `Position` ne doit pas être mise en `OPEN` tant qu’aucun événement « FILLED » n’a été reçu. Toutefois, le champ `payload.expected_entry` doit refléter la taille / prix planifiés.
- **R6** : en cas de rejet `REJECTED`, `OrderPlan.status` bascule en `FAILED` (sauf si `retryable=true` dans le contexte) et une alerte doit être propagée (hook alerte à définir techniquement).

## 10. Contraintes non fonctionnelles
- Latence : < 500 ms entre la réponse Bitmart et la réception du signal par `trading-app` (hors retries).
- Disponibilité : `trading-app` doit supporter des bursts de 30 ordres/minute.
- Sécurité : authentification du signal (token partagé ou signature HMAC), TLS obligatoire.
- Traçabilité : corrélation par `order_id`, `client_order_id`, `plan.id`, et `trace_id` généré par `ws-worker`.
- Observabilité : métriques Prometheus côté `trading-app` (`orders_signal_total`, `orders_signal_failed_total`, `orders_signal_processing_seconds`).

## 11. Scénarios de validation (haut niveau)
1. **Happy path entrée** : ordre principal accepté, signal reçu → `OrderPlan` = `EXECUTED`, `OrderLifecycle` créé, `Position.payload.expected_entry` renseigné.
2. **Happy path protections** : SL & TP acceptés, deux signaux successifs → `OrderLifecycle` dédiés, `OrderPlan.execJson.protections` contient 2 éléments.
3. **Rejet entrée** : Bitmart renvoie une erreur → signal avec `REJECTED` → `OrderPlan.status=FAILED`, log d’alerte.
4. **Retry réseau** : `trading-app` indisponible → 2 échecs → 3ᵉ tentative OK, un seul `OrderLifecycle` créé (idempotence vérifiée).
5. **Signal non corrélable** : `plan` introuvable pour un `ENTRY` → HTTP 400, `ws-worker` logge l’erreur et passe l’ordre en `sync_failed`.

## 12. Hypothèses & points ouverts
- `ws-worker` disposera des identifiants du plan (`plan.id` ou `plan.uuid`). Sinon, ajouter une API pour les récupérer avant la soumission.
- Les ordres multiples (ladder) ne sont pas gérés : un seul ordre principal par plan.
- L’authentification concrète (clé HMAC, Basic Auth, autre) sera précisée dans la spécification technique.
- L’impact côté interface utilisateur (frontend) sera spécifié ultérieurement.

## Implémentation (journal)
- **Étape 1** – Analyse des composants existants `ws-worker` / `trading-app` pour confirmer les points d’injection du signal REST.
- **Étape 2** – Ajout côté worker du DTO `OrderSignal`, du `OrderSignalDispatcher` (HTTP + retries) et de l’intégration dans `OrderWorker` avec anti-duplication + nouvelle configuration `TRADING_APP_*`.
- **Étape 3** – Création côté `trading-app` de l’endpoint `/api/ws-worker/orders`, du service `WorkerOrderSyncService`, de l’entité `ExchangeOrder` et de l’extension `OrderLifecycle` (colonne `kind`).
- **Étape 4** – Couverture minimale par tests unitaires (`WorkerOrderSyncServiceTest`) et mise à jour des documentations pour refléter le comportement implémenté.
