# TradingV3 — feuille de route et plan d'exécution par agents

> **For agentic workers:** REQUIRED SUB-SKILL: Use `subagent-driven-development` or `executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. L'utilisateur a choisi la délégation par agents ; utiliser des contextes ciblés et les modèles/efforts ci-dessous.

**Goal:** Expliquer les zéro-trades Paper, obtenir des profils versionnés évaluables, puis préparer leur validation OKX démo / Hyperliquid testnet avec un coût d'agent maîtrisé.

**Architecture:** Conserver le chemin canonique capture → replay Paper/Fake → journal → lifecycle/coûts → `position_trade_analysis_v2` → export. Commencer sur une seule cellule et réutiliser les preuves existantes. Les modifications de stratégie et les corrections du moteur suivent des lots distincts, déterminés par le diagnostic.

**Tech Stack:** Symfony/PHP, PostgreSQL, configurations YAML versionnées, Python/FastAPI, Temporal, Symfony/Twig, Docker pour les bases jetables.

**Statut :** plan préparé le 8 octobre 2026 ; « go » utilisateur reçu, lots 0 et 1 exécutés, revue consolidée terminée sans blocage. Deux agents de lecture ont été utilisés pendant la préparation : `gpt-6-sol / medium` et `gpt-6-luna / medium`. Instruction utilisateur complémentaire : un seul cycle de revue par PR.

## 1. Référence et faits vérifiés

- Référence étudiée : `origin/main` = `88c500a02f7d86ce6dd7d11e7343048ac4554ae2`, également vérifiée sur GitHub.
- Le checkout local est à `5fb306bd`, en retard de 57 commits. Il contient une suppression utilisateur et plusieurs fichiers non suivis : les préserver intégralement.
- Les PR #440–444 ont livré le retrait de Bitmart, le backtest par cellule, l'exécution OKX démo et le premier lot de readiness Hyperliquid.
- Le récap rapporte zéro trade dans les 12 cellules des captures 24 h avec les contrats `1.1.0`. Ce résultat accepté ne démontre aucune performance de stratégie et ne satisfait pas le seuil de 50 trades certifiés par cellule.
- Le replay moderne lit les contrats sous `trading-app/config/trading/`. Les profils legacy sont `reference_only` pour ces cellules.
- Les observations Paper existent sous deux formes : `strategy_observed` et triplets `[status, reason_code, count]` compressés dans `replay_batch`. Un nombre de lignes SQL n'est pas un nombre d'observations.
- Le flux MTF Hyperliquid ne possède pas de preuve d'autorisation d'écriture ; le smoke utilise encore la capacité `Paper`.
- Une CI verte ne couvre pas toutes les suites Exchange, TradeEntry et Provider.

Sources prioritaires :

- `docs/handbook/recap-2026-10-08-okx-hyperliquid.md`
- `docs/handbook/backtesting-okx-hl.md`
- `CLAUDE.md` sur la référence actuelle
- [#439 — optimisation des configurations](https://github.com/pivox/tradingV3/issues/439)
- [#190 — recette finale du PnL](https://github.com/pivox/tradingV3/issues/190)

Les anciennes roadmaps et les statuts d'issues peuvent être en retard : #305 reste ouverte malgré le retrait de Bitmart ; la mention d'une PR #438 encore ouverte dans le récap est également périmée d'après la liste GitHub vérifiée. Ne pas utiliser ces statuts seuls pour déduire du code manquant.

## 2. Répartition des agents et maîtrise du coût

| Travail | Modèle | Effort | Résultat demandé |
|---|---|---|---|
| Inventaire de fichiers, artefacts et statuts ; documentation ciblée | `gpt-6-luna` | `low` | Faits, chemins, preuves et écarts |
| Lecture d'artefacts et vérification d'un périmètre borné | `gpt-6-luna` | `medium` | Synthèse courte et exploitable |
| Diagnostic Paper, SQL, correctif backend ou configuration | `gpt-6-sol` | `medium` | Un lot cohérent avec vérification ciblée |
| Relecture indépendante du diff et des preuves | `gpt-6-sol` | `medium` | Défauts concrets, risques résiduels, verdict |
| Contradiction de traces, invariant d'exécution ou causalité complexe | `gpt-6-sol` | `high` | Résolution de la seule difficulté identifiée |

Les choix précis sont une politique de travail, pas une estimation tarifaire. La [documentation OpenAI sur le choix des modèles](https://developers.openai.com/api/docs/guides/model-selection) recommande Luna pour les tâches bornées et un modèle Sol pour les travaux techniques complexes ; un [effort inférieur réduit généralement l'usage de tokens](https://developers.openai.com/api/docs/guides/reasoning).

Règles pour le coordinateur après le « go » :

1. Confier les inspections et implémentations aux agents ; conserver la coordination, les arbitrages et la revue des livrables dans le fil principal.
2. Utiliser `fork_turns="none"`, un prompt autonome, les chemins utiles et les preuves acquises. Ne pas recopier tout l'historique.
3. Deux agents actifs au maximum par défaut, sur des fichiers ou tâches indépendants. Aucun sous-agent supplémentaire lancé par un agent.
4. Un seul agent exécute un replay lourd. Commencer par une cellule ; étendre uniquement si les résultats le justifient.
5. Demander des retours courts : fichiers, cause démontrée, commandes/résultats, limites. Ne pas refaire l'audit général du dépôt.
6. Escalader vers `high` seulement après avoir documenté la difficulté précise ; ne pas activer automatiquement Astra, `xhigh`, `max` ou `ultra`.
7. Exécuter les tests pertinents une fois, puis les répéter seulement après modification ou échec. Une correction de documentation ne déclenche pas de campagne Paper.
8. Pendant un replay, utiliser l'attente du processus et communiquer son état ; éviter les boucles d'analyse ou les agents qui surveillent tous le même processus.
9. Un seul cycle de revue par PR : revue consolidée → correction des retours → vérifications ciblées. Ne pas multiplier les reviewers ni demander une seconde revue systématique. Cette instruction utilisateur prévaut sur les cycles multiples proposés par les skills. Les tests pertinents et les contrôles requis restent applicables ; une difficulté persistante est traitée ou signalée sans relancer une boucle de revues.
10. Instruction utilisateur du 8 octobre : après validation de l'agent ou correction de la revue unique, fusionner puis poursuivre le lot logique suivant sans redemander un « go ». Attendre les contrôles requis et ne pas contourner les protections ou laisser de défaut bloquant connu. Ne pas demander une revue GitHub supplémentaire si la revue déléguée a déjà rempli ce cycle. Les activations d'écriture et décisions de risque restent hors de cette autorisation.

## 3. Lot 0 — préparer un espace de travail à jour

**Agent :** Luna / low pour l'inventaire ; coordinateur pour l'isolation.

- [x] Relire l'état sans toucher aux modifications utilisateur :

```bash
git status --short --branch
git worktree list --porcelain
git rev-parse HEAD origin/main
git log -5 --oneline origin/main
```

- [x] Vérifier la référence GitHub au démarrage et récupérer les nouveaux commits si nécessaire. Si elle a évolué, examiner le diff pertinent avant de reprendre les conclusions du présent plan.
- [x] Utiliser le skill `using-git-worktrees` à l'exécution pour réutiliser ou créer un checkout isolé depuis la référence actuelle. Ne pas stasher, réinitialiser ni mettre à jour de force le checkout utilisateur.
- [x] Rendre ce plan accessible depuis le checkout d'exécution et y vérifier les dépendances PHP/Docker, les captures et les receipts. Ne pas afficher les secrets.

**Sortie :** SHA de travail, emplacement isolé et dépendances identifiés ; modifications utilisateur préservées.

## 4. Lot 1 — expliquer les zéro-trades d'une cellule

**Agents :** Luna / medium pour les artefacts ; Sol / medium pour l'interprétation. Aucune modification de stratégie dans ce lot.

### Fichiers de référence

| Chemin | Rôle |
|---|---|
| `scripts/backtest-profile.sh` | Replay par cellule, base jetable, export, `--keep-db` |
| `trading-app/config/trading/paper_certification/first-baseline-v1.json` | Identités et seuil de la campagne de référence |
| `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparation.php` | Statuts `planned`, `no_trade`, `missing_evidence` |
| `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyEvidenceProvider.php` | Identité, configuration et sélection des événements déclencheurs |
| `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyObservation.php` | Contrat des observations persistées |
| `trading-app/src/Trading/Paper/Execution/Persistence/PaperReplayBatchJournal.php` | Compression des observations et journal des effets |
| `trading-app/migrations/Version20260801120000.php` | Schéma cell/event/checkpoint |
| `docs/handbook/reports/queries/bad-trades-baseline-v2.sql` | Autorité de l'export de trades |

### 1A. Réutiliser les preuves disponibles

- [x] Inspecter le run existant `/home/haythem/workspace/perso/tradingV3-private/backtests/smoke-20261008T112439Z-hl/` : `summary.txt`, `runtime-check.out`, `replay.out`, `export.csv`, `table-rows.txt`. Vérifier sa disponibilité à nouveau ; conserver les données privées hors Git.
- [x] Relever le `run_id`, l'identité complète, les hashes, l'état du replay et le nombre de lignes exportées. Ce run est un candidat de départ HL `day_trading.trend_continuation.long@1.1.0`.
- [x] Vérifier si la base jetable correspondante existe encore, en consultant uniquement les noms et états des conteneurs. Ne pas inspecter leurs variables d'environnement dans les sorties de chat.
- [x] Déterminer si les preuves suffisent à localiser le premier point de rupture. Un export vide prouve seulement l'absence de trade exporté selon ses filtres.

### 1B. Rejouer une cellule seulement si nécessaire

Depuis le checkout isolé, une fois les dépendances et les chemins validés :

```bash
scripts/backtest-profile.sh \
  --venue=hyperliquid \
  --mode-id=day_trading --mode-version=1.1.0 \
  --setup-id=day_trading.trend_continuation.long --setup-version=1.1.0 \
  --side=long --keep-db
