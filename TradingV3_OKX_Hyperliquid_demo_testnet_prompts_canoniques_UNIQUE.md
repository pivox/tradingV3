# TradingV3 - Prompts canoniques restants pour Fake/Paper, OKX Demo et Hyperliquid testnet

> Objectif final : terminer le filet de sécurité Fake/Paper, produire des preuves
> représentatives, puis autoriser uniquement des scénarios contrôlés sur OKX Demo
> et Hyperliquid testnet.
>
> Aucun prompt de ce document n'autorise une écriture mainnet.

<!-- CODEX_ORCHESTRATION_STATE_START -->
| Ordre | Prompt | Statut | Branche | PR | HEAD validé | Profil final | CI | Revue Codex | Terminé UTC |
|---:|---|---|---|---:|---|---|---|---|---|
| 1 | #196 — Fallback taker de fin de zone | done | issue/196-fake-fallback-taker-v1 | #282 | 4186abf243a035af16f7672bc493c225d31112d3 | worker_critical → review_fix | verte | favorable sur 4186abf243 | 2026-07-16T14:18:05Z |
| 2 | #196 — TP1 puis trailing stop | done | issue/196-fake-tp1-trailing-v1 | #283 | 806f92dc1a162b50515a44e42a825f976f0610cb | worker_critical → review_fix → review_escalated | verte | favorable sur 806f92dc1a | 2026-07-16T19:32:05Z |
| 3 | #196 — Injection out-of-order | in_progress | issue/196-fake-out-of-order-v1 | — | b636812c3543746e08cf9de7901fce639285be7a | worker_complex | — | — | — |
| 4 | #196 — Funding positif/négatif | pending | — | — | — | — | — | — | — |
| 5 | #196 — Garde One-Way | pending | — | — | — | — | — | — | — |
| 6 | #196 — Recette Fake multi-profils | pending | — | — | — | — | — | — | — |
| 7 | #196 — Daily loss cap | pending | — | — | — | — | — | — | — |
| 8 | #196 — Liquidation guard/model | pending | — | — | — | — | — | — | — |
| 9 | #196 — Audit final Fake/Paper | pending | — | — | — | — | — | — | — |
| 10 | #195 — Inventaire statique Bitmart | pending | — | — | — | — | — | — | — |
| 11 | #195 — Vérification runtime Bitmart | pending | — | — | — | — | — | — | — |
| 12 | #195 — Matrice de remplacement | pending | — | — | — | — | — | — | — |
| 13 | #132 — Baseline PnL représentative | pending | — | — | — | — | — | — | — |
| 14 | #188 — Preuve R1-R16 représentative | pending | — | — | — | — | — | — | — |
| 15 | DEMO-005 — Réévaluation | pending | — | — | — | — | — | — | — |
| 16 | OKX-010 — Activation OKX Demo | pending | — | — | — | — | — | — | — |
| 17 | DEMO-006 — Rapport final | pending | — | — | — | — | — | — | — |
<!-- CODEX_ORCHESTRATION_STATE_END -->

## 0. Etat actuel au 16 juillet 2026

| Domaine | Etat | Prochaine preuve requise |
|---|---|---|
| Fake/Paper #196 | 14 scénarios golden exécutables sur 20 | Implémenter les 6 gaps restants, le daily loss cap et la liquidation, puis auditer `20/20`. |
| Inventaire Bitmart #195 | Issue ouverte, inventaire global incomplet | Inventaire statique, vérification runtime dry-run, matrice de remplacement. |
| Baseline PnL #132 | Outillage prêt, dataset local vide | Extraire des lignes certifiées `regular`, `scalper`, `scalper_micro` depuis une base représentative. |
| Recette orchestrateur #188 | Outillée, scénarios critiques déjà exercés, preuve représentative incomplète | Rejouer R1-R16 sur un vrai jeu et consolider les preuves automatiques/guidées. |
| DEMO-005 | Rapport mergé avec décision `blocked` | Réévaluer après les preuves #196/#188/#132 et les runtime-checks exchange. |
| Hyperliquid HL-012 | Code contrôlé mergé, désactivé par défaut | Exécution testnet réelle seulement après décision autorisant la fenêtre. |
| OKX-010 | Prérequis read-only/observabilité mergés | Activation mutative finale bloquée par DEMO-005. |
| Kill switch | Actif | Reste actif hors fenêtre demo/testnet explicitement approuvée. |

Gaps golden exacts restant dans #196 :

1. `fallback_taker_not_implemented`
2. `trailing_stop_not_implemented`
3. `out_of_order_event_injection_not_implemented`
4. `funding_model_not_implemented`
5. `one_way_conflict_guard_not_implemented`
6. `multi_profile_fake_recipe_not_consolidated`

Le fichier est ordonné : prendre le premier prompt actif dont toutes les
préconditions sont satisfaites. Les numéros de PR peuvent être décalés ; toujours
vérifier GitHub avant de créer une branche ou de citer une PR.

---

## 1. Terminologie obligatoire

