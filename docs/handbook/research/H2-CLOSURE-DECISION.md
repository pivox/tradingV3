# H2 — décision de clôture

**Statut : `H2_GATE1_FAIL_CLOSED`.** Décision du propriétaire, 2026-10-15.

L'étude d'effet H2 Gate 1 a été exécutée une seule fois. Elle testait le retour à la moyenne du résidu
idiosyncratique sur 24 h, en marché calme, sur les OKX USDT-SWAP de 2023 à 2026. Le protocole était celui figé le
2026-10-15T15:57:04Z avec l'Amendement A1. Le verdict est **`FAIL`**, et il **n'est pas dû au seul G4**
(`fail_due_to_G4_alone = false`). Après un FAIL, le protocole n'autorise aucune proposition de Gate 2 (§8).

## 0. Décision du propriétaire (texte enregistré)

Message du propriétaire dans la session Claude Code d'orchestration, 2026-10-15T19:00:27Z (21:00 heure de Paris),
mission « TradingV3 — Clôture H2 et reprise de H1 après Gate 1 » :

> Décision explicite : Clôturer H2 dans sa formulation actuelle.
> […] Enregistrer : H2_GATE1_FAIL_CLOSED
> […] Décision finale : H2 : CLOSE.

Le même message interdit de :

- refaire Gate 1 ;
- modifier ses seuils ;
- changer rétroactivement les horizons ;
- sélectionner uniquement les shorts ;
- retenir les semestres les plus favorables ;
- réutiliser les mêmes données pour construire une nouvelle version supposée indépendante ;
- passer au Gate 2 ;
- développer un moteur de trading H2.

Il ajoute : « Cette clôture ne démontre pas qu'aucune forme de retour à la moyenne n'existe. Elle signifie que
l'hypothèse pré-enregistrée n'a pas satisfait ses critères sur l'archive consommée. »

Le propriétaire l'a confirmé à 19:05:53Z : « conserver H1 et H2 clôturées, puis examiner les éventuelles propositions
H3 avant d'autoriser une nouvelle campagne ».

Le protocole ne définit pas lui-même d'état « clos ». `H2_GATE1_FAIL_CLOSED` est donc la décision du propriétaire, prise
sur ce verdict. Elle est cohérente avec deux textes :

- le protocole §8 : un FAIL n'autorise rien ;
- le protocole §12 : une seconde exécution est refusée, et une relance exige un amendement écrit. Le rapport (§9)
  ajoute qu'un amendement écrit après cette lecture ne pourrait plus être confirmatoire sur cette archive.

## 1. Références

| Élément | Valeur |
|---|---|
| Protocole | `docs/handbook/research/PREREGISTRATION-H2-GATE1.md`, `FROZEN 2026-10-15T15:57:04Z` (Amendement A1 ; la v1 figée à 13:56:41Z est conservée en historique) |
| sha256 du corps | `0ec71c563a6247464765fd0696ceb193d60d980e97d93d6f53d0565940d8bf20` |
| Empreinte du code | `e8c087a1a1ee105c81d2f9f06d79b966f1db52a43ffd293860e1a8f6c8c41904` (code au commit `a4844111`) |
| Règle d'acceptation A1 | `sim/c2-pool-3m/ACCEPTANCE-RULE.md`, sha256 `192beca2577808eb1bcb98d1c4b9363c83ea35f2a46671700df11e7668bdc893` (ACCEPT) |
| Résultat | [`H2-GATE1-RESULT.json`](H2-GATE1-RESULT.json), sha256 `f046728c0b187655f6a614a8744b6d54a4b7dd5e81f282e2acfcd0745a03c67c` (copie octet pour octet de `run/h2-gate1-result.json`) |
| État d'exécution | [`H2-GATE1-RUN-STATUS.json`](H2-GATE1-RUN-STATUS.json) (champ `closure` ajouté le 2026-10-15) |
| Rapport | [`research-h2-gate1-2026-10-15.md`](../reports/research-h2-gate1-2026-10-15.md) |
| Répertoire d'exécution | `tradingV3-private/research/20261015T1200Z-h2-gate1/run/` |
| PR | #510 (gel v1, fusionnée), #511 (Amendement A1, regel, exécution, clôture ; ouverte) |

## 2. Résumé du FAIL

- 890 événements, 113 bases, 45 mois.
- Taux d'appariement C2 : 100 % (890/890), donc pas de `STATISTICAL_BLOCKER`.
- Moyenne de y à 3 j : **−9,0 pb**, IC 95 % [−90,6 ; +67,8]. Moyenne de y_raw : −15,0 pb.
- Seuls G1 et G6 passent. **G2, G3, G4, G5 et G7 échouent.** Le verdict resterait FAIL même si G4 passait.

