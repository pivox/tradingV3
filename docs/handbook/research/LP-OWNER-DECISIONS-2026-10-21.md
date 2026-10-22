# Programme LP — décisions du propriétaire (2026-10-21)

Réponses du propriétaire, reçues le 2026-10-21, aux quatre points posés après le plan
[LP-PROGRAMME-PLAN-2026-10-20.md](LP-PROGRAMME-PLAN-2026-10-20.md). Texte reçu, recopié tel quel :

> 1 ok go
> 2 spot admin en marge
> 3 ok
> 4 ok

Le mandat qui encadre ces décisions est celui du 2026-10-20 : « Ok go. Mais ta mission reste de trouver comment faire un pnl
positif avec le scalping (même sur des périodes précises) ». Le scalping reste l'objectif ; G5 (périodes et régimes déclarés à
l'avance) passe en tête de la piste L.

## D1 — Licence OKX API Agreement §9.4 : accordé

- **Objet accordé.** Capture publique continue, sans ordre, du 2026-11-02 00:00 UTC au plus tard le 2027-03-31, sur au plus
  40 X-Perps crypto et 2 swaps globaux de calibration, par l'hôte EEE et, pour les deux swaps, par l'hôte global. Le
  propriétaire la considère comme une préparation de son propre trading, au sens de « personal, non-commercial trading and
  account management » (texte recopié dans [LP-DATA-DESK-2026-10-20.md](LP-DATA-DESK-2026-10-20.md) §2).
- **Couvert aussi.** La lecture historique unique de G5 Gate 1 : bougies 1 minute dernier prix, mark et indice, et historique de
  funding des 29 X-Perps liquides, du listing au 2026-10-13 inclus, fin dure codée au 2026-10-14 00:00 UTC.
- **Règles prudentes conservées par défaut.** Séries, tables chiffrées et fichiers de capture restent dans `tradingV3-private`.
  Le dépôt `pivox/tradingV3` ne reçoit que des agrégats statistiques sans prix. Le travail d'agents sur ces données n'entraîne
  aucun modèle commercialisé ni partagé. Tout allongement, instrument, canal ou hôte ajouté est une nouvelle décision.

## D2 — Spot admis en marge : piste S ouverte

- **Déclaration du propriétaire, niveau interface, 2026-10-21** : le spot est admis en marge (« Selected Margin ») sur son
  compte X-Perps. C'est la condition S-O1 de [LP-PROGRAMME-GATES.md](LP-PROGRAMME-GATES.md) §10.2, qui sépare la variante
  auto-collatéralisée K1(c) de la variante K1(a), réfutée à 500 USDT.
- **À relever au gel, sans ordre** : les décotes BTC et ETH affichées dans les réglages de marge X-Perps ; la confirmation qu'un
  short `BTC-USD_UM_XPERP-310404` est porté par du BTC spot ; la clause d'appel de marge « < 30 % de la valeur du contrat ».
- **Conditions restantes avant tout gel de la piste S** : S-O2 devise de règlement USD-M sur USDC (USDC vu à l'interface le
  2026-10-15, [H1-XPERP-ACCOUNT-CHECK.md](H1-XPERP-ACCOUNT-CHECK.md)) ; S-O3 paires BTC-USDC et ETH-USDC négociables ; S-O4
  barème du compte ; S-O5 amendement CS-FUND (A-S1 à A-S4, en rédaction) ; S-O6 conseil fiscal ou risque accepté ; S-O7 devise de
  compte ; S-O8 verdict `fwd-okx-r1`, après le 2026-10-31.

## D3 — Gate 1 Hyperliquid pour G4 : consenti

- **Objet consenti.** Une lecture unique, statistique, de l'archive publique des exécutions Hyperliquid (`hl-mainnet-node-data`,
  champ `liquidation`), pour mesurer le signe du retour du prix après une cascade de liquidations, sous le pré-enregistrement
  [LP-G4-DRAFT-PREREGISTRATION.md](LP-G4-DRAFT-PREREGISTRATION.md) gelé. Données de développement seulement : elles ne
  confirment jamais une affirmation sur les X-Perps (déclaration de transférabilité, desk §4.3).
