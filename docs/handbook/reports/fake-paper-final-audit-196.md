# Audit final Fake / Paper — issue #196

## Décision

**Le résultat métier de l'issue #196 reste `blocked`/incomplet, et le Prompt 9
pourra être marqué `done` seulement après validation et merge de sa PR.**

L'audit du 19 juillet 2026 porte sur le dépôt `pivox/tradingV3`, la branche
`issue/196-fake-paper-final-audit` et la base exacte
`e2c1e30d6610ed262daf834003cadafaf1b76bab`, identique à `origin/main` au
préflight. Les PR #274 à #289 sont mergées. Aucun comportement de stratégie,
MTF, EntryZone, sizing, fréquence, garde live ou Bitmart n'a été modifié.

Le résultat vérifié est le suivant :

- 18 scénarios golden sur 20 exécutent réellement leur comportement nommé dans
  le runner consolidé, deux fois avec une horloge contrôlée et un état neuf ;
- le scénario 15 reconnecte le private WS, mais ne réalise pas le resync par
  snapshot annoncé par son nom ;
- le scénario 20 dispose de tests Python utiles, mais ceux-ci utilisent des
  doubles HTTP en mémoire ; l'ancien runner PHP ne lançait pas la recette et
  certifiait plusieurs faits par constantes ;
- aucun contrat de seed fixe n'existe pour l'ensemble Fake/Paper ; des identités
  internes utilisent encore une source aléatoire, même si les résultats golden
  normalisés observés sont stables ;
- une entrée ordinaire partiellement remplie peut ouvrir une exposition avant
  qu'une protection soit attachée. Le scénario 3 masquait ce point en soumettant
  lui-même une protection après le cancel ;
- le mode Paper réel/replay, la matrice complète de capabilities, plusieurs
  modes de fill, le public WS et la matrice de remplacement Bitmart restent à
  livrer.

La présence d'une ligne dans le catalogue n'est donc pas une certification. Le
catalogue conserve les vingt exigences, mais classe honnêtement 15 et 20
`partial`. Ce rapport ne ferme ni #196 ni #195 et n'autorise aucune écriture
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

Cela prouve la répétabilité des sorties couvertes. Cela ne prouve pas le critère
plus fort « seed fixe » : aucun seed n'est injecté ni vérifié, et certaines
identités non incluses dans les faits normalisés ne sont pas déterministes.

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
| 15 | `websocket_disconnect_resync` | La fixture déconnecte après deux événements et `reconnect()` reprend sans doublon/perte. Aucun snapshot REST local ni `ExchangeReconciliationService` n'est appelé dans ce scénario. | `testReconnectResumesAfterDeterministicDisconnectWithoutLossOrDuplicate` | **PARTIAL** — `websocket_disconnect_snapshot_resync_not_exercised` |
| 16 | `duplicate_out_of_order_event` | Duplicat, gap, conflit de séquence, blocage de projection, snapshot local, reprise contiguë et restart sont exécutés. | `duplicateOutOfOrderEvent()` et `testOutOfOrderOneThreeTwoRequiresSnapshotBeforeFurtherProjection` | PASS — exécutable deux fois |
| 17 | `restart_with_open_position` | Un fichier neuf est repris dans une nouvelle instance avec position, protection et séquence conservées. | `restartWithOpenPosition()` et `testStateStoreRestoresProtectedPositionAndContinuesEventSequence` | PASS — exécutable deux fois |
| 18 | `funding` | Funding positif/négatif/absent, long/short/partiel, deadline, replay exact-once, restart et montant inconnu `null`. | `funding()` et `FakeFundingModelTest` | PASS — exécutable deux fois |
| 19 | `one_way_conflict` | Conflits position/ordre, reduce-only, symboles indépendants, replay et restart en One-Way. | `oneWayConflict()` et `FakeOneWayConflictGuardTest` | PASS — exécutable deux fois |
| 20 | `dry_run_multi_profiles_same_symbol` | Les tests Python exercent la logique d'orchestration avec une API Symfony simulée ; l'ancien runner PHP ne lançait pas la recette et construisait les faits à partir de constantes/du JSON. Il ne s'agit pas de deux piles fraîches complètes. | `test_r12_exports_deterministic_redacted_multi_profile_reports_and_replays_after_restart` et `test_same_symbol_fake_profiles_coexist_with_distinct_lineage_hashes_and_bounded_parallelism` | **PARTIAL** — `multi_profile_recipe_uses_in_memory_http_harness`, `golden_runner_does_not_execute_recipe_twice_from_fresh_state` |

