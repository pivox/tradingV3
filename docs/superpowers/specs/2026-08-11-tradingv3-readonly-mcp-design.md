# TradingV3 — Serveur MCP local de diagnostic read-only

- **Statut** : design approuvé, prêt pour planification d’implémentation
- **Date** : 2026-08-11
- **Identifiant** : TV3-MCP-001
- **Portée** : MVP local, lecture seule, données persistées
- **Décision principale** : monter un serveur MCP Python dans \`python-orchestrator\`
- **Transport** : Streamable HTTP sur \`http://127.0.0.1:8099/mcp\`
- **SDK cible** : \`mcp==2.0.0\`
- **Spécification protocolaire cible** : MCP 2026-07-28

## 1. Résumé exécutif

TradingV3 dispose déjà de nombreuses surfaces de lecture : historique des runs d’orchestration, résultats MTF, lineage, décisions, positions, protections, risque, configuration effective, files Messenger et santé système. Ces informations restent cependant réparties entre Symfony, le Python Orchestrator, PostgreSQL et plusieurs écrans opérateur.

Le présent design propose d’exposer ces capacités à un assistant local — Codex, Claude ou tout autre hôte compatible MCP — au travers d’un serveur Model Context Protocol strictement read-only. Le MVP doit répondre à des questions opérationnelles telles que :

- pourquoi un symbole n’a-t-il produit aucun ordre ?
- quelles règles ou timeframes ont bloqué un run MTF ?
- comment relier un run, une décision, un order intent, un ordre, une position et ses événements de lifecycle ?
- quelles positions persistées paraissent non protégées ?
- quelle configuration effective a été résolue pour un mode, un exchange et un environnement ?
- quelles files, tables ou dépendances locales semblent dégradées ?

Le MCP ne devient ni un moteur de trading, ni un accès SQL générique, ni un lecteur de logs. Il adapte des read-models contrôlés et bornés. Aucun outil ne peut lancer un run, placer ou annuler un ordre, modifier un SL/TP, gérer un lock, rafraîchir des contrats ou contacter un exchange.

L’architecture retenue monte le serveur MCP dans \`python-orchestrator\`, qui possède déjà FastAPI, Pydantic, httpx, une base d’orchestration et un port loopback. Les données propres à l’orchestrateur sont lues par ses repositories. Les données métier Symfony sont obtenues via des façades GET dédiées ou des contrats GET existants. Python ne lit jamais directement les tables Doctrine.

## 2. Contexte et état actuel

### 2.1 Architecture pertinente

TradingV3 sépare actuellement :

| Composant | Responsabilité pertinente pour MCP |
| --- | --- |
| Symfony | Moteur MTF, décisions, TradeEntry, lineage, read-models métier, configuration effective |
| Python Orchestrator | Dashboards, sets, exécution agrégée, historique des runs et résultats par set |
| PostgreSQL | Données métier et schéma isolé \`orchestration\` |
| Messenger/Redis | Décisions, projections et surveillance d’ordres |
| Front Ops | Read-models de risque, santé, décisions et investigation |
| Temporal | Déclenchement planifié ; hors du chemin MCP MVP |
| Exchanges | Sources externes explicitement interdites au MCP MVP |

Le service \`python-orchestrator\` est déjà derrière le profil Docker \`orchestrator\` et publié sur \`127.0.0.1:8099\`. Il utilise FastAPI, Pydantic, httpx et SQLAlchemy. Il expose notamment :

- \`GET /healthcheck\` ;
- \`GET /runs\` ;
- \`GET /runs/{run_id}\` ;
- \`GET /runs/{run_id}/sets/{set_id}\` ;
- \`GET /runs/{run_id}/outcome\` ;
- les routes dashboards/sets ;
- \`POST /orchestrator/run\`, qui ne sera jamais adapté comme outil MCP dans ce lot.

Symfony expose déjà des lectures exploitables :

- \`GET /api/lineage/v1/search\` et le détail/events par trade ;
- \`GET /api/trading/config/effective\` ;
- \`GET /api/positions/analysis\` ;
- \`GET /app/api/risk/summary\` ;
- \`GET /app/api/system/health\` ;
- \`GET /app/api/decisions/latest\` ;
- \`GET /mtf/runs/{runId}\`.

Certaines surfaces ne sont pas encore adaptées à un agent :

- le détail MTF historique renvoie les symboles et métriques sans pagination dédiée ;
- \`InvestigationQuery\` applique une limite fixe de 80 à chaque section ;
- l’investigation Front Ops est rendue en HTML et ne possède pas de contrat JSON MCP ;
- l’investigation inclut une liste de fichiers d’export, inutile et trop révélatrice pour MCP ;
- les erreurs et payloads imbriqués nécessitent une redaction défensive ;
- aucune enveloppe de résultat commune ne distingue source vide, source indisponible et réponse tronquée.

### 2.2 Problème à résoudre

Aujourd’hui, un assistant doit connaître les routes internes, leurs formes, les identifiants de corrélation et les différences entre run MTF et run d’orchestration. Il peut être tenté de lire directement des tables, des fichiers ou des logs, ce qui produit des réponses fragiles, trop volumineuses et potentiellement sensibles.

Le MCP doit fournir une frontière stable et métier :

1. découverte de sept outils clairement nommés ;
2. entrées fortement typées ;
3. résultats structurés, paginés, bornés et expurgés ;
4. erreurs actionnables ;
5. aucune capacité mutative ;
6. aucune dépendance à un exchange ;
7. tests prouvant ces invariants.

## 3. Objectifs et critères de succès

### 3.1 Objectifs

- Permettre une investigation guidée depuis un assistant local.
- Corréler les données persistées sans accès SQL générique.
- Réutiliser les read-models canoniques au lieu de reconstruire le métier en Python.
- Distinguer explicitement « aucun résultat » de « source indisponible ».
- Réduire la taille de contexte par pagination et troncature structurée.
- Préserver les contrats HTTP et les écrans existants.
- Fournir des annotations MCP fiables pour tous les outils.
- Produire une base testable et extensible vers d’autres lectures futures.

### 3.2 Critères de succès

Le MVP est accepté si :

- un client MCP local découvre exactement les sept outils définis dans ce document ;
- une enquête par symbole, run ou decision key retourne les artefacts persistés corrélés ;
- aucun appel MCP ne produit d’écriture en base, de message Messenger ou d’appel exchange ;
- aucune réponse ne contient secret, credential, variable d’environnement, stack trace ou contenu brut de log ;
- toutes les listes sont bornées et signalent leur troncature ;
- les erreurs de source ne sont jamais converties silencieusement en tableaux vides ;
- l’endpoint n’écoute que sur loopback dans la configuration livrée ;
- les suites Python et PHP restent vertes ;
- la couverture Python demeure au moins à 95 % ;
- dix évaluations read-only stables démontrent l’utilité réelle du catalogue.

## 4. Non-objectifs et hors périmètre

Le MVP n’inclut pas :

- lancement d’un run MTF ou d’orchestration ;
- placement, modification, annulation ou clôture d’ordre ;
- modification de SL/TP ;
- gestion de locks, switches, schedules ou workers ;
- rafraîchissement des contrats ;
- calcul d’indicateurs à la demande ;
- récupération de klines ou d’order books ;
- lecture de positions ou ordres directement depuis un exchange ;
- accès SQL, shell ou filesystem générique ;
- recherche dans les logs ;
- téléchargement des exports d’investigation ;
- resources MCP, prompts MCP, MCP Apps ou sampling ;
- exposition LAN/Internet ;
- OAuth 2.1 dans le MVP local ;
- modification automatique de stratégie ou recommandation de trading ;
- promesse de rentabilité.

Tout outil mutatif futur devra faire l’objet d’un design séparé, d’une autorisation forte, d’une confirmation utilisateur, d’idempotence, d’un audit et de garde-fous trading explicites. Il ne sera pas ajouté derrière un simple feature flag au catalogue read-only.

## 5. Options étudiées

### 5.1 Option A — MCP dans Python Orchestrator

Le serveur MCP est monté dans l’application ASGI existante.

**Avantages**

- réutilisation de Python 3.11, FastAPI, Pydantic, httpx et SQLAlchemy ;
- accès naturel à l’historique d’orchestration ;
- SDK MCP Python officiel mature ;
- un seul port local déjà publié ;
- tests en mémoire possibles avec le client MCP officiel ;
- pas de nouveau service opérationnel.

**Inconvénients**

- nécessite d’intégrer correctement le lifespan MCP au lifespan FastAPI ;
- certaines lectures Symfony exigent des façades supplémentaires ;
- le service d’orchestration porte une seconde interface protocolaire.

### 5.2 Option B — Sidecar MCP séparé

Un nouveau conteneur Python expose MCP et appelle Symfony/Python Orchestrator.

**Avantages**

- isolation de panne et de dépendances ;
- frontière de déploiement très explicite ;
- aucune modification du lifespan FastAPI actuel.

**Inconvénients**

- nouveau conteneur, port, healthcheck, configuration et maintenance ;
- duplication des clients HTTP et des modèles ;
- latence et observabilité supplémentaires ;
- peu de valeur pour un MVP mono-utilisateur local.

### 5.3 Option C — MCP natif Symfony/PHP

Symfony expose directement les outils MCP et appelle ses services métier.

**Avantages**

- accès direct aux read-models Symfony ;
- moins de hops pour les données métier ;
- pas de façade HTTP supplémentaire pour Symfony.

**Inconvénients**

- écosystème MCP moins naturel dans le stack actuel ;
- couplage protocolaire au moteur de trading ;
- accès moins direct à la base d’orchestration Python ;
- davantage de protocole et de transport à maintenir.

### 5.4 Décision

L’option A est retenue. Elle maximise la réutilisation, minimise l’exploitation et garde le MCP hors du cœur d’exécution Symfony. Le serveur utilisera le SDK Python officiel \`mcp==2.0.0\` et le protocole MCP 2026-07-28, tout en restant compatible avec les clients supportés par le SDK.

## 6. Architecture cible

\`\`\`mermaid
flowchart LR
    Client[Assistant local<br/>Codex / Claude / autre hôte MCP]
    MCP[MCPServer<br/>python-orchestrator<br/>127.0.0.1:8099/mcp]
    Service[TradingV3 MCP Read Service]
    Orchestration[(Repositories<br/>schéma orchestration)]
    SymfonyClient[Symfony Read Client<br/>GET allowlisté]
    Symfony[Façades/read-models Symfony]
    TradingDB[(Tables métier persistées)]
    Config[Configuration effective]
    Exchange[Exchanges]

    Client -->|Streamable HTTP| MCP
    MCP --> Service
    Service --> Orchestration
    Service --> SymfonyClient
    SymfonyClient --> Symfony
    Symfony --> TradingDB
    Symfony --> Config
    Service -. interdit .-> Exchange
    SymfonyClient -. aucune route live .-> Exchange
\`\`\`

### 6.1 Frontières de responsabilité

- **MCPServer** : protocole, découverte, schémas d’entrée, annotations et dispatch.
- **MCP Read Service** : orchestration des lectures, enveloppes, pagination, warnings, redaction et limites.
- **Symfony Read Client** : allowlist de chemins GET, timeout, authentification Ops optionnelle et mapping des erreurs HTTP.
- **Repositories orchestration** : historique local des runs et sets ; aucune lecture des tables Doctrine.
- **Façades Symfony** : sérialisation métier et requêtes DB canoniques.
- **Transport security** : validation Host/Origin et bind loopback.
- **Observabilité** : métriques et logs sans arguments métier.

### 6.2 Flux nominal

\`\`\`mermaid
sequenceDiagram
    participant A as Assistant
    participant M as MCPServer
    participant S as MCP Read Service
    participant P as Repository Python
    participant H as Symfony GET
    participant D as PostgreSQL

    A->>M: tools/call tradingv3_investigate
    M->>M: validation Pydantic
    M->>S: critères normalisés
    S->>H: GET /app/api/mcp/v1/investigation
    H->>D: lectures bornées
    D-->>H: sections persistées
    H-->>S: JSON versionné
    S->>S: validation, redaction, limite
    S-->>M: résultat structuré
    M-->>A: structuredContent
\`\`\`

### 6.3 Flux dégradé

\`\`\`mermaid
sequenceDiagram
    participant A as Assistant
    participant M as MCPServer
    participant S as Read Service
    participant H as Symfony

    A->>M: tools/call tradingv3_get_run
    M->>S: run_kind=mtf
    S->>H: GET façade MTF
    H--xS: timeout / 503
    S->>S: map source_unavailable
    S-->>M: erreur métier sans stack trace
    M-->>A: isError + code + prochaine action
\`\`\`

## 7. Catalogue MCP v1

Le serveur se nomme \`tradingv3_mcp\`. Tous les outils utilisent le préfixe \`tradingv3_\`.

Annotations communes :

| Annotation | Valeur | Justification |
| --- | --- | --- |
| \`readOnlyHint\` | \`true\` | aucun état modifié |
| \`destructiveHint\` | \`false\` | aucune suppression ou mutation |
| \`idempotentHint\` | \`true\` | répétition sans effet additionnel |
| \`openWorldHint\` | \`false\` | sources locales persistées uniquement |

### 7.1 \`tradingv3_list_recent_runs\`

**But** : lister les derniers runs MTF ou d’orchestration.

**Entrée**

| Champ | Type | Règle |
| --- | --- | --- |
| \`run_kind\` | enum | \`mtf\` ou \`orchestration\` |
| \`limit\` | entier | défaut 20, min 1, max 50 |
| \`offset\` | entier | défaut 0, min 0 |

**Sortie**

- métadonnées de pagination ;
- liste légère sans payloads volumineux ;
- statut, compteurs, timestamps, dry-run et identifiants disponibles ;
- \`has_more\` et \`next_offset\`.

Pour \`mtf\`, la source est la façade Symfony paginée. Pour \`orchestration\`, la source est le repository SQLAlchemy.

### 7.2 \`tradingv3_get_run\`

**But** : obtenir le détail contrôlé d’un run.

**Entrée**

| Champ | Type | Règle |
| --- | --- | --- |
| \`run_kind\` | enum | \`mtf\` ou \`orchestration\` |
| \`run_id\` | chaîne | 1 à 255 caractères ; UUID exigé pour \`mtf\` |
| \`symbol_limit\` | entier | défaut 20, max 50 |
| \`symbol_offset\` | entier | défaut 0 |

**Sortie MTF**

- résumé du run ;
- page de symboles avec statut, timeframe, side, raison principale et identifiant de décision ;
- métriques bornées ;
- warnings de données incomplètes.

**Sortie orchestration**

- résumé du run ;
- dernier JSON global après redaction ;
- au plus 50 sets triés ;
- payload et réponse de set redacted ;
- warning si les sets ou le JSON ont été tronqués.

Un run MTF inconnu retourne \`not_found\`. Une source indisponible ne retourne jamais un faux run vide.

### 7.3 \`tradingv3_investigate\`

**But** : corréler les artefacts persistés expliquant une décision ou une absence d’ordre.

**Entrée**

| Champ | Type | Règle |
| --- | --- | --- |
| \`symbol\` | chaîne optionnelle | normalisée en majuscules |
| \`occurred_at\` | RFC 3339 optionnel | normalisé UTC ; fenêtre ±1 h |
| \`run_id\` | chaîne optionnelle | identifiant MTF/corrélation |
| \`decision_key\` | chaîne optionnelle | identifiant exact ou préfixe prévu par le read-model |
| \`limit_per_section\` | entier | défaut 20, max 50 |

Au moins un parmi \`symbol\`, \`run_id\` ou \`decision_key\` est obligatoire. Un timestamp invalide produit \`invalid_input\` au lieu d’être ignoré.

**Sections**

- \`mtf_symbols\` ;
- \`mtf_audit\` ;
- \`order_intents\` ;
- \`orders\` ;
- \`plan_orders\` ;
- \`lifecycle\` ;
- \`zone_events\` ;
- \`snapshots\` ;
- \`entry_zones\`.

Chaque section retourne \`items\`, \`count\`, \`limit\` et \`truncated\`. Les métadonnées de fichiers d’export ne sont pas exposées.

### 7.4 \`tradingv3_trace_trade\`

**But** : naviguer dans le lineage persistant sans reconstruire les relations par symbole ou fenêtre approximative.

**Entrée**

- \`identifier_kind\` parmi :
  - \`orchestration_run_id\` ;
  - \`correlation_run_id\` ;
  - \`orchestration_set_id\` ;
  - \`orchestration_dashboard_id\` ;
  - \`internal_trade_id\` ;
  - \`internal_position_id\` ;
  - \`order_intent_id\` ;
  - \`client_order_id\` ;
  - \`exchange_order_id\` ;
  - \`position_id\`.
- \`identifier\` non vide ;
- \`exchange\` et \`market_type\` obligatoires pour les identifiants venue-scoped ;
- \`limit\` défaut 20, max 50 ;
- \`offset\` défaut 0.

**Sortie**

- lineage canonique ;
- completeness status ;
- quality flags ;
- événements lifecycle paginés ;
- conflits d’identifiants explicites.

### 7.5 \`tradingv3_get_risk_snapshot\`

**But** : obtenir une vue persistée de l’exposition et des protections.

**Entrée** : aucune.

**Sortie**

- positions persistées ouvertes ;
- ordres et plan orders persistés actifs ;
- détection de protection stop-loss selon le read-model canonique ;
- locks actifs/stale ;
- order intents stale ;
- alertes structurées.

Ce snapshot n’est pas une lecture live de l’exchange. La réponse doit exposer \`source=persisted\`, \`generated_at\` et les timestamps disponibles afin d’éviter toute confusion de fraîcheur.

### 7.6 \`tradingv3_get_system_health\`

**But** : résumer l’état local des dépendances nécessaires à l’investigation.

**Entrée** : aucune.

**Sortie**

- connectivité DB ;
- présence des tables critiques ;
- compteurs des files Messenger persistées ;
- statut connu/inconnu des workers ;
- nom, taille et fraîcheur des fichiers de log, sans contenu et sans chemin absolu ;
- état du Python Orchestrator.

Les erreurs internes sont converties en checks \`warning\`, \`critical\` ou \`unknown\`, jamais en stack trace.

### 7.7 \`tradingv3_get_effective_config\`

**But** : expliquer la configuration réellement résolue.

**Entrée**

| Champ | Type | Règle |
| --- | --- | --- |
| \`mode\` | chaîne | requis |
| \`exchange\` | chaîne | requis |
| \`environment\` | chaîne | requis, mappé vers \`env\` côté Symfony |

**Sortie**

- configuration finale ;
- couches et provenance si disponibles ;
- version/hash ;
- warnings de résolution ;
- redaction récursive obligatoire.

Aucun outil ne retourne les secrets ayant servi à construire un provider.

## 8. Contrat de réponse commun

Chaque succès utilise l’enveloppe :

\`\`\`json
{
  "schema_version": "1.0",
  "ok": true,
  "source": "symfony",
  "generated_at": "2026-08-11T10:30:00Z",
  "data": {},
  "pagination": {
    "limit": 20,
    "offset": 0,
    "count": 20,
    "total": 42,
    "has_more": true,
    "next_offset": 20
  },
  "warnings": []
}
\`\`\`

Les outils non paginés mettent \`pagination\` à \`null\`.

Une erreur d’exécution contrôlée utilise un résultat MCP en erreur et une charge structurée :

\`\`\`json
{
  "schema_version": "1.0",
  "ok": false,
  "error": {
    "code": "source_unavailable",
    "message": "La source Symfony de diagnostic est indisponible.",
    "retryable": true,
    "suggestion": "Vérifier /healthcheck puis réessayer."
  },
  "warnings": []
}
\`\`\`

### 8.1 Codes d’erreur

| Code | Signification | Retryable |
| --- | --- | --- |
| \`invalid_input\` | schéma, enum, timestamp ou combinaison invalide | non |
| \`not_found\` | objet absent d’une source disponible | non |
| \`upstream_auth_failed\` | token Ops manquant/invalide | non jusqu’à correction |
| \`source_unavailable\` | timeout, connexion, 502/503/504 | oui |
| \`upstream_contract_error\` | JSON invalide ou forme inattendue | non |
| \`response_too_large\` | résultat impossible à réduire proprement | non |
| \`internal_error\` | erreur inattendue masquée | selon contexte |

### 8.2 Règle empty versus unavailable

- source disponible, zéro ligne : \`ok=true\`, \`count=0\` ;
- table optionnelle absente et read-model conçu pour le tolérer : succès avec warning explicite ;
- DB inaccessible, HTTP non-200 inattendu ou timeout : erreur \`source_unavailable\` ;
- jamais de normalisation d’une panne en liste vide silencieuse.

## 9. Pagination, taille et troncature

- taille de page par défaut : 20 ;
- maximum public : 50 ;
- taille sérialisée maximale par réponse : 262 144 octets ;
- aucun chargement non borné en mémoire ;
- troncature appliquée par collection ou section, jamais au milieu d’un objet JSON ;
- toute troncature ajoute un warning avec chemin, limite et prochaine pagination ;
- les payloads imbriqués volumineux peuvent être remplacés par :
  - \`truncated=true\` ;
  - \`original_size_bytes\` ;
  - un sous-ensemble sûr ;
- \`get_run(orchestration)\` limite les sets à 50 même si le stockage en contient davantage ;
- les métriques MTF sont bornées et signalées comme tronquées.

## 10. Sécurité

### 10.1 Modèle de confiance

Le MVP suppose un poste de développement de confiance et un client MCP natif local. Il ne suppose pas que les arguments ou les données persistées sont fiables.

Le serveur doit donc :

- écouter uniquement via le port Docker publié sur \`127.0.0.1\` ;
- refuser les Host autres que \`localhost\` et \`127.0.0.1\` ;
- refuser par défaut toute requête portant un Origin navigateur ;
- accepter un Origin uniquement s’il figure dans une allowlist explicite ;
- ne jamais prendre les annotations MCP comme mécanisme d’autorisation ;
- ne jamais construire une URL arbitraire depuis un argument de modèle ;
- ne jamais accepter un chemin de fichier ou une requête SQL ;
- ne jamais transmettre un token retourné par une source.

### 10.2 Redaction

La redaction est récursive sur mappings et listes. Une clé est sensible si son nom normalisé contient notamment :

- \`password\` ;
- \`passwd\` ;
- \`secret\` ;
- \`token\` ;
- \`api_key\` ;
- \`apikey\` ;
- \`credential\` ;
- \`authorization\` ;
- \`cookie\` ;
- \`private_key\` ;
- \`mnemonic\` ;
- \`seed\`.

La valeur est remplacée par \`[REDACTED]\`. La redaction intervient avant mesure finale et sérialisation MCP.

Les messages d’erreur ne reprennent pas :

- corps brut de réponse HTTP ;
- DSN ;
- headers ;
- stack trace ;
- chemin absolu ;
- variable d’environnement ;
- payload exchange privé.

### 10.3 Authentification

L’endpoint MCP n’utilise pas OAuth en v1 parce qu’il est limité au loopback et à un utilisateur local. Ce choix cesse d’être valable dès qu’une exposition réseau est envisagée.

Les façades Symfony sous \`/app\` restent protégées par \`OpsFrontAccessSubscriber\`. Le Python Orchestrator transmet \`SYMFONY_OPS_TOKEN\` dans \`X-Ops-Token\` lorsqu’il est configuré. En dev/test, le comportement actuel sans token reste possible.

Pour une exposition distante future, un design séparé devra imposer HTTPS, OAuth 2.1, validation d’audience et autorisation par scope.

### 10.4 Prompt injection et données hostiles

Les champs persistés peuvent contenir du texte contrôlé indirectement par une API, un exchange ou un opérateur. Le MCP :

- les retourne comme données, jamais comme instructions système ;
- n’insère aucune donnée dans la description des outils ;
- n’interprète aucun contenu comme URL, commande ou nom de fichier ;
- ne fournit aucun outil qui permettrait à une donnée hostile de déclencher une action.

## 11. Façades Symfony

Ajouter une façade read-only sous \`/app/api/mcp/v1\`.

### 11.1 Routes

\`\`\`text
GET /app/api/mcp/v1/mtf-runs
GET /app/api/mcp/v1/mtf-runs/{runId}
GET /app/api/mcp/v1/investigation
\`\`\`

Les routes risk, system health, lineage et effective config existantes restent réutilisées. Le client Python maintient une allowlist fermée des chemins autorisés.

### 11.2 Liste MTF

Paramètres : \`limit\`, \`offset\`.

Réponse :

- \`items\` légers ;
- \`total\` ;
- \`limit\`, \`offset\`, \`has_more\`, \`next_offset\` ;
- ordre \`started_at DESC\`, puis \`run_id\` pour stabilité.

### 11.3 Détail MTF

Paramètres : \`symbol_limit\`, \`symbol_offset\`.

La sérialisation existante de \`MtfRunReadController\` doit être extraite dans un service de lecture partagé. La route historique conserve sa forme actuelle. La façade MCP ajoute la pagination et des métadonnées de complétude.

### 11.4 Investigation

Paramètres : \`symbol\`, \`occurred_at\`, \`run_id\`, \`decision_key\`, \`limit_per_section\`.

Évolutions de \`InvestigationQuery\` :

- paramètre de limite contrôlé ;
- défaut 80 conservé pour l’UI ;
- maximum 80 dans le read-model interne ;
- façade MCP clampée à 50 ;
- timestamp invalide rejeté avant appel ;
- aucune section \`exports\` dans la réponse MCP ;
- maintien du comportement des pages Front Ops existantes.

### 11.5 Compatibilité

Aucune route existante n’est supprimée ou renommée. Aucun schéma DB ni migration Doctrine n’est requis. Les tests actuels de Front Ops et MTF doivent continuer à passer.

## 12. Découpage Python

Structure cible :

\`\`\`text
python-orchestrator/app/mcp/
├── __init__.py
├── schemas.py
├── redaction.py
├── symfony_read_client.py
├── read_service.py
└── server.py
\`\`\`

### 12.1 \`schemas.py\`

Contient :

- \`RunKind\` ;
- \`LineageIdentifierKind\` ;
- modèles d’enveloppe ;
- modèles de pagination ;
- modèle d’erreur ;
- contraintes de taille, limites et descriptions de champs.

### 12.2 \`redaction.py\`

Fonctions pures :

- redaction récursive ;
- mesure JSON UTF-8 ;
- troncature par collection ;
- génération des warnings ;
- tests sur objets imbriqués, listes et variantes de clés.

### 12.3 \`symfony_read_client.py\`

Responsabilités :

- construire uniquement des URLs depuis une table interne ;
- utiliser \`httpx.AsyncClient\` ;
- méthode GET exclusivement ;
- timeout global configurable ;
- header Ops optionnel ;
- parsing JSON ;
- mapping HTTP vers erreurs métier ;
- validation minimale de forme avant retour.

Aucun appel ne doit être ajouté au client mutatif existant sans nécessité ; ce client de lecture reste focalisé et auditable.

### 12.4 \`read_service.py\`

Responsabilités :

- router \`run_kind\` ;
- appeler les repositories orchestration ;
- appeler le client Symfony ;
- normaliser les enveloppes ;
- appliquer redaction et limites ;
- journaliser statut/durée ;
- distinguer empty/unavailable.

### 12.5 \`server.py\`

Responsabilités :

- instancier \`MCPServer("TradingV3")\` ;
- enregistrer exactement sept outils ;
- descriptions précises ;
- annotations communes ;
- déléguer toute logique au read service ;
- exposer l’application Streamable HTTP avec chemin racine pour montage sous \`/mcp\`.

### 12.6 Montage ASGI et lifespan

Le sous-app MCP est monté sous \`/mcp\`, avec son chemin interne réglé sur \`/\` afin d’éviter \`/mcp/mcp\`.

Le lifespan FastAPI parent doit démarrer explicitement le gestionnaire du serveur MCP. Un sous-app monté ne peut pas compter sur son propre lifespan. Les tests doivent prouver qu’un premier appel MCP ne produit pas l’erreur « task group is not initialized ».

Les routes FastAPI existantes sont enregistrées avant le mount global afin qu’aucune route ne soit masquée.

## 13. Configuration

Ajouter à \`Settings\` :

| Variable | Défaut | Validation |
| --- | --- | --- |
| \`MCP_SYMFONY_TIMEOUT_SECONDS\` | \`10\` | entier 1..60 |
| \`MCP_MAX_RESPONSE_BYTES\` | \`262144\` | entier 16384..1048576 |
| \`MCP_ALLOWED_HOSTS\` | \`localhost,127.0.0.1\` | CSV non vide |
| \`MCP_ALLOWED_ORIGINS\` | vide | CSV exact, pas de wildcard par défaut |
| \`SYMFONY_OPS_TOKEN\` | vide | chaîne, jamais loguée |

La configuration Docker transmet ces valeurs explicitement lorsque nécessaire. Le port reste :

\`\`\`yaml
ports:
  - "127.0.0.1:8099:8099"
\`\`\`

Le profil \`orchestrator\` reste opt-in. Aucun port supplémentaire n’est créé.

## 14. Observabilité

Chaque appel produit un événement structuré contenant uniquement :

- \`event=mcp_tool_call\` ;
- nom de l’outil ;
- source \`symfony|orchestration|mixed\` ;
- statut \`success|empty|error|truncated\` ;
- code d’erreur éventuel ;
- durée en millisecondes ;
- taille de réponse ;
- compteur d’items.

Les arguments, symboles, identifiants, payloads et résultats ne sont pas journalisés.

Les métriques peuvent réutiliser le registre in-process avec cardinalité bornée au nom d’outil et au statut. Aucun label par symbole, run ou decision key.

\`GET /healthcheck\` doit rester compatible. La disponibilité MCP est vérifiée par un test \`tools/list\`, pas par un appel métier.

## 15. Tests

### 15.1 Tests Symfony

Ajouter ou étendre les tests pour :

- liste MTF triée et paginée ;
- limite 0, négative ou >50 rejetée ;
- offset négatif rejeté ;
- UUID MTF invalide ;
- run inconnu ;
- pagination des symboles ;
- investigation par symbole seul ;
- investigation par run seul ;
- investigation par decision key seule ;
- critères combinés ;
- timestamp RFC 3339 valide ;
- timestamp invalide rejeté ;
- fenêtre UTC ±1 h ;
- limite par section ;
- table optionnelle absente ;
- absence de la section exports ;
- protection Ops ;
- non-régression des contrôleurs historiques.

### 15.2 Tests Python unitaires

Couvrir :

- parsing de chaque variable de settings ;
- sept outils exactement ;
- noms et annotations ;
- contraintes Pydantic ;
- mapping de toutes les erreurs ;
- redaction récursive ;
- taille UTF-8 ;
- troncature par section ;
- empty versus unavailable ;
- timeout ;
- réponse non JSON ;
- forme inattendue ;
- 401/403 ;
- 404 ;
- 503 ;
- repositories SQLite en mémoire ;
- absence de retry ;
- absence de POST/PUT/PATCH/DELETE sortant.

### 15.3 Tests MCP

Utiliser le client officiel en mémoire :

\`\`\`python
async with Client(mcp) as client:
    tools = await client.list_tools()
    result = await client.call_tool(
        "tradingv3_list_recent_runs",
        {"run_kind": "orchestration", "limit": 20, "offset": 0},
    )
\`\`\`

Vérifier :

- découverte exacte ;
- schémas structurés ;
- erreurs d’entrée avant appel de source ;
- contenu structuré ;
- annotations ;
- absence de capacité non prévue.

### 15.4 Tests ASGI

- démarrage/arrêt du lifespan ;
- endpoint exact \`/mcp\` ;
- Host localhost accepté ;
- Host arbitraire refusé ;
- requête sans Origin acceptée pour client natif ;
- Origin non allowlisté refusé ;
- Origin explicitement allowlisté accepté ;
- routes FastAPI existantes toujours accessibles ;
- CORS cockpit non régressé.

### 15.5 Intégration Symfony simulée

Étendre le \`httpx.MockTransport\` existant avec les routes read-only MCP. Le faux backend échoue sur toute méthode non GET et tout chemin non allowlisté.

Scénario nominal complet :

1. lister les runs ;
2. lire un run MTF ;
3. investiguer un symbole bloqué ;
4. suivre sa decision key ;
5. lire le risk snapshot ;
6. lire la configuration effective ;
7. constater qu’aucun appel exchange n’a été capturé.

## 16. Évaluations agentiques

Créer dix questions indépendantes, stables et read-only sur fixtures. Exemples de capacités à mesurer :

1. identifier le timeframe ayant bloqué un symbole dans un run ;
2. retrouver la raison principale d’absence d’ordre ;
3. relier une decision key à son order intent ;
4. déterminer si une position persistée possède un SL détecté ;
5. distinguer run MTF et run d’orchestration ;
6. retrouver les sets échoués d’un run ;
7. identifier un conflit de lineage ;
8. expliquer une source indisponible sans conclure « zéro trade » ;
9. retrouver la configuration effective d’un profil ;
10. diagnostiquer une file Messenger avec backlog.

Chaque question possède une réponse vérifiée par comparaison stable. Les évaluations ne dépendent pas de l’heure courante, d’un exchange ou d’un secret.

## 17. Déploiement local

### 17.1 Préparation

- ajouter la dépendance MCP épinglée ;
- reconstruire \`python-orchestrator\` ;
- conserver le profil opt-in ;
- configurer le token Ops seulement si Symfony le requiert ;
- ne pas activer d’Origin navigateur par défaut.

### 17.2 Lancement

\`\`\`bash
docker compose --profile orchestrator up -d --build python-orchestrator
\`\`\`

### 17.3 Vérification

- \`GET http://127.0.0.1:8099/healthcheck\` ;
- connexion MCP à \`http://127.0.0.1:8099/mcp\` ;
- \`tools/list\` retourne sept outils ;
- appel d’un outil orchestration ;
- appel d’un outil Symfony ;
- inspection des logs pour confirmer l’absence d’arguments ;
- vérification réseau : aucun bind \`0.0.0.0:8099\`.

### 17.4 Rollback

Le rollback consiste à :

- retirer le mount MCP et la dépendance ;
- retirer les façades Symfony MCP inutilisées ;
- reconstruire uniquement \`python-orchestrator\` et Symfony ;
- aucune migration à inverser ;
- aucune donnée utilisateur à supprimer.

Une panne du MCP ne doit pas empêcher les routes FastAPI historiques de fonctionner. Si le SDK MCP échoue à s’initialiser, le choix MVP est fail-fast au démarrage du conteneur afin de ne pas annoncer un service partiellement initialisé.

## 18. Phasage d’implémentation

### Lot 1 — Contrats Symfony read-only

- tests de façades ;
- service de lecture MTF partagé ;
- pagination repositories ;
- investigation bornée ;
- protection Ops ;
- non-régression Front Ops.

### Lot 2 — Infrastructure MCP Python

- dépendance et settings ;
- schémas ;
- redaction ;
- client Symfony GET ;
- service d’agrégation ;
- tests unitaires.

### Lot 3 — Catalogue et transport

- sept outils ;
- annotations ;
- montage ASGI/lifespan ;
- transport security ;
- tests client MCP et ASGI.

### Lot 4 — Intégration et qualité

- MockTransport bout en bout ;
- métriques/logging ;
- dix évaluations ;
- documentation/runbook ;
- Docker smoke ;
- couverture et analyses statiques.

Chaque lot doit être testable et révisable indépendamment. Aucun lot n’introduit de capacité de trading.

## 19. Gates de validation

Commandes minimales :

\`\`\`bash
cd python-orchestrator
python -m pytest --cov=app --cov-report=term-missing --cov-fail-under=95
\`\`\`

\`\`\`bash
cd trading-app
php bin/phpunit tests/Front tests/MtfValidator tests/Trading
vendor/bin/phpstan analyse --no-progress
\`\`\`

Puis :

\`\`\`bash
docker compose --profile orchestrator up -d --build python-orchestrator
\`\`\`

Gates fonctionnelles :

- [ ] exactement sept outils ;
- [ ] aucun outil mutatif ;
- [ ] aucune méthode HTTP sortante autre que GET ;
- [ ] aucun endpoint exchange appelé ;
- [ ] aucun secret dans les fixtures ou réponses ;
- [ ] pagination et troncature visibles ;
- [ ] empty et unavailable distincts ;
- [ ] Host/Origin tests verts ;
- [ ] lifespan MCP testé ;
- [ ] routes historiques non régressées ;
- [ ] dix évaluations stables vertes ;
- [ ] port loopback confirmé.

## 20. Risques et mitigations

| Risque | Impact | Mitigation |
| --- | --- | --- |
| réponses trop volumineuses | contexte gaspillé ou timeout | pages max 50, plafond 256 Kio, warnings |
| données persistées sensibles | fuite locale | redaction récursive avant sérialisation |
| confusion persisted/live | mauvaise conclusion | source et fraîcheur explicites |
| source Symfony indisponible | faux diagnostic vide | erreur \`source_unavailable\` |
| lifecycle ASGI incorrect | premier appel MCP en échec | lifespan parent testé |
| extension future mutative | élargissement silencieux du risque | nouveau design obligatoire |
| exposition réseau accidentelle | fuite de données trading | bind loopback + Host/Origin allowlists |
| duplication métier Python | divergence | façades/read-models Symfony canoniques |
| contrat HTTP changeant | outils cassés | validation de forme + tests MockTransport |
| cardinalité métrique | mémoire/observabilité dégradée | labels limités outil/statut |

## 21. Hypothèses verrouillées

- Les premiers consommateurs sont des assistants locaux.
- Les données persistées suffisent au MVP.
- La fraîcheur live n’est pas recherchée.
- Le Python Orchestrator reste la frontière MCP.
- Le transport est Streamable HTTP, pas stdio.
- Le serveur n’utilise pas OAuth tant qu’il reste loopback.
- Le catalogue v1 contient sept outils, sans resource ni prompt.
- Les endpoints Symfony existants restent compatibles.
- Aucune migration DB n’est nécessaire.
- L’implémentation doit cibler le \`main\` courant au moment du développement, pas un checkout historique.

## 22. Références

### MCP

- Spécification MCP 2026-07-28 : https://modelcontextprotocol.io/specification/2026-07-28
- SDK Python officiel : https://github.com/modelcontextprotocol/python-sdk
- Documentation SDK Python : https://py.sdk.modelcontextprotocol.io/
- Montage ASGI : https://github.com/modelcontextprotocol/python-sdk/blob/main/docs/run/asgi.md
- Autorisation MCP : https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization

### TradingV3

- \`docs/handbook/architecture.md\`
- \`docs/handbook/technical/python-orchestrator.md\`
- \`python-orchestrator/app/main.py\`
- \`python-orchestrator/app/settings.py\`
- \`python-orchestrator/app/routers/runs.py\`
- \`python-orchestrator/app/services/symfony_client.py\`
- \`trading-app/src/Front/Query/InvestigationQuery.php\`
- \`trading-app/src/Front/Query/RiskSummaryQuery.php\`
- \`trading-app/src/Front/Query/SystemHealthQuery.php\`
- \`trading-app/src/Front/Security/OpsFrontAccessSubscriber.php\`
- \`trading-app/src/Trading/Controller/Api/LineageReadApiController.php\`
- \`trading-app/src/Trading/Controller/Api/EffectiveTradingConfigApiController.php\`

---

Cette spécification est la source de vérité fonctionnelle et technique de TV3-MCP-001. Toute divergence d’implémentation concernant le périmètre read-only, les sources persistées, les sept outils ou l’isolation loopback doit être soumise à une nouvelle décision explicite.