- **Ce qui reste nécessaire.** L'archive est « requester-pays » : le transfert est facturé au compte AWS du demandeur. Le
  propriétaire fournit un profil AWS. Avant tout transfert, le script liste les objets et affiche le coût estimé ; il s'arrête
  si l'estimation dépasse le plafond. **Plafond provisoire : 10 USD**, à relever par le propriétaire. Les conditions d'utilisation
  de l'archive et son schéma sont vérifiés sur un échantillon de schéma avant toute lecture de valeurs.
- **Hyperliquid n'est pas une place d'exécution** pour ce compte : aucune autorisation dans l'UE
  ([FRANCE-EXCHANGE-ELIGIBILITY.md](FRANCE-EXCHANGE-ELIGIBILITY.md), « Hyperliquid: not retained for real trading »). Les
  drapeaux Hyperliquid restent à 0.

## D4 — Porte économique : 97 USD par an

```
S_éco = 5 × SOFR × capital = 5 × 3,89 % × 500 USDT ≈ 97 USD par an
```

Seuil sous lequel une famille n'est pas poursuivie même si elle passe statistiquement ([LP-PROGRAMME-GATES.md](LP-PROGRAMME-GATES.md)
§1.2). Il est inscrit dans chaque protocole avant son gel et ne change plus après une lecture.

## Inchangé

Aucun ordre, aucun drapeau, aucune clé avec droit de trading. `fwd-okx-r1` et ses fenêtres W0–W2 restent intacts. Aucune
donnée datée 2026-10-14 → 2026-10-31 n'est lue. La capture prospective ne démarre pas avant le 2026-11-02 00:00 UTC, plancher
inscrit dans le code (`QUARANTINE_FLOOR`).

---

# Décisions du 2026-10-21, second lot (G5, piste S, G4)

Réponses du propriétaire, reçues le 2026-10-21 vers 06:40 UTC, aux cinq points posés pour le gel de G5. Texte reçu, recopié
tel quel :

> 1 je confirme
> 2 oui
> 3 Nn
> Clore s
> 5 ok

## D5 — Échelle de la lecture G5 Gate 1 : confirmée (P-G5-1)