Résultat : **18 PASS / 2 PARTIAL / 0 UNSUPPORTED** dans le catalogue. Ce
résultat n'est pas « 20 scénarios automatisés PASS » au sens de l'acceptance
criterion de #196.

## Matrice des livrables de #196

| Livrable #196 | Évidence vérifiée | Statut | Écart exact / condition de clôture |
|---|---|---|---|
| ADR Fake vs Paper | Le handbook distingue Fake local et Paper persistant avec source marché réelle/replay. | PARTIAL | Pas d'ADR formel/versionné couvrant hypothèses, invariants et décision de source Paper. |
| Matrice de capabilities | `ExchangeCapabilities` publie 13 booléens et le présent audit inventorie le reste. | PARTIAL | Le contrat ne couvre pas toute la liste #196 ; `supportsTestnet=true` est ambigu pour Fake local ; plusieurs absences ne disposent pas d'une opération explicite à faire échouer. |
| Fixtures de métadonnées instruments | Catalogue versionné avec tick, step, min notional, levier et maintenance margin ; tests de validation. | PASS | Aucun écart sur le sous-périmètre Fake perpétuel. |
| Machine d'état des ordres | Les chemins `pending/open/partially_filled/filled/cancelled/rejected/expired/unknown` sont persistés/testés. | PARTIAL | Les états minimum demandés `created`, `cancel_pending`, `replace_pending`, `replaced` et `failed` ne sont pas représentés dans `ExchangeOrderStatus`. |
| Fill engine configurable | Crossing top-of-book, IOC, partial explicite, fallback taker, slippage et gaps sont déterministes. | PARTIAL | Pas de modes configurables `fill_immediate`, probabiliste seedé, volume-constrained, replay historique, latence/jitter ou queue maker réaliste. |
| Modèle de coûts | Frais, rôle maker/taker, slippage, spread explicite, funding et liquidation sont séparés ; inconnu funding reste `null`. | PASS pour les modèles synthétiques implémentés | Les hypothèses Paper devront être sourcées/versionnées avec la future source de marché. |
| Positions, SL/TP, trailing, compensation | Attachments terminaux, compensation, TP1/trailing, liquidation et races terminales sont testés. | **FAIL** | Un fill partiel ordinaire crée une exposition avant protection ; le cancel ordinaire ne l'attache pas automatiquement. Protéger/redimensionner ou compenser à chaque accroissement d'exposition. |
| Persistance/recovery Paper | Enveloppe versionnée, checksum, écriture atomique, reprise ordres/positions/fills/événements/fautes/funding. | PASS pour l'état local | Cela ne transforme pas le Fake local en Paper sans source marché réelle/replay. |
| Simulation WS public/privé | Private WS persistant, ack, duplicate/out-of-order/gap et snapshot resync au scénario 16. | PARTIAL | Public WS absent ; scénario golden 15 ne réalise pas son snapshot resync nommé. |
| DSL/fixtures d'erreurs | Fautes typées `network_timeout`, `transport_error`, `http_429`, `http_500`, avant/après mutation, FIFO et restart. | PARTIAL | Pas de quota glissant, latence/jitter seedés, précision/marge dans une DSL commune, ni catalogue de divergences. |
| Runtime-check Fake/Paper | Contrôle local de book, balance, horloge, coûts, SL et reprise de sonde ; dry-run, permissions off, kill switch et writes off imposés. | PASS fail-closed | La configuration courante reste non-ready sans horloge contrôlée et source marché Paper. Aucun résultat ready Paper ne doit être revendiqué. |
| 20 scénarios golden | Catalogue strict de vingt lignes et runner consolidé. | **FAIL** | 18 exécutables ; scénarios 15 et 20 `partial`. |
| Matrice de parité/remplacement Bitmart | Les adapters exposent quelques flags et la recette Fake garde une frontière structurelle avec Bitmart. | **FAIL** | Aucune matrice complète critère→preuve→divergence→condition de remplacement n'était livrée. L'entrée proposée pour #195 figure plus bas. |
| Documentation opérateur et rollback | Handbook, README Fake, modèle risque et rollback local existent. | PARTIAL | Les anciens documents sur-certifiaient 20/20 ; Paper réel/replay et rollback de sa source restent à documenter après implémentation. |