| # | Critère figé (horizon 3 j, côtés réunis, univers primaire) | Valeur | Résultat |
|---|---|---|---|
| G1 | ≥ 200 événements, ≥ 15 bases, ≥ 24 mois | 890 / 113 / 45 | PASS |
| — | Taux d'appariement C2 ≥ 80 % | 100 % (890/890) | pas de blocage |
| G2 | moyenne y > 0 et borne basse de l'IC > 0 | −9,0 pb [−90,6 ; +67,8] | **FAIL** |
| G3 | moyenne y ≥ 40 pb (avant funding) | −9,0 pb | **FAIL** |
| G4 | S2 > 0 (estimation ponctuelle ; IC publié, non décisif) | −6,8 pb [−161,1 ; +158,3] | **FAIL** |
| G5 | S1 > 0 et borne basse de l'IC > 0 | −45,0 pb (732 événements) [−145,7 ; +56,8] | **FAIL** |
| G6 | moyenne y > 0 dans ≥ 5 des semestres à ≥ 10 événements | 5 / 7 | PASS |
| G7 | moyenne y > 0 sans les 5 % les plus forts **et** sans la base de plus grande somme de y | −125,2 pb (45 retirés) ; −17,5 pb (sans SATS) | **FAIL** |
| **Verdict** | | **FAIL**, pas dû au seul G4 | |

Les IC à 95 % viennent d'un bootstrap par blocs mensuels. Chaque intervalle repose sur 10 000 tirages finis.

Les analyses rapportées, jamais décisives, ne contredisent pas le verdict :

- horizon 1 j : −25,9 pb ;
- horizon 5 j : −21,5 pb ;
- univers secondaire X-Perps : −18,8 pb, FAIL ;
- k = 2,5 : −10,1 pb ;
- marché = BTC : −34,8 pb.

À 5 j, S2 est positif (+175,2 pb). Ce chiffre vient surtout de la part de marché des contrôles C2 (−145,3 pb), pas
d'un retour des événements.

## 3. Côtés

| Côté | n / bases / mois | Moyenne y [IC 95 %] | S1 | S2 | G7 (top 5 % / top base) | Résultat |
|---|---|---|---|---|---|---|
| Long | 165 / 61 / 40 | −177,3 pb [−376,6 ; −4,9] | −40,8 | −232,7 | −289,4 / −193,4 (AIXBT) | `INSUFFICIENT_EVIDENCE` (< 200 événements) ; exclu de Gate 2 (moyenne ≤ 0) |
| Court | 725 / 101 / 45 | **+29,3 pb** [−49,5 ; +110,3] | −46,0 | +44,6 | −89,9 / +20,8 (SATS) | FAIL (G2, G3, G5, G7) |

**Le côté court (+29,3 pb) n'est qu'une observation exploratoire, jamais un candidat.** Plusieurs raisons :

- il est sous le plancher G3 de 40 pb ;
- son IC contient 0 et son S1 est négatif ;
- sans ses 5 % d'événements les plus forts, la moyenne tombe à −89,9 pb ;
- ses deux derniers semestres sont négatifs (−27,8 et −29,6 pb).

Dans `H2-GATE1-RUN-STATUS.json`, le champ `excluded_from_gate2: false` du côté court indique seulement que la règle
d'exclusion des côtés (§8) ne s'applique pas à lui. Cette règle exclut, elle n'autorise rien. Le verdict réuni est
FAIL et le côté court échoue lui-même : aucun côté ne va en Gate 2.

## 4. Interdiction de toute récupération

**Aucune hypothèse ne peut être dérivée de cette lecture, ni ajustée sur elle.** L'interdiction s'applique quelle que
soit l'archive (OKX, Binance ou une période plus longue « pour gagner en puissance »). Elle vise en particulier :

- le côté court seul ;
- le côté long inversé, lu comme une continuation ou un momentum. Son IC est entièrement sous 0, mais c'est une
  lecture a posteriori ;
- les horizons 1 j et 5 j, où G4 passe isolément (S2 +11,4 et +175,2 pb) alors que le verdict reste FAIL ;
- k = 2,5 et le marché BTC ;
- le côté court de l'univers secondaire (+8,3 pb) ;
- les semestres favorables (par exemple 2023H2).

Si une idée future porte sur le retour ou la continuation idiosyncratique, son pré-enregistrement doit déclarer que
ses auteurs ont vu ces résultats. Les sorties de H2 ne comptent jamais comme preuve à l'appui. C'est la même règle que
`H1-GATE2-RESULT.md`, Conséquences 3.

## 5. Limites : ce que FAIL veut dire, et ce qu'il ne veut pas dire