Lecture unique de 25 X-Perps : funding (≈ 50 requêtes, scellé, avant le gel), puis bougies 1 minute dernier prix, mark et
indice (≈ 58 300 requêtes en tout), une IP, stockage `tradingV3-private`. Le propriétaire n'a pas choisi la cadence :
**facteur 0,4 retenu par défaut** (≈ 5,6 h, proposition du protocole, la plus prudente vis-à-vis d'OKX). Le texte de D5 devient
le `--decision-ref` du fetcher.

## D6 — Liste des 25 instruments : dérogation accordée (P-G5-4)

Le propriétaire accepte que l'univers figé reprenne la liste D1, issue d'un relevé de volume du 2026-10-14 (quarantaine,
sélection sur la liquidité de fin de fenêtre), avec les biais déclarés au protocole §3.1. Le snapshot d'instruments relevé après
le 2026-10-31 reste exigé ; aucun instrument n'est ajouté par un volume.

## D7 — Gate 2 de G5 : « non » (P-G5-10)

- **(i) Fin de fenêtre de T05 au Gate 2** : refusée. La fenêtre s'arrête au 2027-03-31 ; avec ≈ 92 instants < 100, T05 au
  Gate 2 est `NOT_INFORMATIVE` **par construction**, comme le protocole l'écrit d'avance.
- **(ii) Source d'indice à la minute pour le Gate 2** : la réponse « Nn » est lue comme un refus portant sur le point 3 entier ;
  une confirmation explicite est demandée, car sans source d'indice T01 au Gate 2 est `INCONCLUSIVE` par construction (§10).

## D8 — Piste S : close (S-O5)

Le propriétaire classe la piste S **sans suite** : à 500 USDT, le portage couvert auto-collatéralisé ne franchit pas la porte
économique, même dans le meilleur cas ([LP-S-CSFUND-K1C-AMENDMENT-DRAFT.md](LP-S-CSFUND-K1C-AMENDMENT-DRAFT.md) §11). Le
conflit P-S3 disparaît : **BTC et ETH restent dans l'univers de G5 Gate 1**. Aucun enregistreur de funding n'est lancé pour la
piste S. La réouverture exigerait une décision nouvelle (porte en dollars absolus et capital plus élevé).

## D9 — Source du Gate 1 de G4 : dYdX v4 (décision 2 du rapport DEX)

- dYdX v4 devient la source principale du Gate 1 de G4 ; Hyperliquid (D3) reste le repli.
- Accords associés (rapport DEX §7, décision 3) : données de l'indexeur public sans licence écrite, pour une recherche
  personnelle non redistribuée ; échantillon de schéma autorisé ; amendement de `LP-G4-DRAFT-PREREGISTRATION.md` §5.2 (proxy
  de mid déclaré, biais déclaré) avant tout gel.
- **Échantillon de schéma pris le 2026-10-21 à 06:39 UTC** (`indexer.dydx.trade/v4/trades/perpetualMarket/BTC-USD`, trois pages
  de 100 trades, toutes datées avant le 2026-10-14) : champs `createdAt`, `createdAtHeight`, `id`, `price`, `side`, `size`,
  `type` ; historique servi au moins jusqu'au 2023-12-31. Aucun prix, aucune taille, aucun décompte n'a été affiché ni stocké.
  La valeur `LIQUIDATED` n'apparaît pas dans ces 300 trades, ce qui est attendu pour un événement rare : sa présence est vérifiée
  au fetch du protocole gelé. Fichier : `tradingV3-private/research/20261021T-dex-alternatives/dydx-schema-sample/`
  (sha256 dans `SHA256SUMS`).

## D10 — Source d'indice du Gate 2 de G5 : les deux, choix sur la qualité (P-G5-10 (ii))

Réponse du propriétaire, 2026-10-21 : « On essaye des deux un par un puis on décide ».

- **(a)** Le canal public `index-tickers` est ajouté à la capture continue (extension de D1 au titre du §9.4 : un canal public de
  plus, mêmes instruments, même hôte, même période).
- **(b)** L'historique `history-index-candles` est récupéré une fois après la fin de la fenêtre du Gate 2.
- **Règle de choix, écrite avant la capture et jamais révisée après une lecture des résultats de T01** : la source primaire est
  (a) si sa couverture est d'au moins 98 % des minutes de la fenêtre et si, sur les minutes communes, l'écart médian absolu entre
  (a) et (b) est inférieur ou égal à 0,5 pb ; sinon (b). Le choix est fait et haché sur les seules mesures de couverture et
  d'accord entre séries, **avant** toute évaluation de T01. L'autre source sert de sensibilité rapportée.
- Ce qui reste interdit : comparer les résultats de T01 obtenus avec chaque source pour choisir.

## D11 — Contrôles de gel réalisables tout de suite (2026-10-22)

Réponse du propriétaire, 2026-10-22 : « Finir les contrôles G5 réalisables dès maintenant ». Les demandes de code 11, 13, 14,
16 et 18 de l'Annexe B du protocole G5 sont écrites et testées : commits `4dd1f6e8` et `b5d9c0a7` sur
`claude/g5-gate2-prefreeze`. Rien n'est gelé.

## D12 — Remplissage du Gate 2 de G5 : traversée en règle principale (2026-10-22)

Réponse du propriétaire, 2026-10-22 : « Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact
comme comparaison descriptive ». Le contact et la file sont rapportés à titre descriptif. Ils ne peuvent jamais rattraper un
échec, un résultat non informatif ou non concluant.

## D13 — Pilote de capture L2 BTC et ETH (2026-10-22)

Autorisation du propriétaire, 2026-10-22 : un pilote de capture publique, sans ordre, démarré au plus tôt le 2026-11-02 dans le
respect des prérequis du programme. Il porte sur deux X-Perps, BTC et ETH, sélectionnés par leurs métadonnées. Sa durée est de
24 heures au plus, avec au plus 5 Gio écrits et au moins 10 Gio laissés libres. Il s'arrête proprement à la première limite
atteinte, sans prolongation automatique. Aucun lancement n'est programmé. Le dispositif et ses règles de protection sont dans
`L2-CAPTURE-PILOT.md`, sur la branche `claude/research-l2-capture-pilot`.

## D14 — P-L2-1 : fenêtre d'exploration du pilote L2, séparée de toute confirmation (2026-10-22)

**Matériau de la décision.** Mission du propriétaire du 2026-10-22, transmise en anglais et recopiée telle que reçue (extraits) :

> Reserve a short development exploration window for the pilot, preferred slot 2026-11-02T00:00Z -> 2026-11-03T00:00Z (a later manual
> start shortens it, never shifts the end). FIRST look for a separation that respects the existing T0 rules […]; if so, add an explicit
> spec rule/check making it binding […] rather than relying on chance. If an exclusion is needed: define the exact interval and margins,
> cover lookbacks and footprints, apply it in ALL concerned draft protocols/specs […], keep decided end dates and statistical floors.
> The separation must bear on the observations used (code-enforced where the evaluator reads data […]), not only storage folders.

> Pilot guarantees […]: keep BTC/ETH X-Perps, 24 h max, 5 GiB max written, >= 10 GiB free, no restart/extension.

**Décision P-L2-1 (texte).**
1. **Fenêtre réservée** du pilote de capture L2 (X-Perps BTC et ETH, carnets `books` 400 niveaux, `books5` et trades publics ; aucun
   ordre) : R = [2026-11-02T00:00Z ; 2026-11-03T00:00Z). Départ au plus tôt le 2026-11-02T00:00Z ; **fin dure** le 2026-11-03T00:00Z quel
   que soit le départ ; durée = min(24 h ; fin dure − départ) ; un départ plus tardif raccourcit le pilote, ne décale jamais sa fin ; au
   plus 5 Gio écrits, au moins 10 Gio libres, aucun redémarrage, aucune prolongation. Usage : développement et exploration seulement.
2. **Marge et intervalle exclu** : marge de 12 h ; plancher de séparation F = 2026-11-03T12:00Z ; intervalle exclu
   E = [2026-11-02T00:00Z ; 2026-11-03T12:00Z).
3. **Règle** : aucune observation horodatée dans E (prints, carnets, bbo, mark, indice, messages de funding, liquidations, open interest,
   comptages de messages) n'entre dans un calcul décisif du programme LP, ni dans ses empreintes, lectures préalables, rodages,
   calibrations, règles de qualité ou sélections d'instruments. Les données de E (pilote ou collecteurs LP) sont des données de
   développement.
4. **Application** (`LP-PROGRAMME-GATES.md` §7.4, tableau des recouvrements) : Gate 2 de G5 — T0 ≥ 2026-11-04 (premier 00:00 UTC ≥ F +
   540 min), premières observations T01 2026-11-03T16:00Z et T05 2026-11-03T23:59Z, lignes de E écartées par le chargeur, départ de
   `g5_index_fetch` refusé dans E, spec `FROZEN` refusé s'il s'en écarte (G5 §10.6) ; G2 — T0 ≥ F, `simulate` et `events` refusent les
   jours 2026-11-02 et 2026-11-03 (G2, amendement du 2026-10-22, §7.5) ; Gate 2 de G4 — historique sans ces deux jours (G4, amendement du
   2026-10-22, §4.2) ; P3 — comptage au plus tôt à F, jamais pendant le pilote (`research_lp count` refuse avant ; plan §3, `GATES` §8) ;
   Gate 1 de G5 et Gate 1 de G4 — aucun recouvrement ; tout protocole futur (G1) applique E à ses rodages.
5. **Pilote** : spec `l2-pilot-spec/2` ; la capture, son plan et la relecture de son contenu (`replay-check`) sont refusés avant le début
   de R, à partir de sa fin dure, et tant que la séparation n'est pas enregistrée et résolue (le spec Gate 2 de G5 donné par
   `--separation-spec` doit porter le bloc `pilot_separation` dont le pilote fige le sha256, en version ≥ `0.1.8`, et `--separation-ref`
   doit nommer P-L2-1).
