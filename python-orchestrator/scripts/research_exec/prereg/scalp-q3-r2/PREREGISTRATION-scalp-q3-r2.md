# PREREGISTRATION — scalp-q3-r2 (Scalping Q3 V2, 2026-10-14)

Rédigé le 2026-10-14 (≈ 12:15Z) sur la mission « scalp-q3-r2 » du propriétaire, **avant tout nouveau
calcul de performance**. Dossier de recherche dédié, séparé de `fwd-okx-r1`. Inventaire des données :
`DATA-EXPOSURE-scalp-q3-r2.md` (même dossier, figé avec ce document).

Ce protocole est **une étude d'entraînement (phase 1) sur des données déjà consommées**. Il ne peut
produire aucun candidat de trading ; il peut seulement conclure `RESEARCH_CANDIDATE` (« mérite une
validation indépendante »), `INSUFFICIENT_EVIDENCE` ou `NO_GO`. Il ne lit ni la validation (phase 2) ni le
test final (phase 3), qu'il se contente de figer.

Rien ici ne modifie `fwd-okx-r1` (règle `Q3-W900-K1.5-T0.1|long|E1|Z3`, données, paramètres, critères,
services systemd, fenêtres W1/W2). Aucun ordre réel ou démo n'est placé.

## 1. Hypothèses testées

Origine : l'étude exploratoire q3x (2026-10-14, `EXPLORATORY-q3x.md`, sha256 `4112da7c…`) sur ces mêmes
fenêtres. **Toutes les observations q3x sont exploratoires ; aucune n'est un paramètre validé.**

- **H1 — utilité du flux.** Le filtre `fi_60 > 0` (flux taker acheteur des 60 dernières secondes) a-t-il
  un surplus de rendement net sur des occasions comparables sans ce flux (même état de bande, même
  déviation normalisée, même liquidité, même heure UTC, même série) ? Et, au niveau portefeuille, S7
  (bande + flux) est-il meilleur que Q3 (bande + flux + profondeur) ?
- **H2 — volatilité en temps actif.** Mesurer la volatilité sur les seules secondes avec transactions
  (`active_sigma`) au lieu des secondes calendaires (`calendar_sigma`) améliore-t-il la robustesse, ou
  sélectionne-t-il seulement moins de trades ? Les deux mesures sont **deux hypothèses statistiques
  différentes** (temps calendaire contre temps de marché) ; `active_sigma` n'est pas une correction de bug.
- **H3 — protection.** Un stop-market fixé avant l'exécution protège-t-il les pertes extrêmes sans
  détruire l'espérance ?

## 2. Univers et périodes

- **Paires (20, figées, les mêmes que r8/q3x/fwd-okx-r1)** : BTC, ETH, SOL, XRP, DOGE, BNB, ADA, LTC, LINK,
  AVAX, ARB, OP, APT, SUI, NEAR, TIA, SEI, INJ, ATOM, FIL (perpétuels USDT-M Binance). Aucune paire n'est
  retirée ni ajoutée en fonction de son PnL (les écarts majors / mid-caps sont rapportés, jamais utilisés
  pour choisir).
- **Phase 1 (seule phase de cette mission)** : les neuf fenêtres d'entraînement r8 de 2025 (02-10..14,
  03-10..14, 04-14..18, 05-12..16, 06-16..20, 07-14..18, 08-11..15, 09-15..19, 10-13..17), 180 séries,
  déjà sur disque (`20261014T130000Z-tape-binance-q3x` : aggTrades `npz/`, bookDepth `depth/`), déjà
  consommées par r1–r8 et q3x. Instruments : `instrument-assumptions-r8.json`.
- **Funding** : archives mensuelles Binance `fundingRate` 2025-02..2025-10 des 20 paires, téléchargées le
  2026-10-14 (180 fichiers, sha256 vérifiés contre les `.CHECKSUM` publiés, 0 échec) dans
  `funding-tape/` ; aucune autre donnée n'est acquise.
- **Phase 2 (réservée, non lue)** : novembre 2024, 20 paires, aggTrades + bookDepth, semaines
  2024-11-04..08, 11..15, 18..22, 25..29 (§9).