## Matrice des critères d'acceptation de #196

| Critère | Preuve | Statut | Écart exact |
|---|---|---|---|
| Même seed ⇒ mêmes résultats | Deux runners frais et deux processus donnent les mêmes sorties normalisées sous horloge fixe. | **FAIL** | Aucun seed fixe n'est injecté/testé ; des identités internes reposent encore sur une source aléatoire. Répétabilité observée ≠ contrat seedé. |
| Paper ne touche aucun endpoint privé réel | Le bundle Fake n'injecte aucun client HTTP exchange ; les tests structuraux et guards bloquent les clients réels. | PARTIAL | Le mode Paper réel/replay n'existe pas encore. La propriété est prouvée pour Fake local, pas pour une source Paper future. |
| Un maker peut rester non rempli ou partiel | Scénarios 2 et 3, IOC non croisé et fill partiel explicite. | PASS | — |
| Précision, marge, balance et levier réalistes | Scénarios 6–8 et catalogue instrument. | PASS pour le modèle synthétique versionné | La réalisme Paper dépendra des données source. |
| Aucun ordre accepté avec `order_id=null` | Les résultats acceptés utilisent une identité locale persistée ; tests adapter/contrat. | PASS | — |
| Idempotence sans multi-submit | Scénarios 9, 10, 12, 13, 18 et 19 couvrent replay et effets exact-once. | PASS dans les chemins couverts | La future pile réelle du scénario 20 doit aussi être instrumentée. |
| Toute position ouverte a un SL accepté ou une compensation | Compensation terminale et protections de fill complet sont testées. | **FAIL** | Fill partiel ordinaire non protégé jusqu'au fill terminal ; le scénario 3 attache manuellement après cancel. |
| Restart Paper sans perte | Scénarios 13, 16–19 et tests du file store restaurent l'état et les séquences. | PASS pour fichier local | Pas de ledger PostgreSQL utilisé par Fake. |
| Network/rate-limit/WS disconnect injectables | Timeout, transport, HTTP 429/500, private WS disconnect/gap sont injectables. | PARTIAL | Pas de rate limiter temporel/quota glissant, latence/jitter seedés, public WS ; scénario 15 sans snapshot resync. |
| Frais/slippage/funding séparés | Ledger/événements et champs distincts, funding exact-once, liquidation exacte. | PASS | — |
| Les 20 scénarios golden sont automatisés | Dix-huit exécutions consolidées ; deux preuves partielles. | **FAIL** | Compléter réellement 15 et 20, puis les exécuter deux fois depuis état/pile neufs. |
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
| Stop / SL / TP / trigger | Ordres reduce-only, attachments, gap, races terminales | Protection d'un fill partiel ordinaire non garantie | **Gap critique** |
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
| Private WS | Replay, ack, disconnect, duplicate, gap, resync/restart | Le golden 15 ne chaîne pas snapshot resync | Supporté partiel |
| Source Paper réelle/replay | Absente ; runtime-check fail-closed | `marketDataSourceReady=false` | **Unsupported** |

## Divergences et follow-ups requis

### P0 — empêche la clôture de #196

1. **Protection au fill partiel ordinaire.** Au premier accroissement
   d'exposition, attacher/redimensionner un SL accepté de quantité exacte ou
   compenser immédiatement. Tester fill partiel, fills successifs, cancel,
   timeout/replay, restart et course avec SL/TP.
