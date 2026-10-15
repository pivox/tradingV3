# Levier théorique au-delà de 2× en scalping tick, hors BTC (leverage-gt2, 2026-10-15)

> **Étude exploratoire, post hoc, sur données consommées.** Sous-groupe de configurations qui avaient déjà
> échoué (r10, r10x, r11, r12), rejouées sur les quatre semaines d'entraînement 2024 déjà lues par ces
> études. Ce n'est ni une validation ni une réplication ; rien n'a été choisi d'après les résultats ; aucun
> critère n'a été assoupli après lecture. Chaque étape (E0a, E1, analyse) a été exécutée une seule fois.

## D'abord : dans cette étude, « levier » veut dire « distance de stop »

Le levier théorique n'est pas un réglage qu'on choisit : il découle de la taille à risque fixe. Avec une
perte au stop fixée à r = 0,5 % du capital E, la taille est N = r·E / (d + c), où d est la distance du stop
et c la provision de coûts, en points de base du prix d'ordre. Le levier théorique est donc :

- **L_eng = 50 / (d_bp + c_bp)** (définition principale) : L_eng > 2 ⇔ d + c < 25 bp ;
- **L_stop = 50 / d_bp** (définition secondaire, forme héritée de `DynamicLeverageService`) : L_stop > 2 ⇔
  d < 25 bp.

Une « opportunité à plus de 2× » est donc une opportunité **à stop serré**. Tant que le stop est atteint
avant la liquidation et qu'aucun plafond ne joue, le levier appliqué ne change pas le résultat en R ni en %
du capital (invariance posée au §1 du protocole, avant toute donnée). Ce que les tranches séparent, c'est la
distance d + c, donc la volatilité, le poids des coûts c/(d + c), la taille du tick et la liquidité.
L'étude ne peut mesurer qu'une **association descriptive** entre « stop serré » et espérance nette, jamais
un effet causal du levier. Aucun trade ≤ 2× n'a été sur-dimensionné : chaque trade est à sa taille
canonique, et le contrôle `assert_not_levered_up` est passé pour tous.

## Verdict du protocole

**L'étude se clôt NOT_INFORMATIVE.**

- **Contraste principal** (Δ ]2,3] − ]1,2] sous L_eng) : **NOT_INFORMATIVE**, aucun trade. Le recensement
  E0a ne déclenche aucune tranche étudiée sous L_eng : ]2,3] compte 237 signaux distincts, et 237 × 0,3 =
  71,1 < 400. Le rejeu E1 n'a donc rien rejoué sous L_eng.
- **Secondaires S1–S9** : tous **NOT_INFORMATIVE**, p ajusté par Holm = 1.
- **Seul contraste avec des trades des deux côtés, S4** (Δ ]2,3] − ]1,2] sous L_stop) : Δ = **−0,095 R**,
  IC 95 % [−0,300 ; +0,126], p brut 0,385, sur **240** trades distincts exécutés contre 1 983 (269 grappes
  paire × jour). 240 < N_min = 400, donc la règle gelée impose NOT_INFORMATIVE et p = 1 dans la famille de
  Holm, quelle que soit la valeur de p.
- **Descriptif seulement** : les deux bras rejoués perdent en net à chaque point de la grille de glissement
  (0, 1, 2, 5 ticks).

Sources : `e0a-trigger.json`, `e1-analysis.json` (`primary`, `secondary`), pages
[recensement E0a](../research/LEVERAGE-GT2-E0A-CENSUS.md) et [rejeu E1](../research/LEVERAGE-GT2-E1-RESULTS.md).

## Provenance et empreintes

Protocole : [`PREREGISTRATION-leverage-gt2.md`](../research/PREREGISTRATION-leverage-gt2.md), gelé le
2026-10-15 à 10:07:48Z (corps et code au commit `79747bf2`, gel au commit `c89c743c`). Décisions du
propriétaire : D1 = `distinct_pair_day`, D2 = `drop` (30 configurations), D3 = 1, D4 = `not_charged` ;
choix C1–C9 acceptés. La copie privée du protocole est identique octet pour octet à la copie du dépôt.