- **Puissance.** Le gate était valide : P(PASS | μ = 0) ≤ 0,01 sur les panels synthétiques de l'Amendement A1. Mais
  il était peu puissant sous 150 pb. Avec une dispersion sd(y) de 1 200 pb, sur les simulations synthétiques :
  - à 80 pb, P(PASS) vaut 0,04 à 0,07 en moyenne par groupe de scénarios, et 0,00 à 0,12 selon le scénario ;
  - à 150 pb, P(PASS) vaut 0,41 à 0,51 en moyenne par groupe, et 0,32 à 0,52 selon le scénario.

  Ces chiffres viennent de `sim/c2-pool-3m/summary.md` §3. À titre descriptif et hors protocole, l'écart-type observé
  de y est d'environ 1 115 pb (rapport §8).
- **Ce que FAIL veut dire.** Aucun effet de retour assez grand n'a battu le contrôle à choc partagé et les gates de
  robustesse sur cette archive de développement. La moyenne observée est négative. L'IC exclut un effet réuni de
  150 pb. Sans les 5 % d'événements les plus forts, la moyenne tombe à −125 pb.
- **Ce que FAIL ne veut pas dire.** Il ne prouve pas l'absence de tout effet. L'IC reste compatible avec un effet
  réuni allant jusqu'à environ +68 pb, que ce gate ne pouvait pas détecter. Cela ne justifie pas un nouveau test sur
  ces données (§4).
- **Biais de C2.** Sous l'hypothèse nulle simulée, S2 est biaisé contre un PASS : environ −25 pb sur le code du module
  avec le pool de trois mois. 610 des 890 contrôles (68,5 %) viennent d'un mois voisin du mois de l'événement. Le FAIL
  ne repose pas sur G4 seul.
- **Données de développement.** L'archive était déjà étiquetée « développement » avant l'exécution (protocole §2). Ce
  résultat n'est pas une validation indépendante, ni dans un sens ni dans l'autre.

## 6. Données consommées

Ces données sont consommées pour **toute utilisation confirmatoire future**, quelle que soit l'hypothèse. Elles ne
peuvent plus servir qu'au développement. Le propriétaire a écrit « consommées pour cette hypothèse » ; en pratique,
elles ne permettent plus aucune validation indépendante.