| Terme | Sens |
|---|---|
| `local_dry_run` | Simulation locale, aucun ordre exchange. |
| `demo` | Environnement OKX Demo avec argent simulé. |
| `testnet` | Environnement Hyperliquid testnet avec fonds fictifs. |
| `mainnet` | Interdit en écriture. |
| `dry_run=true` | Aucun ordre envoyé à l'exchange. |
| `dry_run=false + demo/testnet` | Mutatif seulement si tous les gates passent. |
| `dry_run=false + mainnet` | Toujours interdit. |
| `mainnet_write_enabled` | Toujours `false`. |
| `demo_testnet_write_enabled` | `false` par défaut ; fenêtre explicite uniquement. |
| `OKX_DEMO_*` | Secrets dédiés à OKX Demo, jamais mainnet. |
| `x-simulated-trading: 1` | Header obligatoire pour toute écriture OKX Demo. |
| `HYPERLIQUID_TESTNET_*` | Secrets dédiés au testnet Hyperliquid. |
| `agent/API wallet` | Wallet de signature testnet dédié au bot. |

---

## 2. Socle obligatoire pour tous les prompts

Chaque prompt détaillé ci-dessous inclut intégralement les règles de cette
section. L'agent doit les lire avant toute action.

~~~text
Tu travailles sur le repo pivox/tradingV3.

Langue de réponse : français.

Objectifs :
- réduire les mauvais trades ;
- fiabiliser l'exécution et la donnée ;
- mesurer l'expectancy nette ;
- ne jamais optimiser par "plus de trades".

Contraintes absolues :
- aucun ordre mainnet et aucun fonds réel ;
- OKX mutatif uniquement en environnement demo ;
- Hyperliquid mutatif uniquement en environnement testnet ;
- `mainnet_write_enabled=false` sans exception ;
- aucune écriture demo/testnet sans activation explicite, whitelist, notional
  minimal, kill switch, SL ou compensation fail-safe, audit et rollback ;
- aucun secret mainnet demandé, lu, stocké, loggé ou documenté ;
- aucun secret dans Git, les logs, fixtures, captures ou documents ;
- aucun fallback silencieux vers Bitmart ;
- aucune donnée incomplète transformée en résultat certifié ;
- aucun changement stratégie, MTF, EntryZone ou fréquence, sauf demande
  explicite du prompt ;
- migrations additives et rollback documenté ;
- une capability absente échoue explicitement ;
- un coût absent ou inconnu ne devient jamais zéro implicitement.

Avant de commencer :
1. Lire entièrement les issues et documents référencés.
2. Vérifier `main` à jour et les dépendances réellement mergées.
3. Vérifier qu'aucune autre PR d'implémentation du même lot n'est active.
4. Auditer le code, les tests, les contrats et la documentation existants.
5. Vérifier les numéros réels des PRs : ils peuvent être décalés.
6. Préserver tous les changements locaux non liés et tous les fichiers non suivis.

Workflow GitHub :
1. Une branche et une PR atomiques par prompt.
2. Tests ciblés puis tests élargis proportionnels au risque.
3. Après chaque commit, lancer Codex en TTY et vérifier le quota affiché.
4. Si le quota des cinq heures est épuisé ou proche de 3 %, attendre la
   réinitialisation avant de poursuivre.
5. Après ouverture de la PR, attendre environ 90 secondes avant le premier
   `@codex review` pour éviter une double revue automatique.
6. Lire tous les threads. Corriger les retours pertinents, répondre dans chaque
   thread puis le résoudre.
7. Un retour non applicable reçoit une justification factuelle avant résolution.
8. Après un push de correction, attendre une éventuelle re-review automatique
   avant de redemander une seule review.
9. Ne merger que si la CI est verte, la PR est mergeable, aucun thread n'est
   ouvert et Codex a réagi avec 👍 ou écrit explicitement qu'il ne trouve pas
   de problème majeur sur le head courant.
10. Après merge, mettre `main` local à jour et commenter l'issue avec le livré,
    les tests, les gaps restants et l'absence d'ordre exchange si applicable.
11. Ne fermer une issue que si tous ses critères sont objectivement satisfaits.

Compression :
- dès que le contexte atteint 80 %, compresser en conservant :
  objectif, branche, PR, commits, fichiers, décisions, tests, état CI, threads,
  quota, risques, rollback et prochaine action.

Definition of Done commune :
- tests unitaires ciblés ;
- tests d'intégration si une frontière runtime/DB/HTTP est touchée ;
- PHPStan ou Pytest sur tout le périmètre touché ;
- `php bin/console lint:container --no-debug` si Symfony/DI est touché ;
- `php bin/console lint:yaml config` si YAML est touché ;
- tests PostgreSQL sur une base `test` ou suffixée `_test` si le schéma réel
  intervient ;
- `python3 -m mkdocs build --strict` si la documentation est touchée ;
- scan de secrets/redaction ;
- `git diff --check`.
~~~

---

## 3. Ordre strict des prompts actifs

| Ordre | Prompt | Dépendance de sortie |
|---:|---|---|
| 1 | #196 - Fallback taker de fin de zone | Golden 4 exécutable |
| 2 | #196 - TP1 puis trailing stop | Golden 13 exécutable |
| 3 | #196 - Injection out-of-order | Golden 16 exécutable |
| 4 | #196 - Funding positif/négatif | Golden 18 exécutable |
| 5 | #196 - Garde One-Way | Golden 19 exécutable |
| 6 | #196 - Recette Fake multi-profils | Golden 20 exécutable |
| 7 | #196 - Daily loss cap | Risque journalier Fake fail-closed |
| 8 | #196 - Liquidation guard/model | Liquidation déterministe et auditée |
| 9 | #196 - Audit final Fake/Paper | Catalogue golden `20/20` ou gaps explicites |
| 10 | #195 - Inventaire statique Bitmart | Rapport et diagrammes |
| 11 | #195 - Vérification runtime Bitmart | Usages réels dry-run confirmés |
| 12 | #195 - Matrice de remplacement | Issues filles et conditions de retrait |
| 13 | #132 - Baseline PnL représentative | Métriques certifiées par profil |
| 14 | #188 - Preuve R1-R16 représentative | Rapport consolidé et rollback |
| 15 | DEMO-005 - Réévaluation | Décision pré-mutative formelle |
| 16 | OKX-010 - Activation OKX Demo | Ordre demo minimal protégé |
| 17 | DEMO-006 - Rapport final | Décision demo/testnet finale |