Fichiers privés sous `tradingV3-private/research/20261015T090000Z-leverage-gt2/` :

| Élément | sha256 |
|---|---|
| Corps du protocole (jusqu'à « ## 13. Freeze ») | `10eaf6cb36a37ce5057f09324acee80696abda38eed6fce17f31fe7760f37d97` |
| Empreinte du code (`claim.code_digest()`) | `6e20c4ebb7f70dba257a57ebd6f2f1753c609368b8569b78cb1ba9a50e17ddeb` |
| Fichier protocole complet (avec la ligne FROZEN) | `7254630bfd2c565ebee1980cb02de1e16bc3af226008ae67df735872a98a58a2` |
| `PREREGISTRATION-leverage-gt2.sha256` | `19fe4949c7e26af419d7bd8b8bce68f02b61fdc737b648fa6aeaafb689017a54` |
| `E0a-claim.json` (10:13:16Z) | `a3178a340fed8ed258d72b5b0a7c184ddcc6201d5ce90e6f0776ad283c1904bd` |
| `E0a-consumed.json` (10:13:21Z → 10:16:43Z) | `823d03e5dd2fc5cbcf83bcf0076c22f9a6b347844655bfcac365c3ba59fde1ac` |
| `e0a-census.json` | `c00673aa90cdc463a986834a5111464d6a76c31b1c8260266cab998591fa1b9d` |
| `e0a-trigger.json` | `fe7e7fa4c64fec10e376287c26ddbabae3d0da5505f997b99adbaefeb92891dc` |
| `E1-claim.json` (10:20:35Z) | `94d8661a4ed5d6f941d596fa07ee2fe3c5ce270786830b3e8b6b0c696df29005` |
| `E1-consumed.json` (10:20:39Z → 10:23:12Z) | `f5d652355a8c5ed5b47b7839ca5dc2856cdd93c901bb4a7859ea4fe4b04289f6` |
| `e1-replay.npz` | `1090b5f114f23a2e00c2ec997afa206cd12bf269dc635b9b68dca3ae80e79254` |
| `e1-manifest.json` | `06de67bd37d338996b8f22d772f70401d090991fa786bc9b58a83f806cf8cd1f` |
| `analysis-claim.json` (10:23:23Z) | `76808719d3dacdeefadf331247978ae10487c475316ea29f991456266f14775d` |
| `analysis-consumed.json` (10:23:23Z → 10:23:24Z) | `2ce20cb5084e8090e0fa61f05a7106a2ce38c51d51239d3355ca839c621ec057` |
| `e1-analysis.json` | `69707e3dd749cfec0303f412a9c1c4cf115bce6ffbfc96b007544d068788696a` |

Les trois réclamations portent le même corps de protocole, le même code et les mêmes décisions ; celles
d'E1 et de l'analyse enregistrent les empreintes de leurs prérequis, égales à celles des sorties ci-dessus.
Garde mémoire (seuil 4 000 Mo) jamais déclenchée ; reprise D3 non utilisée ; `nice -n 15`, 2 workers.

## Ce qui a été étudié

- **Population P2-B4** : moteur tick `research_exec` gelé pour r10–r12 (entrée E1 au meilleur prix, latence
  250 ms, règle de traversée, maker 2 bp, durée de vie de l'ordre 45 s) ; sortie B4 (stop à max(1,5 × ATR14
  5 min, 2 ticks), sortie au temps 2 h, pas d'objectif). 30 configurations tirées des textes de r10, r10x,
  r11 et r12 (`R1|both` retiré, D2), imbriquées les unes dans les autres.
- **Univers** : 19 paires (les vingt de r8/r10 moins BTCUSDT, retiré par nom avant tout calcul).
- **Fenêtres** : les quatre semaines d'entraînement r10 (w2404, w2405, w2406, w2408), soit 76 séries
  paire × semaine et 380 paires-jours, données Binance USD-M aggTrades déjà lues. Cotations reconstruites à
  partir des transactions (aucun carnet Binance sur disque).
- **Coûts** : c = 2 bp (entrée maker) + 5 bp (stop taker) + écart + 1 tick ; exécution réaliste du stop ;
  financement non facturé (D4).

## Réponses aux questions A–D du propriétaire

Les réponses s'en tiennent aux métriques gelées (§11 du protocole). Une partie de question qu'aucune
métrique gelée ne couvre est déclarée sans réponse plutôt que calculée après coup.

### A. Sélection

*Combien d'opportunités sont éliminées parce que leur levier théorique est ≤ 2× ? Combien subsistent dans
chaque tranche ? Assez de paires et de périodes pour une étude statistique ?*

Recensement E0a (`e0a-census.json`), 34 629 signaux distincts, tous déterminables (0 indéterminable, 0 au
plancher de deux ticks) ; 69 688 en comptage nominal (une copie par configuration, ratio 2,01) :

| Tranche | L_eng distincts | L_stop distincts |
|---|---:|---:|
| ]0,1] | 26 298 (75,9 %) | 21 559 (62,3 %) |
| ]1,2] (référence) | 8 093 (23,4 %) | 11 474 (33,1 %) |
| ]2,3] | 237 (0,68 %) | 1 380 (3,99 %) |
| ]3,5] | 1 | 210 (0,61 %) |
| ]5,10] | 0 | 6 |
| > 10 | 0 | 0 |
| **≤ 2× (éliminés par un filtre > 2×)** | **34 391 (99,3 %)** | **33 033 (95,4 %)** |
| **> 2×** | **238 (0,69 %)** | **1 596 (4,61 %)** |