2. **Scénario 15 complet.** Après disconnect, imposer `requiresResync`, obtenir
   un snapshot Fake local, appeler le service de réconciliation, terminer le
   resync puis seulement reprendre la projection. Exécuter deux fois depuis
   fichiers frais.
3. **Scénario 20 sur pile réelle locale.** Démarrer deux états applicatifs
   indépendants ou remettre à zéro toutes les persistences, lancer réellement la
   recette R12 vers Symfony local Fake, instrumenter les frontières HTTP de tous
   les exchanges et comparer les rapports. Les doubles HTTP en mémoire restent
   des tests unitaires, pas la preuve golden finale.
4. **Contrat de déterminisme seedé.** Introduire un seed explicite dans le
   scénario/runtime, dériver les identités non métier de ce seed ou les exclure
   contractuellement avec justification, puis comparer l'état persistant complet
   et les coûts/événements de deux exécutions fraîches.
5. **Mode Paper.** Implémenter une source marché réelle publique ou replay
   enregistrée, versionnée et redacted, sans client privé et sans write, puis
   rendre le runtime-check ready uniquement si source et horloge sont prêtes.

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
| SL/TP attachés | Déclarés/supportés Fake, gap fill et compensation | Attachés SL et TP déclarés supportés | Identités parent/enfants, acceptation, partial fill, resize, rejet et compensation | À mesurer ; P0 protection partielle côté Fake |
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
- tests Python ciblés du scénario 20, comptés comme preuves partielles ;
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
| Golden consolidé, processus frais 1 | 26 tests, 452 assertions. | PASS |
| Golden consolidé, processus frais 2 | 26 tests, 452 assertions. | PASS |
| Suite proportionnelle Exchange/Fake/Provider/TradeEntry/TradingCore | Premier audit : 949 tests, 5280 assertions, 1 erreur d'environnement et 3 failures révélant des tests Provider `cross` périmés. Après environnement local sûr et réalignement TDD : 950 tests, 5297 assertions. | PASS final |
| Provider Fake ciblé | Reproduction rouge : 24 tests, 196 assertions, 3 failures ; après correction et preuve `cross=false` : 25 tests, 216 assertions. Registry avec `LOCK_DSN=flock` et valeurs locales : 4 tests, 14 assertions. | PASS final |
| Restart fichier ciblé | Position/protection/séquence, private WS, funding, One-Way, liquidation et trailing : 6 tests, 62 assertions. | PASS |
| Runtime-check CLI Fake | Exit 0 de la commande de diagnostic ; `readiness=not_ready`, blockers `fake_paper_clock_not_controlled` et `public_connectivity_unavailable`, warning `fake_paper_market_source_not_configured`; state writable/recovery et modèles locaux ready. | PASS fail-closed, **pas ready** |
| Sécurité structurale exchange | `FakeOnlyExchangeCallAuditTest` : 6 tests, 28 assertions. | PASS |
| Python scénario 20 | Deux tests ciblés PASS ; un warning de dépréciation Starlette/httpx. Ces tests restent classés comme preuve partielle car ils utilisent des doubles HTTP. | PASS de la preuve partielle |
| Python redaction/fixtures | Deux tests ciblés PASS. | PASS |
| PHPStan | Quatre fichiers PHP golden/Provider touchés, aucun défaut. | PASS |
| Symfony container lint | Tous les services ont des types d'injection compatibles. | PASS |
| YAML lint | 55 fichiers valides, tags parsés. | PASS |
| MkDocs strict | L'exécutable `mkdocs` n'est pas dans le `PATH` (exit 127) ; `python3 -m mkdocs build --strict` réussit. Les pages historiques hors navigation sont signalées au niveau INFO. | PASS via module Python ; blocker PATH documenté |
| Scan des ajouts | Affectations de secrets : PASS ; ajout d'appel réseau/exchange : PASS ; aucune valeur n'est imprimée par les scans. | PASS |
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