Ne pas sauter directement à OKX-010 parce que son prompt est disponible. Il
reste bloqué tant que DEMO-005 ne rend pas
`ready_for_demo_testnet_trading_attempt`.

---

# Prompts détaillés

## Prompt 1 - #196 Fallback taker de fin de zone

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196
Références : golden scenario 4 `fallback_taker`, PRs #278 à #281.

Objectif :
Implémenter dans Fake/Paper un fallback taker déterministe lorsqu'un ordre maker
arrive en fin de zone ou expire selon une politique explicitement configurée.

Préconditions :
- PR #281 mergée dans `main` ;
- aucun autre lot #196 actif sur le fallback taker ;
- catalogue golden toujours à 14/20 au début du lot.

Comportement attendu :
- l'ordre maker initial reste visible avec son fill partiel éventuel ;
- le reliquat, jamais la quantité initiale complète, peut être converti en ordre
  MARKET taker ;
- l'ordre fallback possède un `client_order_id` déterministe lié au parent ;
- le fallback suit les validations de marge, précision, slippage, coûts,
  idempotence, lineage et protection ordinaires ;
- le fallback est interdit si le signal/ordre est annulé, si le reliquat est nul,
  si la zone est invalide ou si le slippage maximal est dépassé ;
- un replay ne crée ni second ordre ni second fill ;
- aucune politique probabiliste dans ce lot.

Livrables :
- politique/config Fake documentée ;
- service ou branche de matching réutilisant le chemin normal de submit/fill ;
- metadata parent/fallback redacted ;
- golden scenario 4 exécutable ;
- documentation opérateur et rollback.

Tests minimum :
- maker non fillé puis fallback complet ;
- maker partiellement fillé puis fallback du reliquat exact ;
- reliquat nul ;
- slippage guard ;
- marge devenue insuffisante ;
- replay idempotent ;
- restart entre maker et fallback ;
- coûts maker/taker séparés ;
- protection couvrant la taille réellement ouverte.

GitHub :
- PR `Part of #196`, références #278, #279, #280, #281 ;
- après merge, commenter #196 et laisser l'issue ouverte.
~~~

## Prompt 2 - #196 TP1 puis trailing stop

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196
Référence : golden scenario 13 `tp1_then_trailing`.

Objectif :
Ajouter un trailing stop déterministe Fake/Paper activé uniquement après
l'exécution de TP1, sans modifier les règles de stratégie ou les profils live.

Préconditions :
- Prompt 1 mergé ;
- audit des ordres TAKE_PROFIT, STOP_LOSS et TRIGGER existants ;
- politique exacte de TP1 déjà portée par les fixtures Fake.

Comportement attendu :
- TP1 réduit la position de la quantité configurée ;
- le stop initial du reliquat est annulé/remplacé atomiquement ;
- le trailing utilise un sommet/plancher favorable persistant et un offset fixe
  explicite dans la fixture ;
- long : le stop ne peut que monter ; short : il ne peut que descendre ;
- un mouvement défavorable sans nouveau sommet/plancher ne desserre jamais le stop ;
- le déclenchement ferme uniquement le reliquat reduce-only ;
- fermeture complète : annulation des protections restantes ;
- restart/replay conservent le watermark et ne doublonnent aucun événement.

Livrables :
- état trailing versionné et persistant ;
- transitions lifecycle normalisées ;
- fixtures long et short ;
- golden scenario 13 exécutable ;
- documentation de l'algorithme et rollback.

Tests minimum :
- TP1 partiel puis création trailing ;
- progression favorable long/short ;
- absence de desserrage ;
- gap au niveau trailing ;
- duplicate price event ;
- restart avec trailing actif ;
- race TP1/SL ;
- clôture et nettoyage des ordres ;
- coûts/PnL sans double comptage.

GitHub :
- PR `Part of #196` ;
- commenter #196 après merge, sans fermer l'issue.
~~~

## Prompt 3 - #196 Injection d'événements out-of-order

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196
Référence : golden scenario 16 `duplicate_out_of_order_event`.

Objectif :
Etendre le simulateur private WS Fake pour injecter des événements dupliqués et
hors ordre, puis prouver une resynchronisation déterministe sans perte.

Préconditions :
- Prompt 2 mergé ;
- conserver le protocole de séquence/resync livré par #274 ;
- auditer normalizer, projector, state store et acquittement.

Comportement attendu :
- fixture explicite pour permuter une séquence finie d'événements ;
- un doublon exact est idempotent ;
- un événement futur crée un gap et impose `resync_required` ;
- aucun événement après gap n'est projeté avant snapshot/resync ;
- le snapshot REST simulé reconstruit l'état canonique ;
- les événements déjà projetés ne sont pas dupliqués ;
- un payload conflictuel portant la même séquence échoue explicitement ;
- aucun tri arbitraire par timestamp seul.

Livrables :
- DSL/fixture out-of-order persistable ;
- métriques/audit gap, duplicate, conflict et resync ;
- golden scenario 16 exécutable ;
- documentation opérateur.