- **Paires et périodes, L_eng.** Les signaux > 2× ne viennent que de trois majeures (comptage nominal de
  ]2,3] : ETH 343, BNB 124, LTC 10) et de deux semaines (w2405, w2406). Aucune mid-cap n'a de signal
  au-dessus de 2×.
- **Paires et périodes, L_stop.** ]2,3] touche 15 des 19 paires au recensement, aucune en w2404.
- **Après rejeu E1 (L_stop seulement)** : ]2,3] donne 418 ordres distincts et **240 trades distincts
  exécutés** (taux d'exécution 57,4 %), sur 15 paires et 3 semaines ; ]1,2] donne 2 806 ordres et 1 983
  trades (70,7 %), sur les 19 paires.
- **Assez pour une étude statistique ? Non.** Les seuls critères gelés sont le déclenchement E0a
  (recensement × 0,3 ≥ 400) et N_min = 400 trades distincts par bras. Sous L_eng, aucune tranche > 2× ne
  passe le premier. Sous L_stop, ]2,3] le passe de justesse (414,0) mais tombe à 240 au second.

### B. Performance

*La tranche > 2× a-t-elle une meilleure espérance brute et nette que la tranche ≤ 2× ? L'avantage
persiste-t-il après frais, écart, glissement et financement ? Résiste-t-il aux changements de période, de
paire et de volatilité ?*

- **Sous L_eng (la question posée) : sans réponse**, aucun trade rejoué.
- **Sous L_stop, aucun test informatif** (S4, ci-dessus). En descriptif, la tranche ]2,3] fait **moins
  bien** que ]1,2], en brut comme en net (`e1-analysis.json`, `arms`) :

| Mesure (R par trade distinct) | ]1,2] référence | ]2,3] |
|---|---:|---:|
| R brut moyen | +0,085 | +0,068 |
| Coût moyen en R | 0,148 | 0,226 |
| **R net moyen** | **−0,063** | **−0,158** |
| R net médian | −0,933 | −0,881 |
| Taux de gain | 30,4 % | 29,2 % |
| R net avec 0 / 1 / 2 / 5 ticks de glissement en plus | −0,063 / −0,080 / −0,097 / −0,148 | −0,158 / −0,189 / −0,221 / −0,314 |
| d / c médians (bp) ; c/(d + c) médian | 39,4 / 9,0 ; 0,189 | 22,8 / 9,4 ; 0,297 |

- **Frais, écart, glissement** : R est net des frais (2 bp + 5 bp) avec l'exécution réaliste du stop ;
  l'écart entre par c et les prix au meilleur ; le signe des deux bras et de Δ reste le même sur toute la
  grille (SIGN_STABLE). Le stop serré pèse plus lourd en coûts : c/(d + c) médian 0,297 contre 0,189.
