# DATA-EXPOSURE — scalp-q3-r2 (inventaire des données déjà consommées, 2026-10-14)

Rédigé le 2026-10-14 vers 12:10Z, **avant tout calcul de performance de scalp-q3-r2**. Méthode : lecture
des pré-enregistrements, notes exploratoires, README, journaux d'exécution, manifestes (listes de
fichiers) et des seules métadonnées `phase` / `series` / `generated_at` des sorties de screen. Aucun
fichier de marché n'a été ouvert pour cet inventaire. Les affirmations de la note de mémoire de
l'orchestrateur ont été vérifiées une à une ; les écarts sont listés au §4.

Conventions : `R = tradingV3-private/research/`, `X = R/20261012T060000Z-execution-r1/part-b/`.
**MAJ** = BTC ETH SOL XRP DOGE BNB ADA LTC LINK AVAX ; **MID** = ARB OP APT SUI NEAR TIA SEI INJ ATOM FIL
(perpétuels USDT-M Binance sauf mention).

## 1. Inventaire par protocole

| Protocole | Données / univers | Périodes (rôle) | Lu ? | Preuve |
|---|---|---|---|---|
| day_trading Codex + explore-entry r1/r2 | MAJ, bougies 1m → 1m…4h | entraînement 2023-01..2024-12 ; validation 2025 | les deux lus (1 312 configs aussi sur 2025) ; holdout 2026 jamais lancé | `R/20261009T060000Z-explore-entry-r2/README.md` |
| benchmarks / smoke | BTC, ETH 1m | 2022-11..2023-01 | lu | `R/…benchmark-btc-eth/manifest.json` |
| swing r1, r2 | MAJ 4h (depuis 1m), funding | 2023-24 ; 2025 | lus | `R/…swing-r1/README.md`, `R/…swing-r2/PREREGISTRATION.md` |
| swing rehearsal r1 | MAJ 4h | 2024-07..09, 2025-07..09, 2025-11 | lu (mécanique) | `R/…swing-rehearsal-r1/README.md` |
| campagne swing canonique | MAJ 4h + funding | 2023-24, 2025, **holdout 2026-01-01 → 2026-10-09T06:00Z** | tout lu (claim 2026 n° 1) | `R/…swing-campaign-r1/holdout/report.json` |
| explore-v2 (+ scalping v2) | MAJ 1m → 15m/4h (5m/1m) | 2023-24 ; 2025 | entraînement lu ; validation 2025 lue pour 4 configs (pas pour le scalping) | `R/20261012T003000Z-explore-v2/` |
| r1 captures (parties A et B) | **OKX + Hyperliquid, tick + top of book, BTC/ETH, 2026-10-02/03 (24 h)** | A : 24 h ; B : 12 h entraînement | lus (validation B non lancée) | `X/../PREREGISTRATION.md` §1.1, `X/captures/verdict.json` |
| r1, r2 Binance | MAJ aggTrades | mars + juillet 2025 | entraînement seul | `X/binance/`, `X/binance-r2/run.log` |
| r3 | MAJ aggTrades | mars/mai/juillet/sept. 2025 ; **novembre 2025 (validation, 2 configs)** | entraînement + novembre ; décembre non lu | `X/binance-r3/run.log` |
| r4 | MAJ | mars/mai/juillet/sept. 2025 | entraînement seul | `X/binance-r4/run.log` |
| r5 | MAJ | 9 fenêtres 2025 (fév.–oct.) | entraînement seul | `X/binance-r5/screen-training.json` |
| r6 | MID aggTrades | 9 fenêtres 2025 | entraînement seul | `X/binance-r6/` |
| r7 | MID | **novembre 2025** (validation) ; **décembre 2025** (holdout) | les deux lus | `X/binance-r7/run.log` |
| r8 | MAJ + MID aggTrades + bookDepth | 9 fenêtres 2025 | entraînement seul | `X/binance-r8/run.log` |
| r8b | MAJ + MID aggTrades + bookDepth | **2025-01-13..17** (validation, 2 configs) ; **2024-12-09..13** (holdout, 1 config) | les deux lus une fois | `X/binance-r8b/run.log` |
| r9 | 18 paires (sans TIA, SEI) bookTicker + aggTrades | 2023-07-10..14, 09-11..15, 11-13..17, 2024-01-15..19 ; val. 2024-02-12..16 ; holdout 2024-03-25..29 | entraînement seul ; février/mars 2024 téléchargés puis supprimés **sans lecture** | `X/binance-r9/run.log` (« February 2024 stays unread ») |
| r10, r10x | 20 paires aggTrades 2024-04-15..19, 05-13..17, 06-10..14, 08-12..16 + **klines 1m mars–août 2024** | entraînement / exploratoire | lus ; validation (sept. MID) et holdout (nov. MID) jamais lancés, répertoire holdout vide | `R/20261013T121000Z-scalping-120-r10/` |
| r11 | mêmes 4 semaines | entraînement | lu ; rien sélectionné, validation/holdout jamais acquis | `R/…zone-time-r11/part-b/binance-r11/run.log` |
| r12 | 4 semaines + **MID septembre 2024 complet** (aggTrades + klines 1m août–sept.) | entraînement + validation | lus ; holdout novembre 2024 non lancé | `R/…zone-only-r12/part-b/binance-r12/run-validate.log` |
| r13 | 4 semaines aggTrades + metrics (OI), 20 paires | entraînement | lu ; validation octobre non lancée | `R/…oi-positioning-r13/` |
| r14 | **MID octobre 2024 complet** aggTrades + metrics | validation | lue ; holdout novembre 2024 non lancé | `R/…oi-buildup-r14/part-b/binance-r14/run-validate.log` |
| q3x | 20 paires aggTrades + bookDepth, 9 fenêtres 2025 | exploratoire (entraînement seul) | lu | `R/20261014T130000Z-q3x/screen-training.json` (180 séries) |
| swing v2 | MAJ 4h 2023-24 (entraînement) ; **MID 1m → 4h + funding, décisions 2023-01-01 → 2026-01-01** (validation) | les deux lus | | `R/20261014T083000Z-swing-v2/PREREGISTRATION-swing-v2.md` §1 |
| swing v2 look2026 | **MID 4h + funding, 2026-01-01 → 2026-10-09T06:00Z** | holdout indicatif (claim 2026 n° 2) | lu | `R/20261014T094500Z-swing-v2-look2026/holdout-claim.json` |
| swing v2 OKX (en cours) | 30 + 82 altcoins OKX hors programme, bougies 1m → 2026-10-12 | forme des données seulement | pas de screen | `R/20261014T110000Z-swing-v2-okx/` |
| regime-r1 / regime-r2 | r1 jamais exécuté ; r2 : spot quotidien 2017-08 → 2023-01, perpétuels quotidiens + funding 2020-01 → 2023-01 | entraînement | lu (r2) | `R/20261014T120000Z-regime-r2/` |
| fwd-okx-r1 | OKX, 20 paires, trades + bbo-tbt + carnet REST 30 s, capture depuis 2026-10-13 ~10:50Z | W0 14-16/10 rodage ; **W1 19-23/10 et W2 26-30/10 interdits** | rien relu à ce jour | `PREREGISTRATION-forward-okx-r1.md` §3 |
| scalp-q3-r2 (ce protocole) | funding mensuel 2025-02..10, 20 paires (180 fichiers, sha256 vérifiés) | entraînement | acquis, aucun calcul de performance | `R/20261014T1200Z-scalp-q3-r2/funding-tape/manifest-fundingrate.json` |