- **Phase 3 (réservée)** : test prospectif OKX sur une nouvelle capture commençant après la fin de
  fwd-okx-r1 (au plus tôt 2026-11-02), quatre semaines (§9).
- **Interdits** : janvier 2025, décembre 2024, novembre/décembre 2025, septembre/octobre 2024 des mid-caps,
  toute donnée ou tout résultat de fwd-okx-r1 (W0, W1 19-23/10, W2 26-30/10). Le code refuse toute phase
  autre que `training` et toute fenêtre hors de la liste d'entraînement (test automatisé).

## 3. Variantes exactes

Long uniquement, décision à la fin de chaque seconde, paramètres de prix **inchangés** (EMA 900 s du mid,
seuil −1,5 σ, `fi_60 > 0`, `di_1 > 0,1` pour Q3). Aucune optimisation d'EMA, de K, de fenêtre de flux
ni de seuil de profondeur.

| ID | Règle (code) | Signal | Volatilité |
|---|---|---|---|
| C1 | `S7-W900-K1.5` | `band_dev_900 < −1,5·sigma_900` et `fi_60 > 0` | calendar |
| C2 | `Q3-W900-K1.5-T0.1` | C1 et `di_1 > 0,1` | calendar |
| C3 | `S7L-W900-K1.5` (nouvelle famille) | `band_dev_900 < −1,5·sigma_900_live` et `fi_60 > 0` | active |
| C4 | `Q3L-W900-K1.5-T0.1` (famille q3x) | C3 et `di_1 > 0,1` | active |

- `calendar_sigma = sigma_900` : racine de la somme des carrés des rendements log du mid sur les
  900 dernières secondes calendaires, plancher d'un tick / mid (définition r3–r8, inchangée).
- `active_sigma = sigma_900_live` : même somme restreinte aux secondes ayant au moins une transaction,
  multipliée par `900 / n_live` (n_live = nombre de ces secondes parmi les 900), puis racine ; plancher
  d'un tick / mid ; NaN si n_live = 0. Sur Binance le mid est reconstitué depuis les transactions : une
  seconde sans transaction a un rendement nul, donc `active_sigma = calendar_sigma · sqrt(900 / n_live)`.
- Chaque signal est testé avec deux sorties : **Z3** (référence de recherche, sans stop) et **Z3-SL200**
  (protégée). Soit **8 configurations**, dont **4 protégées seules éligibles**. Les variantes sans stop
  sont des références et ne sont jamais éligibles au trading.

## 4. Modèle d'entrée et de sortie

- **Entrée E1 (inchangée)** : ordre limite passif au meilleur bid (prix arrondi au tick) actif 250 ms
  après la fin de la seconde de décision, annulé s'il croiserait le ask live à l'activation (bloque 5 s),
  rempli selon la règle `through` (une transaction strictement sous le prix), TTL 30 s, pas de fallback
  taker ; aucune décision si le mid manque ou si la cotation a plus de 10 s. Une position (ou un ordre)
  à la fois par paire.
- **Sortie Z3 (inchangée)** : ordre passif au ask live à `fill + 3 600 s + 250 ms`, TTL 300 s, rempli selon
  la règle `through` ; sinon sortie taker au bid live à `fill + 3 900 s + 250 ms`.
- **Sortie Z3-SL200 (nouvelle)** : Z3 plus un stop-market posé avec l'ordre d'entrée, actif dès le
  remplissage. Déclencheur `T = floor_tick(entrée × (1 − 0,0200))`. Déclenché par la première transaction
  postérieure à la transaction de remplissage dont le prix est ≤ T, à un instant strictement antérieur à
  la sortie Z3 (remplissage de l'ordre passif de sortie, ou instant `fill + 3 900 s` de l'ordre de
  fallback). Exécution au marché 250 ms après le déclenchement, au prix
  `min(prix de la transaction déclenchante, plus bas des transactions dans les 250 ms, bid live à
  l'exécution)` ; l'ordre passif de sortie éventuel est annulé ; frais taker. Si le stop se déclenche, la
  sortie Z3 n'a pas lieu.