- **Financement** : non facturé (D4). Seule l'exposition est publiée : 2,9 % des trades de ]2,3] et 7,5 %
  de ceux de ]1,2] traversent une échéance de financement (grille 00/08/16 UTC approximative).
- **Période** (moyennes par bras, trades / R moyen) : ]2,3] w2405 105 / −0,238, w2406 115 / −0,184,
  w2408 20 / +0,409, aucun trade en w2404 ; ]1,2] w2404 106 / −0,176, w2405 718 / +0,004, w2406 696 /
  −0,022, w2408 463 / −0,204. La seule semaine positive de ]2,3] compte 20 trades.
- **Paire** (]2,3], trades / R moyen) : LTC 58 / −0,135, XRP 49 / −0,442, ETH 43 / −0,006, BNB 33 / −0,179,
  ATOM 16 / −0,413, ADA 13 / −0,681, DOGE 8 / +0,668 ; les 8 autres paires ont 1 à 4 trades.
- **Volatilité : sans réponse.** Les comparateurs C1 (S7) et C2 (S8) sont définis sous L_eng et n'ont aucun
  trade.

### C. Risque

*Impacts du levier sur la marge, les liquidations possibles, les pertes extrêmes, le drawdown et le
capital disponible.*

- **Marge.** À taille canonique, notionnel / E = L_eng. Dans le bras L_stop ]2,3], L_eng va de 1,37 à 2,10
  (moyenne 1,61) ; dans ]1,2], de 0,75 à 1,56 (moyenne 1,06). En marge isolée avec un levier appliqué
  plafonné à 2 (A_max = 2), **9 des 240** trades de ]2,3] sont refusés (ceux dont L_eng > 2) ; 0 avec
  A_max = 10 ; 0 dans ]1,2].
- **Liquidations.** **0** avant la sortie, dans tous les scénarios (règles A_ceil et A_full, A_max 2 et 10,
  MMR mid-caps 0,005 / 0,01 / 0,02, majeures 0,01). Attendu : à A ≤ 10 la liquidation est à ≈ 9 % ou plus
  de l'entrée, bien au-delà de stops de moins de 25 bp.
- **Pertes extrêmes.** Pire trade −2,44 R (]2,3]) et −2,75 R (]1,2]) ; part sous −2 R : 1,25 % contre
  0,30 % ; glissement au-delà du stop p50 / p90 / max : 0,000 / 0,093 / 1,44 R contre 0,010 / 0,064 /
  1,78 R ; exécution réaliste moins exécution figée : −0,074 R contre −0,048 R. Aucun stop déclenché dans la
  seconde de l'exécution. À lire contre le plancher mécanique : un même glissement en bp pèse plus en R
  quand d + c est petit.
- **Drawdown et capital disponible : non mesurés.** Le rejeu de portefeuille à 500 USDT **n'est pas
  autorisé** dans ce protocole (§6) : pas de courbe de capital, pas de drawdown, pas de série de capital
  immobilisé. Seules des mesures par trade et analytiques sont publiées.

### D. Robustesse

*Concentration sur quelques paires, journées ou les 5 % meilleurs trades ? La différence est-elle crédible
ou due à la sélection post hoc ? Comparateur qui sépare levier, distance de stop et volatilité.*

- **Paires.** Part maximale d'une paire dans ]2,3] : 24,2 % (LTC) ; dans ]1,2] : 8,8 % (BNB). Sous L_eng,
  au recensement, ETH porte 72 % des signaux ]2,3] (nominal).
- **Configurations.** Part maximale d'une configuration (nominal) : 23,2 % (`P3-A3-K0|short`) dans ]2,3],
  20,2 % (même configuration) dans ]1,2]. Les configurations sont imbriquées : 474 copies nominales pour
  240 trades distincts dans ]2,3] (234 copies en surnombre), 3 742 pour 1 983 dans ]1,2].
- **Journées et 5 % meilleurs trades : sans réponse.** Aucune métrique gelée ne les couvre ; seuls
  `best_r` (+7,80 R dans ]2,3], +16,40 R dans ]1,2]) et `worst_r` sont publiés. Les grappes paire × jour
  du bootstrap portent la dépendance entre jours.