Tests minimum :
- duplicate exact ;
- 1,3,2 avec resync ;
- même séquence, payload conflictuel ;
- crash avant acquittement ;
- retry après rollback DB ;
- batch multi-projections atomique ;
- restart avec `resync_required`.

GitHub :
- PR `Part of #196`, référence #274 ;
- laisser #196 ouverte après merge.
~~~

## Prompt 4 - #196 Modèle de funding

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196
Référence : golden scenario 18 `funding`.

Objectif :
Ajouter un modèle de funding déterministe, exchange-neutral et persistant pour
les positions perpétuelles Fake/Paper.

Préconditions :
- Prompt 3 mergé ;
- auditer `fill_cost_ledger`, DTOs de funding et conventions de signe/de devise ;
- utiliser l'horloge contrôlée Fake.

Comportement attendu :
- événements de funding à échéances explicites, jamais dérivés d'une fenêtre
  temporelle approximative ;
- montant basé sur notional de position, taux connu et intervalle connu ;
- convention : montant positif crédité, montant négatif débité ;
- long et short couverts ;
- funding absent reste inconnu, pas zéro ;
- idempotence exacte par position + échéance + version de modèle ;
- funding persisté dans le ledger avec lineage `internal_trade_id` si disponible ;
- funding ne crée pas de fill d'entrée/sortie ;
- restart et événements en retard ne doublonnent pas le coût.

Livrables :
- modèle/config versionnés ;
- projection ledger et événements normalisés ;
- fixtures funding positif, négatif et absent ;
- golden scenario 18 exécutable ;
- documentation conventions monétaires.

Tests minimum :
- long paie/reçoit ;
- short paie/reçoit ;
- position partielle à l'échéance ;
- aucune position ;
- duplicate et out-of-order ;
- devise connue/inconnue ;
- restart/relecture ;
- impact PnL net sans double comptage.

GitHub :
- PR `Part of #196`, `Related to #190` ;
- laisser #196 ouverte après merge.
~~~

## Prompt 5 - #196 Garde de conflit One-Way

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196
Référence : golden scenario 19 `one_way_conflict`.

Objectif :
Faire respecter un mode One-Way déterministe par `exchange + market_type +
symbol`, sans hedge implicite.

Préconditions :
- Prompt 4 mergé ;
- auditer les clés de position et la résolution `positionSide`.

Comportement attendu :
- une position LONG ouverte bloque une nouvelle entrée SHORT non reduce-only ;
- une position SHORT ouverte bloque une nouvelle entrée LONG non reduce-only ;
- les ordres reduce-only du côté de sortie restent autorisés ;
- une position plate permet ensuite le côté opposé ;
- les ordres actifs incompatibles participent au conflit ;
- le rejet précède toute réservation de marge ou création d'ordre ;
- le rejet est structuré, persisté, redacted et idempotent ;
- aucun netting arbitraire et aucun fallback hedge.

Livrables :
- garde centrale Fake/Paper ;
- raison stable de rejet ;
- golden scenario 19 exécutable ;
- documentation du mode supporté.

Tests minimum :
- long puis short rejeté ;
- short puis long rejeté ;
- reduce-only autorisé ;
- ordre opposé après clôture ;
- ordre actif sans position ;
- replay du rejet ;
- restart ;
- symboles différents indépendants.

GitHub :
- PR `Part of #196` ;
- laisser #196 ouverte après merge.
~~~

## Prompt 6 - #196 Recette Fake multi-profils

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196
Références : golden scenario 20 `dry_run_multi_profiles_same_symbol`, #188.

Objectif :
Consolider une recette reproductible où `regular`, `scalper` et
`scalper_micro` ciblent le même symbole Fake en dry-run, sans collision ni effet
exchange.

Préconditions :
- Prompt 5 mergé ;
- runner #188 et fixtures multi-profils disponibles ;
- `dry_run=true` forcé.

Comportement attendu :
- trois sets distincts avec lineage/config hash propres ;
- locks orchestration et métier visibles séparément ;
- aucun set perdu et aucun résultat masqué ;
- conflits d'activité explicitement `skipped` ou `blocked` selon le contrat ;
- deux dry-runs sans effet de bord peuvent coexister si le contrat le permet ;
- aucune écriture vers OKX, Hyperliquid ou Bitmart ;
- rapport agrégé déterministe et redacted ;
- replay idempotent.

Livrables :
- fixture et commande uniques ;
- rapport JSON/Markdown de recette ;
- golden scenario 20 exécutable ;
- documentation des règles de contention.

Tests minimum :
- trois profils même symbole ;
- symboles distincts ;
- set désactivé ;
- conflit lock ;
- replay ;
- exécution parallèle bornée ;
- preuve d'absence d'appel exchange ;
- restart du runner.

GitHub :
- PR `Part of #196`, `Related to #188` ;
- laisser #196 ouverte après merge.
~~~

## Prompt 7 - #196 Daily loss cap Fake/Paper

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196

Objectif :
Ajouter un daily loss cap déterministe au compte Fake/Paper, appliqué avant toute
nouvelle augmentation d'exposition.

Préconditions :
- Prompt 6 mergé ;
- auditer le PnL réalisé, les coûts certifiés et l'horloge Fake.

Comportement attendu :
- journée définie en UTC par l'horloge contrôlée ;
- perte journalière basée uniquement sur PnL réalisé net et coûts connus ;
- si un coût nécessaire est inconnu, état `not_computable` et blocage fail-closed ;
- le cap bloque les nouvelles entrées et augmentations ;
- les réductions, SL, TP et fermetures d'urgence restent autorisés ;
- cap et consommation persistés/reconstruits depuis les événements, sans compteur
  mutable non auditable ;