```

- [x] Utiliser les captures figées en lecture seule et le receipt existant si accepté. Le script crée sa propre base ; ne jamais utiliser la base partagée `cc_postgres` / `trading_paper`.
- [x] Conserver la base avec `--keep-db` jusqu'à la fin du diagnostic. Relever son nom exact dans la sortie du script.
- [x] Vérifier `ready=true`, la complétude du replay et les hashes. Le coût documentaire mesuré est d'environ 46 min pour HL, 103 min pour OKX, hors vérification initiale des données ; ce n'est pas une garantie de durée.
- [x] Ne pas lancer les 12 cellules, une nouvelle capture ou une modification des seuils pour obtenir artificiellement un résultat.

### 1C. Lire correctement le journal

Exécuter les requêtes dans `psql` connecté à la base jetable identifiée, avec une variable `run_id` égale à celle de `summary.txt`. Première requête :

```sql
BEGIN READ ONLY;
SELECT e.event_type, count(*) AS journal_rows
FROM paper_execution_event e
JOIN paper_execution_cell c ON c.id = e.cell_id
WHERE c.run_id = :'run_id'
GROUP BY e.event_type
ORDER BY e.event_type;
COMMIT;
```

Agrégation pondérée des deux formats, en laissant visibles les sources regroupées sans observation :

```sql
BEGIN READ ONLY;
WITH scoped AS (
    SELECT e.event_type, e.payload
    FROM paper_execution_event e
    JOIN paper_execution_cell c ON c.id = e.cell_id
    WHERE c.run_id = :'run_id'
), observations AS (
    SELECT payload->>'status' AS status,
           payload->>'reason_code' AS reason_code,
           1::bigint AS n
    FROM scoped
    WHERE event_type = 'strategy_observed'
    UNION ALL
    SELECT item.value->>0 AS status,
           item.value->>1 AS reason_code,
           (item.value->>2)::bigint AS n
    FROM scoped
    CROSS JOIN LATERAL jsonb_array_elements(
        CASE WHEN event_type = 'replay_batch'
             THEN payload->'observations' ELSE '[]'::jsonb END
    ) AS item(value)
    WHERE event_type = 'replay_batch'
)
SELECT CASE WHEN status IS NULL THEN 'source_without_observation'
            ELSE 'strategy_observation' END AS kind,
       status, reason_code, sum(n) AS count