- **Crédibilité.** Il n'y a pas de différence à juger : aucun test n'est informatif. Le seul Δ calculé
  (S4, −0,095 R) a un intervalle qui contient 0, repose sur 240 trades (sous N_min) et serait de toute
  façon dans une famille de Holm de neuf. L'étude est post hoc sur des fenêtres consommées, et les
  résultats agrégés B4 de ces configurations sur ces 380 paires-jours étaient déjà connus (r10, r10x,
  r11, r12).
- **Séparer levier, distance de stop et volatilité : sans réponse.** C1 (S7) et C2 (S8) n'ont aucun trade.
  Sous L_stop, la tranche ]2,3] est en fait majoritairement ≤ 2× sous L_eng (231 des 240 trades, puisque
  seuls 9 sont refusés à A_max = 2) : « L_stop > 2 » et « L_eng > 2 » ne désignent pas les mêmes trades.
  Le ratio c/(d + c) est indissociable du levier et n'est rapporté que comme mécanisme.

## Ce qu'on ne peut pas conclure

- Que les opportunités > 2× sont meilleures, pires ou équivalentes : aucun test n'est informatif, et sous
  la définition principale il n'existe presque pas d'opportunité > 2× (0,69 % des signaux, trois majeures,
  deux semaines).
- Un effet causal du levier : par construction, le levier ne change pas R ; seule une association avec la
  distance de stop est mesurable.
- Une stratégie, une validation ou une réplication : données consommées, configurations déjà en échec.
- Un transfert vers OKX Europe X-Perps : données Binance USD-M, cotations reconstruites ; ticks, tailles de
  contrat, frais, intervalles de financement et leviers maximaux diffèrent et ne sont pas modélisés.
- Le drawdown, le capital immobilisé, la concentration par journée ou dans les 5 % meilleurs trades,
  l'espérance en points de base, le facteur de profit : non calculés par le protocole gelé.

## Ce qui reste non autorisé

Non exécuté, et pas autorisé par ce protocole (§6) : le rejeu de portefeuille à 500 USDT ; les sorties à
objectif B1–B3 ; les sorties σ (sur q3x) ; la ligne à filtre de coût K2 sur sa propre population sous
L_stop ; le contrôle aléatoire C3 ; le recadrage « stop serré à volatilité égale ». Chacun demanderait un
nouveau protocole et un go du propriétaire.

## Écarts et réserves

- **Hypothèse f_min = 0,3 trop optimiste.** Le déclenchement E0a prévoyait 414 trades pour L_stop ]2,3] ;
  E1 en a produit 240 (17,4 % des 1 380 signaux distincts). Une position par paire, la durée de vie de
  45 s et la règle de traversée en retirent plus que prévu. C'est une remarque sur l'hypothèse du
  déclenchement, pas une raison de relancer.
- **Redéfinition des lignes « both »** (D2) : `P0-A3-K0-Z1|both` fusionne ses deux flux (une position par
  paire), ce qui diffère de la sémantique de r11 ; `R1|both` est retiré.
- **Financement non facturé** (D4) ; la grille d'échéances 00/08/16 UTC est approximative (fausse pour une
  paire à 4 h en 2024).
- **Cotations reconstruites** à partir des transactions ; MMR des majeures « non attesté », MMR des mid-caps
  inconnu (trois valeurs testées).
- **Libellé corrigé.** Le résumé de l'étape E1 décrivait 234 / 1 759 comme « trades présents dans plusieurs
  configurations » ; c'est en réalité le nombre de copies nominales en surnombre (nominal − distinct,
  `stats.arm_description`). Les nombres ne changent pas.
- **Différences calculées dans ce rapport** : seules des sommes et des parts sur les comptages gelés
  (≤ 2× / > 2×, 231 = 240 − 9, 17,4 %) ; aucune nouvelle métrique de résultat.
- **Aucun écart d'exécution** : chaque étape exécutée une fois avec la CLI gelée, empreintes du protocole et
  du code identiques à chaque réclamation, aucun appel réseau autre que git, aucun appel à une plateforme,
  aucun ordre, BTC jamais lu.