- nouveau jour UTC réinitialise la fenêtre, pas l'historique ;
- rejet structuré avec limite, consommation et date, sans secret.

Livrables :
- policy/config versionnée ;
- runtime-check exposant ready/not-ready ;
- audit et métriques ;
- documentation opérateur et rollback.

Tests minimum :
- sous le cap ;
- cap exactement atteint ;
- cap dépassé par PnL ;
- cap dépassé par frais/funding ;
- coût inconnu ;
- réduction autorisée ;
- passage minuit UTC ;
- restart et replay.

GitHub :
- PR `Part of #196` ;
- laisser #196 ouverte après merge.
~~~

## Prompt 8 - #196 Liquidation guard et modèle de liquidation

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196

Objectif :
Ajouter pour Fake/Paper un modèle de liquidation v1 déterministe et un guard
préventif basé sur les metadata instrument déjà disponibles.

Préconditions :
- Prompt 7 mergé ;
- `maintenance_margin_rate`, leverage, contract size et balances connus ;
- horloge et prix mark Fake contrôlables.

Décision de modèle v1 :
- supporter le calcul de liquidation pour marge isolée ;
- déclarer explicitement le calcul cross `unsupported` tant qu'un modèle de
  portefeuille n'est pas livré ;
- utiliser le mark price, jamais le dernier trade seul ;
- maintenir un buffer de guard distinct du seuil de liquidation ;
- tous les montants sont calculés avec les primitives décimales du projet.

Comportement attendu :
- préflight refuse une entrée dont le prix de liquidation ou le buffer est
  invalide/inconnu ;
- mouvement du mark dans la zone guard produit une alerte sans liquider ;
- franchissement du seuil liquide la position via un événement/fill dédié ;
- liquidation fee connue séparée des autres coûts ;
- annulation des protections devenues obsolètes ;
- lifecycle, ledger, balance et position restent atomiques ;
- replay/restart ne créent pas une seconde liquidation.

Livrables :
- calculateur et policy versionnés ;
- événements/ledger `liquidation` ;
- runtime-check ;
- documentation des limites isolé/cross et rollback.

Tests minimum :
- liquidation long et short ;
- zone guard sans liquidation ;
- gap au-delà du seuil ;
- frais de liquidation ;
- cross explicitement unsupported ;
- metadata inconnue ;
- protections nettoyées ;
- restart/replay ;
- coût/PnL net sans double comptage.

GitHub :
- PR `Part of #196` ;
- laisser #196 ouverte après merge.
~~~

## Prompt 9 - #196 Audit final Fake/Paper et golden 20/20

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #196
Référence : #195.

Objectif :
Auditer l'ensemble Fake/Paper après les Prompts 1 à 8, rendre les vingt scénarios
golden réellement exécutables ou documenter factuellement tout critère restant.

Préconditions :
- Prompts 1 à 8 mergés ;
- aucune PR #196 active ;
- `main` et le catalogue golden à jour.

Scope :
- exécuter deux fois chaque scénario depuis un état frais avec seed/horloge fixes ;
- vérifier restart, idempotence, coûts, protections, redaction et absence réseau ;
- vérifier la capability matrix, runtime-check, documentation et rollback ;
- auditer lifecycle/fills/coûts/protections contre les critères complets de #196 ;
- produire une matrice critère -> preuve -> statut ;
- ne pas passer un scénario en executable si sa preuve n'exerce pas réellement le
  comportement.

Livrables :
- catalogue golden `20/20` si objectivement atteint ;
- rapport final Fake/Paper ;
- liste exacte des divergences et follow-ups ;
- mise à jour de #196 et entrée pour la matrice #195.

Tests :
- suites Exchange/Fake/Provider/TradeEntry/TradingCore ;
- PHPStan tous fichiers touchés ;
- lint container/YAML ;
- MkDocs strict ;
- scan réseau/secrets ;
- restart sur état fichier ;
- PostgreSQL si ledger réel.

GitHub :
- PR `Part of #196`, `Related to #195` ;
- fermer #196 uniquement si tous les critères sont couverts ;
- sinon commenter la liste exacte restante et laisser ouverte.
~~~

## Prompt 10 - #195 Inventaire statique Bitmart

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #195
Références : #173, #187, #196.

Objectif :
Inventorier statiquement toutes les dépendances Bitmart sans supprimer ni
modifier le comportement runtime.

Préconditions :
- Prompt 9 mergé ou état final #196 explicitement documenté ;
- lire entièrement #195 et les documents d'architecture.

Scope :
- registry/DI, REST public/privé, WS public/privé, metadata/précision ;
- lifecycle ordre/position/SL/TP ;
- risk/leverage/liquidation ;
- Temporal, Messenger, CLI, cron et scripts ;
- persistance, audit, frontend, YAML/env et tests ;
- recherche texte et usages indirects via interfaces/aliases.

Classification obligatoire :
`BITMART_CORE_REQUIRED`, `BITMART_GATEWAY_SPECIFIC`,
`SHARED_BUT_LEAKING_BITMART`, `LEGACY_ACTIVE`, `LEGACY_UNUSED`,
`DEAD_CODE_CANDIDATE`, `UNKNOWN_REQUIRES_RUNTIME_CHECK`.

