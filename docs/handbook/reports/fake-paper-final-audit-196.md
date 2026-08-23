# Audit final Fake / Paper — issue #196

## Décision

**Le résultat métier de l'issue #196 reste incomplet. Le présent rapport est une
baseline historique mise à jour par les lots correctifs listés ci-dessous.**

### Mise à jour du 23 août 2026 — éligibilité baseline moderne

Le pont canonique end-to-end OKX/Hyperliquid étant désormais complet, une
identité moderne exacte qui passe l'Effective Config et la readiness est
persistée `baseline_eligible`. Les profils legacy et les cellules modernes
historiques restent `reference_only` sans backfill. L'export #132 exige
explicitement `baseline_eligible` avant les gates de lineage, fermeture, coûts,
PnL et minimum 50 ; ce statut rend un trade candidat, il ne le certifie pas.

### Mise à jour du 20 août 2026 — identité Paper moderne

Le stockage Paper accepte désormais une identité moderne versionnée contenant
exactement réseau, venue publique, mode et version, setup et version, side, hash
de configuration canonique et hash du catalogue de conditions. Les cellules
legacy conservent leur identité v1 byte-identique et restent `reference_only` ;
aucun alias ni backfill ne transforme leur provenance. Une cellule moderne peut
être enregistrée pour audit, mais son exécution échoue explicitement avec
`paper_modern_strategy_bridge_unavailable` tant que le pont vers la stratégie et
les effets canoniques n'est pas livré. Elle ne peut donc pas encore devenir
`baseline_eligible`.

Les deux commandes Paper acceptent maintenant cette identité moderne uniquement
sous forme complète et exclusive. Le runtime-check résout les six couches de
configuration effective avec la venue et le réseau du dataset, publie les hashes
canoniques redacted, puis reste `ready=false` sur le blocker ci-dessus. La
commande de replay réutilise le même contrat et s'arrête avant toute écriture.

### Mise à jour du 20 août 2026 — readiness Paper replay

Le P0 « source Paper » est levé. Le runtime possède des datasets publics OKX et
Hyperliquid versionnés, redacted et vérifiés, une horloge de replay contrôlée,
un coordinateur persistant et une frontière d'exécution Fake-only sans client
privé ni adapter réel. `app:paper-market:runtime-check` vérifie désormais un
tuple exact dataset/configuration/profil/run sans mutation ; le replay réutilise
la même préparation avant toute écriture. Le résultat distingue la readiness
technique de l'éligibilité baseline : les profils legacy restent
`reference_only`, donc aucune baseline moderne n'est certifiée par ce lot.

### Mise à jour du 20 août 2026 — protection partielle

Le premier P0 de l'audit est levé : le matching engine attache un SL exact dès
le premier fill partiel, redimensionne le même stop sur les fills successifs et
compense uniquement l'incrément non protégé en cas de rejet. Le reliquat parent
est annulé après rejet ou déclenchement du stop. Restart, replay et fallback
maker/taker conservent cette garantie. Le scénario golden 3 ne crée plus de stop
manuellement et prouve désormais le comportement runtime.

### Mise à jour du 20 août 2026 — golden scenario 15

Le golden 15 est désormais exécutable deux fois depuis des fichiers frais. Il
force la déconnexion après deux acquittements, interdit toute projection tant
que `requiresResync` est actif, projette le snapshot local avec
`ExchangeReconciliationService`, acquitte ce résultat puis reprend de nouveaux
événements sans perte ni doublon. Le catalogue passe à 19 exécutables et un
seul scénario partiel.

### Mise à jour du 20 août 2026 — golden scenario 20

Le golden 20 lance désormais R12 dans deux piles applicatives indépendantes.
Chaque pile crée une base SQLite d'orchestration, un répertoire d'état Fake, un
processus Symfony Kernel et un processus FastAPI/uvicorn neufs. Les appels
passent par HTTP loopback réel, sans `MockTransport` ni API Symfony simulée. Les
deux rapports normalisés sont byte-identiques ; trois profils distincts ciblent
`BTCUSDT`, le replay conserve le même run, et ordres comme appels
Bitmart/OKX/Hyperliquid restent à zéro. Le catalogue passe à 20 exécutables.

### Mise à jour du 20 août 2026 — déterminisme seedé

