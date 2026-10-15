# H2 Gate 1 (étude d'effet, Amendement A1) : verdict FAIL, pas dû au seul G4

**Statut au 2026-10-15T17:11:30Z : verdict `FAIL`, `fail_due_to_G4_alone = false`.** La première exécution de l'étape
`events` a été arrêtée par le garde-fou disque (16:16:33Z, aucune sortie). La reprise unique prévue par le protocole
(§12) a abouti à 16:56:33Z. L'étape `outcomes` a ensuite été exécutée une fois, sans autre décision, et a abouti à
17:11:30Z. Cinq critères échouent (G2, G3, G4, G5, G7) ; seuls G1 et G6 passent. Même si G4 passait, le verdict
resterait FAIL.

**Étiquettes (protocole §2) :** **développement** ; **archive OKX déjà lue** au niveau bougies (test swing v2 OKX,
PR #500, pour les familles hors programme ; bases du programme lues sur Binance) ; **pas une validation
indépendante**. Les fenêtres β de 60 jours sont désormais consommées elles aussi. Ce FAIL n'autorise **aucune**
proposition de Gate 2 ; le trading n'était de toute façon autorisé par aucun résultat de Gate 1.

## 1. Références figées

| Élément | Valeur |
|---|---|
| Protocole | `docs/handbook/research/PREREGISTRATION-H2-GATE1.md`, `FROZEN 2026-10-15T15:57:04Z` (avec l'Amendement A1) |
| sha256 du corps | `0ec71c563a6247464765fd0696ceb193d60d980e97d93d6f53d0565940d8bf20` |
| Empreinte du code (`research_h2` + `research_swing2/okx.py` + `okx_universe.py`) | `e8c087a1a1ee105c81d2f9f06d79b966f1db52a43ffd293860e1a8f6c8c41904` |
| Environnement | `tradingV3-private/research/20261014T090000Z-swing-v2-runtime/bin/python`, Python 3.12.3 (main, Aug 31 2026, 10:18:26) [GCC 13.3.0], numpy 2.5.4 |
| Décisions enregistrées dans les claims | `g4_rule=point_estimate`, `c2_caliper=none`, `c2_candidates=first_crossing`, `c2_pool=three_months` |
| Feu vert du propriétaire (texte enregistré, identique dans les deux claims) | « owner go 2026-10-15: execute the H2 Gate 1 effect study once, exactly as frozen with Amendment A1 (FROZEN 2026-10-15T15:57:04Z); report the verdict even if negative; a PASS only authorises a Gate 2 proposal, never trading » |
| Confirmation du propriétaire après l'interruption | 2026-10-15 ≈ 18:39 heure de Paris : « Check sur l'espace disque et continue » (reprise unique de `events`, puis `outcomes`, tels que figés) |

Entrées vérifiées par les deux claims (égales aux constantes de `protocol.py`) : `manifest-h2-okx-candles.json`
`95794fdd…6f0d00` (1 016 zips H2, chacun conforme au manifeste), `manifest-candles.json` `516e7183…9c5d01`,
`daily-quote-volume.json` `7c262b44…beedd`, `eea-futures.json` `732044a8…c44f8d`, 9 665 fichiers npz swing v2
(empreinte de la liste `b99f42f4…acc22`).

## 2. Première tentative : arrêt par le garde-fou disque (conservé tel que rapporté à 16:24Z)

Répertoire de sortie : `tradingV3-private/research/20261015T1200Z-h2-gate1/run/`.

| Heure (UTC) | Événement | Source |
|---|---|---|
| 15:57:04 | Gel du protocole (A1) | ligne `FROZEN` |
| 16:02:02 | Claim `events` écrite | `events-claim.json`, sha256 `7c612faab328a8c1d5c3f2881446639b45d554d8c2821b368074406da6f1c2cd` |
| ≈ 16:02:15 | Démarrage du processus `cli events` (`nice -n 15`, un processus) | 14:17.53 écoulés, fin à 16:16:33 |
| 16:03:55 | `consume()` : re-vérification du protocole, du code, de l'environnement et des entrées, puis création exclusive de l'enregistrement de consommation | `events-consumed.json` (`started_at`) |
| 16:16:33 | Arrêt par le garde-fou : `guard: available memory 8306 MB, free disk 18 GB`, code de sortie 3 | `events-consumed.json` (version de 16:16:33, sha256 `fa028b43c7801ac80dcda08f66fb1de65933838586223d3e48f28b65302fd642`) ; journal `events.log` sha256 `de2f69d0…290947` |

Mesures du processus (`/usr/bin/time`) : 733,84 s CPU utilisateur, 520 856 kB de mémoire résidente maximale,
24 blocs écrits au total. La mémoire n'était pas en cause (8 306 MB disponibles à l'arrêt, plancher 4 000 MB).

**Rien n'avait été vu.** Le garde-fou n'est appelé que dans le chargement des données (`universe.gather` et la
boucle de `events.load_closes`), avant `events.compute`. Aucun β, résidu, z-score, événement, appariement C2 ou tirage
C1 n'avait été calculé ; aucun fichier de sortie n'existait.

**Le disque.** Le plancher du protocole est 20 Go, calculé comme `shutil.disk_usage().free // 1024**3`, donc
20 GiB. L'exécution n'avait écrit que 24 blocs ; la baisse d'espace libre venait d'un autre processus de la machine,
non identifié. Relevés de l'opérateur pendant cette tentative, non conservés dans un fichier (donc non vérifiables) :
≈ 51 Go libres au départ, 18 Go à l'arrêt, 16,6 Go à 16:20:24Z ; 37,3 Go à 16:24:09Z.

## 3. Reprise unique et étape `outcomes`

### 3.1 Vérifications avant la reprise

- Hash du corps du protocole recalculé : `0ec71c56…bf20`, égal à la ligne `FROZEN` ; copie privée du protocole
  identique à celle du worktree.
- Empreinte du code (`cli digest`) : `e8c087a1…1904`, égale au gel. Environnement : Python 3.12.3, numpy 2.5.4.
- Règle de reprise (§12) : seule une étape `events` arrêtée par le garde-fou avant toute écriture peut reprendre, une
  fois (`MAX_GUARD_RESUMPTIONS = {"events": 1, "outcomes": 0}`). Garde-fou : 4 000 MB de mémoire disponible, 20 GiB
  de disque libre.
- État précédent : `events-consumed.json` à `aborted_by_guard` (16:16:33Z), aucune sortie, 0 reprise sur 1.
- Disque : à 16:40:44Z, **77 Go libres** (seuil opérationnel fixé avant le départ : 40 Go), donc aucune attente ;
  8 197 MB de mémoire disponible. Le code `events` n'écrit aucun fichier temporaire : la baisse à 18 Go de la
  première tentative venait d'ailleurs.

### 3.2 Déroulement (chaque chiffre vient d'un fichier haché)

| Heure (UTC) | Événement | Source |
|---|---|---|
| 16:40:44 | Premier relevé disque/mémoire (77 Go libres, 8 197 MB disponibles) | `monitor-resume.log` |
| 16:40:49 | Démarrage de `nice -n 15 …/bin/python -m scripts.research_h2.cli events --resume` (un processus) | `events-resume.log` |
| 16:41:19 | Reprise enregistrée : `resumptions: [{"at": "2026-10-15T16:41:19Z", "after": "guard: available memory 8306 MB, free disk 18 GB"}]` | `events-consumed.json` |
| 16:56:33 | `events` : statut `complete`, `h2-events.json` écrit ; code de sortie 0 | `events-consumed.json`, `events-resume.log` |
| 16:57:13 | Claim `outcomes` écrite, même référence de feu vert, prérequis `h2-events.json` `b537fde8…` (fichier non inspecté avant la claim) | `outcomes-claim.json` |
| 16:57:19 | Démarrage de `nice -n 15 …/bin/python -m scripts.research_h2.cli outcomes` | `outcomes-run.log` |
| 16:57:43 | `consume()` de l'étape `outcomes` (`started_at`) | `outcomes-consumed.json` |
| 17:11:30 | `outcomes` : statut `complete`, `h2-outcomes.json` et `h2-gate1-result.json` écrits ; code de sortie 0 | `outcomes-consumed.json`, `outcomes-run.log` |
| 17:11:40 | Dernier relevé (« monitor stopped ») | `monitor-resume.log` |

Mesures (`/usr/bin/time`) :

| Étape | Durée | CPU utilisateur + système | Mémoire résidente max. |
|---|---|---|---|
| `events --resume` | 15:43.47 | 911,08 + 13,66 = 924,7 s | 535 404 kB |
| `outcomes` | 14:11.91 | 823,65 + 14,24 = 837,9 s | 537 128 kB |

Le garde-fou ne s'est jamais déclenché ; aucun plantage.

### 3.3 Fichiers (tous dans `tradingV3-private/research/20261015T1200Z-h2-gate1/run/`)

| Fichier | UTC | sha256 |
|---|---|---|
| `events-claim.json` | claim 16:02:02Z | `7c612faab328a8c1d5c3f2881446639b45d554d8c2821b368074406da6f1c2cd` |
| `events-consumed.json` | `complete` 16:56:33Z | `c01dc3752e292e25514a9adf22d7cd83da9fa0c0ed886ed53d20ba51a4f5c5c1` |
| `h2-events.json` | 16:56:33Z | `b537fde8aec07091a8892c28d4d48f4632c5f365c075da92924ac0360bcc380e` |
| `outcomes-claim.json` | claim 16:57:13Z | `c9e560330d8a545bb1c11c2ef682a2bb936885e3ef91261ee6c2e36a47c05e71` |
| `outcomes-consumed.json` | `complete` 17:11:30Z | `2649c55d4fdfc8d1696554034f2e508427a0b2c0d52ce42ab739ba164c652fdb` |
| `h2-outcomes.json` | 17:11:30Z | `60e413e3e3a0e87f8659d25861a598fd6182bef5daab03a1cd9384e93ef7825a` |
| `h2-gate1-result.json` | 17:11:30Z | `f046728c0b187655f6a614a8744b6d54a4b7dd5e81f282e2acfcd0745a03c67c` |
| `events-resume.log` | — | `204988c900b19cdec8c5c292c7359dca6e14c23cc4a92411c642892ac4a6a7d5` |
| `outcomes-run.log` | — | `afbca2148ae93f7e6e6c93137bb2be203ed28d001bd858198b2b44f9e66d2e4f` |
| `monitor-resume.log` | — | `1fe8c5445cc147f2f7ef273edeab5bbea4c6aab57a45f43914a7ba6d69caabef` |

Le fichier de synthèse prescrit (`h2-gate1-result.json`) est versionné octet pour octet sous
[`H2-GATE1-RESULT.json`](../research/H2-GATE1-RESULT.json) (même sha256 `f046728c…c67c`) ; l'état de l'exécution est
consigné dans [`H2-GATE1-RUN-STATUS.json`](../research/H2-GATE1-RUN-STATUS.json).

### 3.4 Relevés disque et mémoire (`monitor-resume.log`, 19 entrées de 16:40:44Z à 17:11:40Z)

Un relevé avant le départ, puis toutes les 2 minutes, un relevé juste avant `outcomes` et un relevé final.

- Disque libre : **77 Go à chaque relevé**.
- Mémoire disponible : minimum 7 963 MB (16:44:44Z), maximum 8 485 MB (17:11:40Z) ; plancher du garde-fou 4 000 MB.
  Mémoire « libre » au plus bas 256 MB (16:57:19Z), le reste étant du cache.
- Swap : 4 095 MB presque plein avant le départ (39 MB libres), 0 MB libre à partir de 16:46:44Z. Les processus de
  l'étude n'ont jamais dépassé ≈ 525 MiB résidents ; la pression sur le swap venait du reste de la machine.

## 4. Événements et contrôles (horizon 3 j)

- **890 événements**, 113 bases, 45 mois, tous dans l'échantillon G1. À 3 j : 0 rendement propre ou de marché
  indéfini, 0 tronqué, 0 contrôle C1 ou C2 sans résultat.
- Côtés : 725 courts, 165 longs. 790 événements dans l'univers secondaire.
- Semestres : 2023H1 124, 2023H2 164, 2024H1 105, 2024H2 145, 2025H1 78, 2025H2 96, 2026H1+Q3 178.
- Événements par heure : 777 heures à 1 événement, 39 à 2, 6 à 3, une heure à 4, une à 6 et une à 7.
- Déclenchements supprimés par la période réfractaire de 120 h : 8 090.

Exclusions par heure-pièce :

| Mesure du marché | Heures-pièce | OK | `beta_window_insufficient` | `coin_bar_invalid` | `degenerate_variance` | `market_window_incomplete` |
|---|---:|---:|---:|---:|---:|---:|
| Leave-one-out (primaire) | 985 680 | 980 965 | 1 927 | 2 788 | 0 | 0 |
| Marché = BTC | 952 824 | 948 109 | 1 927 | 2 788 | 0 | 0 |

**C2 (choc partagé)** : 2 715 candidats de première traversée retenus, 26 235 supprimés par la période réfractaire.
890 / 890 appariés, **taux d'appariement 100 %** (seuil 80 %, donc pas de `STATISTICAL_BLOCKER`). 610 des 890
contrôles (68,5 %) viennent d'un mois voisin du mois de l'événement, ce que permet le pool de trois mois ;
63 contrôles réutilisés, 827 distincts.

**C1 (même pièce, sans événement)** : 732 / 890 événements (82,2 %) ont au moins un contrôle, 158 n'en ont aucun ;
576 ont les 20 contrôles, 314 en ont moins de 20 (dont les 158 sans contrôle). 12 967 tirages, 14,6 en moyenne par
événement. 1 031 tirages tombent à moins de 120 h d'un déclenchement supprimé (limite déclarée au §13).

## 5. Critère par critère (horizon primaire 3 j, côtés réunis, univers primaire)

IC à 95 % par bootstrap par blocs mensuels, 10 000 tirages finis chacun, aucun `ci_incomplete`.
1 pb = 10⁻⁴ en rendement logarithmique.

| # | Critère (figé) | Valeur | Résultat | Échec dû au seul G4 ? |
|---|---|---|---|---|
| G1 | ≥ 200 événements, ≥ 15 bases, ≥ 24 mois | 890 / 113 / 45 | PASS | sans objet (passe) |
| Taux d'appariement C2 | ≥ 80 % | 100 % (890 / 890) | pas de blocage | sans objet |
| G2 | moyenne y > 0 et borne basse de l'IC > 0 | **−9,0 pb**, IC [−90,6 ; +67,8] | FAIL | non : échoue indépendamment de G4 |
| G3 | moyenne y ≥ 40 pb (avant funding) | −9,0 pb | FAIL | non : échoue indépendamment de G4 |
| G4 | S2 > 0 (estimation ponctuelle ; IC publié, non décisif) | **S2 = −6,8 pb**, IC [−161,1 ; +158,3] | FAIL | non : quatre autres critères échouent aussi |
| G5 | S1 > 0 et borne basse de l'IC > 0 | **S1 = −45,0 pb** (732 événements), IC [−145,7 ; +56,8] | FAIL | non : échoue indépendamment de G4 |
| G6 | moyenne y > 0 dans ≥ 5 des semestres à ≥ 10 événements | 5 / 7 | PASS | sans objet (passe) |
| G7 | moyenne y > 0 sans les 5 % les plus forts, et sans la base de plus grande somme de y | sans les 45 plus forts : **−125,2 pb** ; sans SATS : **−17,5 pb** | FAIL | non : échoue indépendamment de G4 |
| **Verdict** | G1 → appariement C2 → G2–G7 | **FAIL** | — | **non** (`fail_due_to_G4_alone = false`) |

Moyenne y_raw (avant retrait de la part de marché) : −15,0 pb.

Moyenne y par semestre (pb, événements) :

| 2023H1 | 2023H2 | 2024H1 | 2024H2 | 2025H1 | 2025H2 | 2026H1+Q3 |
|---|---|---|---|---|---|---|
| −8,1 (124) | +77,3 (164) | +21,6 (105) | +43,4 (145) | +8,5 (78) | +36,4 (96) | −182,0 (178) |

G6 passe (5 semestres positifs sur 7), mais le dernier semestre, le plus fourni, est fortement négatif, et G7 montre
que la moyenne dépend de quelques gros événements : sans les 5 % les plus forts, elle tombe à −125 pb.

## 6. Côtés (G2–G7 calculés séparément, 3 j)

| Côté | n / bases / mois | Moyenne y [IC 95 %] | y_raw | S1 [IC] | S2 [IC] | G6 | G7 (top 5 % / top base) | Résultat |
|---|---|---|---|---|---|---|---|---|
| Long | 165 / 61 / 40 | **−177,3** [−376,6 ; −4,9] | −147,9 | −40,8 [−304,3 ; +254,0] | −232,7 [−485,5 ; +37,4] | 3 / 6 | −289,4 / −193,4 (AIXBT) | `INSUFFICIENT_EVIDENCE` (< 200 événements) ; **exclu de Gate 2** (moyenne ≤ 0) |
| Court | 725 / 101 / 45 | **+29,3** [−49,5 ; +110,3] | +15,2 | −46,0 [−169,7 ; +79,9] | +44,6 [−113,1 ; +221,5] | 5 / 7 | −89,9 / +20,8 (SATS) | FAIL (G2, G3, G5, G7) ; G4 passe |

Moyenne y par semestre (pb, événements), de 2023H1 à 2026H1+Q3 :

- Long : −92,3 (18), +30,4 (26), −33,6 (9), +71,7 (22), −348,2 (19), +280,1 (20), −561,5 (51).
- Court : +6,2 (106), +86,2 (138), +26,8 (96), +38,3 (123), +123,4 (59), −27,8 (76), −29,6 (127).

Le côté court n'est pas exclu par la règle des côtés (sa moyenne ponctuelle est > 0), mais cette règle ne fait
qu'exclure : elle n'autorise rien. Le verdict réuni est FAIL, donc aucun côté ne va en Gate 2. Sa moyenne (+29,3 pb)
est sous le plancher de 40 pb, son IC contient 0, S1 est négatif et G7 échoue.

## 7. Analyses rapportées, jamais décisives

Prescrites par le §8 ; aucune ne peut changer le verdict.

| Analyse | n | Moyenne y [IC 95 %] (pb) | S1 [IC] (pb) | S2 [IC] (pb) | G6 | G7 top 5 % / top base (pb) | Critères en échec |
|---|---|---|---|---|---|---|---|
| Horizon 1 j | 890 | −25,9 [−76,3 ; +20,3] | −65,0 [−138,7 ; +6,6] | +11,4 [−74,2 ; +104,5] | 4 / 7 | −101,9 / −31,2 (APT) | G2, G3, G5, G6, G7 |
| Horizon 5 j | 890 | −21,5 [−157,3 ; +106,2] | +65,6 [−82,4 ; +227,4] | +175,2 [−36,9 ; +414,2] | 4 / 7 | −185,8 / −37,4 (LAB) | G2, G3, G5, G6, G7 |
| Univers secondaire, réuni | 790 (73 bases) | −18,8 [−97,2 ; +53,8] | −25,8 [−133,5 ; +84,0] | −8,3 [−161,0 ; +151,6] | 5 / 7 | −112,1 / −26,5 (NOT) | G2, G3, G4, G5, G7 |
| Univers secondaire, long | 144 | −140,7 [−331,2 ; +15,9] | +16,2 [−247,1 ; +322,6] | −210,5 [−469,6 ; +69,8] | 2 / 6 | −241,2 / −158,9 (WLD) | `INSUFFICIENT_EVIDENCE` |
| Univers secondaire, court | 646 | +8,3 [−72,0 ; +87,4] | −35,4 [−172,5 ; +99,3] | +36,7 [−125,0 ; +208,3] | 4 / 7 | −85,4 / −0,6 (NOT) | G2, G3, G5, G6, G7 |
| k = 2,5 (sans contrôles) | 1 324 (125 bases) | −10,1 [−69,8 ; +47,0] | — | — | 4 / 7 | −121,9 / −16,7 (TRUMP) | `REPORTED_ONLY` |
| Marché = BTC (sans contrôles) | 827 (107 bases) | −34,8 [−129,5 ; +61,9] | — | — | 4 / 7 | −157,4 / −41,1 (BLUR) | `REPORTED_ONLY` |

Décomposition C2 de y_raw en part de marché + part résiduelle (pb ; 890 événements, 890 contrôles) :

| Horizon | Événements : total = marché + résidu | Contrôles C2 : total = marché + résidu |
|---|---|---|
| 1 j | −35,5 = −9,6 + −25,9 | −47,0 = −31,3 + −15,7 |
| 3 j | −15,0 = −6,0 + −9,0 | −8,2 = −2,9 + −5,3 |
| 5 j | −19,7 = +1,8 + −21,5 | −194,9 = −145,3 + −49,5 |

Aucune analyse non décisive ne montre de moyenne y positive au-dessus du plancher : aucune ne contredit le verdict.
Le S2 positif à 5 j (+175 pb) vient surtout du marché des contrôles C2 (−145 pb), pas d'un retour des événements
(−21,5 pb).

## 8. Lire correctement ce FAIL : puissance du gate figé

P(PASS) avec le pool de trois mois (Amendement A1), moyenne sur les scénarios, sd(y) 500 / 800 / 1 200 pb
(section « Amendment A1 » du protocole ; panels synthétiques uniquement) :

| Effet vrai μ | VR 1,0 | VR 0,8 | Student t3 |
|---:|---|---|---|
| 0 pb | 0,00 / 0,00 / 0,00 | 0,00 / 0,00 / 0,00 [max 0,01] | 0,00 / 0,00 / 0,00 |
| 40 pb | 0,05 / 0,02 / 0,01 | 0,04 / 0,02 / 0,01 | 0,06 / 0,01 / 0,00 |
| 80 pb | 0,39 / 0,18 / 0,07 | 0,35 / 0,15 / 0,04 | 0,45 / 0,16 / 0,06 |
| 150 pb | 0,84 / 0,74 / 0,44 | 0,82 / 0,74 / 0,41 | 0,94 / 0,86 / 0,51 |
| 300 pb | 1,00 / 1,00 / 0,98 | 1,00 / 0,99 / 0,98 | 1,00 / 1,00 / 0,99 |

P(FAIL dû au seul G4) avec ce pool : au plus 0,11 en moyenne (0,21 dans un scénario) à 150 pb, 0,00–0,01 à 300 pb.
Sous l'hypothèse nulle, S2 est biaisé vers le bas (module : −25 ± 110 pb, P(S2 > 0) = 0,41).

**Lecture descriptive, hors protocole (ne change rien) :** l'écart-type observé de y à 3 j est ≈ 1 115 pb
(890 événements, médiane +8 pb), donc proche de la colonne 1 200 pb, la moins puissante.

Ce que le FAIL dit, et ne dit pas :

- Le gate était valide (P(PASS | μ = 0) ≤ 0,01) mais peu puissant sous 150 pb : avec sd(y) ≈ 1 200 pb, un effet
  vrai de 80 pb n'aurait passé qu'environ 4 à 7 fois sur 100.
- Ici le FAIL n'est **pas** un simple manque de puissance face à un effet mesuré positif : la moyenne observée est
  négative (−9,0 pb), l'IC à 95 % [−90,6 ; +67,8] exclut un effet réuni de 150 pb, S1 est négatif (−45 pb), et sans
  les 5 % d'événements les plus forts la moyenne tombe à −125 pb.
- Il ne prouve pas l'absence d'effet : l'IC reste compatible avec un effet réuni jusqu'à ≈ +68 pb, que ce gate ne
  pouvait pas détecter. Formulation correcte : **pas d'effet de retour assez grand pour battre le contrôle à choc
  partagé et les gates de robustesse sur cette archive de développement**, pas « aucun effet ».
- Le FAIL n'est pas dû au seul G4 : G4 a la puissance la plus faible et un biais connu contre un PASS, mais G2, G3,
  G5 et G7 échouent aussi.

## 9. Ce qui suit, selon le protocole

- **Rien n'est autorisé.** Un PASS n'aurait autorisé qu'une proposition de Gate 2 (§8) ; le verdict FAIL n'en
  autorise aucune, ni réunie ni par côté. Le trading n'est autorisé dans aucun cas.
- **Les deux étapes sont consommées** : `events` avec sa reprise unique, `outcomes` sans reprise possible. Toute
  nouvelle exécution exigerait un amendement écrit, rapporté comme tel (§12). Un amendement écrit après cette
  lecture ne pourrait plus être confirmatoire sur cette archive : les rendements, résidus et événements H2 de
  2023–2026 sont maintenant lus.
- **Pas de récupération a posteriori** : le côté court (+29,3 pb), le semestre 2023H2 ou l'horizon 5 j ne peuvent
  pas devenir une nouvelle hypothèse testée sur les mêmes données. Une validation indépendante ne pourrait être que
  prospective (X-Perps, sans réutiliser les fenêtres W0–W2 réservées au test forward OKX, §2), et seulement pour une hypothèse préenregistrée avant
  toute lecture.
- La suite de H2 (clôture ou non, autre famille) est une décision du propriétaire ; ce rapport n'en propose pas.

## 10. Audit indépendant

**Première tentative** (rapport de 16:24Z) : `integrity_ok: true`, `verdict_consistent: true` (pas de verdict,
conforme à l'état enregistré) ; hash du corps, empreinte du code et ordre gel < claim < consommation vérifiés.

**Reprise et `outcomes`** : `integrity_ok: true`, `verdict_consistent: true`.

- Les quatre entrées figées (manifeste H2, manifeste swing, `daily-quote-volume.json`, `eea-futures.json`) ont été
  re-hachées et sont égales à `protocol.py INPUT_SHA256`. Les 1 016 zips H2 et les 9 665 npz n'ont pas été re-hachés
  par l'audit ; leur nombre et l'empreinte `b99f42f4…acc22` sont identiques dans les deux claims et égaux aux
  constantes figées, et `consume()` les re-vérifie au début de chaque exécution.
- « Exactement une reprise » : `events-consumed.json` contient une seule entrée `resumptions` (16:41:19Z, après
  « guard: available memory 8306 MB, free disk 18 GB ») et le statut `complete` ; `events-resume.log` montre un seul
  démarrage (16:40:49Z, `nice -n 15`, un processus, sortie 0). Une tentative de `consume` refusée ne laisserait pas
  de trace, mais n'aurait pas pu reprendre l'étape. `outcomes-consumed.json` : aucune reprise, statut `complete`.
- Le verdict et `fail_due_to_G4_alone = false` sont cohérents avec les valeurs de `h2-gate1-result.json`.
- Non vérifiable depuis les fichiers : que `h2-events.json` n'ait pas été inspecté avant la claim `outcomes`
  (la chronologie est compatible avec un enchaînement mécanique : fin d'`events` 16:56:33Z, claim 16:57:13Z,
  démarrage 16:57:19Z, consommation 16:57:43Z) ; que les feux verts viennent bien du propriétaire (seul leur texte
  est enregistré).

Les chiffres du présent rapport ont en outre été relus contre `h2-gate1-result.json` et `h2-events.json`
(événements, côtés, semestres, C1, C2, exclusions) : aucun écart.

## 11. Écarts et réserves

- **Aucun écart au protocole** : une seule reprise d'`events`, une seule exécution d'`outcomes`, aucune modification
  du code, des critères, des seuils ou des données ; `nice -n 15`, un processus par étape.
- **Formulation (mineur, corrigé ici)** : le rapport d'exécution donnait « 911 s CPU » et « 824 s CPU » ; ce sont les
  temps utilisateur seuls. Les totaux utilisateur + système sont 924,7 s et 837,9 s (§3.2).
- **Formulation (mineur, corrigé ici)** : la description des relevés omettait l'entrée finale « monitor stopped »
  de 17:11:40Z (19 entrées au total, §3.4).
- **Première tentative** : l'heure `started_at` 16:03:55Z de l'enregistrement est écrite après la re-vérification
  des entrées ; le processus avait démarré vers 16:02:15Z. Les relevés de disque de cette tentative ne sont pas
  dans un fichier.
- **Machine** : swap plein pendant toute l'exécution, cause extérieure à l'étude ; la mémoire disponible est restée
  au-dessus de 7,9 Go et le garde-fou n'a jamais été déclenché.
- **Lecture descriptive hors protocole** : l'écart-type de y (§8) a été calculé après le verdict, pour situer le
  résultat dans le tableau de puissance ; il ne sert à aucune décision.