Livrables :
- rapport d'inventaire ;
- table usage, criticité, consommateurs, remplacement, condition de retrait ;
- diagrammes PlantUML provider, ordre/position, WS/REST, config, workflows ;
- liste des fallbacks implicites ;
- aucun changement runtime.

Tests/validation :
- recherche `bitmart` expliquée pour chaque usage pertinent ;
- validation des liens/classes/services ;
- MkDocs strict ;
- scan secrets ;
- diff check.

GitHub :
- PR documentation `Part of #195`, `Related to #196` ;
- laisser #195 ouverte.
~~~

## Prompt 11 - #195 Vérification runtime Bitmart en dry-run

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #195
Références : Prompt 10, #188.

Objectif :
Compléter l'inventaire statique par une observation runtime strictement dry-run.

Préconditions :
- Prompt 10 mergé ;
- stack Docker/Temporal/Symfony disponible ;
- aucune credential mainnet nécessaire à la recette ;
- kill switch actif.

Scope :
- tracer services instanciés, providers résolus, endpoints/commands appelés ;
- configs effectivement chargées, tables écrites, topics WS, fallbacks et erreurs ;
- relier chaque observation à une ligne de l'inventaire statique ;
- utiliser des traces redacted et des corrélations de run ;
- aucun submit/cancel exchange réel.

Livrables :
- rapport runtime et preuves redacted ;
- écarts static/runtime ;
- usages dynamiques confirmés ;
- éléments inconnus classés ;
- procédure de reproduction et nettoyage.

Tests/validation :
- preuve que `dry_run=true` est forcé ;
- preuve qu'aucun transport write n'est appelé ;
- cohérence des run IDs et timestamps ;
- vérification des fichiers de preuve sans secret ;
- MkDocs strict et diff check.

GitHub :
- PR `Part of #195`, `Related to #188` ;
- laisser #195 ouverte.
~~~

## Prompt 12 - #195 Matrice de remplacement et issues filles

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #195
Références : Prompts 10-11, #196, #197, #198.

Objectif :
Produire la matrice de remplacement Bitmart vers TradingCore, Fake/Paper, OKX et
Hyperliquid, puis créer les issues atomiques nécessaires. Ne supprimer aucun code.

Préconditions :
- Prompts 10 et 11 mergés ;
- preuves runtime relues ;
- état final #196 connu.

Matrice minimale :
contracts, market data, balance, leverage, submit/cancel, order status, fills,
positions, stop, TP, funding, WS public/private, precision, runtime-check, rate
limit, idempotence, audit.

Pour chaque capability :
- implémentation Bitmart ;
- port TradingCore ;
- couverture Fake/OKX/Hyperliquid ;
- divergence ;
- criticité ;
- issue bloquante ;
- condition de migration et rollback.

Livrables :
- matrice versionnée ;
- conditions de retrait ;
- liste ordonnée d'issues filles ;
- documentation opérateur finale #195 ;
- aucune suppression et aucun changement de défaut exchange.

Tests/validation :
- chaque capability critique possède une ligne, une preuve et un statut ;
- chaque gap bloquant pointe vers une issue existante ou nouvellement créée ;
- aucun remplacement `complete` si la preuve Fake/OKX/Hyperliquid est partielle ;
- liens, diagrammes et références validés ;
- scan secrets, MkDocs strict et diff check.

GitHub :
- PR documentation `Part of #195` ;
- fermer #195 uniquement si l'inventaire, la vérification runtime et la matrice
  couvrent tous les critères ; sinon laisser ouverte avec gaps exacts.
~~~

## Prompt 13 - #132 Baseline PnL sur données certifiées

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #132
Références : #190, #188, rapports baseline existants.

Objectif :
Exécuter l'export déjà livré sur une base contenant des données certifiées
représentatives, puis quantifier les résultats sans tuning dans cette PR.

Précondition bloquante :
- une base PostgreSQL représentant réellement `regular`, `scalper` et
  `scalper_micro` avec lineage, lifecycle et coûts exploitables ;
- si les lignes certifiées sont absentes, produire `blocked` et ne pas inventer
  de métriques.

Scope :
- extraction v2/ledger certifiée, sans rapprochement symbole/temps ;
- génération Markdown/JSON/CSV redacted ;
- winrate et intervalle de Wilson ;
- expectancy nette, profit factor, drawdown, MFE/MAE ;
- coûts, maker/taker, direction, profil, exchange et causes de pertes ;
- segmentation explicite des lignes exclues/partielles/inconnues ;
- Monte Carlo uniquement si l'échantillon est suffisant selon l'outil existant.

Livrables :
- artefacts datés et reproductibles ;
- résumé des limites de l'échantillon ;
- issues filles pour les causes réellement quantifiées ;
- aucun changement YAML/stratégie dans cette PR.

Tests :
- exécuter les tests du générateur ;
- valider JSON/CSV ;
- PostgreSQL sur copie/read-only ou base dédiée ;
- scan secrets ;
- MkDocs strict et diff check.

GitHub :
- PR `Part of #132`, références #188/#190 ;
- fermer #132 uniquement si les trois profils sont couverts avec données
  certifiées et conclusions relues.
~~~

## Prompt 14 - #188 Preuve R1-R16 sur stack représentative

~~~text
Applique intégralement le socle obligatoire de la section 2.

Issue principale : #188
Références : #132, #196, runner `runtime_recipe_runner.py`.

Objectif :
Rejouer R1-R16 sur une stack représentative et consolider les preuves
automatiques et guidées dans un rapport versionné.