Le contrat `fake-deterministic-seed-v1` lie désormais les identités persistées
Fake et les rapports de recette à une seed explicite. Seule son empreinte
SHA-256 est exposée. Les cycles de resynchronisation private WS, preuves,
attestations et identités de preuve Python sont dérivés par HMAC avec domaines
versionnés. Un nonce d'invocation opérationnel, non certifié, reste unique pour
empêcher la réutilisation d'un run persistant. Deux états frais exécutant les mêmes opérations sous la même seed
sont byte-identiques ; un restart sous une autre seed échoue avec
`fake_exchange_state_seed_mismatch`. Un état antérieur sans preuve de seed reste
`seed_certified=false` et bloque le runtime-check. Golden 20 injecte une seed
fixe dans ses deux piles et atteste le schéma et l'empreinte dans son rapport.
La certification R12 exige en plus que l'open-state Symfony fournisse la même
empreinte avec `seed_certified=true`; une preuve backend absente, legacy ou
divergente produit `BLOCKED` et ne peut pas réutiliser la clé de replay précédente.

L'audit du 19 juillet 2026 porte sur le dépôt `pivox/tradingV3`, la branche
`issue/196-fake-paper-final-audit` et la base exacte
`e2c1e30d6610ed262daf834003cadafaf1b76bab`, identique à `origin/main` au
préflight. Les PR #274 à #289 sont mergées. Aucun comportement de stratégie,
MTF, EntryZone, sizing, fréquence, garde live ou Bitmart n'a été modifié.

Le résultat vérifié est le suivant :

- 20 scénarios golden sur 20 exécutent réellement leur comportement nommé dans
  le runner consolidé, deux fois avec une horloge contrôlée et un état neuf ;
- le scénario 20 complète les tests unitaires Python par deux piles locales
  fraîches et de vraies frontières HTTP FastAPI/Symfony ;
- le contrat seedé couvre les identités Fake persistées et la recette R12 ; les
  noms de fichiers temporaires atomiques et le nonce anti-replay de dispatch
  sont explicitement hors preuve car ils ne sont pas des résultats métier ;
- la faille historique de protection des fills partiels est corrigée par la mise
  à jour du 20 août 2026 ci-dessus ;
- la promotion des modes modernes, la matrice complète de capabilities,
  plusieurs modes de fill et la matrice de remplacement Bitmart restent à
  livrer.

La présence d'une ligne dans le catalogue n'est pas à elle seule une
certification. Les vingt lignes sont désormais `executable`, mais les autres
écarts de représentativité listés ci-dessous restent ouverts. Ce rapport ne
ferme ni #196 ni #195 et n'autorise aucune écriture
exchange.

## Méthode et périmètre de preuve

Sources lues intégralement ou dans le périmètre demandé :