- **Choix du stop (déclaré ici, avant simulation)** : **un seul niveau, −200 bp du prix d'entrée,
  indépendant du levier.** Ce seuil **provient de l'observation exploratoire q3x** (7 % des trades Q3 au
  MAE < −200 bp, 27 % < −100 bp ; un stop à −100 bp couperait trop de trades qui finissent mieux) et est
  déclaré comme tel. Aucune autre distance n'est testée (pas de grille, pas de stop en multiple de
  volatilité) pour ne pas multiplier les variantes.
- **Proxy de cotation** : sur Binance il n'y a pas de bid/ask historique ; la cotation est reconstituée
  à la seconde depuis les transactions (bid = dernier prix vendeur taker, ask = dernier prix acheteur
  taker, un tick d'écart s'ils se croisent). Remplissages, MAE/MFE et prix de stop sont des
  approximations ; c'est une limite déclarée, pas un paramètre.
- **Quantité disponible** : au niveau portefeuille, un ordre d'entrée n'est rempli que pour la quantité
  cumulée des transactions `through` entre la première d'entre elles et la fin du TTL (hypothèse d'être
  dernier dans la file) ; si elle est inférieure à la quantité de l'ordre, la position est partielle ; si
  la quantité partielle arrondie au pas est inférieure au minimum, l'ordre est traité comme non rempli.
  Pour la sortie passive, la part des sorties dont le volume `through` est inférieur à la quantité est
  rapportée (diagnostic).
- **Décision → ordre** : la décision utilise tout ce qui précède la fin de la seconde ; l'ordre est actif
  250 ms plus tard ; l'âge de la cotation à la décision et le déplacement du bid entre décision et
  remplissage sont rapportés.

## 5. Paramètres de risque (portefeuille principal)

- Capital initial **500 USDT**, levier maximum **1×** (marge = notionnel), pas d'emprunt, pas de capital
  hors de ces règles.
- **Equity de dimensionnement** = equity réalisée (capital initial + PnL réalisés nets de frais et
  funding) à l'instant de la décision.
- **Notionnel** = `0,005 × equity / d` avec `d = (200 + frais maker entrée + frais taker sortie +
  slippage additionnel du scénario + 20) / 10 000` (20 bp de marge pour le dépassement du stop) ; quantité
  arrondie **à l'inférieur** au pas de quantité ; refus si quantité < minimum ou notionnel < minimum
  notionnel de l'instrument. Les références sans stop sont dimensionnées avec **le même notionnel** que
  leur jumelle protégée (comparaison à exposition égale) et marquées non éligibles.
- **Contraintes globales**, vérifiées à chaque décision : au plus **2** éléments actifs (ordres en attente +
  positions) ; une seule position ou un seul ordre par symbole ; risque nominal (notionnel × d) ≤ 0,5 % de
  l'equity par trade et ≤ 1 % au total ; exposition brute (notionnels actifs + nouveau) ≤ 80 % de
  l'equity ; marge totale ≤ equity. Un refus est compté avec sa raison (`slots`, `exposure`, `risk`,
  `min_notional`, `capital`) et ne bloque pas la paire : sa prochaine seconde de signal est réévaluée.
- **Réservation** : du moment de décision à la fin de l'ordre (expiration du TTL, annulation) ou, s'il est
  rempli, jusqu'à la sortie.
- **Signaux simultanés** : traités par instant de décision croissant ; à égalité, par ordre alphabétique
  du symbole (règle déterministe indépendante du profit futur).
- Stop obligatoire sur les configurations candidates (seules les Z3-SL200 sont éligibles).

## 6. Frais, funding et slippage

- **Base (historique du protocole)** : maker 2 bp, taker 5 bp, pas de slippage additionnel (le prix des
  sorties taker et du stop est déjà celui du proxy de cotation).
- **Pessimiste** : frais × 1,5 (maker 3 bp, taker 7,5 bp) **et 3 bp** de slippage additionnel sur chaque
  exécution taker (fallback Z3 et stop). 3 bp ≈ demi-écart typique des mid-caps les moins liquides ;
  valeur déclarée, non calibrée.