Préconditions :
- dataset représentatif disponible ;
- Docker, orchestrateur, Symfony, PostgreSQL et Temporal disponibles ;
- migrations appliquées ;
- sets `regular`, `scalper`, `scalper_micro` en `dry_run=true` ;
- aucun secret live requis.

Scope :
- exécuter R1-R16 avec le runner mergé ;
- R6 : panne Symfony contrôlée ;
- R10 : crash/reclaim réel, sans modifier arbitrairement la production ;
- R15 : schedule Temporal réel, paused/dry-run ;
- R16 : pause/reprise/rollback mesuré ;
- collecter run IDs, run_sets, cockpit, Temporal et exports redacted ;
- relier le rapport automatique aux preuves guidées ;
- créer des issues atomiques pour tout écart.

Décisions possibles :
`blocked`, `ready_for_parallel_observation`, `ready_to_replace_legacy`.

Contraintes :
- aucun scénario mutatif exchange ;
- aucun PASS si le scénario n'a pas été réellement exécuté ;
- garder les anciens schedules actifs tant que la décision ne le permet pas.

Livrables :
- rapport automatique R1-R16 et preuves guidées consolidées ;
- exports JSON redacted, run IDs et références cockpit/Temporal ;
- chronologie du rollback avec durée mesurée ;
- matrice scénario -> preuve -> statut -> écart ;
- décision finale et issues atomiques pour les écarts.

Tests/validation :
- runner et fixtures Pytest ;
- santé Docker/Temporal/Symfony avant recette ;
- vérification PostgreSQL des runs, run_sets, claims et locks ;
- validation JSON et scan secrets ;
- MkDocs strict et diff check.

GitHub :
- PR de rapport `Part of #188`, `Related to #132`, `Related to #196` ;
- fermer #188 uniquement si les critères d'acceptation sont couverts.
~~~

## Prompt 15 - DEMO-005 Réévaluation de la décision pré-mutative

~~~text
Applique intégralement le socle obligatoire de la section 2.

Références : DEMO-005 mergée par #254, #188, #196, #197, #198.

Objectif :
Réévaluer le rapport pré-mutatif existant avec les preuves actuelles. Cette PR
est documentaire et read-only.

Préconditions :
- Prompt 9 audité ;
- Prompt 14 terminé avec rapport relu ;
- runtime-checks OKX et Hyperliquid rejoués avec credentials dédiés ;
- kill switch et rollback testés ;
- observabilité privée confirmée.

Scope :
- recalculer le hash de preuve ;
- vérifier Fake/Paper, R1-R16, readiness, credentials, whitelist, notional,
  SL/compensation, observabilité, audit et rollback ;
- distinguer OKX et Hyperliquid ;
- conserver chaque manque en `blocked`, jamais en PASS implicite.

Décisions possibles :
- `blocked`
- `ready_for_demo_testnet_trading_attempt`

Livrables :
- rapport Markdown et JSON redacted ;
- matrice gate -> preuve -> statut ;
- décision signée par le commit/config hash ;
- prochaine action et rollback.

Tests :
- générateur/validation du rapport ;
- absence de secret ;
- aucune mention mainnet-ready ;
- MkDocs strict et diff check.

GitHub :
- PR `Related to #188`, `Related to #196`, `Related to #197`, `Related to #198` ;
- ne lancer OKX-010 que si la décision exacte est
  `ready_for_demo_testnet_trading_attempt`.
~~~

## Prompt 16 - OKX-010 Activation mutative finale OKX Demo

~~~text
Applique intégralement le socle obligatoire de la section 2.

Objectif :
Autoriser un ordre minimal contrôlé sur OKX Demo uniquement, jamais mainnet.

Préconditions obligatoires :
- Prompt 15 = `ready_for_demo_testnet_trading_attempt` ;
- #188 couvert sur stack représentative ;
- runtime-check OKX = `demo_testnet_candidate` ;
- credentials `OKX_DEMO_*` disponibles ;
- private WS/read reconciliation opérationnels ;
- whitelist et max_notional minimal définis ;
- kill switch testé ;
- fenêtre opérateur explicitement validée.

Activation requise :
- `DEMO_TRADING_ENABLED=true`
- `OKX_DEMO_TRADING_ENABLED=true`
- `environment=demo`
- `mainnet_write_enabled=false`
- `demo_testnet_write_enabled=true`
- kill switch OKX explicitement désactivé pour la fenêtre
- `x-simulated-trading: 1` sur chaque requête

Scope :
- submit/cancel minimal nécessaire à la recette ;
- URL et flags production refusés avant transport ;
- client order ID et idempotence obligatoires ;
- SL immédiatement attaché ou compensation fail-safe ;
- audit redacted complet ;
- reconciliation REST/private WS ;
- rollback immédiat réactivant le kill switch et les flags false.

Livrables :
- transport write OKX Demo gardé et audité ;
- commande/runbook start, vérification, stop et rollback ;
- preuve redacted de l'ordre minimal et de sa protection ;
- IDs de corrélation permettant la reconciliation ;
- rapport d'incident si la tentative échoue.

Tests :
- mainnet toujours bloqué ;
- mauvais endpoint/header/credentials bloqués ;
- kill switch, whitelist, notional, SL et observabilité bloquants ;
- fake transport complet ;
- une tentative réelle OKX Demo uniquement après validation opérateur ;
- fill/cancel/protection/reconciliation ;
- restart/replay.

GitHub :
- ne merger qu'avec preuves de la fenêtre, CI verte et validation Codex ;
- commenter les issues liées avec les IDs redacted et le résultat ;
- aucun secret dans la PR.
~~~