- les instructions `AGENTS.md` de la session ;
- le prompt maître d'orchestration v2 ;
- la section 2 et le Prompt 9 du registre canonique ;
- [l'issue #196](https://github.com/pivox/tradingV3/issues/196),
  [l'issue #195](https://github.com/pivox/tradingV3/issues/195) et tous leurs
  commentaires ;
- les descriptions, commits, revues, commentaires et threads des PR #274 à
  #289 ;
- le catalogue, le runner, les tests, le runtime-check, la persistance, les
  modèles de fills/coûts/protections et les documents opérateur Fake/Paper.

La preuve golden comprend deux niveaux distincts :

1. pour chaque ligne `executable`,
   `FakePaperGoldenScenarioExecutionTest` construit deux runners indépendants,
   compare les résultats complets, impose l'horloge
   `2026-01-01T00:00:00+00:00` et compare les faits au résultat attendu ;
2. la commande consolidée est relancée dans deux processus PHPUnit distincts,
   sans configuration PHPUnit et avec des états Fake locaux/temporaires.

Cela prouve la répétabilité des sorties couvertes. Golden 20 complète désormais
cette preuve avec une seed fixe injectée dans Symfony et Python, une empreinte
attestée, des identités HMAC déterministes et la comparaison byte pour byte du
rapport normalisé.

## Catalogue golden : exigence, preuve et statut

| # | Scénario | Comportement réellement exercé | Preuve principale | Statut audit |
|---:|---|---|---|---|
| 1 | `limit_maker_full_fill` | Un LIMIT maker repose, le book croise, un fill complet ouvre une position protégée. | `FakePaperGoldenScenarioRunner::limitMakerFullFill()` et `FakeExchangeAdapterTest::testMovePriceFillsLimitOrderAndCreatesPosition` | PASS — exécutable deux fois |
| 2 | `limit_unfilled_then_expired` | Un LIMIT IOC non croisé expire sans fill ni position. | `limitUnfilledThenExpired()` et `testNonCrossingIocLimitExpiresWithoutResting` | PASS — exécutable deux fois |
| 3 | `partial_fill_then_cancel` | Un fill partiel est appliqué, le reliquat est annulé et le replay conserve quantité/statut. | `partialFillThenCancel()` et `testCancelledPartialClientOrderIdReplayPreservesFilledSemantics` | PASS pour le comportement nommé ; ne prouve pas la protection automatique de toute exposition partielle |
| 4 | `fallback_taker` | À expiration de zone, le reliquat exact devient un enfant MARKET borné et idempotent. | `fallbackTaker()` et tests `FakeFallbackTakerPolicy` | PASS — exécutable deux fois |
| 5 | `market_with_slippage` | Un MARKET taker calcule séparément 5 bps de slippage adverse. | `marketWithSlippage()` et tests de coûts du matching engine | PASS — exécutable deux fois |
| 6 | `insufficient_balance` | La marge insuffisante rejette sans mutation monétaire. | `insufficientBalance()` et tests instrument/risk | PASS — exécutable deux fois |
| 7 | `precision_reject` | Tick/step/min-notional invalides sont rejetés avant fill. | `precisionReject()` et tests instrument/risk | PASS — exécutable deux fois |
| 8 | `leverage_cap_reject` | Un levier au-delà de la limite instrument est rejeté. | `leverageCapReject()` et tests instrument/risk | PASS — exécutable deux fois |
| 9 | `duplicate_client_order_id` | Le même `client_order_id` restitue l'ordre original sans second ordre/fill. | `duplicateClientOrderId()` et tests de replay adapter | PASS — exécutable deux fois |
| 10 | `timeout_after_acceptance` | Une réponse perdue après acceptation est rejouée sans double mutation. | `timeoutAfterAcceptance()` et tests `applied_response_lost` | PASS — exécutable deux fois |
| 11 | `stop_loss_attach_success` | Le fill terminal crée la protection SL attachée attendue. | `stopLossAttachSuccess()` et tests de protection adapter | PASS — exécutable deux fois |
| 12 | `stop_loss_attach_failure` | L'échec terminal d'attachement déclenche une compensation MARKET reduce-only sur la quantité effectivement exposée. | `stopLossAttachFailure()` et tests de compensation | PASS — exécutable deux fois |
| 13 | `tp1_then_trailing` | TP1 réduit exactement la position, puis un trailing monotone protège le reliquat à travers replay/restart. | `tp1ThenTrailing()` et tests `FakeTp1TrailingPolicy` | PASS — exécutable deux fois |
| 14 | `gap_at_stop_loss` | Un gap au-delà du SL ferme au prochain top-of-book disponible. | `gapAtStopLoss()` et `testMovePriceTriggersAttachedStopLossAndClosesPosition` | PASS — exécutable deux fois |
| 15 | `websocket_disconnect_resync` | Déconnexion après deux événements, blocage avant snapshot, réconciliation locale exacte, événement ajouté après snapshot, acquittement borné au watermark puis reprise sans perte. | `websocketDisconnectResync()` et `testReconnectResumesAfterDeterministicDisconnectWithoutLossOrDuplicate` | PASS — exécutable deux fois depuis fichiers frais |
| 16 | `duplicate_out_of_order_event` | Duplicat, gap, conflit de séquence, blocage de projection, snapshot local, reprise contiguë et restart sont exécutés. | `duplicateOutOfOrderEvent()` et `testOutOfOrderOneThreeTwoRequiresSnapshotBeforeFurtherProjection` | PASS — exécutable deux fois |
| 17 | `restart_with_open_position` | Un fichier neuf est repris dans une nouvelle instance avec position, protection et séquence conservées. | `restartWithOpenPosition()` et `testStateStoreRestoresProtectedPositionAndContinuesEventSequence` | PASS — exécutable deux fois |
| 18 | `funding` | Funding positif/négatif/absent, long/short/partiel, deadline, replay exact-once, restart et montant inconnu `null`. | `funding()` et `FakeFundingModelTest` | PASS — exécutable deux fois |
| 19 | `one_way_conflict` | Conflits position/ordre, reduce-only, symboles indépendants, replay et restart en One-Way. | `oneWayConflict()` et `FakeOneWayConflictGuardTest` | PASS — exécutable deux fois |
| 20 | `dry_run_multi_profiles_same_symbol` | Deux bases, deux états Fake et deux couples Symfony/FastAPI frais exécutent R12 par HTTP loopback sous la même seed explicite ; rapports identiques, empreinte attestée, trois lineage/config hashes distincts, replay stable et zéro ordre/appel exchange. | `run_fresh_stacks()`, `test_golden20_runs_twice_through_fresh_real_http_stacks()` et `dryRunMultiProfilesSameSymbol()` | PASS — exécutable deux fois depuis piles fraîches seedées |

Résultat : **20 PASS / 0 PARTIAL / 0 UNSUPPORTED** dans le catalogue. Cela lève
le livrable golden, sans lever les autres écarts de #196.

## Matrice des livrables de #196

| Livrable #196 | Évidence vérifiée | Statut | Écart exact / condition de clôture |
|---|---|---|---|
| ADR Fake vs Paper | Le design versionné du 19 juillet et le delta readiness du 20 août fixent hypothèses, invariants, source publique/replay et frontière Fake-only. | PASS | La promotion des profils reste traitée séparément. |
| Matrice de capabilities | `ExchangeCapabilities` publie 13 booléens et le présent audit inventorie le reste. | PARTIAL | Le contrat ne couvre pas toute la liste #196 ; `supportsTestnet=true` est ambigu pour Fake local ; plusieurs absences ne disposent pas d'une opération explicite à faire échouer. |
| Fixtures de métadonnées instruments | Catalogue versionné avec tick, step, min notional, levier et maintenance margin ; tests de validation. | PASS | Aucun écart sur le sous-périmètre Fake perpétuel. |
| Machine d'état des ordres | Les chemins `pending/open/partially_filled/filled/cancelled/rejected/expired/unknown` sont persistés/testés. | PARTIAL | Les états minimum demandés `created`, `cancel_pending`, `replace_pending`, `replaced` et `failed` ne sont pas représentés dans `ExchangeOrderStatus`. |
| Fill engine configurable | Crossing top-of-book, IOC, partial explicite, fallback taker, slippage et gaps sont déterministes. | PARTIAL | Pas de modes configurables `fill_immediate`, probabiliste seedé, volume-constrained, replay historique, latence/jitter ou queue maker réaliste. |
| Modèle de coûts | Frais, rôle maker/taker, slippage, spread explicite, funding et liquidation sont séparés ; inconnu funding reste `null`. Les datasets Paper portent leur qualité et leur modèle versionné. | PASS pour les modèles implémentés | Toute nouvelle hypothèse doit conserver cette provenance explicite. |
| Positions, SL/TP, trailing, compensation | Attachments terminaux, fill partiel immédiatement protégé, resize stable, compensation exacte, TP1/trailing, liquidation et races terminales sont testés. | PASS pour le contrat attaché | Une entrée sans SL attaché reste hors de cette garantie. |
| Persistance/recovery Paper | Dataset public vérifié, journal PostgreSQL en trois phases, checkpoints, reprise des effets pending et identité moderne exacte sans backfill legacy. | PASS pour le replay Paper | Les profils legacy restent exclus ; les cellules modernes restent bloquées avant effets jusqu'au pont canonique. |
| Simulation WS public/privé | Private WS persistant, disconnect, ack, duplicate/out-of-order/gap et snapshot resync aux scénarios 15 et 16. | PARTIAL | Public WS absent. |
| DSL/fixtures d'erreurs | Fautes typées `network_timeout`, `transport_error`, `http_429`, `http_500`, avant/après mutation, FIFO et restart. | PARTIAL | Pas de quota glissant, latence/jitter seedés, précision/marge dans une DSL commune, ni catalogue de divergences. |
| Runtime-check Fake/Paper | Le check Fake certifie son état seedé ; le check Paper dédié vérifie source publique exacte, checksum, horloge, base, cellule et frontière Fake-only sans mutation. | PASS fail-closed | `PAPER_EXECUTION_ENABLED=0`, source invalide, clock en régression ou DB non allowlistée restent bloquants. |
| 20 scénarios golden | Catalogue strict de vingt lignes, runner consolidé et R12 exécuté depuis deux piles fraîches. | PASS | Aucun écart sur le livrable golden. |
| Matrice de parité/remplacement Bitmart | Les adapters exposent quelques flags et la recette Fake garde une frontière structurelle avec Bitmart. | **FAIL** | Aucune matrice complète critère→preuve→divergence→condition de remplacement n'était livrée. L'entrée proposée pour #195 figure plus bas. |
| Documentation opérateur et rollback | Le runbook décrit le check read-only, l'interprétation de l'éligibilité, le rejeu exact et la conservation des datasets au rollback. | PASS pour le replay | Les follow-ups P1 restent documentés dans cet audit. |

## Matrice des critères d'acceptation de #196

| Critère | Preuve | Statut | Écart exact |
|---|---|---|---|
| Même seed ⇒ mêmes résultats | `fake-deterministic-seed-v1`, état persistant byte-identique, restart lié à l'empreinte et Golden 20 sur deux piles fraîches sous seed fixe. | PASS pour le périmètre Fake/R12 certifié | Les futurs modèles probabilistes devront réutiliser ce contrat et ajouter leur propre domaine versionné. |
| Paper ne touche aucun endpoint privé réel | Le sous-graphe Paper utilise les sources publiques vérifiées et un registry d'exécution vide ; les tests de wiring excluent client privé, credential, signer et adapter réel. | PASS pour le replay | Aucune activation d'écriture exchange n'est autorisée. |
| Un maker peut rester non rempli ou partiel | Scénarios 2 et 3, IOC non croisé et fill partiel explicite. | PASS | — |
| Précision, marge, balance et levier réalistes | Scénarios 6–8 et catalogue instrument. | PASS pour le modèle synthétique versionné | La réalisme Paper dépendra des données source. |
| Aucun ordre accepté avec `order_id=null` | Les résultats acceptés utilisent une identité locale persistée ; tests adapter/contrat. | PASS | — |
| Idempotence sans multi-submit | Scénarios 9, 10, 12, 13, 18–20 couvrent replay et effets exact-once ; R12 relit le même run sur chaque pile fraîche. Le coordinateur Paper reprend les effets pending avant consommation. | PASS dans les chemins couverts | Les futurs profils modernes devront conserver cette preuve. |
| Toute position ouverte a un SL accepté ou une compensation | Fill complet/partiel avec SL attaché, resize et compensation exacte sont testés. | PASS pour le contrat attaché | Les ordres sans SL attaché ne sont pas certifiés par cette garantie. |
| Restart Paper sans perte | Scénarios 13, 16–19 et tests du file store restaurent l'état et les séquences. | PASS pour fichier local | Pas de ledger PostgreSQL utilisé par Fake. |
| Network/rate-limit/WS disconnect injectables | Timeout, transport, HTTP 429/500, private WS disconnect/gap et snapshot resync sont injectables. | PARTIAL | Pas de rate limiter temporel/quota glissant, latence/jitter seedés ou public WS. |
| Frais/slippage/funding séparés | Ledger/événements et champs distincts, funding exact-once, liquidation exacte. | PASS | — |
| Les 20 scénarios golden sont automatisés | Vingt exécutions consolidées ; R12 lance deux piles HTTP fraîches sous seed fixe. | PASS | — |
| Divergences critiques Bitmart listées | La section #195 ci-dessous propose une entrée statique. | PARTIAL | Il manque les fixtures empiriques Bitmart redacted et la matrice de remplacement validée par #195. |

## Capabilities Fake/Paper observées

La colonne « explicite » signifie qu'une capability est déclarée ou qu'une
demande invalide échoue par une garde testée. Elle ne signifie pas que cette
capability existe sur un exchange réel.

| Capability canonique | Fake observé | Échec explicite / limite | Statut |
|---|---|---|---|
| Spot | Non routé par le bundle Fake testé | Contexte hors `fake/perpetual` rejeté | Unsupported explicite |
| Perpétuel linéaire | Catalogue BTC/ETH synthétique, positions et funding | Local uniquement | Supporté |
| Long / short | Positions dans les deux sens | One-Way interdit l'exposition opposée concurrente | Supporté |
| One-Way | Garde versionnée, replay/restart | Clé `exchange+market+symbol` | Supporté |
| Hedge | Non implémenté | Mode non One-Way rejeté | Unsupported explicite |
| MARKET | Top-of-book, slippage/cost séparé | Pas de profondeur/impact volume | Supporté partiel |
| LIMIT / IOC / post-only | Repos, crossing, IOC, partial et expiry | Queue maker/volume historique absents | Supporté partiel |
| Stop / SL / TP / trigger | Ordres reduce-only, attachments, fill partiel immédiat, resize, gap et races terminales | Les ordres sans SL attaché restent hors garantie | Supporté pour le contrat attaché |
| Trailing | Politique TP1→trailing opt-in, persistante | Pas de trailing générique | Supporté ciblé |
| Cancel | Par ordre et par client ID, replay idempotent | État `cancel_pending` absent | Supporté partiel |
| Replace / modify | `supportsModifyOrder=false` | Aucun lifecycle replace | Unsupported déclaré |
| Isolated margin | Réservation/release, maintenance, liquidation | Modèle synthétique | Supporté |
| Cross margin | Non implémenté | Mode rejeté | Unsupported explicite |
| Leverage par symbole | Limites catalogue et fallback borné | Données synthétiques | Supporté |
| Balances / equity / PnL / margin | Projections locales | Certaines absences sont converties en `0.0` par les providers legacy | **Partiel ; unknown→zero à corriger** |
| Positions / ordres ouverts | Adapter/state store | Historique complet absent | Supporté pour état actif |
| Trade / transaction history | Providers retournent une liste vide | Vide ne distingue pas « aucun résultat » de « capability absente » | Unsupported non typé |
| Transfers | Non implémentés | Pas de contrat Fake dédié | Unsupported non typé |
| Funding | Modèle versionné, deadlines et exact-once | Taux absent reste inconnu dans le modèle canonique | Supporté |
| Liquidation | Modèle isolated linéaire, coûts et bankruptcy | Pas de cross/ADL/insurance fund | Supporté ciblé |
| Fees / slippage / spread | Modèles/version/source séparés | Hypothèses synthétiques | Supporté |
| REST exchange | Aucun transport réseau Fake | API locale de scénario uniquement | Sans réseau par conception |
| Public WS | Absent | Aucun flux public simulé | Unsupported |
| Private WS | Replay, ack, disconnect, duplicate, gap, snapshot resync/restart | Public WS séparé absent | Supporté |
| Source Paper réelle/replay | Datasets publics OKX/Hyperliquid vérifiés, replay déterministe et readiness dédiée | Dataset/clock/DB/cellule invalides échouent avant mutation | Supporté, profils legacy `reference_only` |

## Divergences et follow-ups requis

### P0

Aucun P0 restant dans le périmètre source/readiness Paper. Cela ne clôt pas les
écarts P1 ci-dessous et ne rend aucun profil legacy certifiable.

### P1 — livrables incomplets

1. Étendre la machine d'état ou documenter/migrer explicitement les états
   `created`, `cancel_pending`, `replace_pending`, `replaced`, `failed` demandés.
2. Remplacer la matrice booléenne partielle par un contrat de capabilities
   canonique couvrant marché, position mode, ordre, marge, données, WS et
   historiques ; toute absence doit échouer de façon typée.
3. Ajouter les modes de fill configurables, seedés et versionnés : immédiat,
   crossing, probabiliste, volume-constrained et replay historique ; ajouter
   latence/jitter/quota glissant sans attente réelle dans les tests.
4. Simuler le public WS ou le déclarer explicitement unsupported dans le contrat.
5. Ne plus convertir une balance, PnL, marge, volume ou donnée inconnue en zéro
   dans les providers legacy ; propager `null`/unknown ou une erreur typée.
6. Construire et valider la matrice Bitmart de #195 avec données/fixtures
   redacted représentatives, sans modifier ni supprimer le comportement Bitmart
   avant décision de remplacement.

## Entrée concrète pour la future matrice de remplacement #195

Cette table est un **input**, pas une décision de remplacement. Les valeurs
Bitmart ci-dessous viennent uniquement du contrat statique
`BitmartExchangeAdapter::capabilities()` et du code existant ; aucun endpoint
Bitmart n'a été appelé. Une cellule « à mesurer » doit rester inconnue, jamais
devenir zéro ou PASS.

| Axe #195 | Oracle Fake disponible | Contrat Bitmart statique observé | Preuve représentative requise avant remplacement | Décision actuelle |
|---|---|---|---|---|
| Identité / `client_order_id` | Replay stable, pas de double ordre | Client ID déclaré supporté | Fixtures redacted accepted/lost-response/duplicate ; mapping local↔exchange non nul | Pending |
| Cancel par client ID | Supporté et idempotent | Déclaré non supporté | Chemin de cancel par exchange order ID, timeout, already-terminal et restart | Divergence critique |
| MARKET | Top-of-book + coût séparé | Adapter expose placement | Prix demandé/exécuté, quantité, rôle, fee, slippage et timestamps redacted | À mesurer |
| LIMIT / post-only / IOC | Repos/crossing/partial/expiry | Post-only et IOC déclarés supportés | Lifecycle complet avec maker non rempli, partiel, cancel et expiry | À mesurer |
| Reduce-only | Fermeture exacte et guards | Déclaré supporté | Rejet de sur-réduction, position absente, course terminale | À mesurer |
| SL/TP attachés | Déclarés/supportés Fake, protection partielle immédiate, resize, gap fill et compensation exacte | Attachés SL et TP déclarés supportés | Identités parent/enfants, acceptation, partial fill, resize, rejet et compensation | P0 Fake levé ; parité externe à mesurer |
| Trigger orders | Ordres stop locaux | Déclaré non supporté | Déterminer si attachments Bitmart remplacent le trigger générique ; échec typé sinon | Divergence contractuelle |
| Modify / replace | Non supporté | Non supporté | Conserver fail-closed ou spécifier cancel+new avec nouvelle identité et risque | Parité unsupported |
| One-Way / Hedge | One-Way seulement, Hedge rejeté | Non représenté dans le DTO actuel | Mode compte réel, side mapping, conflits et reduce-only avec fixtures redacted | À mesurer |
| Isolated / Cross | Isolated seulement, Cross rejeté | Non représenté dans le DTO actuel | Mode marge effectif, refus des modes absents, pas de fallback | À mesurer |
| Leverage par symbole | Supporté et borné | Déclaré supporté | Cap instrument, valeur appliquée, fallback, erreur et restart | À mesurer |
| Instrument metadata | Tick/step/notional/leverage/MMR versionnés | Provider existant hors matrice | Snapshot redacted versionné et validation de stale/absent | À mesurer |
| Balances / margin / PnL | Ledger synthétique local | Provider existant hors matrice | Inconnu distinct de zéro, currency, equity, used/available margin | À mesurer |
| Positions / ordres ouverts | État actif canonique | Provider existant hors matrice | Reconciliation REST/WS, positions orphelines, ordre terminal manquant | À mesurer |
| Trade / transaction history | Gap explicite à typer | Provider existant hors matrice | Pagination, bornes temporelles, déduplication, fees/funding/transfers | À mesurer |
| Fees / slippage / spread | Modèles séparés et versionnés | Hors matrice booléenne | Source maker/taker, devise, arrondis et inconnus redacted | À mesurer |
| Funding | Ledger exact-once avec inconnu `null` | Hors matrice booléenne | Deadline/rate/payment/currency, pagination/restart et absence de double coût | À mesurer |
| Liquidation | Modèle isolated synthétique | Hors matrice booléenne | Événement exchange, prix, fee, bankruptcy, cause et ordre de clôture | À mesurer |
| Private WS | Déclaré supporté côté Fake et Bitmart | `supportsWebSocketPrivate=true` | Séquences, duplicate/out-of-order/gap, reconnect+snapshot, ack après projection | À mesurer |
| Public WS | Absent côté Fake | Hors matrice booléenne | Book/trades/mark/funding, stale/gap/reconnect, horodatage | Gap des deux contrats |
| Testnet | Fake local publie actuellement `supportsTestnet=true` | Bitmart publie `false` | Clarifier la sémantique : Fake local n'est pas un testnet ; ne pas activer une URL Bitmart | Divergence à corriger dans le contrat |
| Sécurité / redaction | Aucun transport Fake ; guards structuraux | Adapter réel présent mais non appelé | Tests structuraux, HTTP client espion, logs/rapports sans valeurs sensibles | Obligatoire avant toute comparaison runtime |
| Rollback | Arrêt des writers Fake + archive/restauration du fichier | Comportement Bitmart à préserver | Feature flag/routage réversible, double lecture avant bascule, aucune suppression | Pending |

Condition proposée pour #195 : aucune ligne Bitmart « à mesurer » ne peut être
certifiée par une valeur Fake. Elle exige une fixture redacted représentative ou
un contrat documenté, une comparaison champ par champ et un échec typé pour
l'absence. La migration ne doit supprimer aucun chemin Bitmart tant que la
parité critique, le rollback et les invariants de protection ne sont pas verts.

## Sécurité, réseau et secrets

L'audit et les tests utilisent uniquement les composants Fake locaux :

- aucun client HTTP exchange n'est injecté dans le bundle Fake ;
- le runtime-check force `dry_run=true`, `permissions_trade=false`, kill switch
  actif et écritures demo/testnet désactivées ;
- `mainnet_write_enabled` reste faux ;
- les tests structuraux de la recette vérifient les frontières OKX,
  Hyperliquid et Bitmart sans effectuer d'appel privé ;
- aucun ordre n'a été créé, annulé ou remplacé sur un exchange ;
- aucun secret ni valeur de credential n'a été lu ou affiché ;
- le fichier d'environnement de test interdit n'est pas utilisé par les
  commandes de validation.

Un scan statique des seules lignes ajoutées/modifiées doit rester sans affectation
de credential, token, clé privée, header d'autorisation ou endpoint exchange. Le
scan réseau doit confirmer que le diff ne crée aucun client/transport/appel HTTP.

## Validation et limites d'environnement

Les validations exigées pour ce rapport sont :

- catalogue/exécution golden, deux processus frais ;
- suites Exchange/Fake/Provider/TradeEntry/TradingCore proportionnelles ;
- tests ciblés restart/fichier, idempotence, coûts, protections, runtime-check et
  absence d'appel exchange ;
- test Python du scénario 20 sur deux piles HTTP fraîches et suite orchestrateur
  complète dans les conditions CI ;
- PHPStan sur chaque PHP touché ;
- lint container Symfony et YAML avec `DEFAULT_URI` sûr explicite si nécessaire ;
- MkDocs strict ;
- scans secrets/redaction/réseau sans imprimer de valeur ;
- `git diff --check`.

Résultats obtenus :

| Validation | Résultat exact | Statut |
|---|---|---|
| Baseline consolidée avant edit | Deux processus : 28 tests, 469 assertions chacun. Cette valeur diffère de la baseline orchestrateur annoncée à 26/433 et confirmait que les vingt lignes étaient alors fournies au runner. | PASS technique, certification auditée ensuite |
| TDD de reclassification | Test rouge initial : 26 tests, 394 assertions, 2 failures attendues (catalogue encore `executable`, runner encore à 20 clés). Après correction ciblée : 24 tests, 421 assertions, PASS. | PASS |
| Golden consolidé avec scénario 20 frais | 26 tests, 453 assertions ; le test PHPUnit relance le runner complet, lequel crée deux piles par invocation. | PASS |
| Suite proportionnelle Exchange/Fake/Provider/TradeEntry | 1392 tests, 7298 assertions, 30 skips et un risque de métadonnée coverage préexistant. | PASS fonctionnel |
| Provider Fake ciblé | Reproduction rouge : 24 tests, 196 assertions, 3 failures ; après correction et preuve `cross=false` : 25 tests, 216 assertions. Registry avec `LOCK_DSN=flock` et valeurs locales : 4 tests, 14 assertions. | PASS final |
| Restart fichier ciblé | Position/protection/séquence, private WS, funding, One-Way, liquidation et trailing : 6 tests, 62 assertions. | PASS |
| Runtime-check CLI Fake | Exit 0 de la commande de diagnostic ; `readiness=not_ready`, blockers `fake_paper_clock_not_controlled` et `public_connectivity_unavailable`, warning `fake_paper_market_source_not_configured`; state writable/recovery et modèles locaux ready. | PASS fail-closed, **pas ready** |
| Sécurité structurale exchange | `FakeOnlyExchangeCallAuditTest` : 6 tests, 28 assertions. | PASS |
| Python scénario 20 | Test frais PASS : deux SQLite, deux états Fake et quatre processus Symfony/FastAPI sur HTTP loopback ; digest identique et zéro ordre/appel exchange. | PASS |
| Contrat seedé PHP/Python | 50 tests PHP ciblés, 263 assertions ; vecteur HMAC commun PHP/Python, état byte-identique, mismatch/legacy fail-closed et seed par cellule. Suite Python complète dans les conditions CI, dont deux piles Golden 20 seedées. | PASS |
| Suite Python orchestrateur | 1188 tests collectés : 1184 PASS et 4 skips attendus dans les conditions CI ; un warning Starlette/httpx et des warnings Backtrader connus. | PASS |
| Python redaction/fixtures | Deux tests ciblés PASS. | PASS |
| PHPStan | Trois fichiers PHP golden touchés, aucun défaut avec limite mémoire explicite de 1 Gio. | PASS |
| Symfony container lint | Tous les services ont des types d'injection compatibles. | PASS |
| YAML lint | 96 fichiers valides, tags parsés. | PASS |
| MkDocs strict | L'exécutable `mkdocs` n'est pas dans le `PATH` (exit 127) ; `python3 -m mkdocs build --strict` réussit. Les pages historiques hors navigation sont signalées au niveau INFO. | PASS via module Python ; blocker PATH documenté |
| Scan des ajouts | Aucun credential exchange ni header d'autorisation ; seul `APP_SECRET` reçoit une constante locale de boot Symfony non sensible. Les nouvelles URL sont exclusivement `127.0.0.1` et aucun appel exchange n'est ajouté. | PASS |
| `git diff --check` | Aucun défaut. | PASS |

> **Note — commande exacte de sélection de la suite proportionnelle :**
> `LOCK_DSN=flock DEFAULT_URI=http://127.0.0.1 REDIS_ORDER_WATCH_CHANNEL=audit-local-order-watch vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Exchange/Fake tests/Exchange/Adapter/FakeExchangeAdapterTest.php tests/Exchange/Adapter/FakeExchangeFaultInjectionTest.php tests/Exchange/Event/ExchangeWsIngestionServiceTest.php tests/Exchange/Event/FakeExchangeEventNormalizerTest.php tests/Exchange/Readiness/FakeRuntimeCheckTest.php tests/Provider/Fake tests/TradeEntry/Execution tests/TradingCore/Execution`

Aucune requête de ledger ou migration PostgreSQL n'est ajoutée par cet audit :
les tests PostgreSQL ne sont donc pas applicables au diff. Les résultats exacts,
skips et éventuels blockers d'environnement sont également reportés dans la
remise du worker ; un test non exécuté n'est pas un PASS. Aucun test n'a été
skippé dans les commandes ci-dessus.

## Risques et rollback

Le diff d'audit ne modifie aucun composant de production. Son risque principal
est documentaire : des consommateurs pouvaient supposer à tort que les vingt
lignes étaient certifiées. La reclassification rend cette hypothèse impossible
dans le test de contrat.

Rollback technique : revenir uniquement sur le catalogue, le runner/test golden
et les documents de cet audit. Aucun nettoyage exchange n'est requis puisqu'il
n'y a eu aucune écriture exchange. Il ne faut toutefois pas rétablir le statut
20/20 sans ajouter les preuves exécutables manquantes. Le fichier d'état Paper
local actif n'est ni lu ni modifié par ce diff.