- **Stress d'exécution** : scénario pessimiste plus latence 1 000 ms (entrée et sorties).
- **Funding** : pour chaque règlement `T` de l'archive tel que `fill < T ≤ sortie`, la position longue paie
  `taux × quantité × P_T` (P_T = dernière transaction à ou avant T ; un taux négatif est reçu). Contrôle
  de complétude : sur chaque fenêtre (± 8 h), l'écart entre règlements consécutifs doit égaler
  l'intervalle déclaré par l'archive (± 1 min). **Un règlement manquant n'est jamais mis à zéro** : le
  trade concerné est chargé au taux absolu maximal observé pour ce symbole et ce mois (défavorable), et
  compté et publié. Le niveau signal (bp) est rapporté sans funding (comparabilité historique) et avec.
- PnL USDT d'un trade : `q·(sortie − entrée) − q·entrée·f_entrée − q·sortie·f_sortie − q·sortie·slippage
  − funding`.

## 7. Comparaison avec le hasard

- **Contrôle historique (continuité)** : timing aléatoire apparié à l'heure (nombre de trades par heure
  UTC et par série, 10 graines, `screen._control_decisions`), pour vérifier la parité avec q3x.
- **Contrôle principal (« décalage d'épisodes »)** : un épisode = suite maximale de secondes consécutives
  où le signal est vrai dans une série. Pour chaque graine, chaque épisode est déplacé à un départ tiré
  uniformément parmi les secondes de la même série ayant la même heure UTC que son départ, en gardant sa
  longueur (tronquée à la fin de la série) ; l'union des épisodes déplacés est le signal de contrôle. Il
  reproduit la fréquence, la persistance et le profil horaire du candidat dans la même paire et la même
  semaine, avec la même géométrie d'exécution, les mêmes coûts et, au niveau portefeuille, les mêmes
  contraintes de capital. Graines : **1 000** au niveau signal, **200** au niveau portefeuille (les 200
  premières, identiques) ; 200 au lieu de 1 000 parce que le portefeuille exige le résultat d'ordre de
  chaque seconde de contrôle (≈ 25 millions de simulations d'ordre pour 200 graines) ; résolution de p
  0,005.
- **Contrôle apparié à l'état de bande (H1)** : pour chaque configuration, réservoir = secondes de la même
  série où la bande (et, pour C2/C4, la profondeur) est dans l'état du signal **mais pas le flux**
  (`fi_60 > 0` faux), cotation fraîche. Strates : heure UTC (24) × déviation normalisée
  `z = −band_dev_900 / sigma` ([1,5 ; 2[, [2 ; 3[, ≥ 3) × liquidité `stale_900` (< 5 %, 5–20 %, ≥ 20 %).
  Par série et strate, autant de secondes tirées (avec remise) que le candidat a passé d'ordres dans
  cette strate ; rejeu avec la même géométrie. 1 000 graines. Les strates sans réservoir sont comptées.
- **Surplus** = moyenne nette du candidat − moyenne nette du contrôle (toutes graines). **p** unilatéral
  = `(1 + #{graines dont la moyenne ≥ candidat}) / (1 + R)`.
- **Intervalles de confiance** : bootstrap par **blocs de jours UTC** (toutes les paires d'un même jour
  ensemble), 5 000 tirages, graine 20261014, IC 90 % (5e–95e centiles) et 95 % rapportés ; sensibilité
  par blocs de fenêtres (9 blocs). Les neuf fenêtres ne sont pas présentées comme neuf régimes
  indépendants. Portefeuille : bootstrap par jours de la différence de PnL quotidien réalisé
  candidat − moyenne des contrôles.

## 8. Critères statistiques (niveau signal = rejeu par paire du protocole historique ; portefeuille = §5)

Pour chaque configuration **protégée** (C1-SL … C4-SL), tous requis :

| Id | Critère |
|---|---|
| T1 | ≥ 300 trades (niveau signal) |
| T2 | net moyen ≥ +2 bp (base) |
| T3 | surplus sur le contrôle à décalage d'épisodes : p ≤ 0,01 et borne basse IC 90 % > 0 |
| T4 | surplus sur le contrôle apparié à l'état de bande (flux) > 0 avec p ≤ 0,05 |
| T5 | portefeuille 500 USDT (base) : PnL > 0, ≥ 150 trades exécutés, surplus sur son contrôle p ≤ 0,05 |
| T6 | pessimiste : net moyen signal > 0 et PnL portefeuille > 0 |
| T7 | stress d'exécution : net moyen signal > 0 |
| T8 | ≥ 60 % des paires avec trades positives (et ≥ 10 paires avec trades) ; ≥ 5 fenêtres sur 9 positives |
| T9 | moyenne tronquée (sans les 5 % meilleurs ni les 5 % pires) > 0 ; somme nette sans le meilleur jour > 0 |
| T10 | portefeuille : drawdown max (equity marquée à la minute au bid) ≤ 10 % du capital initial ; pire trade ≥ −2 × son risque nominal ; pire jour ≥ −3 % du capital initial |
| T11 | taux de remplissage ≥ 20 % |

Tests multiples : quatre configurations protégées sont jugées ; le seuil p ≤ 0,01 de T3 tient compte de
cette multiplicité (≈ 0,04 au niveau de la famille).

## 9. Sélection, rejet, et validation

**Verdict de la phase 1 (règle figée) :**

- `RESEARCH_CANDIDATE` : au moins une configuration protégée passe T1–T11. Toutes celles qui passent (au
  plus 4) sont transmises à la phase 2 ; aucune autre.
- `NO_GO` : **chaque** configuration protégée échoue au moins un critère fatal : F1 net moyen base ≤ 0 ;
  F2 PnL portefeuille base ≤ 0 ; F3 p du surplus (T3) > 0,10 ; F4 surplus de flux (T4) ≤ 0 ; F5 moyenne
  tronquée ≤ 0 ; F6 moins de 150 trades exécutés au portefeuille.
- `INSUFFICIENT_EVIDENCE` : sinon.

**Phase 2 (novembre 2024, lue une fois, seulement si `RESEARCH_CANDIDATE`)**, par configuration transmise,
tous requis : ≥ 150 trades ; net moyen > 0 (base) ; PF ≥ 1,05 ; surplus sur le contrôle à décalage
d'épisodes (1 000 graines) > 0 avec p ajusté Holm (sur le nombre de configurations transmises) ≤ 0,05 et
borne basse IC 90 % (blocs de jours) > 0 ; ≥ 50 % des paires avec trades positives et ≥ 10 paires avec
trades ; net moyen > 0 au scénario pessimiste ; moyenne tronquée 5/5 > 0 ; portefeuille 500 USDT : PnL
> 0, drawdown max ≤ 10 %, pire trade ≥ −2 × risque nominal.

**Phase 3 (prospective OKX, nouvelle capture après fwd-okx-r1, quatre semaines, seulement pour les
configurations qui passent la phase 2)** : mêmes critères avec ≥ 100 trades et les vraies cotations OKX
(bid/ask avec tailles), frais OKX du projet (maker 2 / taker 5 bp). Le pré-enregistrement de la phase 3
sera figé avant la lecture de W1 de fwd-okx-r1 (≥ 2026-10-24) et n'utilisera aucun résultat de fwd-okx-r1.

Un candidat qui passe les trois phases reste une hypothèse de recherche : jamais un GO de trading réel.

## 10. Règles d'arrêt

1. **Parité** : avant toute autre mesure, C2 sans stop doit reproduire q3x exactement (1 130 trades,
   +12,74 bp, mêmes lignes de trades que `screen-training-outcomes/Q3-W900-K1.5-T0.1__long__E1__Z3.npz`),
   ainsi que C1 (1 548) et C4 (512) et les médianes des contrôles historiques à 10 graines. Toute
   différence arrête le travail jusqu'à explication écrite.
2. **Ressources** : `nice -n 15`, 2 workers au plus ; arrêt propre si `MemAvailable` < 2 Go ou disque libre
   < 15 Go. Si les ressources imposent moins de graines, l'amendement est écrit et haché **avant** tout
   résultat du calcul concerné.
3. Aucun changement après le gel. Une erreur technique est corrigée dans le code, documentée, et le
   calcul rejoué (version numérotée) ; une correction n'est jamais une optimisation.
4. Cette mission s'arrête avant toute lecture de phase 2 ou 3. Si le verdict n'est pas
   `RESEARCH_CANDIDATE`, novembre 2024 reste non lu par ce protocole.

## 11. Tests de robustesse (rapportés ; seuls ceux de §8 décident)

Scénario pessimiste, stress d'exécution, moyenne sans les 5 % meilleurs, moyenne sans les 5 % pires,
sans le meilleur jour, sans la meilleure paire, par paire, par fenêtre, majors / mid-caps, première moitié
(février–mai) / seconde moitié (juin–octobre), blocs de fenêtres, contrôle historique à 10 graines,
funding inclus ou non, diagnostics d'exécution (âge des cotations, délai de remplissage, volume
`through`, ordres non remplis, refus par raison).