## Prompt 17 - DEMO-006 Rapport final d'exécution demo/testnet

~~~text
Applique intégralement le socle obligatoire de la section 2.

Objectif :
Produire le rapport final après les tentatives contrôlées OKX Demo et
Hyperliquid testnet.

Préconditions :
- Prompt 16 terminé ;
- HL-012 activé uniquement dans une fenêtre testnet validée ;
- preuves de tentative disponibles pour les deux exchanges ou blocage explicite.

Rapport Markdown et JSON :
- version code et config hash ;
- exchange/environment ;
- runtime-check avant/après ;
- état kill switch ;
- ordre minimal, fill/cancel ;
- SL attaché ou compensation/quarantaine ;
- reconciliation REST/WS ;
- lineage/audit IDs ;
- coûts connus/inconnus ;
- rollback testé et durée ;
- incidents et écarts ;
- aucune valeur secrète.

Décisions possibles :
- `blocked`
- `demo_testnet_execution_validated`
- `demo_testnet_execution_failed`
- `needs_fix_before_next_run`

Contraintes :
- aucun statut mainnet-ready ;
- ne pas agréger deux exchanges si l'un manque de preuve ;
- une tentative non exécutée reste `blocked` ;
- relier à `position_trade_analysis_v2` uniquement si la certification est
  réellement disponible.

Tests :
- schéma JSON ;
- scan secrets ;
- cohérence IDs/hash ;
- MkDocs strict ;
- diff check.

GitHub :
- PR documentation finale ;
- mettre à jour les issues concernées ;
- ne fermer la roadmap demo/testnet que si toutes les preuves sont complètes.
~~~

---

## 4. Etat final attendu

~~~text
- Fake/Paper couvre réellement les 20 scénarios golden.
- Les capacités et divergences Bitmart sont inventoriées sans suppression.
- Une baseline PnL factuelle existe pour les trois profils.
- R1-R16 sont prouvés sur une stack représentative.
- DEMO-005 autorise ou bloque explicitement la tentative.
- OKX ne peut écrire qu'en Demo avec le header simulé.
- Hyperliquid ne peut écrire qu'en testnet avec un agent dédié.
- Aucun chemin mainnet write n'existe.
- Toute position demo/testnet possède un SL ou une compensation fail-safe.
- Le rapport final ne déclare jamais mainnet-ready.
~~~

---

## 5. Non-objectifs

~~~text
- trading mainnet ;
- fonds réels ;
- augmentation de fréquence ;
- tuning stratégie avant baseline factuelle ;
- suppression Bitmart dans ces prompts ;
- promesse de rentabilité ;
- PnL certifié à partir de données incomplètes ;
- contournement d'un gate pour obtenir un PASS.
~~~

---

## 6. Références officielles pour les docs opérateur

- OKX API Demo Trading : https://my.okx.com/docs-v5/en/
- OKX FAQ clés API demo : https://www.okx.com/en-eu/help/api-faq
- Hyperliquid API testnet : https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api
- Hyperliquid nonces/API wallets : https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/nonces-and-api-wallets
- Hyperliquid exchange endpoint : https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/exchange-endpoint
- Hyperliquid info endpoint : https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint

---

# Annexe A - Historique compact des lots mergés

Cette annexe est informative. Aucun lot ci-dessous ne doit redevenir un prompt
actif sans nouveau gap factuel.

| Série | PRs / merges | Résultat compact |
|---|---|---|
| Socle commun | #221 `45ff518`, #222 `9cb132f`, #223 `b801a6b`, #224 `0e61c01`, #225 `49d45fb`, #226 `280837e` | Safety envelope, config effective, readiness, kill switch, observabilité privée et minimum Fake/Paper. |
| OKX read-only/dry-run | #227 `6313c55` à #235 `c103c1b` | Capabilities, providers, REST public/privé, metadata, normalizers, payload preview, runtime-check et recette orchestrateur. |
| Hyperliquid read-only/dry-run | #236 `5724aab` à #249 `0b62049` hors numéros réservés | Capabilities, providers, market/account reads, signer isolé, nonce, metadata, lifecycle, preview et recette orchestrateur. |
| Demo orchestration | #250 `d90b3dd`, #251 `e524424`, #252 `79acc0c`, #253 `0026613`, #254 `221f25c` | Fixtures, recette double exchange, runbook, schedule gardé et rapport DEMO-005 `blocked`. |
| Hyperliquid contrôlé | #259 `ede6c98` | HL-012 mergé, désactivé par défaut, aucune tentative réelle prouvée dans ce lot. |
| OKX prérequis | #260 `6ac0840`, #261 `a736a875`, #262 `a659f5a5` | Candidate read-only, private WS/reconciliation et endpoint EEA validés sans ordre. |
| Prépreuve data | #263 `da94f19c` | Bases de test isolées et schéma restauré ; dataset représentatif toujours absent. |
| Fake/Paper #196 | #274 `d7f93c13`, #275 `a7216f1c`, #276 `75c85254`, #277 `0c513bfe`, #278 `685bb926`, #279 `d0ee8fb4`, #280 `c19cd1f0`, #281 `a7bbad86` | WS/resync, recovery, faults, runtime-check, golden suite, instrument/risk, slippage et compensation SL ; état 14/20. |

Note de numérotation : #244 et #247 sont des PRs documentation/roadmap hors
prompts actifs. Toujours vérifier l'état GitHub réel avant de citer une nouvelle
PR.