| Donnée | Détail (protocole §2, claims) |
|---|---|
| Bougies OKX 1 min, dernier prix | De 2022-09 (période de chauffe) à 2026-10-06T16:00Z |
| 20 bases du programme | Lues sur OKX **pour la première fois** par H2 (1 016 zips) ; elles avaient été lues sur Binance auparavant |
| Familles hors programme | Via les 9 665 fichiers npz de swing v2 (déjà lus par #500 au niveau des bougies) |
| 42 premiers jours de chaque série | Lus par les fenêtres β de 60 j, donc **nouvellement consommés** |
| Heures de décision | 2023-01-01 → 2026-10-01 (exclu) |
| `daily-quote-volume.json` | Lu en entier (jours jusqu'au 2026-10-12) ; les jours postérieurs à la coupure sont écartés en mémoire |
| Rendements, résidus et événements H2 2023–2026 | Lus |

La fin des données est **imposée par le code** (`DATA_END_MS` et la coupure par période de fichier, protocole §2).
Aucune trace d'accès ne l'atteste directement. Les fenêtres forward OKX (W0–W2 de `fwd-okx-r1`) ne font pas partie des
entrées de H2.

## 7. Conséquences (décision du propriétaire)

- Gate 2 est interdit pour ce protocole.
- Aucun setup H2.
- Aucun trading H2, ni moteur H2.
- Aucun nouveau holdout préparé pour récupérer ce candidat.
- Tous les résultats, négatifs compris, restent consultables et conservés intégralement.
- Les données ci-dessus sont consommées (§6).

## 8. Constats d'audit (2026-10-15, lecture seule, valeurs enregistrées uniquement)

Aucun rendement n'a été recalculé. Seules les décisions ont été recalculées, à partir des valeurs sauvegardées.

1. **Résultats et verdict.** G1–G7 ont été recalculés à partir des valeurs de `H2-GATE1-RESULT.json`. Ils sont
   identiques aux décisions stockées dans les 10 cellules : primaire, 1 j, 5 j, les deux côtés, l'univers secondaire
   (réuni, long, court), k = 2,5 et BTC. Le verdict FAIL et `fail_due_to_G4_alone = false` sont confirmés. Le nombre
   d'événements retirés par G7 est bien ⌈0,05 n⌉ = 45.
2. **Gel.** Le hash du corps, recalculé avec `claim.body_sha256`, vaut `0ec71c56…bf20` : il est égal à la ligne
   `FROZEN`. L'empreinte du code, `code_digest()`, vaut `e8c087a1…1904` : elle est égale au gel et aux deux claims. La
   copie privée du protocole est identique, et la règle d'acceptation A1 est conforme (`SHA256SUMS` vérifié).
3. **Claims.** Le sha256 de chaque claim est égal au `claim_sha256` de son enregistrement de consommation. Les deux
   claims ne diffèrent que par l'étape, l'heure et le prérequis. Le hash de `h2-events.json` (`b537fde8…380e`) est le
   même partout : le fichier, son `.sha256`, les sorties d'`events`, le prérequis d'`outcomes` et le résultat.
4. **Reprise.** La règle est `MAX_GUARD_RESUMPTIONS = {"events": 1, "outcomes": 0}`.
   - Le garde-fou s'est déclenché sur le disque (18 Gio < 20 Gio ; mémoire 8 306 Mo ≥ 4 000 Mo), avant toute sortie.
   - Il y a eu une seule reprise (16:41:19Z) ; `outcomes` n'en a eu aucune.
   - Pendant la reprise, 77 Go sont restés libres et le garde-fou ne s'est pas déclenché.
   - Avant la reprise, l'orchestrateur a vérifié qu'au moins 40 Go étaient libres. Ce n'est pas un seuil du
     protocole : le garde-fou est resté à 20 Gio.
5. **Aucun changement de paramètre.** Le code est inchangé depuis `a4844111`. Le protocole est inchangé depuis
   `e16f5a1e`, qui ne touche que des lignes situées après le marqueur de gel. Les commits postérieurs au gel ne
   touchent que le rapport, les JSON, `mkdocs.yml` et le présent document. Les paramètres écrits dans le fichier
   d'événements sont ceux du protocole.
6. **Données consommées.** Les deux étapes sont `complete` ; le détail figure au §6.
7. **Clôture.** Elle est valide au regard du protocole (§8, §12) et elle est enregistrée (§0).

### Réserves, sans effet sur le verdict

- **R1.** Le journal de la première tentative (`events.log`, sha256 `de2f69d0…0947`) n'est conservé que dans un
  répertoire temporaire de session, pas dans `run/`. Le copier dans `run/` relève de l'orchestrateur ou du
  propriétaire.
- **R2.** La commande chronométrée de ce journal ne montre pas `nice -n 15`. Il n'est donc **pas prouvé** que la
  première tentative tournait sous `nice`, contrairement à ce qu'indique le rapport §2 (voir l'addendum du rapport,
  §12). Cette tentative n'a écrit aucune sortie.
- **R3.** L'empreinte de l'enregistrement au moment de l'arrêt (`fa028b43…`) n'est plus vérifiable : le fichier a été
  réécrit par la reprise, comme prévu.
- **R4. Chute du disque : cause établie après coup, sans lien avec H2.** Un diagnostic Linux en lecture seule,
  conservé hors du dépôt public, a établi les faits suivants (synthèse dans `RESEARCH-INFRA-DIAGNOSTIC.md`, branche
  `claude/research-status-2026-10-15`) :
  - une écriture d'environ 36 Gio liée à une activité personnelle sans rapport avec la recherche, sur le même disque (≈ 15:58Z → 16:18Z). H2 n'a écrit que 24 blocs. L'autre session locale (nettoyage de fichiers personnels) n'a fait que supprimer des fichiers après l'arrêt : c'est elle qui a rendu l'espace. L'outil et l'auteur exacts ne sont pas prouvés.
- **R5.** Deux points restent non prouvés :
  - l'origine de la confirmation de reprise. Seul son texte est enregistré (`owner_confirmation_after_guard_stop`,
    vers 18:39 heure de Paris) ;
  - l'absence d'inspection de `h2-events.json` avant la claim `outcomes`. La chronologie (40 s) est compatible avec un
    enchaînement mécanique.
- **R6.** La ligne de statut du protocole figé, située après le marqueur, indique encore « not executed ». Le fichier
  figé n'est pas modifié : l'exécution et la clôture sont consignées dans `H2-GATE1-RUN-STATUS.json`, dans le rapport
  et dans ce document.

## 9. État du programme

- **H1 est clos** par son propre protocole. Le Gate 2 historique, avec l'amendement A3, a donné `GATE2_FAIL`, et la
  v1 §17 clôt H1 (`H1-GATE2-RESULT.md`, PR #508). Le `GATE1_PASS` de S_B reste un résultat scientifique.
- **H2 est clos** par la présente décision.
- Le propriétaire examinera les éventuelles propositions H3 avant d'autoriser une nouvelle campagne. Ce document n'en
  propose aucune.