**Mesures obligatoires** — signal : décisions, ordres, remplissage, net/brut moyen, PF, surplus, p, IC,
par paire et par semaine. Stop : stops touchés, PnL des trades stoppés, pertes évitées et gagnants
interrompus (comparaison au résultat Z3 contrefactuel du même ordre), slippage du stop, perte maximale,
effet sur le drawdown. Risque : drawdown max, perte max par trade, P95/P99 des pertes, MAE/MFE (à la
seconde, depuis les transactions), plus longue série de pertes (chronologique, par sortie), contribution
des 5 % meilleurs / pires, pire jour, exposition simultanée, dépassement au stop. Capital : courbe
d'equity, PnL net, capital engagé / disponible, refus par manque de capital, commissions, slippage,
funding, nombre de trades exécutables.

**Réponses attendues** (§10 de la mission) : S7 contre Q3 au portefeuille (bootstrap par jours de la
différence quotidienne) ; surplus du flux (T4) ; H2 : `active_sigma` « améliore la robustesse » seulement
si la différence de surplus (active − calendar) a une borne basse IC 90 % > 0 et que la part de paires et
de fenêtres positives et le PnL portefeuille ne baissent pas ; « sélectionne seulement moins de trades »
si les trades baissent d'au moins 30 % et que l'IC de la différence de surplus contient 0 ; sinon
« non concluant » ; coût du stop = net(SL200) − net(Z3) avec IC par jours.

