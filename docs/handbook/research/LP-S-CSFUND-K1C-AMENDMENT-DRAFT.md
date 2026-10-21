# Track S — amendement de CS-FUND pour la variante auto-collatéralisée K1(c)

> **Statut au 2026-10-21 : CLASSÉ SANS SUITE par le propriétaire (décision D8, [LP-OWNER-DECISIONS-2026-10-21.md](LP-OWNER-DECISIONS-2026-10-21.md)).** Ce brouillon reste comme archive du raisonnement ; il n'est pas gelé et rien n'est lancé pour la piste S.


**Statut : `DRAFT_NOT_FROZEN`** (rédigé le 2026-10-21, desk ; révisé le même jour). Prêt à geler **après S-O8** (verdict
`fwd-okx-r1`, après le 2026-10-31), après les prérequis du §12.1 et la check-list du propriétaire (§10). Ce texte ne gèle rien,
ne lance rien, ne lit rien et n'autorise rien.

**Révision du 2026-10-21 (passe unique après les relectures `track-s/review-quant.md` et `track-s/review-risk.md`, verdicts
`NEEDS_CHANGES`).** Statut inchangé. Version relue : sha256 `4c795b02…322e`. Les constats bloquants, majeurs et mineurs des deux
relectures sont appliqués dans le corps. Le détail constat → traitement est en §14 ; les refus et les traitements différents y
sont écrits « Réponse à la revue ». **La révision change les conclusions** (voir la « Réponse en dix lignes ») : la porte
économique est désormais lue comme GATES §1.1, un choc de décote est dimensionné (K3), et, sous les défauts prudents du texte,
K1(c) n'a plus d'avantage de capital sur K1(a).

**Objet.** Amendement écrit exigé par `LP-PROGRAMME-GATES.md` §10.4 avant tout gel du track S. Texte amendé (référence
désignée par GATES §10.4) : `tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-A/notes/PROTOCOL-H3A-GATE1-DRAFT.txt`
(« CS-FUND », `DRAFT_NOT_FROZEN`, 2026-10-15, 98 lignes, sha256
`a1299e0f8e15b6003a3a57bb750ad6b50b9365b9cfd27c12e8f44c8ec4fa3929`, lu en lecture seule le 2026-10-21). Le protocole qui sera
gelé est **« CS-FUND + présent amendement »** ; en cas de conflit, l'amendement prévaut sur CS-FUND, jamais sur GATES. Il
instancie A-S1 à A-S4 et les points « déjà relevés » de GATES §10.4 pour la seule variante K1(c), ouverte par la décision D2 du
propriétaire (`LP-OWNER-DECISIONS-2026-10-21.md`).

**Version longue de CS-FUND.** Un brouillon Markdown existe :
`.worktrees/research-h3-dossiers/docs/handbook/research/H3-CS-FUND-DRAFT-PREREGISTRATION.md` (20 769 octets, sha256
`b1eac1c787e1bd2c7238d7107e9e3fbfe7d74e87c57b1ee7f520623fbb4c0f86`). Ce n'est pas le texte de référence (GATES §10.4). Seuls
ses titres ont été parcourus ici. Sa règle « PROXY » (§5.3 : si le carnet spot n'est pas prouvé être celui de l'entité EEE,
le plancher de glissement spot est doublé à 4 pb), citée par K1-eco §7.5, est reprise et **appliquée par défaut** : aller-retour
de 0,42 % du notionnel (§3.3, §11). Le reste n'est ni repris ni contredit. Au gel, le propriétaire confirme qu'aucune autre base
n'est visée (§12).

**Conventions.** « programme » = chiffre recopié d'un document cité. « Calcul d'audit » = arithmétique sur des champs de
règle, formule affichée. « Paramètre de conception » = choix a priori de l'auteur, déclaré, jamais mesuré (liste en §3.13).
**UNKNOWN** = ni sourcé ni mesuré. **Aucune valeur de marché** (prix, funding, base, volume, carnet) n'est écrite ici. Toute
quantité qui dépend d'un prix est écrite avec le symbole de ce prix, qui n'est lu qu'à T0. « pb » = point de base. Unité de
tous les critères et contrôles : **USDC** (§8.1).

---

## Réponse en dix lignes

1. **Objet.** D2 ouvre le track S. Cet amendement rend CS-FUND exécutable en papier pour K1(c) : short
   `BTC-USD_UM_XPERP-310404` et `ETH-USD_UM_XPERP-310404` à 1×, dont la marge est le spot BTC/ETH du même actif plus une réserve
   USDC, en Selected Margin.
2. **Clauses OEM 30 % et 100 % : lectures prudentes par défaut, qui sont des hypothèses et non des faits.** L-b (appel de marge
   quand le collatéral **libre** passe sous 30 %) et λ2 (liquidation quand le collatéral libre est nul) sont retenues faute de
   réponse écrite d'OEM. Toutes deux sont peu plausibles au-delà de 1× : une position ouverte avec une marge égale à son IMR
   serait en appel (L-b) ou liquidée (λ2) dès l'ouverture, à tout levier (§3.4, §3.5). À lecture égale, et tant que la décote
   tient, (c) porte 1,74 (L-b) à 1,96 (L-a) fois le notionnel de (a).
3. **Choc de décote (K3).** Les décotes « can change », et la liste de marge EEE n'est relevée qu'une fois par semaine. Au défaut
   (décote ramenée à 0, mouvement admis 50 %), K3 rend (c) identique à (a) : N total = 142,89 USD. Moins de réserve exige une
   source quotidienne des décotes, une déclaration de préavis d'OEM et une décision écrite (§3.5).
4. **Porte économique, lue comme GATES §1.1.** « Poursuivi » ⇔ Σ y_m − Ctrl_SOFR ≥ S_éco, avec
   S_éco = max(97,25 ; 5 × Ctrl_SOFR au taux du gel ; coût fixe) = 98,60 USD/an au SOFR de 3,89 %. À F = I, l'excédent sur le
   SOFR va de −4,67 USD/an (défaut) à +31,28 USD/an (L-a sans K3). **Au cas de référence, le track S n'est poursuivable sous
   aucune lecture.** Être poursuivi exige un funding moyen de 24,85 %/an (2,27 × I) dans le cas le plus favorable, et de
   83,23 %/an (7,60 × I) au défaut.
5. **Au défaut, même C2 échoue à F = I** : 15,05 USD/an contre 19,72. Il faut F̄ ≥ 14,22 %/an (1,30 × I).
6. **Conséquences écrites.** Seul un « PASS, poursuivi » ouvre une proposition écrite de démo ; un « PASS, non poursuivi »
   n'autorise rien et ferme le track. Une futilité économique F3 (`NOT_PURSUED_FUTILITY`, dès la fin du mois 3) est ajoutée : à
   F ≈ I et à dispersion faible, elle se déclencherait au mois 3 dans toutes les constructions. Sous ces défauts, le classement
   sans suite (S-O5) est l'issue cohérente. Le texte reste gelable si le propriétaire le choisit par écrit.
7. **Risque.** L1 et L2 (−2,50 et −5,00 USD) forment un coupe-risque de marché à **queues non bornées** : un dénouement forcé sur
   un mouvement de 10 % coûte de 2,9 à 9,7 × L1 selon N, et la contrepartie porte sur 500 USDC (100 × L2). Le propriétaire choisit
   par écrit : dimensionner par le stress (N_a ≤ 25 USD, 5,27 USD/an à F = I) ou accepter une queue chiffrée. L1 agit comme un
   stop de base : il porte sur une valeur modélisée confirmée sur deux relevés, et un grand-livre sans L1 est rapporté.