## 2. Vérification des affirmations de départ

1. **Fenêtres d'entraînement 2025 (tick, 20 paires) : vrai**, par sous-ensembles : r1/r2 MAJ mars+juillet ;
   r3/r4 MAJ mars/mai/juillet/sept. ; r5 MAJ 9 fenêtres ; r6 MID 9 fenêtres ; r8 et q3x 20 paires 9 fenêtres
   (aggTrades + bookDepth). Ces fenêtres sont l'entraînement de scalp-q3-r2 : **consommées plusieurs fois**.
2. **Janvier 2025 (13-17) et décembre 2024 (09-13) : vrai**, lus une fois par r8b (tick + bookDepth, 20 paires).
3. **Novembre / décembre 2025 : partiellement vrai.** MAJ novembre lu par r3 (validation) ; **MAJ décembre
   2025 jamais lu au niveau tick** (acquis puis supprimé) ; MID novembre et décembre 2025 lus par r7.
4. **Mid-caps 2024 : vrai au niveau tick, faux à tous niveaux.** Septembre 2024 lu par r12, octobre 2024 par
   r14, novembre 2024 **jamais acquis au niveau tick/metrics**. Mais swing v2 a lu en validation les bougies
   1m → 4h des MID de 2023-01 à 2025-12, **novembre 2024 compris**, et look2026 a lu 2026 jusqu'au 09/10.
5. **Avril/mai/juin/août 2024 : vrai** (r10, r10x, r11, r12, r13 ; 20 paires ; klines 1m mars–août 2024 en contexte).
6. **bookTicker 2023-07..2024-03 (r9) : vrai** ; février et mars 2024 jamais lus (acquis puis supprimés).
7. **day_trading / swing / v2 : vrai** ; le holdout 2026 des MAJ (4h) a été consommé par la campagne swing.
8. **regime : nuance** ; regime-r1 n'a jamais tourné ; regime-r2 n'a lu que 2017-08 → 2023-01 (quotidien).
9. **Forward OKX : vrai** ; W1/W2 restent interdits à scalp-q3-r2, pour toujours (y compris après le verdict
   de fwd-okx-r1, voir §3).
10. **Données 2026 déjà touchées :** captures OKX/HL BTC/ETH 2026-10-02/03 (r1, tick) ; MAJ 4h jusqu'au
    2026-10-09T06:00Z (campagne swing) ; MID 4h même période (look2026) ; capture OKX fwd-okx-r1 depuis le
    13/10 (réservée) ; altcoins OKX hors programme (swing v2 OKX, forme des données).