## 12. Exposition préalable et limites

- Les neuf fenêtres sont consommées (r1–r8, q3x) ; les règles C1/C2/C4 ont été choisies **sur elles** ;
  C3 est la combinaison des deux hypothèses q3x ; le stop vient des MAE q3x. Un résultat positif ici est
  donc attendu en partie par construction et **ne vaut rien seul**.
- Q3 (C2 long) a déjà été lu sur janvier 2025 (+18,6 bp) et décembre 2024 (+6,5 bp, 9/20 paires) par r8b ;
  S7 sans profondeur a échoué en décembre 2025 sur les mid-caps (r7) ; fwd-okx-r1 est en cours.
- Novembre 2024 a été vu en bougies 4h (swing v2, mid-caps) et 1m–4h (day_trading/swing, majors) ; voir
  `DATA-EXPOSURE-scalp-q3-r2.md`.
- Carnet reconstitué depuis les transactions ; aucune file d'attente réelle ; déconnexions non
  modélisées autrement que par l'âge des cotations (> 10 s : pas d'ordre).
- Code : nouveaux modules isolés `python-orchestrator/scripts/research_exec/q3r2_*.py` (moteur, portefeuille,
  statistiques, pilote) et la famille `S7L` dans `tick.py` ; `scalp_engine.py` et les règles existantes ne
  sont pas modifiés. Branche `claude/research-scalp-q3-r2` ; protocole `scalp-q3-r2`.