8. **Exécution réaliste.** Latence D_man = 8 h sur toutes les règles manuelles (1 h = exécuteur automatique, qui n'existe pas) ;
   spot vendu avant le rachat du short ; appels et liquidations évalués à la minute, sur les bougies lues après T0 ; ADL simulé
   seulement quand le short gagne ; stress de dénouement forcé décisionnel (C7) ; une baisse de I donne `FAIL_MECHANISM`.
9. **Puissance.** C3 (10 mois positifs sur 12) n'a une puissance de 0,80 que si μ_m ≥ 1,12 σ_m. À F = I, cela demande
   σ_m ≤ 0,78 % de N total par mois, soit 1,12 USD au défaut. Un FAIL par C3 sous un portage positif est une issue probable.
10. **Avant tout gel.** P-S1, licence (paires spot, canal WS `adl-warning`, bougies 1 min, après le 2027-03-31) ; P-S2, durée ;
    P-S3, G5 Gate 1 (exclure BTC et ETH de son univers, recommandé, ou amender §0.4) ; check-list réordonnée (décisions hachées
    avant tout écran des identifiants) ; T0 au plus tôt le **2026-11-09 00:00 UTC**, fin ≈ 2027-11-09.

---

## 0. Déclarations

### 0.1 Décision du propriétaire D2 (2026-10-21)

Texte reçu, recopié tel quel : « 2 spot admin en marge ». Il est consigné dans `LP-OWNER-DECISIONS-2026-10-21.md` §D2 comme
une déclaration **niveau interface** : le spot est admis en « Selected Margin » sur le compte X-Perps. C'est la condition S-O1
(GATES §10.2), qui sépare K1(c) de K1(a), réfutée à 500 USDT. **À relever au gel, sans ordre** (D2) :
- les décotes BTC et ETH affichées dans les réglages de marge X-Perps ;
- la confirmation qu'un short `BTC-USD_UM_XPERP-310404` est porté par du BTC spot ;
- la clause d'appel de marge « < 30 % de la valeur du contrat ».

Restent aussi S-O2…S-O8 (D2) et les prérequis ajoutés par cet amendement (§12.1).

### 0.2 Aucune lecture de funding par l'auteur

L'auteur n'a appelé **aucun** point d'accès, public ou privé. Il n'a lu **aucune** valeur de funding, de prix, de base, de
volume ou de carnet. Il n'a lu aucune donnée datée du 2026-10-14 au 2026-10-31, hormis deux instantanés de **métadonnées** dont
il a extrait les champs de règle par script, champ par champ (`public/instruments` ne porte aucun champ de valeur de marché) :
- `tradingV3-private/research/20261016T2145Z-positive-pnl-structural/eea-instruments-FUTURES-20261016.json`
  (sha256 `f8a7f046efb0ff4b64952edf76d604ecfb79d5fc04fa60a6d7e08721084be316`, identique au rapport structurel §7) ;
- `tradingV3-private/research/20261016T2145Z-positive-pnl-structural/a2-spot-yield-crossvenue/raw/eea-instruments-SPOT.json`
  (sha256 `e360dd3b1720d6d3f9fb822bc4716dafced1242682f9093ec2b48ac4a511411d`, calculé le 2026-10-21).

Ces deux instantanés sont datés dans la fenêtre 2026-10-14..31. Ils sont permis comme métadonnées et **doivent être re-figés
après le 2026-10-31** (GATES §6.1, statut `quarantine` ; rapport structurel §4.1 point 4). Rien n'a été lu sous
`20261013T100000Z-forward-okx-r1/`.

**Passe de révision.** Aucun point d'accès appelé. Lus en lecture seule : les deux relectures, les notes de travail de
l'auteur, GATES, D1–D4, CS-FUND, le desk (§0, §1.3, §1.4, §4.1, §5, §6), le rapport structurel (§2.1 ligne K8, §4.1, §6.2), K1-eco §10,
K1-ops §1, §4 et §8, `H1-XPERP-ACCOUNT-CHECK.md`. Aucune valeur de marché nouvelle. Les calculs d'audit ont été refaits par un
script local (`python3 -I`, sans réseau) sur les seuls champs de règle et chiffres cités.

### 0.3 Expositions antérieures au funding (liste de CS-FUND §0.2, recopiée puis complétée)

CS-FUND §0.2, recopié : « funding Binance (swing, Q3 V2, H1 Gate 2), funding OKX USDT-SWAP (swing v2 OKX #500, H1 Gate 2),
détecteur D4 « funding contrarian » (regime-r2). Ces lectures servaient de coûts ou de signaux directionnels, jamais à mesurer un
portage. Elles ne comptent pas comme preuve. »

Compléments (sources : `RESEARCH-CAMPAIGN-STATUS.md` §3, CS-FUND §0.1, rapport structurel en-tête et §6.1) :

| # | Exposition | Produit, période | Nature de la lecture | Effet déclaré |
|---|---|---|---|---|
| E1 | swing v2 OKX (#500) | 193 séries OKX USDT-SWAP 1 m + funding, 2023-01 → 2026-10-12T16Z | lu comme coût | Même groupe de venue, autre produit (USDT-SWAP global, pas X-Perp). La présence des séries BTC et ETH est probable, non vérifiée ici (UNKNOWN). |
| E2 | campagnes swing, v2, regime-r2, Q3 V2, H1 Gate 2 | Binance 2020-01 → 2026-10-09T06Z selon la campagne ; 55 fichiers OKX (H1 Gate 2) | coût ou signal | Autre venue ou autre produit ; ne compte pas comme preuve |
| E3 | H3-A, 2026-10-15 vers 19:45Z | `public/funding-rate`, 125 X-Perps | champs de résultat supprimés en mémoire ; seuls les champs de règle conservés (CS-FUND §0.1) | Règle seulement |
| E4 | mission structurelle, 2026-10-16 | `public/funding-rate-history` des X-Perps BTC et ETH, c'est-à-dire les familles des identifiants figés | valeurs jetées sans affichage ; seuls le nombre de lignes et les dates de première et dernière ligne lus | La **profondeur** est connue : ≈ 3 mois, pages 2026-07-13 → 2026-10-16 (K1-ops §3.1, citant le BRIEF §3.4). Aucune valeur. |
| E5 | a1, mission structurelle | `settFundingRate` du X-Perp or, vu une fois en console | valeur non notée (rapport §6.1 point 9) | Autre actif |
| E6 | littérature (rapport structurel §5a, K1-eco §2.2) | Binance, BitMEX, 2020–2025 | ordres de grandeur publiés : ≈ 8 %/an (Borri et al.), composante 5,06 pt/an (He et al.), années négatives | Connus de l'auteur. **Aucun** n'entre dans un critère. S_éco vient du SOFR (D4). |
| E7 | lecture prescrite de cette passe et de sa révision, 2026-10-21 | K1-eco §2.3 et §6, K1-ops §2, CS-FUND §2, rapport §2.1 | ces textes contiennent les trois prix de conversion du 2026-10-16, des valeurs de contrat qui en dérivent et des volumes du 2026-10-14, tous datés en quarantaine | Lus parce que la lecture était prescrite, **non recopiés**, utilisés dans **aucune** formule. Toute quantité ci-dessous est écrite en fonction du prix d'entrée, lu à T0. Les deux relectures ne contiennent que des chiffres dérivés du budget et des champs de règle. |
| E8 | items C1–C3 de la check-list (§10), si le propriétaire ouvre l'onglet d'ordre d'un identifiant figé ou le panneau spot | funding courant, prime et prix affichés par l'interface | n'existe que si l'onglet est ouvert ; les items C se font par script booléen ou capture recadrée par un tiers pour l'éviter | Déclarée au gel avec son heure. Les décisions sont hachées avant (étape A, §10) : aucun paramètre ne peut en dépendre. |
| E9 | lecture G5 Gate 1 (D1), sous l'option β de P-S3 seulement (§4.3) | historique de funding et bougies 1 min des 29 X-Perps liquides, du listing au 2026-10-13 | lecture d'un autre protocole ; le propriétaire reçoit ses sorties | Déclarée, sans effet sur les paramètres figés. N'existe pas sous l'option α. |

### 0.4 Interdits (rappel de GATES §9 et §10.5, et des consignes de la passe)

- Aucun ordre, souscription, conversion, virement, stake ou dépôt.
- Aucune clé avec droit Trade, aucun drapeau, aucun `.env`.
- Aucune lecture de funding avant T0, sauf A-S1(ii) consentie.
- Aucune sélection d'actif sur le funding ; aucun levier > 1×.
- Rien lu ni copié de `fwd-okx-r1` (W0–W2). Quarantaine 2026-10-14..31 sur toute venue.
- Après une lecture : aucun seuil abaissé, aucune fenêtre allongée, aucune analyse promue. Une seule exécution.
- UNKNOWN reste UNKNOWN.
- Séries et tables chiffrées dans `tradingV3-private` seulement (D1, GATES §6.3).

### 0.5 Hors de la famille Holm

GATES §2.1 définit la famille du programme L : G2-C2, G4-Gate2, G5-Gate1, G5-Gate2 et G1, soit m_max = 5. Le track S n'y
figure pas et ne la modifie pas. Son niveau propre est celui du test de signe de CS-FUND §6 :
P(X ≥ 10 | n = 12, p = ½) = 79/4096 = 0,019.

---

## 1. Ce que l'amendement change dans CS-FUND, article par article

| Art. | Ancien texte (CS-FUND, cité ou résumé) | Nouveau texte | Motif |
|---|---|---|---|
| Titre | « PROTOCOLE H3-A — GATE 1 — Portage de funding couvert (short X-Perp + long spot) » | « CS-FUND-K1(c) — mesure prospective de 12 mois du portage de funding couvert **auto-collatéralisé** : short X-Perp à 1× porté par le spot du même actif en Selected Margin » | Le texte est prospectif, ce n'est pas un Gate 1 ; D2 ; GATES §10.1 |
| §0.1 | Déclaration de l'auteur du 2026-10-15 | Conservée ; s'y ajoute le §0.2 du présent texte | Nouvel auteur, nouvelles lectures |
| §0.2 | Liste des expositions (recopiée en §0.3) | Complétée par E1–E9 (§0.3) | E4 touche les familles mêmes des identifiants figés |
| §0.3 | « Littérature connue : BIS WP 1087 […] Aucun chiffre de cette littérature n'entre dans les critères » | Liste étendue au rapport structurel §5 (CMU, He et al., Borri et al., BitMEX) ; la règle est inchangée | E6 |
| §0.4 | Toute lecture antérieure à T0 est interdite « avant et après le gel » | Devient l'option A-S1(i), **retenue par défaut**. L'interdit vise le protocole S, son code, ses décisions et ses auteurs ; la lecture G5 Gate 1 est réglée par P-S3 (§4.3). A-S1(ii) est une alternative sur consentement écrit distinct (§4) | GATES §10.4 A-S1 ; relectures quant m8, risque M7 |
| §1 | Short X-Perp + long spot à 1× sur BTC et ETH, qui doit battre (a) zéro et (b) « le taux sans risque de la période » | Structure K1(c) (§3) ; (b) = **SOFR** actual/360 (§5) ; ajout de la porte économique S_éco sur l'excédent au-delà du SOFR (§8.3) | A-S2 ; D4 ; GATES §1.1 |
| §2 bras A | Identifiants figés ; « Choix a priori par la liquidité X-Perp du 2026-10-14 » | Identifiants inchangés. Justification remplacée : BTC et ETH sont les seuls actifs nommés par GATES §10.1 et par D2 | La liquidité citée est un volume daté en quarantaine, et un volume n'est pas une métadonnée (GATES §9.5, §6.1) |
| §2 bras B | 27 X-Perps ≥ 1 M USD/jour ayant une paire USDC, à titre descriptif | **Supprimé** | Liste bâtie sur le même volume en quarantaine ; admission du spot de ces autres actifs en marge non vérifiée ; aucun rôle dans K1(c) |
| §2 prérequis | BTC-USDC / ETH-USDC négociables, USDC vers la marge isolée, API avant exécution | S-O1…S-O4 et check-list §10 ; « marge isolée » devient « Selected Margin » | D2 ; variante (c) |
| §3 capital | 500 USDC de référence (500 USDT convertis), conversion exclue de la mesure et rapportée à part | **Inchangé** | — |
| §3 positions | « 2 positions […] N = 120 USD […] marge isolée du short = N (1x). Réserve 20 USD » | 2 structures (BTC, ETH), budget B = 250 USDC chacune ; levier 1× ; marge = spot du même actif + réserve USDC dans le pool Selected Margin ; N et réserve donnés par §3.3–§3.6, **choc de décote K3 compris** | Variante (c) ; clauses 30 % et 100 % ; relecture risque B2 |
| §3 quantité | q = ⌊N / (ctVal × prix)⌋ ; spot = q × ctVal / (1 − frais) ; lot spot 1e-8 | Même principe, avec N = N_max(h, lectures, K3) ; spot arrondi au lot **supérieur** (1e-8 BTC, 1e-6 ETH) | K1-eco §6 ; garantit une couverture ≥ q × ctVal |
| §3 entrée et sortie | « au premier règlement de funding 00:00 UTC ≥ T0 » | Entrée à T0 00:05 UTC ; premier règlement compté = premier `fundingTime` postérieur à l'entrée. Sortie à T0 + 12 mois, 00:05 UTC, après le règlement de 00:00, qui est compté | GATES §5.1 : first_fill < T ≤ exit ; « The actual fee assessment may take up to a minute », desk §4.1 |
| §3 rééquilibrage | Si \|mark/prix_ref − 1\| ≥ 50 % : fermer les deux jambes et rouvrir à N « au même instant » | Conservé, avec une latence **D_man = 8 h** et une séquence écrite (spot vendu d'abord). S'ajoutent un seuil de **ratio de marge** évalué à la minute (E/V ≤ c_ret + 0,05) et le changement de décote (§3.7) | K1-eco §7.6 ; relecture risque M2, M3 |
| §3 coupe-risque | « PnL net cumulé d'une position […] −2,50 USD […] Les deux positions ensemble ne peuvent pas perdre plus de 5 USD » | Mesuré sur la **structure entière**, en valeur de dénouement **modélisée**, confirmé sur deux relevés, exécuté après D_man ; **queues non bornées déclarées** et décision écrite du propriétaire (§3.8) | GATES §10.4 ; rapport §6.2 décision 6 ; relectures quant M3, risque B1 |
| §3 bis (nouveau) | absent | Dénouement forcé (ADL, côté gagnant seulement) ; suspension et délisting ; retrait de l'admission en marge ; baisse de `interestRate` ; cascade CGU OEM §1.9.5 et ordre de consommation du pool (§3.9–§3.11) | GATES §10.4 « déjà relevés » ; rapport §4.1 points 13 et 15 ; relectures quant M4, risque M4–M6 |
| §4 données | Enregistreur lecture seule sur `eea.okx.com` ; `fundingTime < T0` rejeté | Conservé et précisé (§9) : plancher 2026-11-02 dans le code ; requêtes paramétrées pour ne rien recevoir d'antérieur ; liste blanche des champs avant T0 ; relevés horaires à HH:05 ; bougies 1 min de mark et de spot lues après T0 ; canal WS `adl-warning` | GATES §9.2, §10.3 ; relecture risque M3, M4, m3, m4 |
| §4 T0 | Premier 00:00 UTC ≥ gel + 7 j, après ≥ 7 j de rodage sans trou | Conservé. Rodage ≥ 2026-11-02 ; pendant le rodage, rien n'est écrit hors santé (§9.4) | K1-ops §5 ; D1 « Inchangé » |
| §4 licence | « conditions d'usage […] vérifiées avant le gel ; sinon pas de gel » | La décision D1 ne couvre pas ce besoin : nouvelle décision P-S1 (§12.1), qui liste hôtes, points d'accès et canaux | D1 couvre ≤ 40 X-Perps + 2 swaps jusqu'au 2027-03-31 au plus tard, et dit : « tout […] instrument […] ajouté est une nouvelle décision » |
| §5 frais | Barème du jour du gel, Regular « Spot & derivatives », tout taker (spot 0,10 %, X-Perp 0,05 %) | Conservé ; confirmé par S-O4 ; sensibilités « Spot-only » (0,20 %) et frais × 1,5 rapportées | GATES §5.3 ; K1-ops §2 |
| §5 glissement | Plancher déclaré 2 pb par exécution | Conservé pour le X-Perp ; spot en **PROXY** (4 pb) **appliqué par défaut** : aller-retour 0,42 % ; 0,38 % seulement si le carnet EEE est prouvé | Version longue §5.3 ; K1-eco §7.5 ; relectures quant m1, risque m1 |
| §5 funding | « valeur de position × taux de chaque règlement » | Valeur **au mark** × taux de chaque règlement, signe réel, en USDC (S-O2) | K1-eco §7.2 ; desk §5.1 |
| §5 base | « PnL spot + PnL X-Perp (mark) entre ouverture et clôture ou fin de mois » | Les deux jambes sont marquées en fin de mois sur une **référence commune** ; la base n'entre dans y_m qu'aux exécutions ; le marquage mixte et le terme USDC/USD sont rapportés à part (§8.1) | Relecture quant m3, m4 |
| §6 unité | Mois calendaire, 12 blocs ; test de signe ≥ 10/12 | Inchangé, avec des mois glissants ancrés à T0 ; effet minimal détectable de C3 ≈ **1,12 σ_m**, table de puissance (§8.5) | GATES §3.2 ; relecture quant M2 |
| §7 critères | C1–C6 ; C2 contre la moyenne de l'€STR sur 480 USD engagés | Réécrits (§8) : C2 contre le SOFR sur 500 USDC ; C6 = aucune liquidation (distance la plus courte des trois lectures), aucun appel, **aucun** coupe-risque, évalués à la minute ; **C7** stress de dénouement forcé ; porte S_éco sur l'excédent au-delà du SOFR | A-S2 ; D4 ; GATES §1.1 ; relectures quant B1, M6, risque M1, M4 |
| §7 INCONCLUSIVE | Trous > 5 %, changement de I, retrait d'un instrument, changement d'accès | S'ajoutent : valeurs erronées (définies), relevés de risque manquants, relevés de règle manqués, retrait de l'admission du spot en marge, retrait d'une paire spot. Une **baisse** de I devient `FAIL_MECHANISM` ; seule une hausse reste `INCONCLUSIVE` | CGU OEM §16.2 (K1-ops §3.2) ; K1-eco §10 ; relectures quant m5, risque M3, M5 |
| §7 futilité | « après le mois 6, si Σ y_1..6 ≤ 0 et ≤ 3 mois positifs » | Évaluée à la fin de chaque mois k = 3…11 : F1, F1b, F2 et la futilité économique **F3** (§8.4) | GATES §10.3 : « futilité ≥ 3 mois » ; relecture quant B2 |
| §8 rapports | Référence I, parts de règlements, bras B, coûts réels contre modélisés | S'ajoutent : contrôles (§5), lignes après impôt (§6), change, lectures des clauses, ADL simulés, événements de règle, grand-livre sans L1, sensibilité « spot consommé d'abord », terme USDC/USD, sensibilités | A-S2, A-S3 ; relectures |
| §9 autorise | « PASS : une proposition au propriétaire pour M5 (petite exécution démo/réelle […]) » | A-S4 (§7) : validité de six mois ; **seul un PASS poursuivi** ouvre une proposition écrite de démo, après des travaux séparés | GATES §1.2, §10.4 A-S4 ; relectures quant B1, risque m8 |
| §10 gel | Remplacement du statut ; sha256 du corps et des codes | Procédure §12 : prérequis, re-figeage des métadonnées, bloc de paramètres, empreintes de CS-FUND et de l'amendement | — |

---

## 2. Instruments figés

### 2.1 X-Perps (instantané `eea-instruments-FUTURES-20261016.json`, sha256 `f8a7f046…e316`)

| Champ | `BTC-USD_UM_XPERP-310404` | `ETH-USD_UM_XPERP-310404` |
|---|---|---|
| `instType` / `ruleType` / `state` | FUTURES / xperp / live | FUTURES / xperp / live |
| `instFamily` / `uly` | BTC-USD_UM_XPERP / BTC-USD | ETH-USD_UM_XPERP / ETH-USD |
| `ctVal` (`ctValCcy`), `ctMult`, `ctType` | **0,0001 BTC**, 1, linear | **0,001 ETH**, 1, linear |
| `minSz` / `lotSz` | 1 / 1 (contrats entiers) | 1 / 1 (contrats entiers) |
| `tickSz` | 0,1 | 0,01 |
| `listTime` | 1774845000428 = 2026-03-30 04:30:00 UTC | 1775016000441 = 2026-04-01 04:00:00 UTC |
| `expTime` | 1933056000000 = 2031-04-04 08:00 UTC | 1933056000000 = 2031-04-04 08:00 UTC |
| `settleCcy` | USD | USD |
| `lever` | 50 | 50 |
| `groupId` | 7 | 7 |
| `initPxLmtPct` / `floatPxLmtPct` / `maxPxLmtPct` | 0,02 / 0,005 / 0,01 | 0,02 / 0,005 / 0,01 |
| `alias` / `futureSettlement` | this_five_years / false | this_five_years / false |

Lecture des champs :
- **L'API gouverne.** La page de spécifications affiche 0,01 BTC et 0,1 ETH par contrat ; l'API donne `ctVal` 0,0001 BTC et
  0,001 ETH, soit un facteur 100 sur la comptabilité des quantités (rapport §4.1 point 7 ; K1-ops §2). Toutes les quantités
  de ce texte sont en contrats API.
- `lever` = 50 côté API, « Up to 10x » côté page (desk §4.3) ; l'interface du compte propose 1×, 2×, 3×, 5×, 10× et 50× sur un
  X-Perp (`H1-XPERP-ACCOUNT-CHECK.md`). Sans effet à 1×, déclaré ; utile à la réduction à l'absurde de §3.5.
- `settleCcy` = USD est la devise de l'instrument. La devise de règlement USD-M du **compte** se choisit (USD par défaut, USDC
  ou USDG) et doit être USDC (S-O2 ; desk §5.1).
- `instFamily` : l'instantané donne `BTC-USD_UM_XPERP` et `ETH-USD_UM_XPERP` ; le desk §5.2 point 4 interroge
  `position-tiers` avec `instFamily=BTC-USD_UM`, qui est la famille des datés. L'enregistreur utilise la valeur de l'instantané
  re-figé ; la bonne valeur pour `position-tiers` et `adl-warning` est vérifiée au gel (§12.1), sans quoi l'enregistreur
  risque de relever les paliers d'un autre produit.
- `groupId` 7 : libellé UNKNOWN. Le desk §5.1 documente seulement 8 « XPERP group two » et 10 « XPERP RWA group two ».
- Bandes de prix : non modélisées. À 1× et à cette taille, l'effet est probablement nul, mais il n'est pas vérifié.
- Échéance 2031-04-04 08:00 UTC (16:00 HK, K1-ops §2) : hors horizon.
- La valeur d'un contrat en USD (ctVal × mark) est UNKNOWN jusqu'à T0.

### 2.2 Paires spot (instantané a2 `eea-instruments-SPOT.json`, sha256 `e360dd3b…411d`)

| Champ | `BTC-USDC` | `ETH-USDC` |
|---|---|---|
| `minSz` | 0,0001 BTC (= 1 contrat BTC) | 0,001 ETH (= 1 contrat ETH) |
| `lotSz` | 0,00000001 | 0,000001 |
| `tickSz` | 0,1 | 0,01 |
| `state` / `ruleType` | live / normal | live / normal |
| `listTime` | 2025-08-21 08:00 UTC | 2025-08-21 08:00 UTC |

Les lots spot sont plus fins que `ctVal` : tout nombre entier de contrats est donc couvrable à un lot près (K1-eco §6). Mais
`eea.okx.com` sert la liste **globale** (rapport §4.1 point 7 ; a4 F1) : la présence sur l'hôte ne prouve pas que les paires
soient négociables par le compte EEE. Cette preuve relève de S-O3 (item C3, §10).

### 2.3 Champs de règle venant des documents (pas de l'instantané)

| Champ | Valeur | Source (statut) |
|---|---|---|
| Terme d'intérêt I | 0,01 % par 8 h (`interestRate` 0,0001), soit 0,0001 × 3 × 365 = 10,95 %/an | rapport §1 l.1, §4.2 (mesuré : champs de règle) |
| Zone morte | F = I si la prime moyenne P̄ ∈ [−0,04 % ; +0,06 %] ; au-dessus, F = P̄ − 0,05 % | perps-funding-fee-mechanism, màj 2026-08-27 (contractuel) |
| Plafond et plancher par règlement | ±0,375 % BTC, ±0,75 % ETH | rapport §1 l.3 ; K1-eco §2.1 (mesuré) |
| Prime au bord du plafond | P̄ = 0,375 + 0,05 = 0,425 % (BTC) ; 0,75 + 0,05 = 0,80 % (ETH) | calcul d'audit sur les deux lignes ci-dessus |
| Paliers X-Perp BTC/ETH | mmr 0,65 %, imr 2 % | rapport §4.2, position-tiers du 2026-10-16 (mesuré) ; à re-figer |
| Frais de liquidation | taker du palier + « Liquidation clearance fee » (U-margined : « Face value × Contract multiplier × Number of liquidated positions × Mark price × Position tier maintenance margin requirement »), soit mmr × V | desk §4.1 (tiered-maintenance-margin-ratio-rules-eea §1.3 ; contractuel) |
| Décotes, palier 1, **compte global** | BTC 0,98 ; ETH 0,98 ; USDC 0,995 | rapport §4.2 (mesuré) ; **valeurs X-Perps EEE : UNKNOWN**, lues au gel (§10) |
| Stabilité des décotes | « a haircut applies to each asset […] Haircuts are tiered and can change » | whats-selected-margin-eea, màj 2026-09-03 (desk §5.1) |
| ADL | « deleveraged positions are forcibly closed at the mark price […] if the applicable security fund is nearly depleted, […] could be closed at bankruptcy price » ; « Trading fees are not charged on deleveraged positions » ; « no warnings are issued prior to ADL occurring » | page ADL, màj 2026-08-11 (desk §4.1) |
| Signal ADL public | canal WS `adl-warning` (`instType` FUTURES, `instFamily` optionnel) : « Data is only pushed in the warning or adl state, once every second » ; `public/insurance-fund` : types `adl` et `platform_revenue` dépréciés, vides | desk §0 point 2, §1.3 et §1.4 (docs-v5) |
| Frais Regular « Spot & derivatives » | spot 0,08 / 0,10 % ; X-Perps 0,02 / 0,05 % (X-Perps vus au compte le 2026-10-15) | rapport §4.2 ; `H1-XPERP-ACCOUNT-CHECK.md` (contractuel ; barème du compte : S-O4) |
| Frais « Spot-only » | spot 0,10 / 0,20 % | rapport §4.2 |
| Frais spot | prélevés dans la crypto reçue à l'achat, sur le produit à la vente | K1-ops §2 ; K1-eco §6 (FAQ EEE 2026-10-07) |
| Quota sans intérêt USD-M | 20 000 USD par sous-compte ; au-delà, l'équité négative est convertie en USDC (emprunt possible). Jamais atteint à 500 USDT | K1-ops §1 |
| Soldes OEM | aucun terme d'intérêt | K1-eco §2.3 (a4 F8) |

---

## 3. Construction papier K1(c) à 500 USDT (aucun ordre)

### 3.1 Notation

Indice a ∈ {BTC, ETH}.

| Symbole | Définition |
|---|---|
| c_a | `ctVal` (0,0001 BTC ; 0,001 ETH) |
| q_a | nombre entier de contrats courts |
| Q_a = q_a × c_a | quantité d'actif à couvrir |
| M, S | mark du X-Perp ; mid spot capturé |
| P₀, M₀ | prix d'exécution papier du short à la dernière ouverture ; mark à la dernière ouverture |
| N_a = Q_a × M₀ | notionnel à l'ouverture |
| x = M/M₀ − 1 | mouvement relatif depuis la dernière ouverture |
| h_a, h_u | décote de l'actif (BTC ou ETH) et de l'USDC, lues au compte au gel |
| mmr | taux de maintenance du palier applicable, lu au gel |
| L | levier réglé = 1 (GATES §10.5) |
| f_s, f_x | frais taker spot et X-Perp |
| g, g_s | glissement par exécution, X-Perp (2 pb) et spot (PROXY 4 pb par défaut) |
| R_a | réserve USDC de la structure, posée dans le pool Selected Margin |
| ρ_a = R_a / N_a | ratio de réserve |
| c_ret | seuil d'appel de la lecture retenue : 0,30 (L-a) ou 1,30 (L-b) à 1× |
| x_P, x_λ1, x_λ2 | distances de liquidation des trois lectures (§3.4) |
| h_stress, x_det | décote après un choc et mouvement admis pendant le délai de détection (K3, §3.5) |
| D_man | latence de toute règle manuelle (§3.7) |
| USDC_a(t) | solde USDC de la structure : réserve de la dernière ouverture + flux réalisés (funding, frais, résultats) |

Dans le pool Selected Margin, on écrit l'équité de la structure, la valeur du contrat et la marge initiale :

```
E_a   = h_a · Q_a · S  +  h_u · R_a  +  Q_a · (P₀ − M)
V_a   = Q_a · M
IMR_a = V_a / L
```

Le prix auquel OKX valorise le collatéral (mid spot, indice ?) est UNKNOWN. Le papier utilise le mid spot capturé. Les
formules de distance ci-dessous posent S = M et P₀ = M₀ (base nulle) ; elles donnent alors
E/V = h − 1 + (1 + h_u·ρ)/(1 + x). La base réelle entre dans les règles de risque par les relevés (§3.7, §3.8) et dans y_m par
les exécutions (§8.1).

### 3.2 Allocation

Capital de référence 500 USDC (CS-FUND §3, inchangé). Le coût de la conversion USDT → USDC est exclu de la mesure et rapporté
à part : c_conv = 0,10 %, pessimiste et figé (convention K8), soit 0,50 USD, remplacé par l'aperçu relevé à T0 (§10, item B7).

Deux structures indépendantes, une par actif (deux créneaux de risque), de budget **B = 250 USDC** chacune. La réserve USDC
n'est jamais placée en Simple Earn : la réserve de funding négatif ne peut pas y dormir (K1-eco §2.4). Elle ne rapporte rien,
puisque les soldes OEM ne portent aucun intérêt.

Le pool Selected Margin est commun aux deux structures (« your collectively selected assets act as a shared pool of collateral
supporting all your open positions », desk §5.1). Toutes les contraintes ci-dessous sont **linéaires**. Si chaque structure
les satisfait séparément, le pool les satisfait : la comptabilité par structure est donc suffisante, et prudente. L'ordre dans
lequel le pool consomme ses actifs est un autre sujet, traité en §3.11.

### 3.3 Quantification exacte, par structure

Étapes au gel :
1. Remplir le bloc de paramètres (§12.3) : h_a, h_u, mmr, f_s, f_x, g, g_s et indicateur PROXY, paire de lectures retenue
   (§3.4, §3.5), paramètres de K3 (h_stress, x_det).
2. Calculer ρ*_a selon §3.5.
3. Calculer le notionnel maximal :

```
N_max,a = (B − R_risk) / ( (1 + g_s)/(1 − f_s) + f_x + ρ*_a )        R_risk = 2,50 USD
```

Étapes à l'entrée (T0 00:05 UTC) :

4. Nombre de contrats : q_a = ⌊ N_max,a / (c_a × M₀) ⌋. Contrats entiers, car `minSz` = `lotSz` = 1. Si q_a < 1, la structure
   n'est pas ouverte : C5 n'est plus évaluable et le verdict est `INCONCLUSIVE`.
5. Achat spot sur-dimensionné, parce que le frais d'achat est prélevé dans la crypto reçue :

   ```
   Q_achat,a = ⌈ q_a · c_a / (1 − f_s) ⌉   arrondi au lot spot SUPÉRIEUR (1e-8 BTC ; 1e-6 ETH)
   ```

   Avec f_s = 0,10 %, cela revient à acheter q_a × c_a × 1,001001… (**sur-achat de 0,10 %**, ou 0,20 % au barème Spot-only).
   La quantité reçue nette, Q_achat,a × (1 − f_s), est ≥ q_a × c_a ; le résiduel long est ≤ 1 lot spot. Calcul d'audit pour
   q = 1 :
   - BTC : 0,0001 / 0,999 = 0,00010010… → 0,00010011 BTC achetés, nets 0,0001000099 ≥ 0,0001 ;
   - ETH : 0,001 / 0,999 = 0,001001001… → 0,001002 ETH achetés, nets 0,001000998 ≥ 0,001.

   `minSz` spot est égal à un contrat : la contrainte de taille est toujours satisfaite.
6. Trésorerie :
   - achat spot = Q_achat,a × ask spot × (1 + g_s) ;
   - frais d'entrée du X-Perp = f_x × q_a × c_a × M₀, payés en USDC ;
   - réserve R_a = B − achat spot − frais X-Perp. On vérifie R_a ≥ ρ*_a × N_a + R_risk aux prix capturés (sinon q_a est diminué
     d'un contrat) ; le reste de l'arrondi en contrats entiers s'ajoute à R_a et augmente ρ.
7. Short : vente de q_a contrats au meilleur bid capturé, moins g ; prix_ref = M₀.

L'ordre d'ouverture est : achat spot, puis vente du short, au même relevé (§3.7).

### 3.4 Liquidation : trois lectures, la plus courte retenue

Selon les paliers API, la liquidation intervient quand l'équité tombe à la marge de maintenance plus les frais de liquidation
(« maintenance margin ratio falls to 100% or below […] plus any applicable liquidation fees », desk §4.1). Ces frais sont le
taker du palier (f_x) et le « Liquidation clearance fee » (≈ mmr × V, §2.3). La clause contractuelle OEM de liquidation dit autre
chose : « the equity value of the Contract has fallen to 100% of the collateral value, net of applicable discounts » (desk
§4.1). Elle a deux lectures. On obtient trois distances (S = M, P₀ = M₀) :

```
(P)   paliers API, frais compris :     E ≤ (2·mmr + f_x)·V    ⇒   x_P  = (1 + h_u·ρ)/(1 + 2·mmr + f_x − h) − 1
(λ1)  « perte du contrat = collatéral décoté », soit E ≤ 0 :    ⇒   x_λ1 = (h + h_u·ρ)/(1 − h)
(λ2)  « collatéral libre nul », soit E/V ≤ 1/L :                ⇒   x_λ2 = (1 + h_u·ρ)/(1 + 1/L − h) − 1    [à 1× : (1 + h_u·ρ)/(2 − h) − 1]

x_liq = min(x_P, x_λ1, x_λ2)          (défaut prudent)
```

Un short couvert ne peut être liquidé qu'**à la hausse** : à la baisse, E_a = Q_a (P₀ − (1 − h) M) + h_u R_a croît.

Calcul d'audit sans réserve (ρ = 0), à titre d'illustration seulement (K1 n'est pas satisfaite à ρ = 0), mmr = 0,65 %
(paliers du 2026-10-16, à re-figer) :

| h | x_P sans frais : (h − mmr)/(1 − h + mmr) | x_P frais compris | x_λ1 = h/(1 − h) | x_λ2 = 1/(2 − h) − 1 |
|---|---|---|---|---|
| 0,98 | 0,9735 / 0,0265 = 36,74 (+3 673,6 %) | 0,9665 / 0,0335 = 28,85 (**+2 885,1 %**) | 49 (+4 900 %) | −2,0 % : liquidé dès l'ouverture |
| 0,80 | 0,7935 / 0,2065 = 3,843 (+384,3 %) | 0,7865 / 0,2135 = 3,684 (**+368,4 %**) | 4 (+400 %) | −16,7 % : liquidé dès l'ouverture |

Les distances P sans frais sont cohérentes avec K1-eco §5 (37,7× et 4,84×) et K1-ops §2 (+384 %). Le clearance fee raccourcit
la distance (de 36,74 à 28,85 à h = 0,98), sans effet sur N.

- **λ2, la lecture la plus prudente, est peu plausible.** Une position ouverte avec une marge égale à son IMR aurait un
  collatéral libre nul et serait liquidée dès l'ouverture, quel que soit le levier. λ2 est donc encore moins plausible que L-b
  (§3.5). Elle est retenue par prudence, faute de réponse écrite. Sous L-b, elle ne lie pas : K2 garantit E/V ≥ 1,30 jusqu'à
  x_cible, donc E/V > 1/L, et la règle de ±50 % joue avant (§3.6 : +96,1 % sans K3).
- **Paires de lectures.** La paire prudente **(L-b, λ2)** est retenue par défaut. La paire **(L-a, P/λ1)** ne s'applique que si
  OEM répond **par écrit sur les deux clauses**, 30 % et 100 %, avant le gel (item B9). Sinon, sous L-a, λ2 se déclencherait dès
  +1,0 % (§3.6), avant tout rééquilibrage.
- Sous chaque paire, la liquidation vient après l'appel de marge : c_ret > seuil de liquidation de la même paire
  (1,30 > 1/L sous (L-b, λ2) ; 0,30 > 2·mmr + f_x et 0,30 > 0 sous (L-a, P/λ1)).
- C6 retient x_liq de la paire retenue, évalué à la minute (§3.7, §8.2).

### 3.5 Clause d'appel de marge « < 30 % » : deux lectures ; choc de décote

Texte (x-perps-contract-specifications, recopié dans le desk §4.1) : « If the value of the available collateral, net of the
applicable discounts, is less than 30% of the value of the Contract, OEM will issue the counterparty with a request to post
additional collateral in their trading account (a "Margin Call"). » L'interprétation est UNKNOWN (rapport §4.1 point 14). À
1×, « valeur du contrat » = notionnel = marge initiale : les lectures « notionnel » et « marge initiale » coïncident. La vraie
divergence porte sur « available collateral ».

| Lecture | « Collatéral disponible » | Appel si | Distance d'appel x_mc | Sans réserve (ρ = 0) |
|---|---|---|---|---|
| **L-a « équité »** | toute l'équité décotée E | E/V < 0,30 | (1 + h_u ρ)/(1,30 − h) − 1 | h = 0,98 : +212,5 % ; h = 0,80 : +100 % (= K1-eco, K1-ops) |
| **L-b « collatéral libre »** | équité moins la marge initiale de la position, E − V/L | (E − V/L)/V < 0,30 ⇔ E/V < 0,30 + 1/L = **1,30** à 1× | (1 + h_u ρ)/(2,30 − h) − 1 | h = 0,98 : −24,2 % ; h = 0,80 : −33,3 % : **appel dès l'ouverture** |

**Réduction à l'absurde (déclarée).** Sous L-b, une position ouverte au levier L avec une marge égale à son IMR a un collatéral
disponible nul : elle serait en appel de marge dès l'ouverture, **quel que soit L**. Pour l'éviter, il faudrait
E ≥ (0,30 + 1/L)·V : 1,30·V à 1×, 0,40·V à 10×, soit 4 fois l'IMR à 10×. Or la venue propose jusqu'à 10× (page) et 50× (API,
interface du compte). L-b n'est donc pas une lecture plausible d'OEM au-delà de 1×. À 1×, elle reste possible. L-a a le défaut
inverse au-delà de 3,33× (E/V = 1/L < 0,30 dès l'ouverture), sans effet à 1×.

- **Pourquoi L-b reste retenue** : la page Selected Margin écrit « Assets supporting an open position aren't available to open
  another one, so your usable balance can be well below your total holdings » (desk §5.1) ; « available » peut donc désigner
  le collatéral qui ne supporte pas déjà la position.
- **Règle.** L-b est retenue par défaut comme **hypothèse prudente non vérifiée**, peu plausible au-delà de 1×, et non comme un
  fait. L-a ne s'applique que si OEM fournit une interprétation **écrite** des clauses 30 % et 100 % avant le gel, hachée et
  versée au dossier (§10, item B9). Le choix est inscrit dans le bloc de paramètres et ne change plus après le gel.

**Dimensionnement de la réserve**, avec c_ret = 0,30 (L-a) ou 1,30 (L-b) et x_cible = +50 % (seuil de rééquilibrage de
CS-FUND §3, déclaré comme paramètre de conception en §3.13) :

```
(K1) marge initiale couverte à l'ouverture :                h + h_u·ρ ≥ 1/L               ⇒ ρ ≥ (1/L − h)/h_u
(K2) pas d'appel avant x_cible :                            ρ ≥ ((1 + x_cible)(1 + c_ret − h) − 1)/h_u
(K3) pas d'appel avant x_det, après un choc de décote :     ρ ≥ ((1 + x_det)(1 + c_ret − h_stress) − 1)/h_u
(vérification) liquidation de la paire retenue au-delà de x_cible : impliquée par K2 sous (L-b, λ2) ;
               sous (L-a, P/λ1), λ2 doit être exclue par écrit (B9)
ρ* = max(K1, K2, K3) ;   R_a = ρ*·N_a + R_risk
```

**K3, le choc de décote** (relecture risque B2). Les décotes « are tiered and can change » (§2.3), et le retrait de BTC ou d'ETH
de la liste de marge est un événement prévu (§3.10). Or la liste et les décotes **EEE** ne sont relevées qu'à la main, chaque
lundi (§9.3), sauf source quotidienne validée (item B3). Sans K3, calcul d'audit à x = 0 et mmr = 0,65 % :

```
L-a (ρ eff = 0,0304) :  h → 0 (retrait) : E/V = h_u·ρ = 0,030 ⇒ appel L-a immédiat ;
                                          liquidation P (sans frais) à (1 + h_u·ρ)/(1 + mmr) − 1 = +2,36 %
                        h → 0,80        : K1 faux (0,80 + 0,030 < 1) ; appel L-a à +106 %
L-b (ρ eff = 1,0050) :  h → 0 (retrait) : liquidation P (sans frais) à +98,7 %, mais appel L-b immédiat (E/V = 1,00 < 1,30)
                                          et liquidation λ2 immédiate (E/V = 1,00 ≤ 1/L)
```

K3 s'applique donc **sous les deux lectures**. Ses paramètres :
- **h_stress = 0 par défaut** (retrait de la liste). Le propriétaire peut retenir le `minDiscountRate` du point d'accès des
  décotes, s'il est servi pour BTC et ETH (il l'est pour BETH 0,8 et OKSOL 0,7, rapport §4.2 ; pour BTC et ETH : UNKNOWN), mais
  seulement en acceptant par écrit le risque résiduel de retrait (item A1).
- **x_det = 50 % par défaut**, égal à x_cible. Sans donnée de marché, le seul mouvement a priori du texte est celui que la
  structure doit tenir entre deux rééquilibrages. Une valeur plus basse (par exemple 10 %) n'est admise que si deux pièces
  existent avant le gel, (i) une source quotidienne automatisable des décotes et de la liste EEE (item B3) et (ii) une
  déclaration écrite d'OEM sur le préavis d'un changement de décote ou de liste (item B9), et sur décision écrite du
  propriétaire.
- **K3 n'est levé** que si (i) et (ii) existent **et** que le préavis écrit dépasse le délai de détection plus D_man : le
  dénouement précède alors le changement. Décision écrite (item A1).

Calcul d'audit de K3 (h_u = 0,995) :

| Lecture | K3, x_det = 50 % | K3, x_det = 10 % | Sans K3 (h = 0,98) |
|---|---|---|---|
| L-b (c_ret = 1,30) | (1,5 × 2,30 − 1)/0,995 = **2,4623** | (1,1 × 2,30 − 1)/0,995 = 1,5377 | K2 = 0,9849 |
| L-a (c_ret = 0,30) | (1,5 × 1,30 − 1)/0,995 = 0,9548 | (1,1 × 1,30 − 1)/0,995 = 0,4322 | K1 = 0,0201 |

Une variante de K3 portant sur la seule liquidation λ2, et non sur l'appel, donnerait 2,0101 et 1,2060 sous L-b. Elle est
rapportée, pas retenue : le texte garde partout la lecture la plus prudente.

**Conséquence, écrite noir sur blanc.** Sous L-b avec x_det = 50 %, K3 vaut exactement la marge USDC que K1(a) exige sous la
même lecture (2,4623, §3.6). La jambe spot ne compte plus pour la sécurité : **(c) devient (a)**. C'est la lecture par défaut de
ce texte. L'avantage de (c) ne revient qu'avec les pièces (i) et (ii).

### 3.6 Réserve USDC : résultat (calcul d'audit)

Paramètres : B = 250 ; R_risk = 2,50 ; g_s = 4 pb (PROXY) ; f_s = 0,10 % ; f_x = 0,05 % ; h_u = 0,995 ; mmr = 0,65 % ;
x_cible = +50 % ; δ_m = 0,05 ; L = 1. N est donné **avant l'arrondi** en contrats entiers : c'est une borne haute, l'arrondi
enlève au plus un contrat (UNKNOWN avant T0). Distances à h = 0,98, sauf mention.

| Construction | ρ* (contrainte liante) | N_a | R_a | ρ effectif | E/V à l'ouverture | Appel, lecture retenue | Déclencheur (ii) (c_ret + 0,05) | Liquidation x_P / x_λ1 / x_λ2 | N total |
|---|---|---|---|---|---|---|---|---|---|
| **Défaut : (L-b, λ2), K3 (h_stress 0, x_det 50 %)** | 2,4623 (K3) | **71,44** | 178,42 | 2,4973 | 3,46 | +164,0 % | +154,4 % | +10 302 % / +17 324 % / +241,6 % | **142,89** |
| (L-b, λ2), K3 x_det 10 % | 1,5377 (K3) | 97,46 | 152,36 | 1,5633 | 2,54 | +93,6 % | +86,5 % | +7 528 % / +12 678 % / +150,5 % | 194,91 |
| (L-b, λ2), sans K3, h 0,98 | 0,9849 (K2) | 124,57 | 125,19 | 1,0050 | 1,98 | +51,5 % | +46,0 % | +5 870 % / +9 900 % / +96,1 % | 249,14 |
| (L-b, λ2), sans K3, h 0,80 | 1,2563 (K2) | 109,60 | 140,19 | 1,2791 | 2,07 | +51,5 % | +46,6 % | +964,5 % / +1 036,3 % / +89,4 % | 219,20 |
| (L-a, P/λ1), K3 x_det 50 % | 0,9548 (K3) | 126,49 | 123,27 | 0,9745 | 1,95 | +515,5 % (sous L-b : +49,2 %) | +432,3 % | +5 780 % / +9 748 % / (λ2 : +93,1 %) | 252,98 |
| (L-a, P/λ1), K3 x_det 10 % | 0,4322 (K3) | 172,59 | 77,09 | 0,4466 | 1,42 | +351,4 % (sous L-b : +9,4 %) | +290,4 % | +4 212 % / +7 122 % / (λ2 : +41,6 %) | 345,17 |
| (L-a, P/λ1), sans K3, h 0,98 | 0,0201 (K1) | 242,17 | 7,37 | 0,0304 | 1,01 | +222,0 % (sous L-b : −21,9 %) | +178,5 % | +2 975 % / +5 051 % / (λ2 : +1,0 %) | 484,34 |
| (L-a, P/λ1), sans K3, h 0,80 | 0,2010 (K1) | 205,75 | 43,86 | 0,2132 | 1,01 | +142,4 % (sous L-b : −19,2 %) | +120,4 % | +467,7 % / +506,0 % / (λ2 : +1,0 %) | 411,50 |

Vérification : achat spot + frais X-Perp + R = 250,00 pour chaque ligne. Sous la paire L-a, les distances λ2 entre parenthèses ne
comptent que si OEM ne les exclut pas par écrit ; elles sont alors inférieures à x_cible dans trois lignes sur quatre, d'où la
condition de §3.4. Pour comparer, le rapport structurel chiffrait K1(c) à N = 393,78 avec une réserve d'environ 100 USDC
(K1-eco §2.4) ; cette réserve n'était dérivée d'**aucune** règle de marge et elle est remplacée ici par ρ*.

**Comparaison (a) / (c) sous une même lecture** (relecture quant M1). Pour (a), la marge est un USDC m·N et le spot n'est pas
collatéral : E/V = (h_u·m − x)/(1 + x). K1 donne h_u·m ≥ 1/L ; K2 donne h_u·m ≥ (1 + x_cible)(1 + c_ret) − 1. Même formule de
budget.

| Lecture | K1(a) : m | N total (a) | (c) sans K3 (h 0,98) | (c)/(a) | (c), K3 10 % | (c), K3 50 % |
|---|---|---|---|---|---|---|
| L-a | 1/h_u = 1,0050 (K1) | 246,65 | 484,34 | 1,96 | 345,17 (1,40) | 252,98 (1,03) |
| L-b | 2,45/0,995 = 2,4623 (K2) | 142,89 | 249,14 | 1,74 | 194,91 (1,36) | 142,89 (1,00) |

Lecture :
1. À lecture égale et sans choc de décote, (c) porte 1,74 à 1,96 fois le notionnel de (a). La phrase de la version précédente
   (« l'avantage de capital de (c) sur (a) n'existe que sous L-a ») était fausse : elle comparait (c) sous L-b à (a) sous L-a.
2. Avec K3 au défaut, (c) = (a) sous L-b, et (c) = 1,03 × (a) sous L-a.
3. Ce qui sépare (c) de (a) n'est donc pas la clause 30 % : c'est la confiance accordée à la stabilité des décotes, qui relève
   des pièces (i) et (ii) de §3.5.

**Sensibilité à x_cible** (paramètre de conception, relecture quant m9) : sous (L-b, λ2) sans K3, h = 0,98,
x_cible = 0,10 / 0,20 / 0,30 / 0,50 donne N total = 339,93 / 311,55 / 287,54 / 249,14. Sous K3 au défaut, x_cible ne change
pas N, puisque K3 lie. **Sensibilité à x_det** sous L-b : x_det = 0 / 0,10 / 0,20 / 0,30 / 0,50 donne N total = 214,43 / 194,91 /
178,65 / 164,90 / 142,89. Même à x_det = 0, K3 (1,3065) dépasse K2 (0,9849) sous L-b.

### 3.7 Rééquilibrage, latence et séquence des jambes (CS-FUND §3, étendu)

Un rééquilibrage ferme les deux jambes, puis rouvre selon §3.3, avec pour budget la valeur de dénouement courante de la
structure. Il compte quatre exécutions, toutes comptées dans y_m.

- **Séquence des jambes.** Fermeture : spot vendu d'abord (bid moins g_s, frais f_s), puis short racheté (ask plus g, frais f_x),
  au **même** relevé. Réouverture : achat spot, puis vente du short, au même relevé. Le solde USDC_a(t) ne devient jamais négatif
  dans le grand-livre. Motif (relecture risque M2) : sous L-a, racheter le short d'abord à +50 % réaliserait
  0,5 × 242,17 = 121,09 USD de perte contre une réserve de 7,37 USDC, donc un solde négatif, un emprunt ou une vente de crypto
  par OKX EEA (CGU §1.9.5), non modélisés. L'écart de quelques minutes entre les deux jambes n'est pas modélisé (déclaré,
  §3.12).
- **Latence D_man = 8 h**, appliquée à **toutes** les règles manuelles : rééquilibrage (i)–(iii), L1, L2, R_a ≤ 0, événements
  de §3.10, vente du spot après un ADL. La décision est prise à t_dec ; l'exécution a lieu au premier relevé horaire
  ≥ t_dec + D_man, au carnet de ce relevé. Pendant D_man, la structure est tenue et les règles d'OKX (appel, liquidation, ADL)
  restent évaluées. Motif : aucun ordre natif ne porte un stop sur la valeur combinée spot + X-Perp ; un stop sur le short seul se
  déclencherait vers +2 à +3,5 % (§3.8) ; TradingV3 n'a ni bundle spot, ni résolveur X-Perp, ni moniteur de structure (rapport
  §4.1 point 18 ; K1-ops §2). La vente est donc manuelle, et 8 h est l'intervalle de règlement par défaut
  (perps-funding-fee-mechanism). Sensibilités rapportées : **D_man = 1 h, étiquetée « exécuteur automatique, inexistant »**, et
  D_man = 24 h.
- **Déclencheurs** :
  - (i) \|M/prix_ref − 1\| ≥ 50 % (CS-FUND, inchangé), évalué à chaque relevé horaire ;
  - (ii) E/V ≤ c_ret + δ_m, avec δ_m = 0,05 (paramètre de conception), soit 1,35 sous L-b et 0,35 sous L-a. Ce déclencheur est
    évalué **à la minute**, de façon prudente : E/V est calculé avec le plus haut de la bougie de mark 1 min du X-Perp et le plus
    bas de la bougie spot 1 min de la même minute (bougies lues après T0, §9.1). Il capte aussi l'érosion de la réserve par un
    funding négatif, la « règle sur le ratio de marge » qui manquait (K1-eco §7.6) ;
  - (iii) un **changement de décote** relevé (événement de règle, §9.3) qui rend fausse l'inégalité K1, K2 ou K3 : t_dec est
    l'heure du relevé qui le constate.
- Les appels de marge et les liquidations de C6 sont évalués de la même façon, à la minute (relecture risque M3).

prix_ref est tenu **hors** du compte, puisque la Daily Compression (CGU OEM §1.11) fusionne les prix (K1-ops §2). Aucune sortie
n'est conditionnée au funding : le bras reste statique.

### 3.8 Coupe-risque de marché ; queues non bornées

**Valeurs de dénouement**, mises à jour à chaque réouverture, avec B fixe depuis T0 :

```
VD_mod,a(t) = USDC_a(t) + Q_a·S_mid(t)·(1 − f_s − g_s) + q_a·c_a·(P₀,a − M(t)) − (f_x + g)·q_a·c_a·M(t)
VD_exe,a(t) = USDC_a(t) + Q_a·bid_spot(t)·(1 − f_s − g_s) + q_a·c_a·(P₀,a − ask_XPerp(t)·(1 + g)) − f_x·q_a·c_a·ask_XPerp(t)
USDC_a(t)   = réserve de la dernière ouverture + funding encaissé ou payé depuis + résultats réalisés antérieurs
perte_a(t)  = VD_a(t) − B          (B = 250 USDC)
```

Q_a, q_a et P₀,a sont ceux de la dernière ouverture. Le funding n'est compté qu'une fois, dans USDC_a. À l'ouverture,
perte_a = −0,42 % × N_a : c'est l'aller-retour de coûts.

- **L1 (0,5 %)** : déclenché si VD_mod,a − B ≤ −2,50 USD (0,5 % × 500) au relevé horaire t **et** au relevé t + 1 h
  (confirmation). Dénouement au premier relevé ≥ t + D_man, dans l'ordre de §3.7, au carnet de ce relevé (VD_exe). La
  structure reste en USDC jusqu'à la fin et n'est jamais rouverte.
- **L2 (1 %)** : même règle sur la somme des deux structures ≤ −5,00 USD (1 % × 500) : les deux sont dénouées.
- **Tout déclenchement fait échouer le track.** C6 exige « aucun coupe-risque » (§8.2). La version précédente en tolérait un ;
  c'était un texte mort, puisqu'un L1 fixait déjà Σ y^a ≤ −2,50 et faisait échouer C5. La futilité F1b arrête alors la mesure
  (§8.4).
- **Jamais sur le short seul.** Au défaut, N_a = 71,44 : le short seul perdrait 2,50 USD sur un mouvement de
  2,50 / 71,44 = +3,5 % (+2,0 % sous L-b sans K3), et la règle se déclencherait sans cesse alors que la structure ne perd rien.
- **L1 est un stop de base, déclaré** (relecture quant M3). Après l'aller-retour, le budget de L1 ne vaut que 1,6 à 3,1 % de N_a
  (0,6 % sous L-a sans K3). Une hausse de la prime contre le short l'entame directement. Or, d'après les champs de règle, la
  formule de funding opère jusqu'à une prime de 0,425 % (BTC) et de 0,80 % (ETH) (§2.3). L1 sort donc quand la prime s'élargit,
  c'est-à-dire quand le short encaisse le plus. **L'hypothèse testée est « le portage sous ce stop de base », pas le portage
  seul.** Pour en mesurer le coût, un **grand-livre fantôme sans L1 ni L2** est rapporté ; il est descriptif, jamais
  décisionnel. La confirmation sur deux relevés et le marquage au mark (et non à l'ask) réduisent les déclenchements sur un écart
  transitoire du carnet.
- **Ce que la règle ne borne pas : les queues (déclarées).** La perte d'un dénouement forcé (ADL, délisting) vaut r_D × N_a, où
  r_D est le mouvement du spot pendant D_man. Le seuil qui fait franchir L1 vaut 2,50/N_a, celui de L2 5,00/N_a. Calculs
  d'audit, avec des mouvements illustratifs de 10 % et 20 % qui ne sont pas des mesures :

| Construction | N_a | Budget L1 après aller-retour | Règlements ETH au plancher avant L1 | Prime au bord du plafond / budget (BTC ; ETH) | Mouvement sur D_man qui franchit L1 ; L2 | Dénouement forcé à 10 % ; 20 % (× L1) | 8 règlements ETH au plancher (escalade à 1 h, UNKNOWN) |
|---|---|---|---|---|---|---|---|
| **Défaut** | 71,44 | 2,20 USD (3,08 %) | 4,1 | 14 % ; 26 % | 3,50 % ; 7,00 % | 7,14 (2,9) ; 14,29 (5,7) | 4,29 (1,7 × L1) |
| (L-b, λ2), K3 10 % | 97,46 | 2,09 (2,15 %) | 2,9 | 20 % ; 37 % | 2,57 % ; 5,13 % | 9,75 (3,9) ; 19,49 (7,8) | 5,85 (2,3) |
| (L-b, λ2), sans K3 | 124,57 | 1,98 (1,59 %) | 2,1 | 27 % ; 50 % | 2,01 % ; 4,01 % | 12,46 (5,0) ; 24,91 (10,0) | 7,47 (3,0) |
| (L-a, P/λ1), sans K3 | 242,17 | 1,48 (0,61 %) | 0,8 | 69 % ; 131 % | 1,03 % ; 2,06 % | 24,22 (9,7) ; 48,43 (19,4) | 14,53 (5,8) |

  Formules : budget = 2,50 − 0,42 % × N_a ; règlements = budget/(0,75 % × N_a) ; dernière colonne = 8 × 0,75 % × N_a (si
  l'escalade de l'intervalle à 1 h s'appliquait aux X-Perps, ce qui est UNKNOWN, GATES §5.1 ; à 8 h, c'est au plus un règlement).
  Sous L-a sans K3, une prime ETH au bord du plafond suffit à elle seule à déclencher L1. Il y a aussi la **perte de
  contrepartie** : jusqu'à 500 USDC, soit 200 × L1 ou 100 × L2. Le collatéral est chez OKX Europe Ltd, sans fonds de garantie
  MiCA, et l'ICS ne couvre qu'OEM (§3.11). Le rapport structurel applique lui-même la limite de 1 % à une perte possible de 100 %
  pour Simple Earn (« aucun montant > 5 USDC ne respecte la limite totale de 1 % », rapport §2.1, ligne K8).
- **Décision écrite du propriétaire avant le gel** (item A1, §12.1), entre deux options :
  - **(a) dimensionner par le stress** : N_a ≤ 2,50 / r_stress, avec r_stress = 10 % sur D_man (paramètre de conception), soit
    N_a ≤ 25 USD. Calcul d'audit à F = I : 2 × 25 × (10,95 % − 0,42 %) = **5,27 USD/an**, contre 19,72 pour le SOFR. Cette option
    tue l'intérêt économique, et le texte le dit ;
  - **(b) accepter par écrit une queue chiffrée** : « perte maximale déclarée par structure = r_stress × N_a + 0,42 % × N_a, hors
    contrepartie ; risque de contrepartie sur 500 USDC accepté hors du cadre 0,5 % / 1 % ». Calcul d'audit à r_stress = 10 % :
    7,44 USD par structure au défaut (14,89 pour les deux, 2,98 % du capital) ; 12,98 (25,96, 5,19 %) sous L-b sans K3 ; 25,23
    (50,47, 10,09 %) sous L-a sans K3.

### 3.9 Dénouement forcé : ADL, la jambe gagnante fermée en premier

Règle OKX (page ADL, màj 2026-08-11, desk §4.1) : la file est classée par « leverage PnL% » ; « positions with the highest
positive leverage PnL% will be offsetted first » ; « no warnings are issued prior to ADL occurring » ; la fermeture se fait au
mark, ou au prix de faillite si le fonds est presque épuisé ; « Trading fees are not charged on deleveraged positions ». **Seules
les positions gagnantes sont touchées.** Le short couvert gagne quand le prix baisse : il est en tête de file dans le krach
(K1-ops §2 : PnL +30 % pour mmr 0,65 % donne un rapport de 46). Après l'ADL, le spot est un **long nu**.

- **Événement simulé.** Une position papier n'est dans aucune file. L'ADL est simulé si, et seulement si, (1) l'enregistreur
  observe un état `adl` pour la famille de l'identifiant sur le canal public `adl-warning`, **et** (2) le short de la structure
  est gagnant à cet instant : M(t) < P₀,a (relectures quant M4, risque M4). Dans une hausse avec ADL actif, ce sont les longs
  gagnants qui sont réduits, et la structure n'est pas touchée.
- **Canal.** `adl-warning` est un canal **WebSocket** (`instType` FUTURES, `instFamily` optionnel), poussé seulement en état
  warning ou adl, une fois par seconde (desk §0 point 2, §1.3) ; le changelog du 2026-06-09 signale des champs vides. Le type `adl`
  de `public/insurance-fund` est déprécié et vide : il n'existe pas de substitut REST. L'enregistreur comporte donc un client WS
  pour ce seul canal, sur l'hôte public EEE. Son caractère public sur cet hôte, sa couverture des X-Perps et ses champs sont
  UNKNOWN : ils sont vérifiés au gel (§12.1) et le canal entre dans P-S1.
- **Règle de dénouement.** À l'horodatage t_ADL :
  - le short est fermé, sans frais, au **pire** mark (le plus haut) des bougies de mark 1 min de l'heure de t_ADL. Le prix de
    faillite de la contrepartie n'est pas public (UNKNOWN), d'où ce majorant ;
  - le spot est vendu au bid du **premier relevé ≥ t_ADL + D_man**, frais f_s et glissement g_s compris ;
  - le coût est **compté dans y_m** : variation signée du spot pendant D_man, plus frais, plus coûts de réouverture ;
  - la structure est rouverte au 00:05 UTC suivant selon §3.3, sauf si L1 ou L2 est atteint.
- **Stress décisionnel C7, indépendant du canal.** Sans canal valide, aucun ADL n'est simulé et le biais vers le PASS n'est pas
  borné. Le stress de dénouement forcé devient donc un critère (C7, §8.2) : pour chaque structure, un dénouement forcé au pire
  relevé de l'année parmi ceux où le short gagne.
- **Sensibilités rapportées** : D_man = 1 h et D_man = 24 h.
- L'indicateur `adl` 0–5 (GATES §10.3, « si le propriétaire l'autorise ») est un champ privé d'une **position réelle** : sans
  objet en papier.

### 3.10 Suspension, délisting, retrait de l'admission en marge, changement de I (CGU OEM §12)

Les CGU OEM §12.1 et §12.2 permettent de « change, interrupt, suspend or terminate the Service(s) at any time without notice »
(K1-ops §1). Rien ne garantit un préavis. L'enregistreur relève chaque jour `state` des quatre identifiants. Toute action
manuelle s'exécute au premier relevé ≥ détection + D_man (§3.7).

| Événement | Détection | Règle papier | Verdict |
|---|---|---|---|
| X-Perp **suspendu** (non négociable) | `state`, quotidien | Aucune action : la couverture tient tant que le short est marqué ; vendre le spot créerait un short nu | Rapporté ; `INCONCLUSIVE` si la suspension fait manquer plus de 5 % des règlements |
| X-Perp **délisté ou réglé** par OEM | `state`, quotidien | Short clôturé au prix de règlement publié ; spot vendu au premier relevé ≥ règlement + D_man ; coûts comptés | `INCONCLUSIVE` (CS-FUND §7 : « retire un instrument ») |
| Paire spot **suspendue ou retirée** | `state`, quotidien | Les deux jambes sont conservées tant que la paire n'est pas négociable. Si le retrait est définitif : spot valorisé au dernier bid capturé, short fermé au relevé suivant, écart compté | `INCONCLUSIVE` |
| **Spot retiré de la liste de marge**, ou décote rendant K1 impossible | relevé hebdomadaire manuel, ou quotidien si la source B3 est validée | Dénouement des deux jambes, coûts comptés : la variante (c) n'existe plus. K3 borne l'exposition pendant le délai de détection | `INCONCLUSIVE` ; le track S est fermé (A-S4 iv) |
| **Baisse** de `interestRate` sous 0,0001 | `funding-rate`, chaque règlement + 5 min | Dénouement des deux structures, coûts comptés : le seul terme contractuel du track a disparu | **`FAIL_MECHANISM`**, compté comme un résultat négatif pour la famille (K1-eco §10 : « perte totale du rendement, pas INCONCLUSIVE technique ») |
| **Hausse** de `interestRate` | idem | Aucune action ; le funding réel continue d'être compté | `INCONCLUSIVE` (CS-FUND §7) |

Le Σ y_m accumulé jusqu'à l'événement est toujours rapporté.

### 3.11 Deux entités, gage, cascade et ordre de consommation du pool (CGU OEM §1.9)

- Le contrat est conclu avec **OEM** (MiFID). Le spot et l'USDC restent chez **OKX Europe Ltd** (CASP, MiCA), sous gage d'OEM
  (§1.9.1, §1.9.2, §9.1.2), sans transfert (K1-ops §1).
- §1.9.5 : une perte réalisée est couverte d'abord par le fiat, puis par les crypto-actifs **liquidés par OKX EEA**. La jambe
  spot peut donc être vendue sans ordre du propriétaire.
- **La garde du papier suppose que l'USDC part en premier.** R_a ≥ R_risk + ρ*·N_a garantit que L1 se déclenche avant que la
  réserve s'épuise, à condition que les débits (funding payé, frais, pertes réalisées) soient pris sur l'USDC. Or l'ordre de
  consommation des actifs du pool Selected Margin (« l'article ne dit pas quels actifs sont liquidés en premier », K1-ops §4) et
  la place de l'USDC, ni fiat ni « crypto » au sens de la clause, dans la cascade §1.9.5 (K1-ops §1) sont UNKNOWN (relecture
  risque M6). Traitement :
  - question écrite à OEM (item B9) ;
  - lecture de `acctLv` et du comportement d'emprunt automatique (item B4) : si l'emprunt automatique est actif, un solde USDC
    négatif devient un prêt non modélisé. Le papier interdit tout solde négatif (séquence de §3.7) et traite R_a(t) ≤ 0 comme un
    coupe-risque (C6) ;
  - **sensibilité « spot consommé d'abord »**, rapportée et déclarée comme biais : chaque débit est pris sur la jambe spot
    (vente au bid, frais f_s). La couverture s'érode, la structure prend un delta court et chaque vente est un événement
    150 VH bis, compté (§6.3).
- Couverture : l'ICS maltais couvre les obligations d'OEM, pas le collatéral chez le CASP ; MiCA n'a pas de fonds de garantie
  (K1-ops §1, rapport §4.1 point 13). Ce risque n'est pas modélisable ; il est déclaré et entre dans la décision de queue (§3.8).
- Risque de requalification en CFD avec KID PRIIPs exigé (circulaire MFSA du 2026-03-09, rapport §4.1 point 16) : rapporté.

### 3.12 Ce que le papier ne voit pas (biais déclarés)

| Élément non modélisé ou choix prudent | Sens du biais |
|---|---|
| File ADL réelle de la position (§3.9) ; prix de faillite remplacé par le pire mark de l'heure | vers le PASS, borné par C7 |
| Exécuteur automatique supposé si D_man = 1 h | vers le PASS (sensibilité seulement ; le scénario principal est 8 h) |
| Écart de quelques minutes entre les deux jambes d'une même exécution | UNKNOWN (exposition nue courte, signe inconnu) |
| L1 agit comme un stop de base (§3.8) | l'hypothèse testée en dépend ; vers le FAIL sous une prime large ; mesuré par le grand-livre sans L1 |
| Lectures prudentes (L-b, λ2) et K3 au défaut | vers le FAIL (prudence voulue) |
| Ordre de consommation du pool (§3.11) | UNKNOWN ; sensibilité « spot consommé d'abord » |
| Impact de marché | vers le PASS |
| Bandes de prix | vers le PASS |
| Prix de valorisation du collatéral par OKX | UNKNOWN |
| Calcul de l'IMR d'une position couverte en Selected Margin (compensation ? `ordFrozen` ?, desk §5.2) | UNKNOWN |
| Paliers de décote selon la quantité | Lus au gel |
| Raccourcissement de l'intervalle de funding sur X-Perps (GATES §5.1) | UNKNOWN ; le code lit les règlements, il ne suppose aucun intervalle |
| Carnet spot EEE | PROXY, 4 pb, tant qu'il n'est pas prouvé |
| Bougies spot 1 min en dernier prix, pas en bid (§3.7, C7) | léger biais vers le PASS sur les stress |
| Marquage de fin de mois sur une référence commune (§8.1) | aucun sur Σ y_m ; retire la base hors exécutions des signes mensuels ; le marquage mixte est rapporté |
| Les mois sont autocorrélés (régimes de 2 à 12 mois, rapport §5a) | Le bootstrap de mois (§8.3, F3) **sous-estime** l'incertitude : vers le « poursuivi » et vers la continuation |

### 3.13 Paramètres de conception (choix a priori, déclarés)

| Paramètre | Valeur | Origine |
|---|---|---|
| B | 250 USDC × 2 | CS-FUND §3 : 500 USDC, deux créneaux à parts égales |
| L (levier réglé) | 1 | GATES §10.5 |
| x_cible | +50 % | Seuil de rééquilibrage de CS-FUND §3 ; **levier principal de N** sous (L-b, λ2) sans K3 (sensibilité §3.6) |
| R_risk | 2,50 USD | CS-FUND §3, coupe-risque |
| δ_m | 0,05 | **choix de l'auteur** |
| Paire de lectures | (L-b, λ2) | la plus prudente ; (L-a, P/λ1) sur réponse écrite d'OEM aux deux clauses |
| h_stress | 0 | retrait de la liste (relecture risque B2) ; `minDiscountRate` sur acceptation écrite |
| x_det | 50 % (= x_cible) | **choix de l'auteur** ; 10 % ou levée de K3 sous les conditions de §3.5 |
| D_man | 8 h (sensibilités 1 h « exécuteur inexistant » et 24 h) | **choix de l'auteur**, ancré sur l'intervalle de règlement par défaut ; s'applique à toutes les règles manuelles |
| Confirmation de L1 | deux relevés horaires consécutifs | **choix de l'auteur** (relecture quant M3) |
| r_stress | 10 % sur D_man | **choix de l'auteur**, repris de l'exemple de la relecture risque B1 ; sert aux options (a) et (b) de §3.8 |
| g, g_s | 2 pb ; spot PROXY 4 pb par défaut | CS-FUND §5 ; version longue §5.3 |
| Cadence de contrôle | horaire à HH:05 UTC ; à la minute pour appels, liquidations et déclencheur (ii) | **choix de l'auteur** (CS-FUND ne relevait qu'à chaque règlement) ; relecture risque M3 |
| Seuil de trous des relevés de risque | > 2 % des heures (175 h sur 8 760) ou un trou > 24 h | **choix de l'auteur** (relecture risque M3) |
| Relevés hebdomadaires | lundi 08:00 UTC ± 4 h ; > 3 manqués ou 2 consécutifs ⇒ `INCONCLUSIVE` tant qu'aucune source quotidienne n'est validée | convention K8 (rapport §3) ; seuil : **choix de l'auteur** |
| Bootstrap (porte S_éco, F3) | 1 000 tirages, graine 20261102 | GATES §3.1 |
| Délai de verdict | fin + 7 jours au plus | **choix de l'auteur** |
| Facteur fiscal | 0,686 | A-S3 (§6) |

---

## 4. A-S1 — Lecture rétrospective

### 4.1 Option (i) : prospectif pur — RETENUE PAR DÉFAUT

CS-FUND §0.4 s'applique **au protocole S, à son code, à ses décisions et à ses auteurs** : aucune lecture de l'historique de
funding, des bougies, des carnets ou des prix des quatre identifiants antérieure à T0, ni avant ni après le gel. La seule
information sur la série est E4 (profondeur, aucune valeur). Une lecture faite par un **autre** protocole (G5 Gate 1) n'est pas
interdite par ce texte, qui ne gouverne pas G5 : elle est réglée par P-S3 (§4.3), avant l'un ou l'autre gel.

### 4.2 Option (ii) : Gate 1 rétrospectif « tuer seulement sur des faits de règle » — ALTERNATIVE

Cette option exige un **consentement écrit distinct** du propriétaire. Ni D1, ni D2, ni la décision 13 de GATES §11 n'en
tiennent lieu. Elle est décrite ici pour être choisie ou refusée **avant** le gel ; elle n'est pas proposée par défaut.

**Exécution**
- Une lecture **unique** de `public/funding-rate-history`, pour les deux identifiants X-Perps figés seulement.
- Code figé et haché au gel ; paramètre `after = 1791936000000` (2026-10-14T00:00:00Z ; `after` renvoie les enregistrements
  antérieurs). Le code s'arrête sur assertion si un enregistrement est ≥ ce timestamp.
- Ligne de registre `okx-xperp-funding-history-trackS` créée avant la lecture ; elle passe au statut `consumed_equivalent`
  après.
- **Sortie scellée** (relecture quant m7) : le code ne sort que quatre booléens, K-R1 à K-R4. Les statistiques descriptives
  (moyenne, signe, variance, parts au plafond, au plancher et à I) sont écrites dans un fichier haché, non ouvert, révélé avec le
  verdict du mois 12. Le propriétaire, qui lance l'enregistreur, ne peut donc pas tuer de fait sur la moyenne.

**Profondeur** (calcul d'audit sur E4 : ≈ 3 mois observés ; rétention documentaire UNKNOWN)
- Si la rétention glisse sur 3 mois, une lecture au 2026-11-02 couvre ≈ 2026-08-02 → 2026-10-13, soit ≈ 72 jours et
  ≈ 216 règlements par actif à 8 h (K1-ops §3.1).
- Après ≈ 2027-01-14, la fenêtre utile est vide.
- Les régimes négatifs durent 2 à 12 mois (rapport §5a) : un arrêt sur la moyenne d'un seul régime serait un faux négatif
  probable (GATES §10.4, quant M9b).

**Faits qui tuent** (décisionnels ; tout autre résultat n'a aucun effet)
- K-R1 : `interestRate` ≠ 0,0001 sur un identifiant figé ;
- K-R2 : `formulaType` ou `method` différent de la formule figée au gel ;
- K-R3 : un intervalle `fundingTime → nextFundingTime` hors de l'ensemble figé **{8 h, 4 h, 2 h, 1 h}**. L'ajustement automatique
  des intervalles est documenté pour les « crypto perpetual contracts » seulement, et son application aux X-Perps est UNKNOWN
  (GATES §5.1) : l'ensemble figé rend le fait décidable sans s'appuyer sur lui (relecture quant m6) ;
- K-R4 : un règlement au-delà du plafond ou du plancher figés (la règle appliquée n'est pas la règle publiée).

**Rapporté, jamais décisionnel, et scellé jusqu'au verdict** : moyenne, signe, variance, part des règlements à I, part au
plafond, part au plancher.

**Interprétation signalée au propriétaire.** GATES §10.4 range la « fréquence des plafonds touchés » parmi les faits de règle.
Ce brouillon la classe en rapport, parce qu'un seuil de fréquence est une statistique de la variable de marché, ce qu'A-S1
interdit d'utiliser pour tuer. Le propriétaire peut trancher autrement, avant le gel de l'option (ii) seulement.

**Effet et biais**
- Un Gate 1 sans fait de règle n'autorise rien de plus que le gel déjà prévu.
- Le biais de persistance est déclaré : la composante funding est « sérialement corrélée » (CMU, rapport §5a). Un filtre sur
  le passé gonfle la probabilité d'un PASS prospectif.

### 4.3 Interaction avec G5 Gate 1 (décision D1) : P-S3

D1 couvre, pour G5 Gate 1, les « bougies 1 minute dernier prix, mark et indice, et l'historique de funding des 29 X-Perps
liquides, du listing au 2026-10-13 inclus ». BTC et ETH font probablement partie de ces 29 : la liste date du 2026-10-14, est en
quarantaine et n'a pas été relue ici (UNKNOWN). Si G5 lit ces séries, le propriétaire, qui reçoit les sorties de G5, est exposé ;
et l'ordre des gels ne règle pas l'interdit de §0.4, qui vaut aussi après le gel (relectures quant m8, risque M7).

**P-S3 — décision écrite du propriétaire, avant le gel du track S et avant celui de G5 Gate 1**, entre :
- **(α) exclusion, recommandée** : `BTC-USD_UM_XPERP-310404` et `ETH-USD_UM_XPERP-310404` sont retirés de l'univers figé de G5
  Gate 1 et de toutes ses sorties. Coût : deux instruments de moins sur 29 pour G5, non recalculé ici. C'est la seule option qui
  laisse le track S sans exposition ;
- **(β) amendement explicite de §0.4** : « l'interdit vaut pour le protocole S ; la lecture G5 est une exposition déclarée (E9),
  sans effet sur les paramètres figés ». Le track S est alors gelé avant l'exécution de G5 Gate 1, et aucun paramètre ne change
  après la lecture G5.

Sous l'option A-S1(ii), la lecture A-S1 et celle de G5 portent sur la même série : sous (β), la seconde est déclarée comme
relecture d'une série `consumed_equivalent`.

---

## 5. A-S2 — Contrôles

Toutes les lignes portent sur la fenêtre [T0 ; T0 + 12 mois] et sur K = 500 USDC (le capital entier est engagé : spot +
réserve). Unité : USDC ; le SOFR, taux USD, est appliqué à K à parité, et l'écart USDC/USD est rapporté à part (§8.1).

| Ligne | Formule | Rôle | Calcul d'audit (365 jours) |
|---|---|---|---|
| **Référence USD : SOFR** (NY Fed) | Ctrl_SOFR = K × Σ_d SOFR_d / 360, sur chaque jour calendaire d, avec SOFR_d = dernier taux publié ≤ d (convention K8) ; part mensuelle ctrl_m = K × Σ_{d ∈ m} SOFR_d / 360 | Hurdle de **C2** ; terme retranché dans la porte S_éco et dans F3 | à SOFR constant 3,89 % : 500 × 0,0389 × 365/360 = **19,72 USD** |
| **Simple Earn USDC EEE**, « descriptive, non admise, borne supérieure affichée » | K × Σ_w a_w × φ × 7/365 sur les relevés hebdomadaires w ; φ = 0,85 si le brut ou net des 15 % est UNKNOWN | Contrôle atteignable ; label A-S4(iii) ; **jamais** substitué au SOFR | ≈ 3,5 % indicatif : 17,5 USD/an (rapport §1 l.3 ; non garanti) |
| **Livret A** (si la devise de compte est l'EUR, S-O7) | K_EUR × Σ_quinzaines taux/24 (convention K8, q/24), K_EUR recalculé à T0 | Borne EUR nette, sans risque | 444,77 EUR × 1,70 % = 7,56 EUR/an (rapport F6) ; certain jusqu'au 2027-01-31, ensuite fixé par arrêté (UNKNOWN) |
| **Change EUR/USD** (si devise EUR) | 500/fx_fin − 500/fx₀ en EUR, taux de référence BCE | **Rapporté, jamais coupé** | 1 % = 4,45 EUR (rapport F6) |

Sources des relevés Simple Earn :
- si K8 est gelé, ses relevés hebdomadaires, jusqu'à la fin de K8, bornée au 2027-01-31 (rapport §6.2 décision 2) ;
- ensuite, ou si K8 n'est pas gelé, un relevé manuel hebdomadaire par le propriétaire, selon les règles de lecture de K8 :
  fourchette → borne basse ; APY → taux simple 365 × ((1 + APY)^(1/365) − 1) ; « up to » → UNKNOWN → MISSING.

**Charge de supervision** (calcul d'audit, cas de référence §9.5) : 53 lundis dans la fenêtre (2026-11-09 → 2027-11-08), dont 41
après la fin de K8 (2027-02-01 → 2027-11-08). Un relevé Simple Earn manqué est noté MISSING dans la ligne descriptive, sans effet
sur le verdict. Un relevé de règle manqué (liste de marge, décotes EEE) obéit au seuil de §3.13.

**Les deux côtés de toute comparaison sont taxés**, au même facteur (§6). Le Livret A est exonéré : il entre net.

---

## 6. A-S3 — Impôt symétrique et asymétrie déclarée

### 6.1 Convention unique

**PFU 31,4 %, soit un facteur 0,686**, appliqué au résultat de K1(c), au SOFR et à Simple Earn. Le PFU 31,4 % est cité comme en
vigueur depuis le 2026-01-01 (service-public A18796, rapport F6), et c'est la convention de K8. Le 0,70 des sceptiques n'est
pas utilisé. Son application aux gains X-Perps et au funding reste UNKNOWN (conseil : S-O6).

### 6.2 Critères avant impôt

C1–C7 et la porte S_éco sont évalués **avant impôt**. Les lignes après impôt sont rapportées.

### 6.3 Asymétrie déclarée

- Jambe X-Perp : instrument financier MiFID, a priori art. 150 ter CGI ; les pertes s'imputent sur des profits de même nature
  pendant 10 ans.
- Jambe spot : actif numérique, art. 150 VH bis ; échange crypto-crypto en sursis ; les pertes ne sont imputables que sur des
  plus-values de même nature de l'année, sans report.
- Les deux régimes ne se compensent pas. Qualification exacte UNKNOWN (rapport §4.1 point 3 ; K1-ops §6).
- Chaque vente de spot (rééquilibrage, dénouement, et chaque débit de la sensibilité « spot consommé d'abord », §3.11) est un
  événement 150 VH bis ; leur nombre est rapporté.

Ligne rapportée « asymétrique, pire cas » (t = 31,4 %, F = funding de l'année, N = notionnel, r = mouvement de l'année) :

```
baisse de r :  short + F (150 ter, imposé) ; spot −r·N jamais imputé   ⇒  après impôt = F·(1 − t) − t·r·N
hausse de r :  short −r·N + F (perte 150 ter jamais utilisée) ; spot +r·N imposé à la sortie fiat
                                                                         ⇒  après impôt = F − t·r·N
symétrique  :  après impôt = F·(1 − t)
```

Calcul d'audit à F = I = 10,95 % du notionnel, frais ignorés : le pire cas devient négatif dès une baisse annuelle
r > I·(1 − t)/t = **23,9 %**, ou une hausse r > I/t = **34,9 %**. Cela rejoint l'exemple de K1-ops §6 : BTC −30 % donne
−1,5 USD après impôt pour +12,5 de funding, au facteur 0,70. Le seuil d'exonération de 305 € (150 VH bis II.B) et la catégorie
du funding reçu (revenu ou élément du 150 ter ?) sont UNKNOWN.

---

## 7. A-S4 — Validité et portée d'un PASS

1. **Durée.** Un PASS vaut **six mois** après la date du verdict du mois 12 (≈ 2028-05 au cas de référence §9.5). Au-delà, il
   faut un nouveau pré-enregistrement. Ces six mois courent pendant les travaux préalables du point 2 : ils peuvent ne pas suffire.
2. **Portée.** **Seul un « PASS, poursuivi »** (§8.3) autorise une **proposition écrite** de petite exécution **démo** (GATES
   §1.2 : « proposition démo possible » pour une famille poursuivie). Un « PASS, non poursuivi » n'autorise rien et ferme le
   track S comme en point 4 ; il est publié comme tel (GATES §1.4). Jamais un trading réel, jamais un levier > 1×, jamais une
   sélection par funding (le bras (d) reste descriptif). La présence des X-Perps en démo et le Selected Margin avec spot en démo
   sont UNKNOWN (`H1-XPERP-ACCOUNT-CHECK.md`) : la proposition peut se révéler impossible. **Prérequis séparés**, chacun soumis à
   une décision et à des travaux distincts (`H1-XPERP-TECHNICAL-GAP.md` ; rapport §4.1 point 18 et Annexe B) : bundle spot OKX ;
   résolveur X-Perp (`instType SWAP` codé en dur) ; comptabilité USDC (USDT codé en dur) ; `tdMode` de Selected Margin ; moniteur
   de structure qui porte un stop sur la valeur combinée spot + X-Perp (§3.7) ; adaptation au `posMode` si le compte est en mode
   net. Le chemin papier, lui, ne dépend d'aucun code TradingV3.
3. **Labels** (GATES §1.4 et A-S4 iii) :
   - « PASS, poursuivi » ;
   - « PASS, non poursuivi (sous S_éco) » ;
   - « PASS, sous le contrôle atteignable » (C2 passe au SOFR, mais Σ y_m < ligne Simple Earn réalisée). Ce label est
     **inapplicable** tant que la ligne Simple Earn reste sous Ctrl_SOFR (indicatif 3,5 % contre 3,89 %) : il ne s'applique que si
     elle le dépasse ;
   - les qualificatifs se cumulent ;
   - verdicts de fermeture : `FAIL`, `FAIL_FUTILITY`, `NOT_PURSUED_FUTILITY`, `FAIL_MECHANISM`, `INCONCLUSIVE` (§8.4).
4. **Fermeture.** `INCONCLUSIVE`, `FAIL`, `FAIL_FUTILITY`, `NOT_PURSUED_FUTILITY`, `FAIL_MECHANISM` ou « PASS, non poursuivi »
   ferme le track S, sans redémarrage sur les mêmes 12 mois et sans variante : autres actifs, sélection par funding, levier,
   autre durée (CS-FUND §9).

---

## 8. Critères réécrits pour K1(c)

### 8.1 Mesure

**Unité.** Tous les critères, contrôles et seuils sont en **USDC** (relecture quant m3). Le X-Perp est indexé en USD (`uly`
BTC-USD) et le spot est coté en USDC : en comptabilité USDC, la structure porte une exposition d'environ N × (1 − p)/p au cours
p de l'USDC en USD. Le PnL et le funding du X-Perp sont payés « in the selected settlement currency » (K1-ops §1), avec un taux
de conversion UNKNOWN : le papier les crédite en USDC à parité, déclaré. Le terme USDC/USD est rapporté à part si un indice
public USDC/USD est servi par l'hôte EEE (point d'accès à identifier au gel, UNKNOWN) ; sinon, il est rapporté confondu avec la
base.

**y_m** = PnL net du portefeuille (deux structures) sur le mois glissant m = [T0 + (m − 1) mois ; T0 + m mois[. Il contient :
- le funding des règlements traversés : −côté × taux × q·c·M_T, le short **recevant** un taux positif ;
- les frais et glissements de toutes les exécutions : entrée au mois 1, sortie au mois 12, rééquilibrages, dénouements forcés
  (§3.9), dénouements de §3.10, coupe-risques ;
- le résultat réalisé de chaque exécution, au carnet du relevé, base et terme USDC/USD compris ;
- la variation de marquage entre deux fins de mois, les deux jambes étant valorisées sur une **référence commune**, le mark M du
  X-Perp (relecture quant m4). Les variations de prix des deux jambes se compensent alors exactement, et la base n'entre dans
  y_m qu'aux exécutions. Σ y_m reste le résultat réel, puisque la sortie du mois 12 est une exécution au carnet.

Rapportés, jamais décisionnels : le marquage mixte (short au mark, spot au mid) et son écart avec le marquage commun, mois par
mois.

On note e_m = y_m − ctrl_m l'**excédent** du mois sur le contrôle SOFR (§5). La réserve ne rapporte rien. Le coût d'opportunité
du capital n'est pas déduit de y_m ; il est porté par C2 et retranché explicitement dans la porte économique (§8.3), qui est un
test distinct : ce n'est pas un double compte (relecture quant B1).

### 8.2 Critères (tous requis pour PASS ; évalués une seule fois, après le mois 12)

| # | Critère |
|---|---|
| **C1** | Σ_{m=1..12} y_m > 0 (USDC, après tous les coûts, structure entière) |
| **C2** | Σ y_m ≥ Ctrl_SOFR = K × Σ_d SOFR_d / 360 (A-S2), K = 500 USDC |
| **C3** | y_m > 0 dans ≥ 10 des 12 mois (test de signe, niveau 0,019) |
| **C4** | Σ y_m − max_m y_m > 0 (le résultat tient sans le meilleur mois) |
| **C5** | Σ y_m^BTC > 0 **et** Σ y_m^ETH > 0 |
| **C6** | Aucune liquidation sous la distance de la paire retenue (min(x_P, x_λ1, x_λ2) par défaut ; min(x_P, x_λ1) sous (L-a, P/λ1) confirmée par écrit) ; aucun appel de marge sous la lecture retenue (E/V < c_ret) ; les deux évalués **à la minute** (§3.7) ; **aucun** coupe-risque (L1, L2, R_a ≤ 0, solde USDC négatif) |
| **C7** | Σ y_m − Σ_a max_{t ∈ T_a} stress_a(t) > 0, où T_a = relevés horaires où le short de la structure a gagne (M(t) < P₀,a(t)), stress_a(t) = Q_a(t)·(S_mid(t) − S_min(t ; t + D_man)) + f_s·Q_a(t)·S_min + 0,42 % × N_a, et S_min = minimum des bid horaires et des plus bas des bougies spot 1 min sur [t ; t + D_man]. Le résultat tient après un dénouement forcé par structure au pire moment de l'année (relecture risque M4) |

### 8.3 Lecture économique (D4 ; GATES §1.1–§1.2)

```
Ctrl_SOFR = K × Σ_d SOFR_d / 360                                     (réalisé, §5)
S_éco     = max( 97,25 ; 5 × K × SOFR_gel × 365/360 ; coût fixe )    (au SOFR 3,89 % : max(97,25 ; 98,60) = 98,60 USD/an)
« poursuivi »  ⇔  Σ y_m − Ctrl_SOFR ≥ S_éco
                  ET ( LB90(Σ e_m) > 0  OU  UB90(Σ e_m) ≥ 2 × S_éco )
```

- **Montant comparé.** GATES §1.1 compte le « coût de la marge gelée (§5) » dans le montant comparé à S_éco, et GATES §5.2 le
  chiffre au SOFR. Pour le track S, les 500 USDC sont immobilisés 12 mois : ce coût est le poste dominant. La porte porte donc sur
  l'excédent Σ y_m − Ctrl_SOFR, pas sur Σ y_m brut (relecture quant B1). Il faut Σ y_m ≥ 98,60 + 19,72 = **118,32 USD/an**, soit
  9,86 USD par mois en moyenne (8,22 USD par mois d'excédent). Le propriétaire peut écrire une dérogation à GATES §1.1 (lecture
  brute) avant le gel ; elle n'est pas proposée par défaut.
- **S_éco.** D4 fixe 5 × 3,89 % × 500 = 97,25 USD/an. GATES §1.2 parle de 5 fois le rendement sans risque du capital au SOFR du
  jour du gel. Exprimé dans la convention de C2 (actual/360 sur 365 jours), ce rendement vaut 19,72 et son quintuple 98,60 : la
  formule retient le maximum, pour que S_éco reste exactement 5 fois le hurdle de C2 et qu'une hausse du SOFR d'ici le gel ne
  revienne pas à abaisser la porte (relectures quant m2, risque m7). Le plancher 97,25 de D4 n'est jamais franchi vers le bas. Le
  propriétaire peut relever S_éco au gel, jamais l'abaisser après une lecture (GATES §1.2).
- LB90 et UB90 : percentiles 10 et 90 de Σ_{m=1..12} e_m*, par bootstrap des 12 mois de e_m (tirage avec remise, 1 000 tirages,
  graine 20261102, GATES §3.1). Le biais d'autocorrélation est déclaré en §3.12.
- Le **coût fixe annuel déclaré** du track S (53 relevés hebdomadaires, supervision de l'enregistreur et du client WS pendant
  12 mois, `verify` quotidien, stockage) est UNKNOWN. S'il dépasse 98,60 USD, il le remplace (max), et il est déclaré au gel.

### 8.4 Verdicts, INCONCLUSIVE, futilité

**PASS** si C1–C7 sont tous satisfaits, avec ses labels (§7.3). **FAIL** sinon.

**INCONCLUSIVE** :
- plus de 5 % des règlements d'un actif manquent ou sont **erronés**. Un règlement est erroné si : (a) OKX publie une correction ;
  (b) le taux réglé sort de [plancher ; plafond] figés ; (c) le taux relevé par `funding-rate` à +5 min (`settFundingRate`) et le
  taux réglé de `funding-rate-history` pour le même `fundingTime` diffèrent (chaînes décimales différentes) (relecture quant m5) ;
- plus de 2 % des heures de la fenêtre (175 h sur 8 760) sans relevé de risque complet, ou un trou continu de plus de 24 h. Un
  relevé de risque est complet si l'heure est couverte par les bougies 1 min de mark des deux X-Perps et de spot des deux paires
  (comblées après coup si le point d'accès les sert) et par au moins un relevé de ticker (relecture risque M3) ;
- plus de 3 relevés hebdomadaires de règle manqués, ou 2 consécutifs, tant qu'aucune source quotidienne n'est validée (item B3) ;
- hausse de `interestRate` ;
- un instrument ou une paire est retiré ;
- l'admission du spot en marge est retirée, ou une décote rend K1 impossible ;
- l'accès du compte change de façon permanente ;
- q_a < 1 à l'entrée.

**FAIL_MECHANISM** : baisse de `interestRate` sur un identifiant figé (§3.10).

Dans tous ces cas, aucun redémarrage n'est permis (A-S4 iv).

**Futilités** : arrêt anticipé autorisé, jamais de PASS anticipé. Le contrôle a lieu à la fin de chaque mois k = 3, 4, …, 11 :
- **(F1)** #{m ≤ k : y_m ≤ 0} ≥ 3 : C3 est devenu impossible, puisque 12 − 3 = 9 < 10. C'est un fait arithmétique, pas une
  statistique. Verdict `FAIL_FUTILITY`.
- **(F1b)** un événement qui fait échouer C6 (liquidation, appel, coupe-risque) : C6 est devenu impossible. Fait arithmétique,
  contrôlé à tout moment et pas seulement en fin de mois. Verdict `FAIL_FUTILITY`.
- **(F2)** Σ y_1..k ≤ 0 **et** #{m ≤ k : y_m > 0} ≤ ⌊k/2⌋. C'est la règle de CS-FUND §7, avancée du mois 6 au mois 3 et
  répétée chaque mois (GATES §10.3). Verdict `FAIL_FUTILITY`.
- **(F3) futilité économique** (relecture quant B2). Soit A_k = Σ_{m ≤ k} e_m. Pour b = 1…1 000 (graine 20261102), on tire avec
  remise 12 − k valeurs parmi {e_1, …, e_k} et on en fait la somme R_b ; q_k est le percentile 90 des R_b. Si A_k + q_k < S_éco,
  le verdict est **`NOT_PURSUED_FUTILITY`**. C'est une statistique sur des mois déjà observés, comme F2, pas une prévision. Ce
  n'est pas un FAIL scientifique, mais il ferme le track. À k = 3, le bootstrap ne repose que sur trois valeurs : il est grossier,
  déclaré. Calcul d'audit : à F = I et à dispersion faible, A_3 + q_3 ≈ 12 × excédent mensuel, qui vaut au plus 31,28 USD dans
  les constructions de §3.6, contre 98,60 : F3 se déclencherait à la fin du mois 3.

La version précédente refusait toute futilité économique (« une borne par F = I serait une prévision »). F3 ne suppose aucun F :
elle n'utilise que les mois observés.

**À l'aveugle.** Avant le verdict, aucune valeur de y_m, de funding ou de prix n'est affichée. Le script de futilité ne sort que
`CONTINUE`, `FAIL_FUTILITY(F1|F1b|F2)` ou `NOT_PURSUED_FUTILITY(F3)` ; les règles de risque (§3.7–§3.11) s'appliquent sans
affichage.

### 8.5 Puissance (GATES §3.2)

C3 est un test de signe 10/12 au niveau 0,019. Sa puissance est P(Bin(12, p) ≥ 10), avec p = P(y_m > 0). Calcul d'audit :

| p = P(y_m > 0) | 0,70 | 0,80 | 0,85 | 0,869 | 0,90 |
|---|---|---|---|---|---|
| Puissance de C3 | 0,25 | 0,56 | 0,74 | 0,80 | 0,89 |

- Sous normalité, p = Φ(μ_m/σ_m) : une puissance de 0,80 demande **μ_m ≥ 1,12 σ_m** (Φ(1,12) = 0,869). Pour comparer, un test de
  moyenne au même α = 0,019 demanderait (2,07 + 0,84)/√12 = 0,84 σ_m. La valeur de 0,61 σ_m citée par la version précédente vient
  de la formule de GATES §3.2 à α = 0,10 : ce n'est pas celle de C3 (relecture quant M2).
- À F = I : μ_m = net annuel/12, donc σ_m ≤ μ_m/1,12 = (I − 0,42 %)/(12 × 1,12) × N total = **0,78 % de N total par mois**, quelle
  que soit la construction : 1,12 USD au défaut, 1,95 USD sous (L-b, λ2) sans K3, 3,80 USD sous L-a sans K3. σ_m, qui comprend la
  base aux exécutions, la variance du funding, les rééquilibrages et les frais, est UNKNOWN. Le marquage commun (§8.1) le réduit
  sans annuler la base aux mois d'exécution.
- **Un FAIL par C3 sous un portage positif est une issue probable, déclarée** (GATES §3.2). C1–C7 étant tous requis, la puissance
  jointe est plus basse encore. L'issue probable au plancher est le FAIL, et non `NOT_INFORMATIVE` : le protocole n'a pas de
  plancher d'observations au-delà de ses 12 mois.

---

## 9. L'enregistreur lecture seule et le calendrier T0

### 9.1 Spécification

- **Processus et code.** Processus et dossier propres, distincts de `fwd-okx-capture`, de `fwd-okx-evaluate` et de
  `research_lp capture` (piste L). Code nouveau, hors pile TradingV3, hors Docker, lancé par le propriétaire. Hash du code au
  lancement.
- **Isolement de `fwd-okx-r1`** (relecture risque m6). Aucun recouvrement de calendrier (R_s ≥ max(G, 2026-11-02), G ≥ S-O8).
  De plus : environnement Python, dossier d'état et nom de service distincts ; aucun arrêt ni redémarrage des services
  `fwd-okx-*` ; lancement par le propriétaire seulement.
- **Données.** Stockage dans `tradingV3-private/research/<horodatage>-track-s-csfund/recorder/` : segments JSONL quotidiens
  append-only, `SHA256SUMS` quotidien, `health.log`. Jamais dans `pivox/tradingV3` (D1 ; GATES §6.3). **Publication** : les
  séries et Σ y_m restent dans le dossier privé ; le dépôt ne reçoit que le protocole et le verdict qualitatif avec ses labels,
  sauf décision contraire du propriétaire (GATES §6.3, relecture risque m5).
- **Hôtes.** REST `https://eea.okx.com` et WS public de l'hôte EEE (forme documentée `wss://wseea.okx.com/ws/v5/public`, desk §6),
  points d'accès **publics** seulement. Aucune clé, aucun point privé, aucun ordre. La source quotidienne des décotes par clé
  Read (item B3), si elle est retenue, est un script séparé, lancé par le propriétaire, hors de l'enregistreur.
- **IP partagée** (relecture risque m5). À partir du 2026-11-02, la capture `research_lp` de la piste L (P3, G2) partage l'IP de
  la machine. Certaines limites REST sont par IP (`instruments` : « IP + instType », desk §1.4) ; l'interaction des limites est
  UNKNOWN. L'enregistreur recule sur un refus de débit et le journalise ; les trous comptent dans les seuils de §8.4.
- **Garde de date.** Le plancher `NOT_BEFORE = max(T0, 2026-11-02T00:00:00Z)` est **écrit dans le code** : tout enregistrement
  de valeur d'horodatage < `NOT_BEFORE`, et tout règlement de `fundingTime < T0`, est **rejeté par le code avant écriture**. Les
  requêtes à `funding-rate-history` portent `before = <T0 en ms>` (« records newer than ») : aucun règlement antérieur n'est même
  servi. Les requêtes de bougies portent des bornes ≥ T0.
- **Valeurs servies avant T0** (relecture risque m4). Pendant le rodage, `ticker`, `mark-price`, `books` et `funding-rate`
  renvoient des valeurs ; le re-figeage des métadonnées (§12.2) lit aussi `funding-rate`. Ces valeurs sont **reçues, non lues** :
  une liste blanche, comme H3-A §0.1, supprime les champs de résultat en mémoire avant toute écriture ou tout affichage. Le test
  hors réseau couvre ce filtre pour **chaque** point d'accès, pas seulement `funding-rate-history`.
- **Relevé horaire** = HH:05 UTC (ce qui inclut 00:05, l'heure d'entrée et de sortie, et les minutes qui suivent les règlements).

| Point d'accès ou canal | Cadence | Usage |
|---|---|---|
| `GET /api/v5/public/funding-rate-history?instId=…` (2 X-Perps) | quotidien, 00:30 UTC | Taux réglés (y_m) ; comblement des trous, `fundingTime ≥ T0` seulement |
| `GET /api/v5/public/funding-rate?instId=…` | chaque règlement + 5 min | Champs de règle : `interestRate`, formule, plafond, plancher, `fundingTime`, `nextFundingTime` ; après T0, `settFundingRate` pour le contrôle « erroné » |
| `GET /api/v5/public/mark-price?instType=FUTURES&instId=…` | horaire | VD modélisée, L1, déclencheur (i), marquage commun |
| `GET /api/v5/market/ticker?instId=…` (2 X-Perps + 2 spot) | horaire | Bid/ask des exécutions et de VD_exe ; mid spot |
| `GET /api/v5/market/books?instId=…&sz=5` (2 + 2) | horaire, dont 00:05 UTC | Glissement mesuré (CS-FUND §5), découplé de l'évaluateur (relecture risque m3) |
| `GET /api/v5/market/history-mark-price-candles?instId=…&bar=1m` (2 X-Perps) | quotidien, jour précédent, bornes ≥ T0 (100 par page, 20 req/2 s, desk §1.4) | Appels, liquidations et déclencheur (ii) à la minute ; pire mark de l'ADL simulé. Couverture des X-Perps à vérifier (l'exemple de la docs est un SWAP) |
| `GET /api/v5/market/history-candles?instId=…&bar=1m` (2 paires spot) | quotidien, jour précédent, bornes ≥ T0 | Plus bas spot de la minute ; S_min de C7 |
| `GET /api/v5/public/instruments?instType=FUTURES` et `SPOT` | quotidien (`state`) et hebdomadaire (tous champs §2) | Suspension et délisting ; événements de règle |
| `GET /api/v5/public/position-tiers?instType=FUTURES&instFamily=…` (valeur exacte et `tdMode` vérifiés au gel) | hebdomadaire | mmr, imr |
| `GET /api/v5/public/discount-rate-interest-free-quota` (BTC, ETH, USDC) | quotidien si validé comme source EEE (item B3) ; sinon hebdomadaire | Décotes (référence globale tant que non validé) |
| Indice public USDC/USD, s'il est servi par l'hôte EEE (UNKNOWN) | horaire | Terme USDC/USD (§8.1) |
| Canal WS `adl-warning` (`instType` FUTURES, familles des deux identifiants), hôte WS public EEE | continu ; poussé seulement en état warning ou adl | Simulation ADL (§3.9) |
| `GET /api/v5/public/insurance-fund?instType=FUTURES&instFamily=…` | quotidien | Contexte descriptif (types `adl` dépréciés) |

Quelques dizaines de requêtes par heure au total, plus une connexion WS. Les limites REST de chaque point d'accès sur l'hôte EEE
sont vérifiées au gel (§12.1).

### 9.2 Évaluateur

Rejeu déterministe du grand-livre papier à partir des seuls fichiers enregistrés. Hash au gel. Tests hors réseau :
- rejet < T0 et liste blanche avant T0, pour chaque point d'accès ;
- quantification (§3.3) et contraintes K1–K3 ;
- paires de lectures et trois distances de liquidation (§3.4, §3.5) ;
- déclencheurs, évaluation à la minute, latence D_man et séquence des jambes (§3.7) ;
- coupe-risque, confirmation et grand-livre sans L1 (§3.8) ;
- ADL simulé avec condition de côté, et C7 (§3.9, §8.2) ;
- suspensions, retrait de marge, changement de I (§3.10) ;
- sensibilité « spot consommé d'abord » (§3.11) ;
- marquage commun et unité USDC (§8.1) ;
- futilités F1, F1b, F2, F3, y compris le bootstrap à graine fixe (§8.4).

### 9.3 Événements de règle, chaque semaine, hachés

Relevé le lundi 08:00 UTC ± 4 h. Chaque événement est enregistré avec : date, champ, ancienne valeur, nouvelle valeur, sha256 de
la réponse brute ou du texte de la page.

- **Automatique** :
  - champs de règle du funding ;
  - champs `instruments` (§2) ;
  - paliers ;
  - décotes (quotidien si la source B3 est validée) ;
  - « Last Updated » + sha256 du texte des pages : perps-funding-fee-mechanism, whats-selected-margin-eea,
    x-perps-contract-specifications, CGU OEM, faq-on-usd-margined-futures-contracts, iv-introduction-to-auto-deleveraging-adl,
    trading-fee-rules-faq-eea, okx-api-agreement, system-liquidation-mechanism-eea, tiered-maintenance-margin-ratio-rules-eea.
- **Manuel** (le propriétaire, capture hachée, sans reporter de valeur de marché) :
  - liste de marge X-Perps EEE et décotes BTC, ETH, USDC, tant que la source B3 n'est pas validée ;
  - barème du compte ;
  - devise USD-M ;
  - Simple Earn (§5).

Un relevé manqué est noté MISSING ; le seuil de §3.13 s'applique aux relevés de règle. Un événement déclenche les règles §3.7
(iii) et §3.10. Il n'autorise **aucune** modification du texte.

### 9.4 Rodage et huit premières semaines

- **Rodage avant T0.** Au moins 7 jours consécutifs sans trou, démarrés ≥ 2026-11-02 00:00 UTC. Pendant le rodage, seules les
  lignes de santé sont écrites (statut HTTP, comptes, latences, empreinte du schéma), **aucune valeur** : la liste blanche de
  §9.1 s'applique.
- **Huit premières semaines (T0 → T0 + 56 j).** Elles font partie de la fenêtre : mois 1 et 2, ni lues à part ni consommées à
  part (GATES §10.3). Un défaut de l'**enregistreur** (pas de l'évaluateur ni des paramètres) peut y être corrigé par un
  amendement écrit et daté, qui ne lit aucune valeur ; le nouveau hash est consigné et les trous comptent dans les seuils de
  §8.4.

### 9.5 Calendrier T0

```
G      = horodatage du gel (≥ verdict fwd-okx-r1, S-O8, après le 2026-10-31)
R_s    = début du rodage = max(G, 2026-11-02T00:00Z)            (le gel précède tout lancement, GATES §10.3 iii)
T0     = premier 00:00 UTC ≥ max(G + 7 j, R_s + 7 j), après 7 jours de rodage sans trou
```

Cas de référence G ≤ 2026-11-02 :

| Étape | Date |
|---|---|
| Rodage | 2026-11-02 (lundi) → 2026-11-09 |
| **T0** | **2026-11-09 00:00 UTC** (lundi) ; entrée à 00:05 |
| Fin des mois 1, 2, 3 | 2026-12-09 ; 2027-01-09 ; 2027-02-09 (première futilité, dont F3) |
| Dernière futilité (mois 11) | 2027-10-09 |
| Fin de la fenêtre | **2027-11-09 00:05 UTC** (365 jours) |
| Relevés hebdomadaires | 53 lundis (2026-11-09 → 2027-11-08), dont 41 après la fin de K8 |
| Verdict | au plus tard le 2027-11-16 |
| Fin de validité d'un PASS | ≈ 2028-05-16 |

Un gel plus tardif décale tout d'autant. La fenêtre déborde le 2027-03-31 de D1 et de GATES §1.3 : voir P-S1 et P-S2.

---

## 10. Check-list du propriétaire au gel (lecture seule, aucun ordre)

Un « GET » se fait avec une clé **Read** seule, créée par le propriétaire, par un script écrit à l'avance et haché ; aucun agent
ne détient de clé. Aucune valeur de marché n'est recopiée. **Ordre imposé** (relecture quant M5) : l'étape A est écrite et
hachée avant les étapes B et C ; l'étape C, la seule qui touche un identifiant figé, vient en dernier. Le bloc de paramètres
n'est complété par les champs de règle (h, h_u, mmr, f_s, f_x, `tdMode`) qu'après C, puis haché.

**Étape A — décisions, avant tout écran ou appel qui touche un identifiant figé (écrites, datées, hachées)**

| # | Item | Ce qu'il change |
|---|---|---|
| A1 | **Décisions** : A-S1 (i) ou (ii) ; P-S1, P-S2, P-S3 (α ou β) ; K8 gelé ou non ; S_éco (formule §8.3, dérogation éventuelle à GATES §1.1) et coût fixe ; F3 maintenu, ou **classement sans suite** (S-O5) ; option de queue (a) ou (b) de §3.8 avec r_stress ; K3 : h_stress, x_det, ou levée sous les conditions de §3.5 ; D_man ; acceptation écrite de la magnitude (§11.4), dont « au cas de référence, le track S n'est pas poursuivable » ; acceptation du risque de contrepartie sur 500 USDC hors du cadre 0,5 % / 1 % | Conditions du gel (§12). Sans A1 complet, pas de gel |
| A2 | **Devise de compte** (S-O7) | Active ou non les lignes Livret A et change (§5) |
| A3 | **Conseil fiscal** engagé, ou risque accepté (S-O6) | Qualifie la ligne asymétrique (§6.3) |
| A4 | **Code** : auteur, relecteur distinct, hébergement (`tradingV3-private/research/<horodatage>-track-s-csfund/code/`), procédure de hash | §12.4 ; sans relecteur nommé, pas de gel |
| A5 | **Supervision** : 53 relevés hebdomadaires (41 après la fin de K8), `verify` quotidien, alertes de l'enregistreur et du client WS ; règle du relevé manqué (§3.13) | Coût fixe (§8.3) ; seuils `INCONCLUSIVE` (§8.4) |

**Étape B — champs de règle et documents (aucun écran d'identifiant figé)**

| # | Item | Comment le lire | Ce qu'il change |
|---|---|---|---|
| B1 | **Décotes** BTC, ETH et USDC dans les réglages de marge X-Perps EEE, au palier de la quantité prévue | Interface, page Selected Margin ; capture hachée | Fixe h_BTC, h_ETH, h_u, donc K1, K2, les distances et N (§3.4–§3.6). Non affichées : UNKNOWN, **pas de gel** (S-O1 incomplet) |
| B2 | **Liste de marge** : BTC, ETH **et USDC** y figurent | Interface | BTC ou ETH absent : (c) n'existe pas pour cet actif, track S classé sans suite (GATES §10.2). USDC absent : la réserve ne compte pas comme marge, la construction §3 est à refaire avant gel |
| B3 | **Source quotidienne automatisable** des décotes et de la liste EEE (relecture risque B2, M8) | Comparer, champ par champ, les décotes servies par le point d'accès public `discount-rate-interest-free-quota` de l'hôte EEE avec B1 ; si elles diffèrent, chercher dans la docs un champ de compte lisible par clé Read | Égales : source publique quotidienne validée. Sinon, script Read lancé par le propriétaire, sur décision. Sans source : détection hebdomadaire, K3 au défaut (x_det = 50 %) |
| B4 | **posMode**, `acctLv` et emprunt automatique, `perm` | `GET /api/v5/account/config` | `acctLv` : si l'emprunt automatique est actif, un solde USDC négatif devient un prêt non modélisé, d'où la séquence de §3.7 et la règle R_a ≤ 0 (§3.11). `posMode` : sans effet sur le papier ; pour une proposition démo, adaptation si le compte est en mode net (`H1-XPERP-ACCOUNT-CHECK.md`) |
| B5 | **Devise USD-M = USDC** (S-O2) | Sous-compte → réglages ; modifiable seulement sans ordre ni position (desk §5.1) | La comptabilité papier est en USDC. Si USDC n'est pas sélectionnable : funding et PnL en équité USD, écart USD/USDC UNKNOWN, rapporté |
| B6 | **Barème** « Spot & derivatives » Regular (S-O4) | Page des frais ; `GET /api/v5/account/trade-fee` | Fixe f_s : sur-achat 0,10 % ou 0,20 %, aller-retour 0,42 % (PROXY) ou 0,62 %, seuils §11 |
| B7 | **One Way Convert** USDT → USDC | Au gel : existence du chemin seulement (bouton, paire), **sans lire le taux**. À T0 : aperçu pour 500 USDT, fermé sans confirmer (convention K8 A2) | Fixe c_conv (0,10 % pessimiste par défaut), rapporté à part |
| B8 | **Paliers effectifs**, `tdMode` de Selected Margin, valeur exacte d'`instFamily` | `GET /api/v5/public/position-tiers` (public) et docs ; comparer `BTC-USD_UM_XPERP` et `BTC-USD_UM` (desk §5.2 point 4) | Fixe mmr, donc x_P ; garantit que l'enregistreur relève les paliers du bon produit |
| B9 | **Réponses écrites d'OEM** (support, hachées) : (1) clause 30 %, avec la conséquence de L-b à 10× (0,40·V, soit 4 fois l'IMR), ce qui rend la question décidable ; (2) clause 100 % : λ1 ou λ2 ; (3) ordre de consommation des actifs du pool Selected Margin et place de l'USDC dans la cascade §1.9.5 ; (4) préavis d'un changement de décote ou d'un retrait de la liste ; (5) un short sur l'identifiant est-il porté par le spot du même actif (si C1 n'est pas faisable) ; (6) calcul de l'IMR d'une position couverte en Selected Margin | Question écrite au support ; réponses hachées | (1)+(2) confirment la paire (L-a, P/λ1) : §3.6, lignes L-a. (3) lève ou garde la sensibilité « spot consommé d'abord ». (4), avec B3, admet x_det = 10 % ou lève K3. Sans réponse : paire (L-b, λ2), K3 au défaut |
| B10 | **KID PRIIPs** X-Perps dans l'espace documents | Interface | Rapporté (risque réglementaire, §3.11) |
| B11 | **Passeport France d'OEM** sur le registre ESMA ou AMF (porte M1 ; rapport §6.2 décision 9 ; K1-ops §8 point 8) | Registre public | Sans passeport, aucune exécution n'est possible : la proposition A-S4 n'a pas d'objet, et le track S est classé sans suite sauf décision écrite |

**Étape C — identifiants figés (après l'étape A hachée)**

| # | Item | Comment le lire | Ce qu'il change |
|---|---|---|---|
| C1 | **Short porté par le spot** : en Selected Margin, la taille disponible en vente sur `BTC-USD_UM_XPERP-310404` augmente quand BTC est sélectionné ; même chose pour ETH | Script Read sur `GET /api/v5/account/max-avail-size` (avec le `tdMode` de B8) et `GET /api/v5/account/balance`, qui ne sort qu'un booléen, sans taille ni prix ; ou capture recadrée par un tiers, sans en-tête ni carnet. **Exige un solde BTC/ETH non nul** : sans solde, l'item ne peut se faire sans achat, interdit, et passe par B9 (5) | Confirme D2 au niveau de l'identifiant. Non : track S sans suite. Si l'onglet d'ordre est ouvert : E8 |
| C2 | **Selected Margin disponible sur les deux identifiants, levier 1× réglable** dans ce mode | Lecture du levier par point d'accès privé en lecture (nom et disponibilité EEE vérifiés dans la docs au gel), sortie booléenne ; ou capture recadrée par un tiers | Sans 1× : l'IMR et K1 changent, et GATES §10.5 interdit > 1× : sans suite, ou décision écrite. Si l'onglet est ouvert : E8 |
| C3 | **Paires spot** BTC-USDC et ETH-USDC négociables par le compte ; transfert USDC Funding → Trading (S-O3) | `GET /api/v5/account/instruments?instType=SPOT` (métadonnées, desk §5.2 point 4 pour FUTURES), sortie booléenne ; écran de transfert sans valider | Non : la structure n'existe pas, sans suite. Panneau spot ouvert : E8 |

---

## 11. La magnitude honnête

F = I est **un cas de référence, pas un plafond ni une prévision**. Au-dessus de la zone morte, F dépasse I jusqu'au plafond de
l'instrument ; F = I en continu n'a jamais été observé (rapport §1 l.3). Les niveaux « publiés » sont de la littérature
(Binance, 2020–2025 ; rapport §5a, K1-eco §2.2), pas des mesures OKX. Net annuel = N_total × (F̄ − 0,42 %), aller-retour PROXY
compté une fois, sans rééquilibrage. N est pris avant arrondi (borne haute). Avec un carnet EEE prouvé (0,38 %), chaque net
gagne 0,04 % × N total (au plus 0,19 USD/an).

### 11.1 PnL net annuel selon la construction et le funding (USD/an)

| Construction | N total | Brut à F = I | Net à F = I | Net à 8 % (Borri) | Net à 5,06 % (He) | Net à 0 | Net à −2 % | Après impôt (× 0,686) à F = I | Surplus sur le SOFR à F = I (avant / après impôt) |
|---|---|---|---|---|---|---|---|---|---|
| **Défaut : (L-b, λ2), K3 50 %** | 142,89 | 15,65 | **15,05** | 10,83 | 6,63 | −0,60 | −3,46 | 10,32 | **−4,67 / −3,21** |
| (L-b, λ2), K3 10 % | 194,91 | 21,34 | 20,52 | 14,77 | 9,04 | −0,82 | −4,72 | 14,08 | +0,80 / +0,55 |
| (L-b, λ2), sans K3, h 0,98 | 249,14 | 27,28 | 26,23 | 18,88 | 11,56 | −1,05 | −6,03 | 18,00 | +6,51 / +4,47 |
| (L-b, λ2), sans K3, h 0,80 | 219,20 | 24,00 | 23,08 | 16,62 | 10,17 | −0,92 | −5,30 | 15,83 | +3,36 / +2,31 |
| (L-a, P/λ1), K3 50 % | 252,98 | 27,70 | 26,64 | 19,18 | 11,74 | −1,06 | −6,12 | 18,27 | +6,92 / +4,75 |
| (L-a, P/λ1), K3 10 % | 345,17 | 37,80 | 36,35 | 26,16 | 16,02 | −1,45 | −8,35 | 24,93 | +16,63 / +11,41 |
| (L-a, P/λ1), sans K3, h 0,98 | 484,34 | 53,04 | 51,00 | 36,71 | 22,47 | −2,03 | −11,72 | 34,99 | +31,28 / +21,46 |
| (L-a, P/λ1), sans K3, h 0,80 | 411,50 | 45,06 | 43,33 | 31,19 | 19,09 | −1,73 | −9,96 | 29,73 | +23,61 / +16,20 |
| *K1(a), sous L-a (m = 1,0050)* | 246,65 | 27,01 | 25,97 | 18,70 | 11,44 | −1,04 | −5,97 | 17,82 | +6,25 / +4,29 |
| *K1(a), sous L-b (m = 2,4623)* | 142,89 | 15,65 | 15,05 | 10,83 | 6,63 | −0,60 | −3,46 | 10,32 | −4,67 / −3,21 |
| *Stress, option (a) de §3.8 (N_a = 25)* | 50,00 | 5,47 | 5,27 | 3,79 | 2,32 | −0,21 | −1,21 | 3,61 | −14,46 / −9,92 |
| *Rapport structurel, K1(c) (réserve ≈ 100 non dérivée), recalculé à 0,42 %* | 393,78 | 43,12 | 41,47 | 29,85 | 18,27 | −1,65 | −9,53 | 28,45 | +21,74 / +14,92 |
| *Rapport structurel, K1(a), recalculé à 0,42 %* | 232,52 | 25,46 | 24,48 | 17,63 | 10,79 | −0,98 | −5,63 | 16,80 | +4,76 / +3,27 |

Surplus = net − Ctrl_SOFR (19,72) avant impôt ; net × 0,686 − 13,53 après impôt (relecture quant B1 iv). Le surplus est
l'avantage réel ; le net seul n'en est pas un.

### 11.2 Contrôles et porte économique

| Ligne | USD/an | Après impôt (× 0,686) |
|---|---|---|
| SOFR actual/360, 500 USDC, 3,89 % constant (Ctrl_SOFR) | 19,72 | 13,53 |
| Simple Earn USDC (≈ 3,5 % indicatif, borne supérieure) | 17,5 | 12,01 |
| Livret A (si EUR) | 7,56 EUR | 7,56 EUR (exonéré) |
| **S_éco** = max(97,25 ; 5 × 19,72 ; coût fixe) | **98,60** (plancher D4 : 97,25) | — (évaluée avant impôt) |
| **Montant à atteindre pour « poursuivi »** : Σ y_m ≥ S_éco + Ctrl_SOFR | **118,32** (9,86 par mois) | — |

### 11.3 Funding annuel moyen requis : F̄* = ligne / N_total + 0,42 %

| Construction | contre SOFR (C2) | contre Simple Earn | contre la porte (S_éco + Ctrl_SOFR = 118,32) |
|---|---|---|---|
| **Défaut : (L-b, λ2), K3 50 %** | **14,22 %/an (1,30 × I)** | 12,67 % (1,16 × I) | **83,23 %/an (7,60 × I)** |
| (L-b, λ2), K3 10 % | 10,54 % (0,96 × I) | 9,40 % (0,86 × I) | 61,12 % (5,58 × I) |
| (L-b, λ2), sans K3, h 0,98 | 8,34 % (0,76 × I) | 7,44 % (0,68 × I) | 47,91 % (4,38 × I) |
| (L-b, λ2), sans K3, h 0,80 | 9,42 % (0,86 × I) | 8,40 % (0,77 × I) | 54,40 % (4,97 × I) |
| (L-a, P/λ1), K3 50 % | 8,22 % (0,75 × I) | 7,34 % (0,67 × I) | 47,19 % (4,31 × I) |
| (L-a, P/λ1), K3 10 % | 6,13 % (0,56 × I) | 5,49 % (0,50 × I) | 34,70 % (3,17 × I) |
| (L-a, P/λ1), sans K3, h 0,98 | 4,49 % (0,41 × I) | 4,03 % (0,37 × I) | 24,85 % (2,27 × I) |
| (L-a, P/λ1), sans K3, h 0,80 | 5,21 % (0,48 × I) | 4,67 % (0,43 × I) | 29,17 % (2,66 × I) |
| *K1(a), sous L-a* | 8,42 % (0,77 × I) | 7,52 % (0,69 × I) | 48,39 % (4,42 × I) |

Avec le chiffre littéral de D4 (97,25) au lieu de 98,60, la colonne de droite baisse d'au plus 0,95 point (défaut : 82,28 %).

### 11.4 Lecture

1. **À lecture égale**, (c) porte 1,74 à 1,96 fois le notionnel de (a) tant que la décote tient (§3.6). Le seuil de 3,9 %/an
   contre Simple Earn du rapport structurel §2.1 (K1-eco §10) n'existe que sous la paire L-a sans K3 (4,03 % ici, PROXY compris),
   c'est-à-dire avec deux réponses écrites d'OEM et une décote réputée stable. Sous les défauts prudents, K3 ramène (c) à (a).
2. **Au cas de référence F = I, le track S n'est poursuivable sous aucune lecture.** L'excédent sur le SOFR va de −4,67 à +31,28
   USD/an, contre une porte de 98,60. « Poursuivi » demande un funding moyen de 2,27 à 7,60 fois I pendant douze mois. La
   littérature n'a vu de tels niveaux que par époques : CMU 39,0 → 19,6 → 14,7 → 7,6 %/an ; Borri ≈ 8 %/an sur 2020–2025,
   négatif en 2025.
3. **Au défaut, même le critère scientifique C2 échoue à F = I** (15,05 contre 19,72) : il faut F̄ ≥ 1,30 × I.
4. **Conséquence.** À F ≈ I, F3 terminerait la mesure à la fin du mois 3 (`NOT_PURSUED_FUTILITY`) ; et un « PASS, non poursuivi »
   n'autorise rien. Le classement sans suite (S-O5) est donc l'issue cohérente de ce texte. Un gel n'a de sens que si le
   propriétaire accepte par écrit (item A1) que la mesure ne sert qu'à connaître le signe du portage sur les X-Perps, et
   qu'elle n'ouvrira une proposition de démo que sous un funding de plusieurs fois I.
5. La réserve, le spot et les deux créneaux de risque restent bloqués jusqu'à 12 mois. Un point de change EUR/USD (4,45 EUR) est
   du même ordre que les surplus sur la trésorerie.

---

## 12. Procédure de gel

### 12.1 Prérequis (tous écrits, datés, hachés dans `tradingV3-private`)

- **S-O1…S-O8** (GATES §10.2). S-O1 est complété par B1, B2 et C1–C2 du §10 ; S-O8 est le verdict `fwd-okx-r1` rendu.
- **P-S1 — licence §9.4 de l'enregistreur.** D1 couvre « au plus 40 X-Perps crypto et 2 swaps globaux » du 2026-11-02 au plus
  tard au 2027-03-31, et précise que « tout allongement, instrument, canal ou hôte ajouté est une nouvelle décision ». Le track S
  demande, en liste complète (relecture risque m5) :
  - instruments : les 2 X-Perps figés et les **2 paires spot** BTC-USDC et ETH-USDC ;
  - hôtes : REST `eea.okx.com` et WS public de l'hôte EEE ;
  - points d'accès REST : `funding-rate-history`, `funding-rate`, `mark-price`, `ticker`, `books` (sz = 5),
    `history-mark-price-candles` et `history-candles` (1 min, après T0), `instruments`, `position-tiers`,
    `discount-rate-interest-free-quota`, `insurance-fund`, et l'indice USDC/USD s'il est servi ;
  - canal WS : `adl-warning` ;
  - période : du 2026-11-02 à T0 + 12 mois + 7 jours (≈ 2027-11-16) ;
  - stockage privé ; IP partagée avec `research_lp` déclarée ; publication privée par défaut.
  Une décision du propriétaire est donc nécessaire.
- **P-S2 — durée.** GATES §1.3 borne le programme à « fin du scoring […] ≤ 2027-03-31 (sauf décision écrite contraire du
  propriétaire avant cette date) ». Il faut une décision écrite : le track S est hors de cette borne, ou la borne est allongée
  pour lui.
- **P-S3 — G5 Gate 1** : option (α), exclusion recommandée, ou (β), amendement de §0.4 (§4.3). Décision écrite avant l'un ou
  l'autre gel.
- **Vérifications desk** (documentation seulement, aucun compte ; relecture risque M8) :
  - canal `adl-warning` sur l'hôte WS public EEE : caractère public, couverture des X-Perps, champs après le changelog du
    2026-06-09 ;
  - limites REST de chaque point d'accès sur l'hôte EEE ;
  - `tdMode` correspondant à Selected Margin, pour `position-tiers` et `max-avail-size` ;
  - valeur exacte d'`instFamily` (`BTC-USD_UM_XPERP` ou `BTC-USD_UM`) ;
  - couverture des X-Perps par `history-mark-price-candles`, et des paires spot par `history-candles` ;
  - existence d'un indice public USDC/USD sur l'hôte EEE ;
  - relecture de `system-liquidation-mechanism-eea` et de `tiered-maintenance-margin-ratio-rules-eea`, pour la définition du
    « margin ratio » en Selected Margin (relecture quant M1).
- **Réponses d'OEM** (item B9), ou défauts prudents écrits : paire (L-b, λ2), K3 au défaut.
- **Source quotidienne des décotes** (item B3) : validée, ou détection hebdomadaire déclarée.
- **Passeport France d'OEM** (item B11).
- **Queues** : option (a) ou (b) de §3.8, écrite, avec r_stress et l'acceptation du risque de contrepartie.
- **F3 maintenu, ou classement sans suite** (S-O5).
- **Choix A-S1** : (i), ou (ii) avec consentement écrit distinct (§4.2).
- **K8** gelé ou non : source de la ligne Simple Earn jusqu'au 2027-01-31 (§5).
- **S_éco** confirmé (formule §8.3, 98,60 au SOFR de 3,89 %, ou plus) et coût fixe déclaré.
- **Supervision et code** : items A4 et A5.
- **Devise de compte ; convention fiscale 0,686 ; acceptation de la magnitude** (§11.4).

### 12.2 Re-figeage des métadonnées (≥ 2026-11-01, hors quarantaine)

- Nouvel instantané `public/instruments` FUTURES et SPOT, des paliers, des décotes et des champs de règle du funding (champs de
  résultat supprimés avant écriture, comme H3-A §0.1, par la liste blanche de §9.1), avec sha256.
- **Comparaison avec les instantanés du 2026-10-16** avant le gel. Tout écart sur `ctVal`, `minSz`, `lotSz`, `tickSz`, `state`,
  `expTime`, `instFamily`, mmr ou `interestRate` est reporté en §2 avant le gel.
- Re-figer aussi la page x-perps-contract-specifications (màj 2026-10-19 en quarantaine ; rapport §6.2 décision 10).

### 12.3 Bloc de paramètres (rempli au gel, puis immuable)

h_BTC, h_ETH, h_u (EEE) ; mmr, `tdMode` et `instFamily` exacts ; f_s, f_x (barème) ; g, g_s et indicateur PROXY ; paire de
lectures ((L-b, λ2), ou (L-a, P/λ1) avec la référence des réponses écrites) ; h_stress et x_det, ou levée de K3 avec ses pièces ;
L = 1 ; B = 250 ; R_risk = 2,50 ; x_cible = 0,50 ; δ_m = 0,05 ; D_man = 8 h ; confirmation de L1 sur deux relevés ; option de
queue et r_stress ; seuils de trous ; source des décotes (quotidienne ou hebdomadaire) ; ρ*_a et N_max,a recalculés (§3.3) ;
T0 ; S_éco et coût fixe ; facteur fiscal ; devise de compte ; unité USDC et référence de marquage ; option A-S1 ; option P-S3.

### 12.4 Code

Enregistreur (dont le client WS) et évaluateur écrits par l'auteur désigné, relus par un relecteur distinct, hébergés dans le
dossier privé désigné à l'item A4, tests hors réseau verts (§9.2), sha256 de chaque fichier.

### 12.5 Acte de gel

1. Remplacer `DRAFT_NOT_FROZEN` par `FROZEN <horodatage UTC>` dans ce fichier.
2. Écrire `LP-S-CSFUND-K1C-AMENDMENT.sha256`, avec : sha256 de ce fichier ; de CS-FUND (`a1299e0f…3929`, à recalculer et à
   comparer) ; des instantanés re-figés ; des codes ; des pièces de la check-list.
3. Publier **avant le lancement du rodage** : sans gel, aucune capture de funding n'est lancée pour le track S
   (GATES §10.3 iii). Le commit est fait par le propriétaire, pas par un agent de recherche (GATES §9.6).
4. Créer les lignes de registre `okx-xperp-funding-trackS` et `okx-spot-trackS` (statut `prospective`), et
   `okx-xperp-funding-history-trackS` sous l'option (ii) seulement (GATES §6.2).
5. Après le gel, toute modification passe par un amendement écrit et daté, **antérieur à toute lecture** de valeur du track S
   (CS-FUND §10). Les règles de §8.4 « à l'aveugle » s'appliquent jusqu'au verdict.

---

## 13. Sources et empreintes (sha256 calculés le 2026-10-21)

| Fichier (lecture seule) | sha256 |
|---|---|
| `tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-A/notes/PROTOCOL-H3A-GATE1-DRAFT.txt` (CS-FUND) | `a1299e0f8e15b6003a3a57bb750ad6b50b9365b9cfd27c12e8f44c8ec4fa3929` |
| `docs/handbook/research/LP-PROGRAMME-GATES.md` (worktree `lp-programme`) | `8b88394728284ba106564047dd76ab3f7bb64a58bd7bc7499ffb8588e393d37c` |
| `docs/handbook/research/LP-OWNER-DECISIONS-2026-10-21.md` | `5c8e70c39df211672fcee43ceac82eb9f7390337fdf7131b80c3ad019664188f` |
| `docs/handbook/reports/research-positive-pnl-structural-2026-10-16.md` | `198b2be1c971421cc59b01970e2c9515ab325952a37f223c5b55df12b666badf` |
| `tradingV3-private/research/20261016T2145Z-positive-pnl-structural/skeptics/K1-eco.md` | `060cf0ddb8871ed72dcd252fba6afa94eaa57b997e3b0113537d8ff18562eb9d` |
| `tradingV3-private/research/20261016T2145Z-positive-pnl-structural/skeptics/K1-ops.md` | `f3c5ddbd8ce09bee4c9acdbfeee5e1208705bfb5d8673871979de1520b211093` |
| `docs/handbook/research/LP-DATA-DESK-2026-10-20.md` (§0, §1.3, §1.4, §4.1, §5, §6) | `fe4e7e5b90b0d328b377428d99d36521e25df852d09327cdb26a88c06a1b5bb6` |
| `docs/handbook/research/H1-XPERP-ACCOUNT-CHECK.md` | `7555c582165feef90158c20f6979b410e88c3b253e6f0663429c334a08656f4a` |
| `docs/handbook/research/LP-PROGRAMME-PLAN-2026-10-20.md` (§2.1, lecture de contexte) | `1f2e1152cd20edd2649e1c8e0c0a3732e6859b89f33ae764c565d810f43af6e6` |
| `…/20261016T2145Z-positive-pnl-structural/eea-instruments-FUTURES-20261016.json` | `f8a7f046efb0ff4b64952edf76d604ecfb79d5fc04fa60a6d7e08721084be316` |
| `…/a2-spot-yield-crossvenue/raw/eea-instruments-SPOT.json` | `e360dd3b1720d6d3f9fb822bc4716dafced1242682f9093ec2b48ac4a511411d` |
| `.worktrees/research-h3-dossiers/docs/handbook/research/H3-CS-FUND-DRAFT-PREREGISTRATION.md` (titres seulement) | `b1eac1c787e1bd2c7238d7107e9e3fbfe7d74e87c57b1ee7f520623fbb4c0f86` |
| `tradingV3-private/research/20261020T2321Z-g5-periods/track-s/review-quant.md` (relecture) | `2a5eaeb523cef7c0fc9d3c64a608e183dad21ecc95d8004beaea243b1ac71262` |
| `tradingV3-private/research/20261020T2321Z-g5-periods/track-s/review-risk.md` (relecture) | `142ec8c8f7181fb46648bb01034524a22c947ccfeb5d8d80d084abc2fe39459b` |
| Version relue de ce fichier (avant révision) | `4c795b02b4288252a7e8cc8f3bd0c2bfe341069302172b0329211470aa77322e` |

Notes de travail (calculs, choix, inconnues) :
`tradingV3-private/research/20261020T2321Z-g5-periods/track-s/NOTES-draft.md` (sha256 `e302b151…1a1b`). Les calculs de la
révision ont été faits par un script jetable hors dépôts, sans réseau ; toutes leurs formules sont affichées dans le corps.

---

## 14. Traitement de la revue (2026-10-21)

Relectures : `review-quant.md` (2 bloquants, 6 majeurs, 9 mineurs) et `review-risk.md` (2 bloquants, 8 majeurs, 8 mineurs),
dossier `tradingV3-private/research/20261020T2321Z-g5-periods/track-s/`. Convention : **appliqué** = texte modifié ;
« **Réponse à la revue** » = refus ou traitement différent, motivé. Les écarts de chiffres avec les relectures viennent de deux
corrections appliquées ensemble : aller-retour PROXY à 0,42 % (quant m1, risque m1) et S_éco = 98,60 (quant m2).

| Constat | Traitement |
|---|---|
| quant **B1** — la porte S_éco compare Σ y_m brut, alors que GATES §1.1 compte le coût de la marge gelée ; LB90 sur le brut ; un PASS non poursuivi ouvrait une démo | **Appliqué** : §8.3 (« poursuivi » ⇔ Σ y_m − Ctrl_SOFR ≥ S_éco, LB90 et UB90 sur Σ e_m) ; §8.1 (pas de double compte : deux tests distincts) ; §7.2 (seul un PASS poursuivi ouvre une proposition de démo ; un PASS non poursuivi ferme le track) ; §11.1 (colonne surplus : +6,51 / +4,47 sous L-b sans K3 à F = I, au lieu de +6,62 / +4,54, à cause du PROXY) ; §11.3 (47,91 % et 24,85 % au lieu de 47,32 % et 24,53 %) ; Réponse lignes 4–6. Aucune dérogation par défaut ; le propriétaire peut en écrire une avant le gel (item A1) |
| quant **B2** — l'issue favorable la plus plausible n'autorise rien ; pas de futilité sur la porte qui lie | **Appliqué** : F3 `NOT_PURSUED_FUTILITY` (§8.4), avec q_k défini comme le percentile 90 de la somme de 12 − k tirages, ce qui équivaut à (12 − k) × le percentile 90 de leur moyenne ; option de classement sans suite S-O5 écrite (item A1, §11.4) ; Réponse ligne 4 : « au cas de référence, le track S n'est poursuivable sous aucune lecture » |
| quant **M1** — L-b réfutée par l'absurde ; (c) sous L-b comparée à (a) sous L-a | **Appliqué** : réduction à l'absurde en §3.5 ; L-b requalifiée « hypothèse prudente non vérifiée, peu plausible au-delà de 1× » ; « fait nouveau » retiré ; comparaison (a)/(c) sous une même lecture (§3.6 : 1,74 et 1,96) ; lignes K1(a) sous L-a et L-b en §11.1 ; §11.4 point 1 ; item B9 (1), avec la conséquence à 10× ; pages `system-liquidation-mechanism-eea` et `tiered-maintenance-margin-ratio-rules-eea` à relire (§12.1). La même réduction est écrite pour λ2 (§3.4). Le choc de décote K3 (risque B2) ramène ensuite (c) à (a) au défaut : les deux constats sont écrits ensemble |
| quant **M2** — effet minimal détectable de 0,61 σ_m, qui n'est pas celui de C3 | **Appliqué** : §8.5 (1,12 σ_m pour C3 ; 0,84 σ_m pour un test de moyenne au même α ; table de puissance ; σ_m ≤ 0,78 % de N total par mois à F = I, soit 1,12 USD au défaut et 1,95 sous L-b sans K3 ; FAIL par C3 probable, déclaré ; issue au plancher = FAIL) |
| quant **M3** — L1 au bid/ask d'un seul relevé agit comme un stop de base | **Appliqué** : §3.8 (déclenchement sur VD modélisée, mark et mid moins les coûts de sortie, confirmé sur deux relevés horaires ; L1 déclaré stop de base, hypothèse testée en dépendant ; grand-livre fantôme sans L1 ni L2) ; §3.12. **Réponse à la revue** (partielle) : l'exécution a lieu au premier relevé ≥ t + D_man, et non au carnet du second relevé, parce que la relecture risque M2 impose D_man à toutes les règles manuelles ; l'exécution au second relevé correspond à la sensibilité D_man = 1 h |
| quant **M4** — ADL sans condition de côté ; aucun ADL sans canal | **Appliqué** : condition « short gagnant » (§3.9) ; stress décisionnel C7 (§8.2), qui joue avec ou sans canal. **Réponse à la revue** sur la règle de repli à seuil (ii) : non retenue. Un seuil de baisse « ADL réputé » serait un paramètre sans ancrage possible sans donnée de marché ; C7 borne le biais de façon déterministe, quel que soit le canal, ce qui est l'option (iii) de la relecture |
| quant **M5** — l'item 3 fait voir le funding avant T0 | **Appliqué** : §10 réordonné en étapes A (décisions hachées), B (champs de règle), C (identifiants) ; C1–C3 par script booléen ou capture recadrée par un tiers ; E8 déclarée (§0.3). Ajout : C1 n'est faisable qu'avec un solde BTC/ETH non nul, sans quoi il passe par une réponse écrite d'OEM (B9 (5)), puisque l'achat est interdit |
| quant **M6** — « au plus un coupe-risque » est un texte mort | **Appliqué** : C6 = « aucun coupe-risque » (§8.2) ; futilité F1b (§8.4) ; §3.8 |
| quant **m1** — PROXY 4 pb posé mais non appliqué | **Appliqué** : 0,42 % par défaut partout (§3.6, §3.8, §11) ; 0,38 % seulement si le carnet EEE est prouvé (§11, en-tête) |
| quant **m2** — SOFR sur deux bases ; S_éco figé | **Appliqué** : S_éco = max(97,25 ; 5 × K × SOFR_gel × 365/360 ; coût fixe) = 98,60 (§8.3) |
| quant **m3** — unité de y_m, risque USDC/USD | **Appliqué** : unité USDC pour tous les critères et contrôles ; terme USDC/USD rapporté à part (§8.1, §5) |
| quant **m4** — marquage mixte | **Appliqué** : marquage de fin de mois sur une référence commune (mark M) ; base réalisée aux exécutions ; marquage mixte rapporté (§8.1) |
| quant **m5** — « valeurs erronées » non défini | **Appliqué** : définition opérationnelle en trois cas (§8.4) |
| quant **m6** — K-R3 non décidable | **Appliqué** : ensemble figé {8 h, 4 h, 2 h, 1 h} (§4.2) |
| quant **m7** — statistiques d'A-S1(ii) affichées avant le prospectif | **Appliqué** : sortie limitée à K-R1…K-R4 en booléens ; statistiques scellées jusqu'au verdict (§4.2) |
| quant **m8** — conflit G5 sous l'option (i) | **Appliqué** avec risque M7 : §4.1 (portée de l'interdit) ; P-S3 (α) ou (β) (§4.3, §12.1) |
| quant **m9** — x_cible, clearance fee, label inapplicable, VD après rééquilibrage | **Appliqué** : x_cible déclaré avec sa sensibilité (§3.6, §3.13) ; clearance fee dans x_P (§3.4 : 28,85 au lieu de 36,74 à h = 0,98) ; label « sous le contrôle atteignable » déclaré inapplicable tant que Simple Earn reste sous le SOFR (§7.3) ; VD avec B fixe et valeurs de la dernière ouverture (§3.8) |
| risque **B1** — §3.8 n'est pas « l'équivalent des limites 0,5 % / 1 % » | **Appliqué** : §3.8 renommé « coupe-risque de marché ; queues non bornées » ; table des seuils et des multiples de L1 ; perte de contrepartie (200 × L1, 100 × L2) ; options (a), N_a ≤ 25 USD et 5,27 USD/an, ou (b), queue chiffrée, à choisir par écrit (item A1, §12.1) |
| risque **B2** — branche L-a sans son risque dominant (décote, liste de marge, détection hebdomadaire) | **Appliqué** : contrainte K3 sous les deux lectures (§3.5), h_stress = 0 et x_det = 50 % par défaut ; x_det = 10 % ou levée de K3 seulement avec une source quotidienne (B3), une déclaration de préavis d'OEM (B9 (4)) et une décision écrite ; Réponse ligne 3 ; §11.3. Ajout : la relecture jugeait L-b robuste pour la liquidation aux paliers ; sous l'appel L-b et la lecture λ2 (risque M1), K3 lie aussi sous L-b, et au défaut (c) devient (a). C'est écrit |
| risque **M1** — clause « 100 % » lue d'une seule façon | **Appliqué** : trois distances (P, λ1, λ2), la plus courte par défaut (§3.4) ; C6 au minimum (§8.2) ; item B9 (2) ; table §3.6 (+96,1 % sous L-b sans K3). Ajout : λ2 est encore moins plausible que L-b (même réduction à l'absurde), ce qui est écrit ; la paire L-a exige des réponses écrites sur les deux clauses |
| risque **M2** — latence incohérente ; séquence des jambes ; aucun exécuteur | **Appliqué** : D_man = 8 h pour toutes les règles manuelles, sensibilité 1 h étiquetée « exécuteur automatique, inexistant » (§3.7) ; séquence « spot vendu d'abord, puis short racheté » ; moniteur de structure inscrit parmi les prérequis séparés d'A-S4 (§7.2). **Réponse à la revue** (partielle) : les deux jambes sont exécutées au même relevé, spot d'abord ; l'écart de quelques minutes entre elles n'est pas modélisé, et il est déclaré (§3.12). Décaler le rachat du short d'une heure ajouterait une exposition nue de signe inconnu, pas de la prudence |
| risque **M3** — trous de relevés de risque non comptés ; saut intra-horaire | **Appliqué** : seuil `INCONCLUSIVE` sur les relevés de risque (§8.4, §3.13) ; appels, liquidations et déclencheur (ii) évalués à la minute sur les bougies lues après T0 (§3.7, §9.1). **Réponse à la revue** pour L1 : L1 reste évalué aux relevés horaires, sur VD modélisée avec confirmation (quant M3). C'est un stop manuel à latence D_man : l'évaluer sur les extrêmes intra-horaires en ferait un stop de base encore plus serré. Le saut intra-horaire est couvert par C7 et par la décision de queue de §3.8 |
| risque **M4** — canal WS non vérifié ; état de famille au lieu de la position ; biais non borné | **Appliqué** : condition « PnL du short > 0 », fermeture au pire mark de l'heure, prix de faillite UNKNOWN (§3.9) ; canal dans P-S1 et dans les vérifications du §12.1 ; client WS dans l'enregistreur (§9.1) ; C7 décisionnel (§8.2). Ajout : le type `adl` de `insurance-fund` est déprécié (desk), donc pas de substitut REST |
| risque **M5** — changement de `interestRate` traité comme `INCONCLUSIVE` sans action | **Appliqué** : baisse = dénouement et `FAIL_MECHANISM`, comptée négative ; hausse = `INCONCLUSIVE` (§3.10, §8.4) |
| risque **M6** — ordre de consommation du pool, cascade §1.9.5, `acctLv` | **Appliqué** : §3.11 (question écrite B9 (3) ; `acctLv` et emprunt automatique lus avec leur effet, B4 ; sensibilité « spot consommé d'abord » déclarée comme biais ; événements 150 VH bis comptés, §6.3) |
| risque **M7** — l'interdit de CS-FUND §0.4 n'est pas réglé par l'ordre des gels | **Appliqué** : P-S3 réécrit, (α) exclusion des deux identifiants de l'univers et des sorties de G5, recommandée, ou (β) amendement explicite de §0.4, décision écrite avant l'un ou l'autre gel (§4.3) ; E9 (§0.3) |
| risque **M8** — check-list et §12.1 incomplets | **Appliqué** : B3 (source quotidienne), B8 (`tdMode`, `instFamily`), B9 (clause 100 %, ordre du pool, préavis), B11 (passeport France d'OEM), A1 (queues), A4 (code), A5 (supervision, 53 relevés dont 41 après K8, règle du relevé manqué) ; vérifications desk au §12.1 |
| risque **m1** — glissement PROXY | **Appliqué** (même traitement que quant m1) : net L-b sans K3 à F = I = 26,23 USD/an ; le F̄* de 39,45 % contre S_éco seul n'a plus d'objet, puisque la porte porte désormais sur l'excédent au-delà du SOFR (47,91 %) |
| risque **m2** — C6 « au plus un coupe-risque » vide | **Appliqué** (même traitement que quant M6) |
| risque **m3** — carnets « à chaque exécution papier » | **Appliqué** : `books` sz = 5 à chaque relevé horaire, dont 00:05 UTC (§9.1) |
| risque **m4** — valeurs servies avant T0 | **Appliqué** : « reçues, non lues », liste blanche, test hors réseau étendu à chaque point d'accès (§9.1, §9.2, §9.4, §12.2) |
| risque **m5** — P-S1 incomplet ; IP partagée ; publication | **Appliqué** : liste complète dans P-S1 (§12.1) ; IP partagée déclarée (§9.1) ; publication privée par défaut (§9.1) |
| risque **m6** — isolement de `fwd-okx-r1` | **Appliqué** : §9.1 |
| risque **m7** — S_éco figé à 97,25 | **Appliqué** sous la forme plus stricte de quant m2. **Réponse à la revue** : la formule proposée, max(97,25 ; 5 × SOFR_gel × 500 ; coût fixe), est retenue avec le facteur 365/360 de C2, pour que S_éco reste exactement 5 fois le hurdle (98,60 au lieu de 97,25 à SOFR constant) |
| risque **m8** — dépendances TradingV3 d'A-S4 | **Appliqué** : prérequis séparés listés, validité de six mois courant pendant ces travaux (§7.1, §7.2) |

Fin. `DRAFT_NOT_FROZEN`.