6. **Conditions de lecture du contenu du pilote** : il n'est ouvert qu'une fois la séparation démontrée et enregistrée (cette décision, la
   ligne du registre, le bloc dans le spec Gate 2 de G5, vérifié par sha256) ; jusque-là, résumés de qualité et de volume seulement
   (en-têtes, aucun prix). Ensuite : développement seulement, jamais une confirmation, jamais la source d'un seuil, d'une fenêtre, d'un
   univers ou du plafond du collecteur ; tout protocole gelé après l'ouverture la déclare parmi ses expositions antérieures.
7. **Effet** (calcul d'audit sur les listes gelées) : T05 au Gate 2 de G5 90 instants au plus (T0 2026-11-04), 89 au T0 réaliste
   (2026-11-05), contre 91 — `NOT_INFORMATIVE` par construction inchangé sous D7 ; T01 443 / 440 instants bruts contre 446 (f_inst ≥ 0,226 /
   0,227 pour 100 instants ; δ₈₀ + 0,7 % au plus) ; G2 : 14 jours scorés, verdict décalé d'un jour au plus ; G4 Gate 2 : 90 jours ; fins
   décidées (2027-03-31) et planchers inchangés. La marge de 12 h ne coûte aucun jour par rapport à une marge nulle (P3 doit suivre le
   pilote, durer au moins 24 h et précéder le gel).

**Références versionnées (2026-10-22).**

| Objet | Branche | Version | sha256 |
|---|---|---|---|
| Bloc `research_lp.separation.BLOCK` (JSON canonique : clés triées, sans espaces, UTF-8) | `claude/g5-gate2-prefreeze` | — | `4b96e2bd3987e123b597b6534a9c464580d560db399401849cf992af004d489e` |
| Spec Gate 2 de G5 `g5_gate2_spec.json` (porte le bloc et `calendars.versioning`) | `claude/g5-gate2-prefreeze` | `0.1.8-draft` | `fc631ef8d7c0624f6db0b20e2dd212f4895033a6b3b34414c6d0f46aae1b14bc` (informatif : brouillon) |
| Spec Gate 1 de G5 `g5_gate1_spec.json` (inchangé) | `claude/g5-gate2-prefreeze` | `0.4.1-draft` | `1d86d3f538da49010f5e70edb2d55d1200c34ac56e4d30cdec5cc96d4cd35a12` |
| Code `research_lp` (`cli.code_digest()`) | `claude/g5-gate2-prefreeze` | — | `d1066fe426e4beb647b1b206c345266ffa5eadf0e15946c36998b636f6d8e7f6` |
| Spec du pilote `l2_pilot_spec_v2.json` (+ `.sha256`) | `claude/research-l2-capture-pilot` | `l2-pilot-spec/2`, `2.0.0`, `FROZEN` | `8ac57f2c601e04d113bf054a07aa1518ecaf3d12ce592db2c7442b050bca9c2c` |
| Spec du pilote `l2_pilot_spec_v1.json` (gardé tel quel, refusé pour un run) | `claude/research-l2-capture-pilot` | `l2-pilot-spec/1`, `1.0.0`, `FROZEN` | `16b1a6941f539c0a5a918d1a811ebbf6c291aedfe784f536a29ad675d52515a3` |
| Code `research_l2` (`pilot.code_digest()`) | `claude/research-l2-capture-pilot` | — | `a7bdb560beace7261b8a1ce7a92dd155567365ecd2757aa910794a1d4e241b4e` |

Inscrite au registre `tradingV3-private/research/20261020T1430Z-lp-programme/REGISTRY.md` (ajout en fin de fichier, 2026-10-22). Rien
n'est lancé ni planifié ; aucun ordre, aucun drapeau.

## Note (sans nouvelle décision) — Dates de calendrier changées après le gel (mission du 2026-10-22, point D)

Écrite en réponse à la même mission (point D) et à la ligne 15 de G5 §2.4 : règle déterministe en G5 §10.5 (instantanés officiels datés
et hachés — bls.gov, federalreserve.gov, nyse.com ; ISM seulement avec un accès officiel —, classement de chaque instant avec la version
disponible à sa décision, un instant retenu seulement s'il est admissible sous toutes les versions, instantané final exigé avant
l'exécution, journal de chaque changement, dates non publiées jamais inventées ; aucune hypothèse, aucun seuil, aucun horizon, aucune fin
de fenêtre ne change). Code : `research_lp/calendar_versions.py`, bloc `calendars.versioning` du spec Gate 2 `0.1.8-draft`. Les dates
JOLTS 2027 et ISM restent bloquantes (G5 §2.4 ligne 5).