## 3. Ce qui reste disponible, et décision pour scalp-q3-r2

**Jamais lu à aucun niveau :** pour les 20 paires, plus rien sur Binance entre 2023-01 et 2026-10-09
(les bougies 4h/1m ont tout couvert) ; restent seulement les données Binance postérieures au
2026-10-09T06:00Z (non téléchargées) et la capture OKX postérieure à fwd-okx-r1.

**Jamais lu au niveau tick, trajectoire de prix vue en bougies (contamination partielle) :**

| Tranche | MAJ | MID | Remarque |
|---|---|---|---|
| **Novembre 2024** (mois entier) | tick non lu ; bougies lues (day_trading, swing 2023-24) | tick non lu ; 4h lu par swing v2 | jamais réservé ni lu par une famille tick ; voisin de la semaine 2024-12-09..13 lue par r8b |
| Septembre–octobre 2024 | tick non lu | **lu au tick** (r12, r14) | utilisable pour MAJ seulement |
| Février–mars 2024 | tick non lu (r9 : téléchargé, non lu) | idem (8 paires) | bookDepth 2024 jamais lu ; disponibilité à vérifier par liste de fichiers |
| Juillet 2024 | tick non lu ; klines 1m lus (r10, rehearsal) | idem | |
| Décembre 2025 | tick non lu | **lu** (r7) | MAJ seulement |
| Semaines 2025 hors des 12 fenêtres | tick non lu ; bougies lues | idem | même année que l'entraînement, adjacentes : faible indépendance |
| 2026 (Binance, jusqu'au 09/10) | tick non lu ; 4h lu (holdout swing) | tick non lu ; 4h lu (look2026) | |

**Lecture.** Une lecture en bougies 4h d'une autre famille (cassures swing) n'a fixé aucun paramètre de
Q3/S7 (EMA 900 s, K = 1,5, `fi_60`, `di_1`, Z3 viennent tous de r3–r8 sur les fenêtres 2025). La
contamination est donc **faible mais réelle** : l'expérimentateur a vu le régime de marché de ces mois
(direction, volatilité) au travers d'autres résultats ; elle est déclarée, pas ignorée.

**Décision (figée avec le pré-enregistrement) :**

- **Phase 1 (cette mission) :** uniquement les neuf fenêtres d'entraînement r8 2025, déjà consommées.
- **Phase 2 — validation réservée :** **novembre 2024, 20 paires, aggTrades + bookDepth, quatre semaines
  de cinq jours ouvrés** (2024-11-04..08, 11..15, 18..22, 25..29), jamais lues au niveau tick par aucun
  protocole. Elle n'est **ni téléchargée ni lue dans cette mission**. Limites déclarées : prix vus en
  bougies (swing v2 pour les MID, day_trading/swing pour les MAJ) ; proximité de la semaine de décembre
  2024 lue par r8b ; carnet toujours reconstitué depuis les transactions (Binance).
- **Phase 3 — test final :** aucun échantillon historique Binance restant n'est suffisamment indépendant
  (tout 2023-2026 a été vu en bougies ; les tranches tick restantes sont soit des MAJ seules, soit
  adjacentes à l'entraînement). Le test final proposé est donc **prospectif, sur OKX**, avec une
  **nouvelle capture** commençant après la fin de fwd-okx-r1 (au plus tôt le lundi 2026-11-02), jamais
  sur les fenêtres W0/W1/W2 de fwd-okx-r1, et pré-enregistré **avant** que W1 de fwd-okx-r1 soit lu
  (≥ 2026-10-24) pour qu'aucune information du forward ne puisse l'orienter.
- **Interdits permanents pour scalp-q3-r2 :** janvier 2025 et décembre 2024 (r8b), novembre et décembre
  2025 MID (r7), novembre 2025 MAJ (r3), septembre/octobre 2024 MID (r12, r14), toute donnée de
  fwd-okx-r1 (W0, W1, W2) et ses résultats.

## 4. Écarts relevés par rapport à la note de mémoire

- « Mid-caps 2026 UNREAD, 2nd 2026 claim not used » est périmé : look2026 a consommé le claim 2026 n° 2
  sur les MID (2026-10-14T08:56:15Z).
- « Nov 2024 mid-caps still unread / LAST unread 2024 month » n'est vrai qu'au niveau tick : swing v2 a lu
  les bougies 4h des MID 2023-2025, novembre 2024 compris.
- Absents de la note : r1 a lu des ticks OKX/HL de 2026 (BTC/ETH, 2026-10-02/03) ; r10 a chargé des
  klines 1m mars–août 2024 pour les 20 paires ; r12 et r14 ont lu des mois entiers ; décembre 2025 des MAJ
  n'a jamais été lu au tick.
- Glissements de l'audit (déclarés) : l'auditeur a vu les chiffres de tête de `verdict.json` de r12, le
  début du `run.log` de look2026 (métadonnées de comptage) et quelques volumes quotidiens d'altcoins OKX
  hors programme ; rien qui concerne Q3/S7 ni les périodes réservées.