FROM observations
GROUP BY status, reason_code
ORDER BY count DESC, status, reason_code;
COMMIT;
```

- [x] Valider la forme des payloads avant l'agrégation. Une donnée absente ou incompatible doit apparaître comme une anomalie, pas être silencieusement assimilée à zéro.
- [x] Séparer les événements de marché des véritables déclencheurs du setup : une bougie confirmée sur son timeframe d'exécution. Dans le code actuel, `evidenceFor()` peut renvoyer `paper_indicator_projection_unavailable` lorsque l'événement n'est pas un déclencheur. Un motif fréquent sur tout le flux ne démontre donc pas, à lui seul, un manque de données sur les opportunités de trading.
- [x] Rapprocher les positions sources des batches, les observations et le checkpoint. Ne pas exiger que tous les événements du carnet soient des opportunités de trade.
- [x] Suivre le premier étage qui n'aboutit pas : déclencheur → données/indicateurs → règle → plan → intention → ordre → fill → clôture → coûts/lineage → export.
- [x] Utiliser les identifiants exacts du run/cell/trade. Inspecter les filtres de `bad-trades-baseline-v2.sql` avant de conclure à une absence d'exécution.

### Livrable et sortie du lot

- [x] Créer `docs/handbook/reports/paper-zero-trade-diagnostic-2026-10-08.md` avec la méthode, les comptes agrégés non sensibles, le premier blocage démontré, les limites et la prochaine hypothèse vérifiable. Si l'exécution a lieu plus tard, utiliser sa date réelle dans le nom du rapport.
- [x] Conserver les traces brutes, exports, paramètres de connexion et artefacts privés dans `tradingV3-private`.
- [x] Faire relire le rapport et les requêtes par Sol / medium. Aucun test d'application ni modification de moteur n'est nécessaire si les traces suffisent.

**Acceptation :** expliquer le zéro exporté avec des preuves et un dénominateur correct ; distinguer absence de signal, données manquantes, blocage d'exécution et exclusion de l'export. La cause reste inconnue tant que ce lot n'est pas exécuté.

## 5. Suite ordonnée, conditionnée aux résultats

Les lots suivants sont des jalons avec critères de sortie. Leur diff exact sera défini à partir des preuves du lot 1 ; ce plan ne présuppose pas une cause ni des valeurs de tuning.

| Lot | Travail et dépendance | Agent | Critère de sortie |
|---|---|---|---|
| 2 — corriger ou instrumenter la cause démontrée | Si un défaut du moteur/export est établi : correctif minimal. Si les traces sont insuffisantes : diagnostic ciblé. Si tout fonctionne : passer à l'hypothèse de profil. | Sol / medium ; high uniquement si causalité/invariant complexe | Reproduction, vérification ciblée et cause traitée sans modification cachée de la stratégie |
| 3 — première configuration candidate | Une hypothèse par changement ; nouveaux contrats de mode/setup compatibles et hashés sous `config/trading/`, puis comparaison avec `1.1.0` sur la même capture | Sol / medium ; Luna / low pour le tableau comparatif | Version candidate reproductible, différences expliquées ; un trade complet observable si le marché et le setup le permettent, sinon verdict explicite |
| 4 — données et statistiques | Finaliser la preuve #190 ; démarrer #439 lorsque des trades complets et des jeux de données indépendants sont disponibles | Sol / medium ; Luna / medium pour rapports | Coûts/lineage fiables, séparation apprentissage/validation/test, seuil par cellule respecté, verdict net documenté |
| 5 — préparation et recette démo/testnet | HL lot 2, recette orchestrateur #188, readiness #197/#198 puis #220 ; dépend des profils candidats et des choix opérateur | Sol / medium ; revue Sol / high ciblée sur les invariants d'écriture | Tests du port, refus hors enveloppe et preuves de réconciliation ; activation opérateur distincte |
| 6 — cockpit ciblé | Socle Twig #312/#313 puis vues nécessaires : décisions #319, backtests #323, readiness #317, configuration #318 | Sol / medium ; Luna / low pour documentation | Consulter les résultats et raisons sans fouiller les logs ; respecter le socle existant et conserver React jusqu'à preuve de parité |

### Vérifications ciblées après une modification de code

Selon les fichiers réellement modifiés, réutiliser notamment :

- `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparationTest.php`
- `trading-app/tests/Trading/Paper/Execution/Persistence/DoctrinePaperExecutionStoreBatchTest.php`
- `trading-app/tests/Trading/Paper/Execution/PaperExecutionReplayEqualityTest.php`
- `trading-app/tests/TradingCore/Setup/SetupContractLoaderTest.php`
- `trading-app/tests/TradingCore/Config/CanonicalEffectiveTradingConfigTest.php`
- `trading-app/tests/TradingCore/Execution/HyperliquidMutationReadinessGateTest.php`
- `trading-app/tests/TradingCore/Execution/HyperliquidTestnetExecutionPortTest.php`

Pour un changement Paper, utiliser les processus et bases isolés décrits dans `.github/workflows/obs003-trading.yml`. Pour un adapter ou TradeEntry, ajouter les suites concernées explicitement : le workflow ne les couvre pas toutes. Ajouter un test de régression portant sur le défaut reproduit, pas un test qui recopie l'implémentation. Les tests d'intégration doivent viser uniquement une base de test identifiée.

### Points techniques déjà identifiés pour les lots suivants

- Profils : `trading-app/config/trading/mode_contract/`, `setup_contract/`, `runtime/mode_exchange/`. Préserver les contrats publiés `1.1.0`, leurs hashes et les captures de référence.
- HL : `trading-app/src/TradingCore/Execution/Enum/ShadowExecutionCapability.php`, `HyperliquidTestnetExecutionPort.php` sous `trading-app/src/TradingCore/Execution/Hyperliquid/`, `trading-app/config/services.yaml` et `trading-app/config/trading/env/testnet.yaml`. Le brouillon `docs/handbook/decision-hl-302-testnet-readiness.md` comporte encore des choix opérateur.
- Déploiement : contrôler l'état de `Version20261008120000` et, pour HL, `Version20260712120000` / `Version20260712230000`. Les bases réelles ne sont pas migrées par le premier lot diagnostic.
- Temps de recherche : envisager un replay borné seulement si les mesures montrent que la durée empêche l'itération. Ne pas construire un optimiseur ou refondre le moteur avant d'avoir exploité le script existant.
- Documentation/backlog : relever les incohérences comme #305 dans le rapport. Ne pas fermer des issues ou publier des commentaires au titre de cette simple préparation.

## 6. Périmètre du « go » reçu et reprise

Le « go » reçu a lancé les lots 0 et 1, désormais exécutés avec agents délégués et la politique de coût ci-dessus. Le coordinateur présente les preuves avant d'entreprendre un changement de stratégie dont le sens n'est pas encore décidé. Le prochain lot retenu est l'instrumentation ciblée des rejets de plan ; les choix de risque ou de profil restent explicites.

Ne pas modifier les flags d'écriture, les plafonds opérateur ou les secrets. Aucune connexion mutative aux exchanges ne fait partie du diagnostic. L'activation démo/testnet et le programme mainnet #394–397 nécessitent leur décision dédiée. Les résultats de certification de référence, ses seuils et les captures ne sont pas retouchés.

À chaque fin de lot, enregistrer dans ce plan : SHA, chemins des preuves, modifications, résultat des vérifications et prochaine action. Après compaction ou changement de modèle, reprendre à partir de cette checklist plutôt que refaire l'analyse du dépôt.

### État d'avancement

- [x] Lecture du code actuel, de la documentation et des issues.
- [x] Deux vérifications déléguées sur des modèles/efforts ciblés.
- [x] Feuille de route et diagnostic initial détaillés.
- [x] « Go » utilisateur reçu après changement d'effort.
- [x] Lot 0 terminé : worktree `.worktrees/paper-zero-trade-diagnostic`, branche `codex/paper-zero-trade-diagnostic`, SHA `88c500a02f7d86ce6dd7d11e7343048ac4554ae2` ; autoload disponible et syntaxe du wrapper vérifiée.
- [x] Lot 1 exécuté : frontière de rejet établie pour la cellule ; sous-motif exact démontré sur un événement réel. Revue consolidée terminée sans blocage ; transaction SQL en lecture seule et statut du plan clarifiés après revue.
- [x] Lot suivant sélectionné : diagnostic ciblé des sous-motifs de construction du plan, avant toute calibration de profil. Collecte terminée : les 191 rejets de plan de cette cellule portent le même sous-motif ; portée limitée à un dataset et une cellule.

### Checkpoint d'exécution

- Un premier essai s'est arrêté avant migrations faute de `DEFAULT_URI`, sans événement consommé. Son conteneur et son volume jetables ont été retirés ; aucun conteneur utilisateur n'a été démarré.
- Après préparation de la configuration locale ignorée, lancement de la même cellule : `bt-20261008T203601Z-hyperliquid-b68767`.
- Artefacts privés : `/home/haythem/workspace/perso/tradingV3-private/backtests/20261008T203601Z-hyperliquid-day_trading.trend_continuation.long-long`.
- Base jetable conservée pour le diagnostic : `backtest-pg-b68767`. Ne pas confondre avec une base partagée.
- Agent exécutant : `paper_zero_trade_diagnostic`, modèle `gpt-6-sol`, effort `medium`. Replay terminé ; ne pas lancer une seconde cellule pour reprendre ce diagnostic.

### Résultats du lot 1

- Replay terminé avec code 0 le 8 octobre à 21:11:31 UTC : 1 488 002 événements consommés, durée totale 35 min 30 ; checkpoint final vérifié indépendamment.
- Journal : 298 batches et 298 snapshots. Comptes pondérés : 1 487 810 `paper_indicator_projection_unavailable`, 191 `paper_order_plan_unavailable`, un `paper_canonical_strategy_setup_section_failed` ; zéro intention et zéro trade exporté.
- Escalade limitée à un agent `gpt-6-sol / high` pour une reconstruction ciblée privée : au déclencheur trié 8180, `canonical_entry_zone_candidate_outside`, bid 86 294 contre zone calculée [84 918, 85 004]. Aucune généralisation de ce sous-motif aux 190 autres rejets.
- Le probe privé a été corrigé après sa première exécution pour les échantillons suivants ; cette version corrigée n'a pas été réexécutée. Seul le premier échantillon fonde la preuve précise.
- Livrables : `docs/handbook/reports/paper-zero-trade-diagnostic-2026-10-08.md` et `docs/handbook/reports/queries/paper-zero-trade-diagnostic.sql`.
- Aucun moteur, contrat de stratégie ou flag d'écriture modifié. La base jetable reste conservée pour vérification.
- Lot 2 : instrumentation NDJSON privée opt-in implémentée sur `codex/paper-plan-rejection-diagnostics`, revue consolidée sans blocage et clarifications appliquées. Vérifications : 23 tests ciblés / 173 assertions ; suite Paper Execution complète 303 tests / 5 557 assertions, sans skip sur base jetable dédiée ; MkDocs strict vert. PR #446 fusionnée après CI, puis collecte des refus sur une nouvelle exécution de la même cellule, sans tuning.
- PR #446 fusionnée après trois jobs CI verts, sans seconde revue : `a94bbd0499cab004ed1483bf5c451bf5b5263640`. Suite en cours sur `codex/paper-plan-rejection-evidence` depuis ce main ; même worktree isolé.
- Collecte terminée une seule fois : `bt-20261008T215036Z-hyperliquid-79fcfc`, artefacts privés `/home/haythem/workspace/perso/tradingV3-private/backtests/20261008T215036Z-hyperliquid-day_trading.trend_continuation.long-long`, base jetable `backtest-pg-79fcfc`. Replay exit 0, 1 488 002/1 488 002 événements, checkpoint 596, `killed=false`; wrapper 34m34s et replay 34m24s. Base antérieure `backtest-pg-b68767` préservée. Ne pas relancer.
- PR #445 fusionnée après CI verte et revue unique : `c43495c235c14fc6694bf2f58b720c74b69dcb76`. Lot suivant démarré depuis ce main dans le même worktree isolé, branche `codex/paper-plan-rejection-diagnostics`.
- Vérifications finales : SQL exact-run en transaction `REPEATABLE READ READ ONLY`, refus d'un run absent, `mkdocs build --strict` et contrôles d'espaces. Les liens du rapport pointent vers le commit étudié et le rapport figure dans la navigation du handbook. Une seule revue indépendante (`gpt-6-sol / medium`), sans blocage ; ses deux clarifications ont été appliquées, sans nouvelle demande de revue.
- Résultat de la collecte : NDJSON privé de 191 lignes/191 event IDs uniques, mode `0600`, SHA-256 `909d3951f5b85157fea840e76fbdba8423e04ef70eca2d77136a473ad859f9fb` ; 95 BTCUSDT et 96 ETHUSDT, tous `canonical_entry_zone_candidate_outside`. Aucun artefact brut privé ajouté au dépôt. Le rang trié 8180 et ses inputs concordent avec l'ancien probe ; le NDJSON n'encode pas la position et les bornes de zone sont `null` au catch, les valeurs antérieures étant un calcul dérivé, pas des bornes capturées.
- Livrable : `docs/handbook/reports/paper-plan-rejection-evidence-2026-10-09.md`, lié dans `mkdocs.yml` ; sources publiques épinglées au commit `a94bbd0499cab004ed1483bf5c451bf5b5263640`. Une clarification PHPStan uniquement ajoute le type PHPDoc de `$diagnosticContext` (`array<string, mixed>`), sans effet comportemental.
- Lots 0 à 2 livrés : préparation, diagnostic initial et instrumentation/preuves de rejet terminés et fusionnés. La cause des 191 rejets est enregistrée pour cette seule cellule ; le diagnostic ne prouve ni rentabilité ni défaut du moteur. Contrats `1.1.0` inchangés. Le choix explicite de profil (par ex. EMA20/5m, tolérance VWAP ou autres périodes) a été demandé et reste en attente ; aucun candidat ni paramètre de risque n'a été modifié. L'analyse calculation-time / candle-close reste une ambiguïté non démontrée.
