# Autres voies vers un PnL positif — examen desk du 2026-10-16 au 2026-10-20

> **Nature du document.** Rapport au propriétaire, issu de la mission lancée sur sa demande du 2026-10-16 (« Trouve un autre
> moyen pour faire un pnl positif »). Examen **desk** de 43 idées non directionnelles (portage, base, spreads, rendement
> couvert, options, trésorerie), consolidées en 8 candidats et 17 rejets, puis deux lectures sceptiques indépendantes par
> candidat. **Verdict : aucune voie non directionnelle n'est défendable comme source de PnL à 500 USDT** ; la seule question
> ouverte qui vaille le temps du propriétaire se tranche dans son compte, sans ordre (§1, ligne 7). Aucune donnée de marché
> lue, aucun protocole gelé, aucun ordre, aucun drapeau touché ; le test forward `fwd-okx-r1` est intact.
>
> **Déclarations de l'orchestrateur** (en plus de celles des agents, §6.1) : (1) le brief de mission a été écrit après la
> lecture, le 2026-10-16 vers 21:30Z, de **trois derniers prix spot** (BTC-USDC, ETH-USDC, SOL-USDC) pour convertir les
> tailles minimales en USD — ces prix sont datés dans la fenêtre de quarantaine 2026-10-14..31 et ne servent qu'aux
> notionnels des plans papier ; (2) pour établir la **disponibilité** des séries publiques (§3.4 du brief), l'orchestrateur a
> interrogé `public/funding-rate-history` (BTC et ETH X-Perp) et `market/history-candles` (X-Perp, datés 261225 et expiré
> 260807, spot BTC-USDC, indice, mark) : les réponses contenaient des valeurs, que les scripts ont jetées sans les afficher
> ni les écrire — seuls le nombre de lignes et les dates de première et dernière ligne ont été lus ; le contrat expiré
> `BTC-USD_UM-260807` était servi le 2026-10-16 et ne l'était plus le 2026-10-20 (sceptiques K3/K5) ; (3) la base de ces
> probes n'a été utilisée par aucun critère. Dossier complet (notes, instantanés de métadonnées hachés, verdicts sceptiques,
> brouillon K8 et ses relectures) : `tradingV3-private/research/20261016T2145Z-positive-pnl-structural/`.

Rapport au propriétaire. Rédigé le 2026-10-20 (desk, rédacteur du rapport) à partir des dossiers de la mission
`20261016T2145Z-positive-pnl-structural/` : notes a1–a4 (2026-10-16/17), consolidation `merge/CANDIDATS.md` (2026-10-17),
seize lectures sceptiques `skeptics/K{1..8}-{eco,ops}.md` (2026-10-20), brouillon `design/K8-PREREGISTRATION-DRAFT.md` et
ses deux relectures `review/K8-{quant,cto}.md` (2026-10-20). Empreintes sha256 des sources en §7.

Règles du BRIEF §1 respectées par ce rapport : aucune valeur de prix, funding, base, prime, volume, OI ou carnet d'un
instrument crypto n'a été lue ni n'est reproduite ici ; aucune donnée de venue datée 2026-10-14..31 ; rien lu dans
`20261013T100000Z-forward-okx-r1/`, aucun service `fwd-okx-*` touché ; aucun git, aucune écriture hors `synthesis/` ;
aucun ordre, aucune clé, aucun téléchargement. Les seuls chiffres sont des taux fiat datés (BCE, NY Fed, arrêtés),
des champs de règle (frais, terme d'intérêt, plafonds, tailles de contrat, décotes, paliers) et l'arithmétique qui en
découle, formule affichée. Les notionnels des plans papier (114,24 ; 118,28 ; 195,84 ; 232,52 ; 393,78 ; 473,29 USD…)
sont des quantités légales × taille de contrat × les trois prix de conversion lus une fois le 2026-10-16 par
l'orchestrateur (BRIEF §3.2) ; les prix eux-mêmes ne sont pas recopiés. Convention de lecture : « si F = I » désigne
toujours une hypothèse de règle (terme d'intérêt encaissé à chaque règlement), jamais une mesure.

Vocabulaire : **contractuel** = écrit dans une règle d'exchange, un arrêté ou un contrat ; **mesuré** = relevé par le
desk sur une métadonnée ou un document ; **UNKNOWN** = ni l'un ni l'autre. Ce rapport ne dit d'aucune voie qu'elle est
rentable.

---

## 1. La réponse en 10 lignes

1. **Ce qui existe de contractuel.** Un seul terme de règle positif a été trouvé sur les produits accessibles : le terme
   d'intérêt du funding des X-Perps **crypto** d'OKX Europe, I = 0,01 % par 8 h (124 X-Perps sur 125 ; 0 pour SAND ;
   **0 pour tous les X-Perps TradFi, or et pré-market vérifiés**), encaissé par un short couvert tant que la prime moyenne
   reste dans [−0,04 %, +0,06 %] (perps-funding-fee-mechanism, màj 2026-08-27). Tout le reste — base des datés, prime de
   variance, taux implicite d'un box, taux Simple Earn — est une valeur de marché non lue ou une décision commerciale d'OKX.
2. **Aucune voie non directionnelle n'est défendable comme source de PnL à 500 USDT** après les deux lectures sceptiques :
   K1 à K7 sont réfutés (scores 1,25 à 3 sur 10) ; K8 survit, mais ce n'est pas une stratégie, c'est la borne que tout le
   reste doit battre.
3. **Ordre de grandeur honnête.** Si F = I à chaque règlement (cas de référence de la zone morte, **pas un plafond** : au-dessus de la zone morte F dépasse I
   jusqu'au plafond de l'instrument, ±0,375 % BTC / ±0,75 % ETH par règlement ; F = I en continu n'a jamais été observé), K1 (a) rapporte
   232,52 × 0,0001 × 3 × 365 = 25,46 USD/an brut, 24,58 net de frais, pour 465,3 USD engagés ; la variante (c)
   auto-collatéralisée 43,12 brut / 41,62 net si le spot est admis comme marge (règle **non écrite**). Contrôles :
   SOFR 480 × 3,89 % = 18,67 USD/an ; Simple Earn USDC 500 × 3,5 % = 17,5 USD/an (indicatif, non garanti) ;
   Livret A 444,77 × 1,70 % = 7,56 EUR/an (net, certain jusqu'au 2027-01-31). Selon le funding réalisé (non lu), le PnL net annuel de (a) va de −0,9 USD
   (F̄ = 0) à +24,6 (F̄ = I, cas de référence ; au-dessus, borné par le plafond de l'instrument), avec +17,7 au niveau publié par la littérature (≈ 8 %/an), soit le niveau du
   contrôle Simple Earn ; celui de (c) de −1,5 à +41,6 (+30,0 à 8 %/an). Après impôt symétrique à 30 %, les signes ne
   changent pas ; un point de change EUR/USD (4,45 EUR) est du même ordre que ces surplus (K1-eco §4.2).
4. **Ce que ça demande.** Bloquer ≈ 465 USD et les deux créneaux de risque pendant 12 mois (horizon honnête d'un rendement
   de funding : les régimes négatifs documentés durent 2 à 12 mois), un bundle spot OKX et un résolveur X-Perp qui n'existent
   pas dans TradingV3, deux entités juridiques (CASP spot / MiFID dérivés), et des vérifications que seul le compte donne.
5. **Ce qui est contractuel mais révocable.** I est un paramètre d'exchange (déjà 0 pour SAND) ; CGU OEM §12.1/12.2 :
   modification, suspension, terminaison « without notice » ; circulaire MFSA du 2026-03-09 relayant l'ESMA : les perpétuels
   sont « likely » des CFD, KID PRIIPs exigé — aucun KID X-Perps trouvé.
6. **Un seul protocole est rédigé : K8** (borne EUR nette, bornes USD théoriques, relevé hebdomadaire du taux d'offre
   affiché de Simple Earn USDC). Statut `DRAFT_NOT_FROZEN`, aucun sha256, deux relectures `NEEDS_CHANGES` sans constat
   bloquant, toutes traitées (§3). Il ne produit aucun PnL : il rend les tableaux des K1–K7 comparables et honnêtes.
7. **Ce qui survit à coût nul** (lecture d'interface, minutes, aucun ordre) : les réglages de marge X-Perps du compte
   (spot BTC/ETH, BETH/OKSOL admis comme marge d'un short ? décotes ?) — c'est le fait qui sépare K1 (a) de K1 (c) ;
   la devise de règlement USD-M (USD par défaut, à fixer sur USDC) ; la visibilité des datés et des options ; le barème du
   compte ; Simple Earn USDC listé.
8. **Ce qu'il doit décider** (numéroté en §6.2) : devise de compte ; geler ou non K8 ; CS-FUND à 12 mois tel quel ou
   classé sans suite ; consentement ou refus d'un Gate 1 rétrospectif `consumed_equivalent` ; conseil fiscal ; définition
   des limites de risque pour une structure couverte (BRIEF §2).
9. **Ce qui n'a pas été fait** : aucune donnée de marché lue, aucun gel, aucun ordre, aucune souscription, aucun git ;
   fwd-okx-r1 intact (§6.1).
10. **Lecture pour un trader** : à 500 USDT, le cas de référence (F = I à chaque règlement) vaut des dizaines d'USD par an ;
    après impôt symétrique il dépasse la trésorerie USD de quelques USD (K1 (a) 17,2 contre SOFR 13,1 et Simple Earn 12,3 ;
    K1 (c) ≈ 29) ; mais au niveau de funding publié par la littérature (≈ 8 %/an, pas une prévision) K1 (a) égale Simple
    Earn **avant** impôt, pour strictement plus de risque (K1-eco §4.2–4.3). La seule question ouverte qui vaille le temps
    du propriétaire est l'auto-collatéralisation (ligne 7), et elle se tranche sans ordre.

---

## 2. Tableau de tous les candidats examinés

### 2.1 Candidats consolidés (K1–K8), après les deux lectures sceptiques

Légende : COND = CONDITIONAL ; UNVER = `ACCOUNT_ELIGIBILITY_UNVERIFIED` (gate non passé, RESEARCH-ELIGIBILITY-GATES.md M1) ;
scores sur 10 (`skeptics/Kn-eco.md`, `skeptics/Kn-ops.md`). « Rendement de règle » = arithmétique sur des champs de règle
sous l'hypothèse F = I ; jamais une mesure.

| # | Candidat | Mécanisme (une ligne) | Rendement de règle à 500 USDT, ou UNKNOWN | Coûts | Gates M1–M5 | Sceptique éco | Sceptique ops | Score | Décision | Fait tueur |
|---|---|---|---|---|---|---|---|---|---|---|
| K1 | Portage de funding couvert (= CS-FUND) : short X-Perp BTC/ETH + long spot USDC, 1× ; variantes (b) conditionnelle, (c) auto-collatéralisée, (d) rotation | Le short encaisse F = I = 0,01 %/8 h tant que la prime moyenne reste dans [−0,04 %, +0,06 %] ; le spot annule le delta | (a) N = 114,24 + 118,28 = 232,52 USD → 25,46 USD/an brut, 24,58 net (F1) ; (c) N = 393,78 → 43,12 brut, 41,62 net ; funding réalisé **UNKNOWN** ; seuil pour égaler Simple Earn : F̄ > 7,4 %/an (a), 3,9 % (c) (F2) | Aller-retour 2 × 0,10 % + 2 × 0,05 % = 0,30 % de N ; glissement 0,08 % ; marge USDC et spot sans intérêt (≈ 465 USD dormants) ; plancher par règlement −0,375 % × 114,24 = 0,43 USD (BTC), −0,75 % × 118,28 = 0,89 USD (ETH) | M1 COND (X-Perp vu au compte ; achat BTC-USDC/ETH-USDC, transfert USDC → marge, API, devise de règlement USDC : non vérifiés) ; M2 COND (liquidité un jour, en quarantaine) ; M3 COND (`consumed_equivalent` ; profondeur funding-rate-history ≈ 3 mois observée, rétention UNKNOWN) ; M4 COND (= brouillon CS-FUND ; un Gate 1 sur funding sériellement corrélé est un filtre, pas une description) ; M5 UNKNOWN | WEAKENED 3 : (a) réfutée à 500 USDT, seule (c) survit | REFUTED 3 : K1 n'ajoute rien à CS-FUND ; Gate 2 court sans pouvoir ; Gate 1 non prouvé disponible | 3 | **REJETÉ tel quel** pour (a), (b), (d) ; **(c) CONDITIONNEL** à une vérification d'interface (spot comme Selected Margin du short) ; aucun protocole rédigé ; le brouillon CS-FUND 12 mois (BRIEF §4) reste le seul texte existant | Le seuil d'équilibre de (a) contre Simple Earn USDC est F̄ ≈ 7,4 %/an (68 % de I) alors que le funding réalisé publié est ≈ 8 %/an ou moins (littérature §5) : surplus ≈ 0 à −7 USD/an pour strictement plus de risque ; (c) repose sur une règle non écrite |
| K2 | Staking couvert (CS-STAKE-HEDGE) : ETH/SOL stakés en BETH/OKSOL (OKX Europe Ltd) + short X-Perp du même actif (OEM), 1× | Récompense PoS (APY affichés, non garantis) + terme I du short ; (i) BETH/OKSOL comme marge, (ii) marge USDC isolée | Dossier (i) 62,67 USD/an dont funding 48,12 (77 %) et staking 14,55 (23 %) ; corrigé K2-eco : à 0,97 × le staké N = 424,93 → funding 46,53 ; apport propre du staking 14,55 (i) / 7,90 (ii) ; staking seul 7,90/238 = 3,3 % < Simple Earn 3,5 % | 0,30 % de N aller-retour ; frais de service staking ETH 5 % (inclus dans l'APR affiché), SOL UNKNOWN ; plancher −0,75 % × 219,66 = 1,65 USD par règlement (i) ; stop-out 2,50 + 0,38 % × N = 3,33 USD | M1 UNVER (BETH/OKSOL comme marge X-Perps : un article Learn seulement ; entité et droit du staking UNKNOWN) ; M2 COND (décote BETH palier 1 = 0,98 = ETH 0,98 : aucun avantage de collatéral ; mmr SOL X-Perp 1,0 %, pas 0,65 %) ; M3 COND (aucune série de taux de staking ; funding = Gate 1 de K1) ; M4 FAIL (77 % du flux = K1 ; auto-collatéral = K1c ; pas « genuinely different ») ; M5 UNKNOWN | REFUTED 2,5 | REFUTED 2 | 2,25 | **REJETÉ** ; une ligne d'information pour K1 (BETH/OKSOL figurent-ils dans les réglages de marge ?) | La jambe stakée n'a pas de stop et ne peut pas en avoir : BETH/OKSOL non négociables ni transférables, rachat rapide « not guaranteed », suspendable sans préavis, unbonding en jours ; toute fermeture forcée du short laisse un long nu de ≈ 220 USD (−10 % = −22 USD = 8,8 × le coupe-risque) |
| K3 | Cash-and-carry daté OKX Europe : long spot USDC + short BTC-USD_UM-YYMMDD, base bloquée à l'entrée, tenu à livraison ou roulé | Convergence contractuelle au règlement (moyenne 30 min de l'indice, vendredi 08:00 UTC, frais 0,01 %) ; pas de funding | **UNKNOWN** (base non lue). Hurdle sur le capital engagé (2N + réserve = 500 sans intérêt) : B* = 500 × r / N + 1,04 % = 10,97 %/an vs SOFR, 9,98 % vs Simple Earn, 7,27 % vs €STR (N = 195,84) (F3) ; exemple du dossier B = 10 %/an → 195,84 × (10 − 1,04) % = 17,5 USD/an = Simple Earn | Cycle tenu à livraison 0,10 + 0,05 + 0,01 + 0,10 = 0,26 % de N ; roulement 0,06–0,10 % ; capital sans intérêt = poste dominant (500 × 3,89 % = 19,45 USD/an, ≈ 38 × les frais explicites) ; liquidité des datés UNKNOWN (littérature : spreads 53–71 % plus larges que les perps) | M1 UNVER (datés listés EEE par la page OEM x-perps-contract-specifications, màj 2026-10-19, non vus au compte ; France non nommée) ; M2 COND (liquidité jamais mesurée ; cadre de marge double : palier API mmr 2 % vs clause OEM « < 30 % de la valeur du contrat ») ; M3 FAIL tel qu'écrit (BTC-USD_UM-261225 coté le 2026-06-12 : 117 des 244 lignes déclarées sont antérieures à la cotation ; 260807 non servi, code 51001) ; M4 COND ; M5 UNKNOWN | REFUTED 2 | REFUTED 3 | 2,5 | **REJETÉ** (1× isolé) ; auto-collatéralisé **CONDITIONNEL** à la même vérification d'interface que K1c (B* ≈ 5,5 %/an si N ≈ 440) ; aucun protocole | Hurdle posé sur N au lieu du capital engagé : à B = 10 %/an (« généreux »), K3 égale Simple Earn et reste sous SOFR ; le stop de base contredit la convergence contractuelle (réalise une perte que la livraison aurait annulée) |
| K4 | Cash-and-carry Kraken EEE : long BTC spot + short FF_XBTUSD à maturité fixe, le BTC servant de marge (décote 1 %) | Même convergence que K3 ; capital « engagé une fois » | **UNKNOWN**. B* = (500 × 3,89 % + 7,60…9,52) / 473,29 = 5,72–6,12 %/an à hausse nulle ; + 26,3 % × A, A = perte latente moyenne / N (≈ 8,4 % à A = 10 %, ≈ 11 % à A = 20 %) (F4) | Spot Kraken Pro niveau 1 : 0,40 % maker / 0,80 % taker (aller-retour 1,20–1,60 % de N, 12 à 16 × OKX) ; futures 8 × 0,05 % ; intérêt horaire 0,0025–0,0030 %/h = 21,9–26,3 %/an sur les pertes latentes non couvertes ; conversion automatique 0,2 % + écart | M1 UNVER (compte non ouvert ; adéquation, TIN ; France non nommée ; KID non localisé ; CGU PEDSL v2.2 trouvées en copie chez un tiers) ; M2 COND (FF_XBTUSD listés EEE, màj 2026-10-16) ; M3 FAIL (aucune ligne de registre pour FF_, spot Kraken, indice CF BTCOPTRR ; CGU EEE interdisent scraping/extraction) ; M4 FAIL (promesse « sans conversion, sans direction » fausse : conversion automatique dès que solde USD + PnL latent < ≈ 120 % de la marge de maintenance) ; M5 UNKNOWN | REFUTED 2 | REFUTED 2 | 2 | **REJETÉ** | Les pertes latentes du short collatéralisé en BTC portent un intérêt horaire de 21,9–26,3 %/an ou déclenchent la conversion du BTC : le « capital engagé une fois » est un emprunt USD implicite qui croît avec la hausse de BTC — exposition directionnelle cachée ; règlement le 2026-12-25 08:00 UTC (vendredi de Noël), pas le 26 |
| K5 | Spread calendaire dérivés seuls (OEM) : short X-Perp (reçoit I) + long daté (paie la base) ; sens inverse rejeté (R14) | PnL = Σ F − B_entrée − résidu de sortie − frais ; une seule entité, aucun spot | À F = I : 232,52 × 0,0273 = 6,35 USD/trimestre ; B UNKNOWN ; base d'équilibre sur le capital B* = 10,95 % − 0,88 % − 500 × 3,89 % / 232,52 = 1,71 %/an vs SOFR, 2,54 % vs Simple Earn (F5) ; le « ≈ +6 %/an de N » du dossier = 14,6 USD/an = 2,9 % de 500, sous SOFR et Simple Earn | 0,05 × 3 + 0,01 = 0,16 % de N par cycle tenu à livraison ; 2N + réserve sans intérêt ; fermeture manuelle de la jambe X-Perp en ≈ 63 tranches dans la fenêtre 07:30–08:00 UTC du 2026-12-25 | M1 UNVER (datés) ; M2 COND (pool Selected Margin non documenté pour les datés ; résidu de sortie non borné par le clamp, bandes d'ordre 0,5–1 %) ; M3 FAIL (Gate 1 non servable : 72 j sans contrat dans la fenêtre déclarée ; règle « ≥ 4 des 6 mois » passe par hasard dans 34 % des cas sous H0) ; M4 FAIL (identité K5 = K1 − K3 ; swap « reçoit flottant / paie fixe » sur le funding = prévision) ; M5 UNKNOWN | REFUTED 2 | REFUTED 2 | 2 | **REJETÉ** ; conservé comme instrument de mesure de (funding − base), pas comme source | Identité comptable K5 = K1 − K3 : au même capital, K5 rapporte strictement moins que K1 dès que la base est positive et ne le dépasse qu'en déport, régime où le funding est lui-même nul ou négatif |
| K6 | Prime de variance à risque défini : condors / strangles couverts sur options USDC EEE (ETH-USD_UM 0,01 ETH, SOL-USD_UM 0,1 SOL), delta couvert par X-Perp | IV > RV en moyenne (littérature BTC) captée par des structures à perte maximale définie | **UNKNOWN**. À la géométrie du dossier (5 ct, ailes 50 USD) : ≈ −8 USD/an (frais 0,145 USD/structure > avantage ≈ 0,126 USD/semaine sous la meilleure prime publiée) ; meilleure géométrie (1 ct, ailes 250 USD) : ≈ +7,2 ± 6,6 USD/an (Monte Carlo sur la prime BTC 2017-2022) ; t ≈ 0,5 à 12 semaines, t = 2 exige ≈ 180 semaines | 4 × min(0,03 % × notionnel ; 7 % × prime) = 4 × 0,0362 = 0,145 USD/structure ; écart bid-ask UNKNOWN (chaîne jeune) ; IMR par jambe courte = [max(0,1 ; 0,15 − OTM/F) × index + mark] × 0,01 × q ⇒ 10–14 × la perte max ; aucune compensation hors Portfolio Margin (VIP3) | M1 UNVER (options EEE : un article Learn avec réserves de région/compte ; « Sell » conditionnel ; pages normatives EEE sans le mot « option » ; listes identiques sur eea.okx.com et www.okx.com) ; M2 COND (grille de strikes 50 USD ⇒ 4 contrats et non 5 ; écarts UNKNOWN) ; M3 FAIL (aucune série d'IV ; hebdo ETH depuis 2026-08-14 ; chaîne SOL créée 2026-10-14/16) ; M4 COND (réellement neuf ; fiscalité symétrique 150 ter) ; M5 UNKNOWN | REFUTED 2 | REFUTED 2 | 2 | **REJETÉ** ; vérification d'interface (onglet Options, bouton Sell, marge affichée) à coût nul ; au plus un relevé d'écarts de 4 semaines explicitement non décisionnel | Espérance nette négative avant écart bid-ask à la géométrie du dossier (frais 0,145 USD contre un avantage ≈ 0,126 USD/semaine) ; la vente n'est pas confirmée pour le compte retail ; « marge = perte max » est faux sur la règle publiée |
| K7 | Box spread : prêt synthétique d'USDC via options USDC EEE (long box = prêter au taux implicite r_box) | Long call K1 + short put K1 + short call K2 + long put K2 ; par parité call-put r_box = taux d'escompte USD des teneurs de marché, sans lien avec I | **UNKNOWN**. Hurdle vs Simple Earn 5,7–10,7 %/an, vs SOFR 6,3–12,0 % (deux jambes vendues margées 48–55 USD à q = 10, soldes OEM sans intérêt) ; à r_box = 8 %/an hypothétique : +3,8 USD/an ; 6 % : −1,0 ; mensuels négatifs | Frais / N = (4 × 0,03 % + 2 × 0,02 %) × S / w = 0,16 % × S / w (0,32 % par cycle pour une largeur w = S/2 ; 3,9 %/an mensuel) ; écart Δr ≈ (4 s / w) × 365 / T ; RFQ / block trading minimum 1 000–10 000 USD ⇒ 4 ordres au carnet (risque de jambe ≈ 241 USD à q = 10) | M1 UNVER (idem K6 ; canal « Simple options » = achat seul) ; M2 COND (SOL-USD_UM : 4 échéances, aucune mensuelle ni trimestrielle) ; M3 FAIL (aucune série ; deux mensuels seulement entre 2026-11-02 et 2027-01-25) ; M4 FAIL (dominé par K3/K5 : E[r_box] ≤ r_base pour un preneur de prix) ; M5 UNKNOWN | REFUTED 1 | REFUTED 1,5 | 1,25 | **REJETÉ** | Frais sous-estimés d'un facteur S / w (assis sur le notionnel du sous-jacent, pas sur le montant prêté) ; prêter N via une box transforme de l'USDC ségrégué MiCA en créance bilatérale sur OEM sans chambre de compensation pour 100 % de N |
| K8 | Contrôles et trésorerie (pas une stratégie) : Livret A / LEP / LDDS, SOFR, €STR ; Simple Earn EEE USDC ; bonus de dépôt (information) | T0 : rendement administré (arrêté) ; bornes USD/EUR théoriques non achetables ; T1 : taux d'offre affiché, fixé par OKX « in its sole discretion », plancher 0,01 % APR | Livret A : 444,77 × 1,70 % = 7,56 EUR/an, certain jusqu'au 2027-01-31 (F6) ; SOFR 500 × 3,89 % = 19,45 USD/an ; €STR 500 × 2,44 % = 12,20 ; Simple Earn 500 × 3,5 % = 17,5 USD/an **si** l'indicatif se réalisait et si 100 % des fonds sont appariés (borne supérieure) ; seuil T1 vs Livret A : a × φ ≥ 1,70 / 0,686 = 2,48 %/an ; bonus : 0 par règle sur un capital déjà déposé | Conversion USDC → EUR : 0,10 % (Spot & derivatives) ou 0,20 % (Spot-only) + glissement 5–10 pb ; One Way Convert USDT → USDC UNKNOWN (0,10 % pessimiste figé) ; Simple Earn 15 % des Returns ; cession 150 VH bis (2086 si cessions > 305 €) | T0 : M1 OK sous conditions (un seul Livret A par personne, capacité à vérifier) ; T1 : M1 COND (produit déclaré hors MiCA, art. 50 non tranché, ToS EEE muettes) ; M2 OK (USDC-EUR minSz 1, lot 1e-6) ; M3 : séries fiat hors registre, Simple Earn sans historique public ; M4 : aucune hypothèse ; M5 sans objet | WEAKENED 3 : T0 survit comme borne ; T1 et bonus réfutés comme PnL | WEAKENED 2 : T0 survit ; parking T1 incompatible avec une structure ouverte ; bonus 0 par CGU ; Kraken OIR BTC retiré | 2,5 | **PROTOCOLE RÉDIGÉ** (`design/K8-PREREGISTRATION-DRAFT.md`, `DRAFT_NOT_FROZEN`, aucun sha256) — une mesure de bornes, pas une stratégie | Comme source de PnL : Simple Earn à 3,5 % indicatif est sous SOFR (17,5 − 19,45 = −1,95 USD/an ; 14,9 − 19,45 = −4,6 si brut des 15 %), créance non garantie hors sauvegarde MiCA à rachat « delayed or restricted at OKX's sole discretion » ; aucun montant > 5 USDC ne respecte la limite totale de 1 % (5,00 USD / 100 % de perte possible) |

**Formules des rendements de règle (toutes refaites par les sceptiques, sources dans leurs fichiers) :**
- F1 (K1) : N_BTC = 14 ct × 0,0001 BTC × prix de conversion = 114,24 USD ; N_ETH = 49 ct × 0,001 ETH × prix = 118,28 USD
  (q = ⌊120 / valeur d'un contrat⌋, BRIEF §3.2) ; brut = N × 0,0001 × 3 × 365 = 232,52 × 0,1095 = 25,46 USD/an ;
  net = 25,46 − 0,30 % × 232,52 − 0,08 % × 232,52 = 25,46 − 0,70 − 0,19 = 24,58. (c) : 24 ct + 82 ct → N = 195,84 +
  197,94 = 393,78 → 43,12 brut, 43,12 − 1,18 − 0,32 = 41,62 net. Capital (a) : spot 232,75 + marge 232,52 = 465,3 USD.
- F2 (K1) : seuil d'égalité avec Simple Earn 17,5 USD/an : N × (F̄ − 0,38 %) + 34,7 × 3,5 % = 17,5 ⇒ F̄ ≈ 7,4 %/an (a) ;
  393,78 × (F̄ − 0,38 %) = 14 ⇒ F̄ ≈ 3,9 %/an (c). Après impôt symétrique à 30 % : (a) 24,58 × 0,70 = 17,2 ;
  SOFR 18,67 × 0,70 = 13,07 ; Simple Earn 17,5 × 0,70 = 12,25 (K1-eco §2.5 ; le dossier initial ne taxait qu'un côté).
- F3 (K3) : B* = (capital × r) / N + frais annuels = 500 × 3,89 % / 195,84 + 1,04 % = 9,93 % + 1,04 % = 10,97 %/an ;
  avec r = 3,5 % : 9,98 % ; r = 2,44 % : 7,27 %. Frais d'un cycle = 0,10 % + 0,05 % + 0,01 % + 0,10 % = 0,26 % ; × 4 = 1,04 %/an.
- F4 (K4) : B* = (500 × 3,89 % + frais première année) / N_short = (19,45 + 7,60) / 473,29 = 5,72 % ; (19,45 + 9,52) / 473,29 =
  6,12 % ; intérêt horaire 0,0030 % × 24 × 365 = 26,3 %/an (0,0025 % → 21,9 %) appliqué à A × N.
- F5 (K5) : funding trimestriel = N × 91 × 0,03 % = 232,52 × 2,73 % = 6,35 USD ; B* = 10,95 % − c − capital × r / N avec
  c = 0,64 % (frais) + 0,24 % (glissement) = 0,88 % ⇒ 10,95 − 0,88 − 8,37 = 1,71 %/an (SOFR) ; 10,95 − 0,88 − 7,53 = 2,54 % (Simple Earn).
- F6 (K8) : 500 / 1,1225 = 445,43 EUR (taux de référence BCE EUR/USD du 2026-10-19) ; × (1 − 0,0010 − 0,0005) = 444,77 EUR ;
  × 1,70 % = 7,56 EUR/an ; cas pessimiste intégral (Convert 0,10 %, Spot-only 0,20 %, 10 pb) : 443,65 EUR, 7,54 EUR/an ;
  1 % de change = 4,45 EUR. Convention fiscale : PFU 31,4 % (12,8 % + 18,6 %, depuis 2026-01-01, service-public A18796 ;
  application aux revenus de prêt crypto et aux gains X-Perps **UNKNOWN**) ⇒ facteur 0,686 ; K1–K7 des sceptiques
  utilisent 0,70 (30 %) : écart de convention, sans effet de signe.

### 2.2 Rejetés à l'entrée (avant les lectures sceptiques ; décision du desk de consolidation, `merge/CANDIDATS.md` §3)

| # | Idée | Mécanisme (une ligne) | Rendement de règle | Gates | Décision | Fait tueur |
|---|---|---|---|---|---|---|
| R1 | Différentiel de funding inter-venues : short X-Perp OKX + long perp Kraken EEE / Coinbase EEE | Encaisser l'écart de funding entre deux venues | Au régime de base = exactement I d'OKX (10,95 %/an), déjà capté par K1 ; aucun terme propre | M2/M3 : deux marges non secourables, transferts on-chain UNKNOWN | REJETÉ (a2, a3, a4 unanimes) | Formules Kraken (horaire, prime seule, ±0,5 %) et Coinbase (TWAP de prime) sans terme d'intérêt ; recouvrement total avec CS-FUND |
| R2 | Rééquilibrage / volatility harvesting (constant-mix BTC/USDC ou multi-actifs) | Rendement de diversification ≈ ½ w(1−w) σ² | Aucune hausse d'espérance arithmétique (Chambers-Zdanowicz 2014) ; net négatif avec coûts (Chen-Cai 2025) | M4 : position nette longue sans stop compatible (règle du propriétaire) | REJETÉ (unanime) | Couvrir la jambe annule σ_p donc la prime ; corrélations intra-crypto 80–85 % ; voisin de band reversion r1–r9 et market making (§5 clos) |
| R3 | Bases de stablecoins / FX (USDC-EUR, EURC-USDC, USDG-EUR, USDC-USDT) ; différentiel EUR/USD via perps FX | Carry entre jetons de monnaie électronique | Nul par règle : MiCA art. 50(1)–(3), aucun intérêt sur un EMT pour le détenteur | M4 : détenir USDC contre un passif EUR = position EUR/USD directionnelle | REJETÉ | Triangulaire sous 24–30 pb de frais pour une déviation bornée par le rachat au pair ; funding FX Kraken sans terme de taux |
| R4 | Covered call / cash-secured put ; spreads calendaires de volatilité ; dispersion | Vente de prime contre un sous-jacent détenu | UNKNOWN ; covered call ≡ short put | M4 : directionnel ; après stop sur le spot le call vendu est nu (« potential loss … unlimited », OKX) | REJETÉ ; la variante à risque défini est K6 | BTC infaisable (0,01 BTC par contrat ≈ 816 USD) ; spread calendaire = prévision de la structure par terme |
| R5 | Expiry à 5 ans des X-Perps (2031-04-04 / 2031-05-02) | Convergence à l'échéance | Aucun mécanisme : le X-Perp est ancré par le funding, pas par un coût de portage | — | REJETÉ (a1) | Rien n'arrive avant 2031 ; « Only one of each Contract will be listed for any 5-year period » |
| R6 | X-Perps pré-market (ANTHROPIC, OPENAI, OURA) : funding « flat » encaissé par un short | Encaisser un funding fixe | Funding = 0 : prime fixée à 0 (pre-market-perpetuals-faq 2026-08-14) ET interestRate = 0 vérifié (a4 jsonl) | M4 : aucune jambe de couverture ⇒ directionnel pur sur une valorisation privée | REJETÉ | Bandes ±2,5 %, délisting si IPO annulée ; voisin de H1 post-cotation (clos) |
| R7 | « Straddle de liquidation » : long + short 10× en mode hedge sur le même X-Perp | Gagner la jambe non liquidée | Espérance de règle < 0 par arrêt optionnel : −(mmr 0,65 % + taker 0,05 % + 4 × 0,05 %) × N × P(toucher) | — | REJETÉ (a1) | Tout gain exige une continuation = prévision ; Daily Compression (CGU OEM §1.11) nette chaque jour ouvré les longs et shorts du même instrument |
| R8 | Datés inverses coin-margined (BTC-USD-YYMMDD, règlement BTC/ETH) : cash-and-carry sans liquidation | Base sans liquidation possible (collatéral = sous-jacent) | UNKNOWN | M1 FAIL : absents de la page OEM x-perps-contract-specifications ; présence sur eea.okx.com non probante (liste identique à www.okx.com, 272 ids dont 12 inverses) | REJETÉ | Non offerts en EEE ; le brief omettait ces 12 inverses de son propre snapshot |
| R9 | Convergence à l'expiry / délisting (fenêtre 30 min) ; arbitrage prime X-Perp / spot intrajournalier | Capturer le résidu de base dans la fenêtre de règlement | Gain borné par le résidu ≈ valeur absolue de F ≈ 1 pb contre ≥ 10 pb taker ; mark-outs passifs −3,1 / −3,4 pb nets | M4 : recouvrement EXPIRY-PIN / FUND-SETTLE et market making (§4–§5 clos) | REJETÉ | Ordres annulés et solde négatif possible au règlement |
| R10 | Staking non couvert ETH/SOL ; produits structurés (Dual Investment, Shark Fin) | Récompense PoS / options vendues emballées | 240 × 1,93 % + 240 × 4,69 % ≈ 15,9 USD/an bruts (APY affichés 2026-10-16) < SOFR 18,67 | M4 : exposition directionnelle pleine sans stop compatible ; disponibilité EEE des structurés UNKNOWN | REJETÉ ; la version couverte est K2 | Payoff directionnel |
| R11 | Portage TradFi : short X-Perp action / ETF / matière première + long sous-jacent chez un courtier ; convergence à la réouverture après gel d'indice ±10 % | Funding TradFi encaissé par un short couvert | F = 0 en zone morte : interestRate = 0 vérifié pour TSLA, SPY, QQQ, NVDA, CL, XAG, XAU (a4 funding-rules-blinded-tradfi-20261017.jsonl) | M1/M4 : courtier hors périmètre TradingV3 ; régime CFD probable (MFSA 2026-03-09) ; H3 (session, dividendes, roll) déjà clos | REJETÉ | Dividendes non reçus (FAQ TradFi) ; prévoir le prix d'ouverture = directionnel |
| R12 | Market making / ordres RPI ; actions tokenisées EEE + short X-Perp action | Capture du spread / portage sur certificats tokenisés | UNKNOWN ; I = 0 sur les X-Perps actions | M4 : market making « sufficiently explored » (§4–§5) ; M2 : aucune action tokenisée en USDC/EUR (167 paires toutes en USDT, retirées EEE) | REJETÉ | RPI améliore le prix des preneurs manuels retail, pas des preneurs API |
| R13 | Or : long XAU X-Perp + short XAU daté (base or contre funding), sans spot or | Encaisser la base or | Aucun terme positif : interestRate = 0 sur XAU-USD_UM_XPERP-310502 (a1 metadata) ; contango or ≈ taux USD − taux de prêt de l'or ⇒ capturer la base = prêter des USD à ≈ SOFR − 0,20 %/cycle | M2 : aucun spot or en USDC/EUR (PAXG/XAUT en USDT/TRY) ; indice or non documenté ; régime CFD probable | REJETÉ (desk, non unanime : a1 CONDITIONAL priorité basse, a4 REJECT) | Surplus ≈ 0 par construction ; réouvrable seulement comme mesure d'un « taux de financement or » (trésorerie) |
| R14 | Spread sens inverse : short daté + long X-Perp (encaisser la base, payer le funding) | Base encaissée contre funding payé | La jambe longue X-Perp paie 0,03 %/j au régime de base = 0,90 %/30 j ; seuil de base 1,11 %/30 j ≈ 13,5 %/an | — | REJETÉ (a3 L3) ; intégré à K5 comme sens rejeté | Niveau de base euphorique seulement ; dominé par K3 |
| R15 | Lending / Simple Earn comme stratégie autonome ; Kraken OIR BTC ; Coinbase EEE | Dépôt rémunéré | Prime ≈ 3–5 USD/an sur l'€STR, ≈ 0 ou négative sur SOFR ; Kraken OIR BTC 0,10 % APY → 120 × 0,10 % = 0,12 USD/an ; Coinbase a supprimé la rémunération USDC en EEE (2024-12) | M4 : aucune hypothèse | REJETÉ comme stratégie ; repris dans K8 (T1) puis réfuté comme règle de parking | Créance hors sauvegarde MiCA à rachat discrétionnaire ; OIR BTC = position directionnelle sans stop, « NOT REGULATED ACTIVITIES IN THE EEA » |
| R16 | Bonus de dépôt OKX Europe comme « candidat de recherche » | Promotion marketing | 8 % × 500 = 40 USD **si** session ouverte et éligible ; **0 par règle** sur un capital déjà déposé (« Balances held before enrollment cannot count », CGU màj 2026-10-16) | — | REJETÉ (exclu par mandat) ; information dans K8 | Les deux sessions documentées (opt-in ≤ 2026-07-31 ; 6–31 août 2026) sont closes ; aucune session trouvée au 2026-10-20 ; retrait-redépôt = « irregular conduct » |
| R17 | Rotation « funding farming » autonome (shorts couverts vers les X-Perps au funding le plus élevé) | Sélection sur le funding réalisé | Amortissement d'une rotation 0,30 % de N = 10 jours de F = I | M4 : sélection sur funding = prévision de funding (voisin de D4, clos) | REJETÉ comme candidat propre ; conservé comme bras descriptif (d) de K1, jamais autonome | Petits X-Perps à plafond ±1 %, intervalle 4 h, carnets spot EEE minces |

---

## 3. Protocole rédigé : K8 — Contrôles et trésorerie

Fichier : `tradingV3-private/research/20261016T2145Z-positive-pnl-structural/design/K8-PREREGISTRATION-DRAFT.md`
(841 lignes, 93 236 octets, sha256 `6abbb6cbfbf39d0512223bc9ab2d3c423f9b2301d6524744ba140ff891b76fc2 (amendé le 2026-10-20 après la revue de la PR #519 ; ancien sha256 cbf3ed9c…)` au 2026-10-20 ; statut
`DRAFT_NOT_FROZEN`, aucun sha256 de gel). Relectures : `review/K8-quant.md` (`NEEDS_CHANGES`, 7 constats majeurs, 10 mineurs,
aucun bloquant) et `review/K8-cto.md` (`NEEDS_CHANGES`, 8 majeurs, 10 mineurs, aucun bloquant) ; traitement constat par constat
au §13 du brouillon (Q1–Q10, T1–T18 : tous « appliqué » sauf trois « partiel » motivés). Aucun autre protocole n'a été rédigé :
K1–K7 sont réfutés et aucun n'atteint le stade du brouillon.

**Nature (à lire d'abord).** K8 n'est pas un candidat de PnL. Il fige (i) la façon dont la borne EUR nette et les bornes USD
théoriques sont calculées et affichées dans les tableaux des K1–K7, et (ii) mesure une fois, prospectivement, si le **taux
d'offre affiché** du seul produit USD atteignable depuis le compte (Simple Earn EEE USDC, OKX Europe Ltd) est disponible et
≥ borne pendant 13 relevés hebdomadaires. Un PASS ne découvre aucun avantage, ne constate **aucun rendement réalisé** (le
produit ne rémunère que les fonds appariés, part appariée u(j) non observable sans souscription) et n'autorise aucune stratégie.

**Position.** Aucune jambe dérivée. Trois chemins papier statiques construits une fois à T0, sans rééquilibrage :
P-EUR (500 USDT → USDC → vente USDC-EUR → SEPA → Livret A, repli LEP → LDDS → fin de chaîne) ; P-USD (souscription papier
Simple Earn USDC, valorisée en EUR à la fin **nette de la conversion de sortie**, mêmes frais et glissement que P-EUR) ;
P-REF (SOFR, €STR, fonds monétaire illustratif : bornes théoriques non achetables, intérêt simple, base actual/360, fixings
pondérés par jours calendaires).

**Capital.** 500 USDT → 500 × (1 − c) USDC avec c = 0,10 % pessimiste (One Way Convert, taux coté UNKNOWN, aperçu relevé à T0
seulement) ; K_EUR = 500 × (1 − c) / fx₀ × (1 − f − s) = 444,77 EUR (cas central : c = 0, f = 0,10 %, s = 5 pb, fx₀ = 1,1225)
ou **443,65 EUR** (cas pessimiste intégral qui lie le brouillon tant que le barème du compte et l'identité EEE de l'interface ne
sont pas vérifiés : c = 0,10 %, f = 0,20 %, s = 10 pb). Livret A sur la fenêtre : K_EUR × 1,70 % × q/24 avec q = 5 quinzaines
entières = 1,58 EUR (borne à battre) ; prorata 91/365 = 1,89 EUR (convention de comparaison).

**Gate 1 déclaré et son coût en données.** Une exécution scriptée (`k8_gate1_fiat.py`, hash au gel, après consentement P12),
sur séries **fiat uniquement** : €STR et change de référence EUR/USD (BCE Data Portal), SOFR (NY Fed), taux réglementés
(Légifrance / Banque de France), du 2024-01-01 au dernier jour publié avant l'exécution (≤ 2026-11-01). Sortie : table des bornes
historiques pour les fenêtres Gate 1 déclarées par K1–K7 et variabilité du change à 91 jours. **Coût en données : nul** —
aucune donnée crypto, aucune ligne du registre §3.5 du BRIEF touchée, séries fiat librement relisibles ensuite ; **aucun
hôte OKX n'est interrogé par script, ni avant ni après le gel** (ancienne série S5 retirée). Règle unique : les séries fiat
sont lues sur toute la période, y compris 2026-10-14..31, car ce ne sont pas des données de venue (BRIEF §1).

**Gate 2 prospectif et sa durée.** T0 = 2026-11-02 00:00 UTC (lundi) si le gel est antérieur au 2026-10-26, sinon le premier
lundi ≥ gel + 7 j ; fin = min(T0 + 91 jours ; 2027-01-31 23:59:59 UTC) — la fenêtre ne dépasse jamais la période de certitude
de l'arrêté du 28/07/2026. N = 13 relevés hebdomadaires manuels de Simple Earn USDC (lundi 08:00 UTC ± 4 h, propriétaire ou
délégué, capture hachée, statuts VALIDE / LATE / MISSING), relevé hebdomadaire des termes de l'accord (date « Last Updated » +
sha256) et de la session de bonus ; enregistreur fiat quotidien lecture seule (hôtes BCE et NY Fed seulement, lancement manuel
par défaut, hors Docker, hors `fwd-okx-*`, dossier `design/k8/`). Rodage 7 jours avant T0. Verdict unique au plus tôt le
2027-02-01 ; futilité au jour 43 (lundi 2026-12-14) sur les relevés 1–6 (trois comptages : listé < 50 %, plancher ≥ 50 %,
produit suspendu).

**Critères (fixés, évalués une fois).** T0 : `K8_T0_BOUND_PUBLISHED` si la borne atteignable EUR est publiée et sourcée (taux
en vigueur, capacité ou repli déclaré, K_EUR, fx₀/fx_fin, devise de compte) ; `K8_T0_REF_COMPLETE` si les lignes €STR/SOFR sont
complètes (manquants ≤ 5 % des jours ouvrés) ; la borne atteignable **peut valoir 0 %** (livrets pleins, LEP inéligible, aucun
fonds monétaire nommé au gel). T1, tous requis pour `K8_T1_PASS`, dénominateur = relevés valides N_v : C1 USDC listé sur
≥ N_v − 1 relevés ; C2 ā × φ × 0,686 ≥ 1,70 % (⇔ ā × φ ≥ 2,48 % ; φ = 0,85 ferme si brut/net des 15 % est UNKNOWN ⇒ ā ≥ 2,92 %),
à change constant, règle de lecture du taux affiché écrite (fourchette → borne basse ; APY → taux simple
365 × ((1 + APY)^(1/365) − 1) ; « up to » → UNKNOWN → MISSING) ; C5 termes stables (frais ≤ 15 %, plancher ≥ 0,01 % APR, produit
non résilié ; un changement daté est recalculé par règle) ; C6 taux affiché ≤ 0,05 % sur au plus un relevé (seuil arbitraire
déclaré = 5 × plancher). `K8_T1_INCONCLUSIVE` si m ≥ 2 relevés MISSING ou devise de compte non déclarée ; `K8_T1_FAIL` immédiat
si USDC Flexible est retiré. C3 (hurdle €STR) **retiré** : le critère choisi était celui que le produit était le plus susceptible
de franchir ; SOFR et €STR passent en rapport avec « attendu sous le SOFR » déclaré au gel. C4 (rachat observé) **reclassé en
rapport** : non falsifiable en papier. Aucune p-value revendiquée.

**Ce qu'un verdict autorise.** T0 : usage obligatoire de la table des bornes (Livret A q/24 et prorata, €STR, SOFR en
actual/360, impôt des deux côtés, devise de compte, ligne de change) comme annexe « contrôle » de tout K1–K7. Dès T0 et quel
que soit le verdict : la ligne « Simple Earn USDC — descriptive, non admise, borne supérieure affichée » est inscrite dans les
tableaux Gate 2 des K1–K7 et n'en modifie aucun verdict (le verdict T1 du 2027-02-01 est postérieur aux verdicts Gate 2 déclarés
des K1–K5, ≈ 2027-01-04). T1 PASS : changement d'étiquette a posteriori et admissibilité pour les protocoles futurs, **valable six mois
après le verdict T1** (au plus tard le 2027-08-01 ; le brouillon disait « expirant le 2027-01-31 », soit avant son propre
verdict — corrigé après la revue de la PR #519) ; information « tout parking réel > 5 USDC excède la limite de 1 % par construction ». **N'autorise jamais** :
substituer Simple Earn au SOFR comme hurdle USD ; lire le PASS comme un rendement réalisé ou une liquidité ; un avis de
conformité MiCA ou fiscal ; une souscription, une conversion, une clé, un drapeau.

**Risques et bornes.** Marché : néant (USDC au pair, aucun levier, aucune liquidation, aucun funding). Change EUR/USD si la
devise de compte est l'EUR : variation EUR de 500 USD = 500 / fx_fin − 500 / fx₀ ; 1 % = 4,45 EUR ≈ la limite totale de 1 % ;
**rapporté, jamais coupé** (c'est la ligne manquante de tous les tableaux K1–K7 ; détenir 500 USDC est déjà une exposition USD
non couverte de 100 % du capital, à acter en P1). Crédit : créance non garantie sur OKX Europe Ltd hors sauvegarde MiCA, perte
possible 100 % ⇒ exposition admissible = 5,00 USD / 100 % = 5 USDC (revenu indicatif 5 × 3,5 % = 0,18 USD/an) ; K8 ne recommande
aucun parking. Liquidité : non mesurable en papier ; seuls les avis publics d'OKX sont rapportés. Produit retiré : résiliation
« at any time at its sole discretion » (accord §5.1) ⇒ FAIL. Livret A : révision par arrêté au 2027-02-01, hors fenêtre.

**Inconnues décisives (du brouillon, à lever avant le gel).** (1) Base de calcul de l'€STR (actual/360 attendu, UNKNOWN sur
la page BCE, P8). (2) Attestation de l'auteur du premier brouillon sur l'ordre entre l'exposition de K8-ops à `okx.com/en-eu/earn`
(2026-10-20, consigne d'ignorer tout taux ; aucune valeur copiée) et le choix du seuil 0,05 % (P13). (3) Barème du compte et
identité EEE de l'interface (P4a) : décident f, s et K_EUR (444,77 vs 443,65 EUR). (4) Libellé du taux Simple Earn (est. APY /
APR / historical) et brut/net des 15 % (P5a) : si UNKNOWN, φ = 0,85 ferme et la moyenne peut porter sur un indicateur
historique. (5) Capacité Livret A / LDDS et éligibilité LEP (P2) : si tout est plein, borne atteignable = 0 %. (6) Devise de
compte (P1). (7) Référence de la case d'option pour le barème progressif (« 3CN » selon K8-cto, à confirmer) et application du
PFU 31,4 % aux revenus de prêt crypto (P10). (8) Date de gel : au-delà du 2026-10-26, T0 glisse (2026-11-09 ⇒ 12 lundis ;
2026-11-16 ⇒ 11 lundis, q = 4) et la futilité est redatée au gel.

**Check-list propriétaire (Annexe A du brouillon ; lecture d'interface et de documents personnels ; aucun ordre, aucune
souscription, aucun dépôt, aucun virement).** Compte OKX Europe : A1 type de compte et pied de page `okx.com/en-eu` ;
A2 aperçu du One Way Convert pour 500 USDT **à T0 seulement**, fermé sans confirmer ; A3 paire USDC-EUR visible ;
A4 compte bancaire EUR déjà utilisé pour un dépôt (condition de première sortie SEPA) ; A5 Simple Earn Flexible : USDC
listé, souscriptible, libellé du taux **sans reporter la valeur**, brut/net, texte du rachat, « Last Updated » de l'accord ;
A5bis dialogue de souscription jusqu'à l'écran de confirmation **sans confirmer** (ou UNKNOWN) ; A5ter gageabilité : ne pas
chercher à y répondre ; A6 session de bonus ouverte ? ; A7 devise de règlement du sous-compte X-Perps (information K1–K7) ;
A17 état de base (aucune souscription ouverte, localisation des 500 USDT). Épargne et fiscalité : A8 Livret A (existence,
encours, marge ≥ 445 EUR), LDDS ; A9 RFR 2024/2025 ≤ 23 028 € (LEP) ; A10 cessions crypto 2026 > 305 € ? plus-values latentes
(prorata 150 VH bis) ; A11 conseil fiscal (catégorie des revenus Simple Earn et bonus, prélèvements sociaux 17,2 % ou 18,6 %) ;
A18 banque du Livret A = banque SEPA cible ? ; A20 option barème progressif. Décisions écrites : A12 devise de compte et phrase
actée ; A14 acceptation de la nature de la mesure et de la durée ≤ 13 semaines ; A15 consentement à l'exécution du Gate 1 fiat ;
A16 chemin de repli T0 (LEP → LDDS → fin, ou fonds monétaire nommé avec M1 sourcé) ; A19 charge de relevé (13 lundis) et
délégué éventuel.

**Note technique (Annexe B du brouillon).** K8 s'exécute avec trois scripts de lecture seule hors pile (hôtes BCE et NY Fed) et
un relevé manuel ; il ne demande aucune modification de TradingV3 (USDT codé en dur dans `OkxAccountGateway.php`,
`NetPnlCertificationService.php`, `ModeContractValidator.php` ; `instType=SWAP` dans ≈ 57 chaînes ; hôte live EEE refusé par
`OkxRestEndpointGuard`), aucune clé, aucun conteneur ; la boucle MTF n'est pas le véhicule.

---

## 4. Angles morts du brief relevés, et ce qui a été vérifié

### 4.1 Angles morts du brief (relevés par a1–a4 et les sceptiques)

1. **Référence sans risque** (a4) : le capital est en USD → SOFR 3,89 % (NY Fed, 2026-10-15), pas €STR 2,440 % (BCE,
   2026-10-15) ; le brief et CS-FUND comparaient à l'€STR. Sur 480 USD : 18,67 contre 11,71 USD/an. **Correction du desk** :
   aucun des deux n'est achetable sur OKX Europe ; les contrôles atteignables sont le Livret A (EUR, net, sans risque) et
   Simple Earn USDC (USD, risqué) ; les deux côtés de toute comparaison doivent être taxés (K8-eco §5).
2. **Impôt et change** (a4, a2) : PFU absent du brief (31,4 % depuis 2026 selon service-public A18796 ; application aux actifs
   numériques et aux revenus de prêt crypto UNKNOWN) ; fiscalité des récompenses de staking UNKNOWN ; risque EUR/USD d'un résident
   français non modélisé : 1 point de change = 4,45 EUR, du même ordre que les surplus bruts annoncés de K1–K7 (8–63 USD/an).
3. **Asymétrie fiscale des structures couvertes** (K1-ops §6, K3-ops, K4-ops) : jambe X-Perp ou daté = instrument financier
   MiFID (a priori art. 150 ter CGI, pertes imputables sur profits de même nature) ; jambe spot = actif numérique (art. 150 VH bis,
   sursis crypto-crypto, pertes non reportables) ; aucune compensation entre régimes ⇒ une couverture n'est pas fiscalement
   neutre (exemple K1-ops : BTC −30 % sur la jambe BTC → PnL après impôt −1,5 USD pour +12,5 de funding). Seuls K5/K6/K7
   (deux jambes MiFID) seraient symétriques. Qualification exacte **UNKNOWN**, conseil requis.
4. **Quarantaine violée par le brief lui-même** (a4) : prix du 2026-10-16 (§3.2) et volumes du 2026-10-14 (§3.2,
   TRADABLE-INSTRUMENT-UNIVERSE) sont dans la fenêtre 2026-10-14..31 ; a2 réutilise la liquidité du 2026-10-14 ; les
   instantanés de métadonnées de la mission (2026-10-16/17) et des sceptiques (2026-10-20) sont datés dans la fenêtre —
   autorisés par le BRIEF §1 comme métadonnées, à **re-figer après le 2026-10-31** (la page x-perps-contract-specifications a été
   mise à jour le 2026-10-19).
5. **Contradiction git** (a4, a1) : §6 autorise `git show origin/<branch>:<path>` alors que §1 interdit toute opération git ;
   a1 a lu les copies de worktree sur disque ; a2/a3 ont cité `git show`. Le dossier privé n'est pas un dépôt git (K8-cto) :
   l'intégrité repose sur les `SHA256SUMS`.
6. **Licence des données** (a4) : le brief dit « non documentée » ; l'API Agreement OKX (màj 2026-07-28) §1.8 définit les Market
   Data (prix, carnets, funding…), §9.4 autorise l'usage « personal, non-commercial trading and account management » et interdit
   « publish, display … to any third party » ; stockage et recherche non nommés ⇒ toute table chiffrée de funding doit rester dans
   `tradingV3-private` ; visibilité du dépôt `pivox/tradingV3` à trancher (K1-ops).
7. **Datés en EEE** (a4, a1) : l'argument par l'hôte est nul (liste FUTURES de eea.okx.com identique à www.okx.com : 272 ids dont
   12 inverses ; 509 SWAP servis) ; la preuve est la page OEM x-perps-contract-specifications (2026-10-13, màj 2026-10-19) :
   « listés EEE, non vérifiés au compte » ; les inverses ne sont pas offerts ; le brief omettait les 12 inverses de son propre
   snapshot. Tailles affichées sur la page (BTC 0,01 BTC) différentes de l'API (ctVal 0,0001 BTC, minSz = lotSz = 1) : facteur 100
   à trancher dans l'InstrumentSpec. Règle de cotation du dossier fausse : mensuels cotés le vendredi d'expiry du précédent
   (listTime 261030 = 2026-08-28, 261127 = 2026-09-25), pas « le 1er jour du mois d'expiry ».
8. **Terme d'intérêt 0,01 %/8 h = X-Perps crypto seulement** (a4, a1) : interestRate = 0 vérifié pour XAU, XAG, CL, TSLA, SPY,
   QQQ, NVDA, ANTHROPIC, OPENAI ⇒ aucun portage de règle sur TradFi, or, pré-market ; le cap XAU « UNKNOWN » du brief est ±0,75 % ;
   la page funding ne nomme pas les X-Perps (preuve = champs API).
9. **Options EEE réelles** (a4) : OEM documente BTC/ETH/SOL/XAU en USDC (page Learn 2026-09-11) ; le brief disait « doubtful » et
   comptait 1 414 options inverses ; tailles : ETH-USD_UM 0,01 ETH, SOL 0,1 SOL, BTC 0,01 BTC ; a2 avait lu la famille inverse
   (0,1 ETH), a1 avait rejeté sur « aucune page EEE ». Conflit tranché sur les snapshots (CANDIDATS §0.2). **Mais** (K6-ops,
   K7-ops) : les pages normatives EEE ne contiennent pas le mot « option », la vente est conditionnelle (« If option selling is
   available to your account »), les listes sont identiques sur les deux hôtes ⇒ `ACCOUNT_ELIGIBILITY_UNVERIFIED`.
10. **Omissions de règles** (a4, a1, a2, sceptiques) : frais de règlement 0,01 % des datés ; Daily Compression OEM §1.11 ;
    rachat discrétionnaire de Simple Earn (accord prime sur la FAQ « instant ») ; frais spot prélevés dans la crypto reçue ;
    barème « Spot-only » 0,20 % ; fenêtre de règlement 30 min (page EEE) vs « final hour » (page globale) — la page EEE
    gouverne ; devise de règlement USD-M = **USD par défaut**, modifiable seulement sans position ouverte, gains non convertis
    automatiquement en USDC ; One Way Convert : frais UNKNOWN ; marge vendeur d'options publiée (help/ii-option-margin) ;
    RFQ minimum 1 000–10 000 USD ; intérêt horaire Kraken sur pertes latentes.
11. **Horizon « semaines »** (a3, tous les sceptiques) : les régimes de funding durent 2 à 12 mois ; un Gate 2 de 8 semaines
    ne mesure qu'un régime ; à F = I le surplus de K1 (a) sur 56 jours vaut 0,26 USD = 0,11 % de base, tiré au sort par le bruit ;
    pour K3/K4/K5 un seul tirage de base avant 2027-01 ; verdict de rendement honnête ≥ 3 mois avec futilité, robuste à
    12 mois (CS-FUND §6.4) ; CS-FUND §0.4 interdit toute lecture rétrospective ⇒ amendement écrit requis avant gel.
12. **Gate 1 rétrospectif** : profondeur de `public/funding-rate-history` ≈ 3 mois observés (BRIEF §3.4), rétention documentaire
    UNKNOWN (doc API tronquée) ⇒ la fenêtre « 2026-04-01 → 2026-10-13 » décrit peut-être une série inexistante ; `after =
    2026-10-14T00:00Z` obligatoire ; statut `consumed_equivalent` ⇒ consentement, lecture unique, « tuer seulement » ;
    datés OKX Europe absents du registre H3-DATA-ELIGIBILITY.
13. **ADL et indemnisation** (a3) : OKX classe par leverage PnL % et ferme d'abord la jambe gagnante, sans préavis, au mark
    ou au prix de faillite ; un short couvert gagnant de +30 % se classe en tête de file ; « spot vendable en minutes » suppose
    une surveillance et un chemin de vente spot qui n'existent pas dans TradingV3. ICS Malte (90 % / 20 000 €) couvre OEM
    (CGU §19.10) mais pas les actifs chez OKX Europe Ltd (CASP) ; MiCA sans fonds de garantie.
14. **Collatéral du même actif** (a1, a4, a2) : l'admission du spot BTC/ETH (ou de BETH/OKSOL) comme marge d'un short X-Perp
    n'est écrite nulle part (page whats-selected-margin-eea, màj 2026-09-03 : « check your X-Perps margin settings ») ; c'est le
    fait qui sépare 5,3 % de 8,5 % de rendement de règle ; décotes relevées 2026-10-20 (compte global) : BTC 0,98, ETH 0,98,
    BETH 0,98, SOL 0,97, OKSOL 0,97, USDC 0,995 ; valeurs X-Perps EEE visibles seulement dans l'app. Clause OEM d'appel de marge
    « collatéral net des décotes < 30 % de la valeur du contrat » : interprétation UNKNOWN (fixe les seuils de (c)).
15. **Deux entités et gage, pas transfert** (K1-ops) : CGU OEM §1.9.1 (OEM ne détient ni fonds ni crypto), §1.9.2 (actifs chez
    OKX Europe Ltd, ségrégués), §9.1.2 (gage), §1.9.5 (perte réalisée : fiat puis crypto liquidés par OKX EEA, puis instruments
    financiers) ⇒ la jambe spot d'une couverture peut être vendue sans ordre du propriétaire.
16. **Requalification réglementaire** (K1-ops, K3-ops) : circulaire MFSA 2026-03-09 relayant ESMA35-243228190-8024 (2026-02-24) :
    perpétuels « likely » CFD « irrespective of the commercial name » ; datés cash-settled « would likely fall in scope » ; KID
    PRIIPs exigé, aucun trouvé ; options hors périmètre CFD. « Délisting avec préavis » : aucune garantie contractuelle
    (§12.1/12.2 « without notice »).
17. **MiCA et staking / lending** (a2) : art. 50 tue le carry des stablecoins ; statut MiCA du staking UNKNOWN ; cons. 94
    invoqué par OKX pour le prêt alors que Coinbase (2024-12) et Kraken (2025-06) ont retiré la rémunération des stablecoins en
    EEE ; aucune Q&A ESMA sur l'art. 50 au 2026-10-20 ; accord On-chain Earn de droit anglais, arbitrage HKIAC.
18. **TradingV3 non prêt** (BRIEF §3.1, a4) : connecteur USDT-SWAP seulement, pas de résolveur X-Perp, pas de bundle spot OKX,
    hôte live EEE refusé par conception ; K5 est le plus proche du code existant, K1–K3 exigent un bundle spot et deux entités.
19. **Magnitude** (tous) : à 500 USDT le meilleur cas de règle vaut 25–63 USD/an bruts selon le candidat, contre SOFR 18,67–19,45
    et Livret A ≈ 7,56 EUR ; aucun candidat ne dépasse quelques dizaines d'USD/an après impôt. Le brief le dit (§7) ; les
    dossiers initiaux le disaient moins.

### 4.2 Ce qui a été vérifié (métadonnées et documents, aucune valeur de marché)

| Fait | Source (date) | Statut |
|---|---|---|
| interestRate = 0,0001 sur les X-Perps crypto (124/125) ; = 0 sur XAU, XAG, CL, TSLA, SPY, QQQ, NVDA, ANTHROPIC, OPENAI | H3-A raw/xperp-funding-rules-blinded.json ; a1 metadata/funding-rules-XAU-XPERP-blinded.json ; a4 metadata/funding-rules-blinded-tradfi-20261017.jsonl (2026-10-17) | mesuré (champs de règle) |
| Formule du funding, clamp ±0,05 %, escalade 8 → 4 → 2 → 1 h | okx.com/en-eu/help/perps-funding-fee-mechanism (màj 2026-08-27) | contractuel |
| Listes FUTURES / SWAP / OPTION identiques sur eea.okx.com et www.okx.com | a4 metadata/eea-FUTURES.json vs global-FUTURES.json (2026-10-17) ; K6-ops-metadata (2026-10-20) | mesuré |
| Datés BTC/ETH/SOL/XAU-USD_UM listés pour l'EEE par OEM (« Up to 10x », cash settled, moyenne 30 min, expiry 08:00 UTC) | okx.com/en-eu/help/x-perps-contract-specifications (2026-10-13 ; section crypto màj 2026-10-19) | contractuel ; non vu au compte |
| listTime BTC-USD_UM-261225 = 2026-06-12 08:10 UTC ; ETH-USD_UM-261225 = 2026-06-15 ; 260807 et 260925 non servis (code 51001) | K3-ops-metadata/, K5-ops-metadata/ (eea.okx.com public/instruments, 2026-10-20) | mesuré |
| Paliers : X-Perp BTC/ETH mmr 0,65 % / imr 2 % ; SOL X-Perp mmr 1,0 % / imr 2 % ; datés BTC-USD_UM mmr 2 % / imr 5 % / lever 20 | a1 metadata/position-tiers-*.json (2026-10-16) ; K2-eco-metadata/tiers-*.json (2026-10-20) | mesuré |
| Décotes palier 1 : BTC 0,98, ETH 0,98, BETH 0,98 (0–500), SOL 0,97, OKSOL 0,97, USDC 0,995 ; minDiscountRate BETH 0,8, OKSOL 0,7 | a1 metadata/discount-rate-*.json ; K2-eco-metadata/, K2-ops-metadata/discount-rate-*.json (2026-10-20, compte global) | mesuré ; valeurs X-Perps EEE UNKNOWN |
| Options USDⓈ-M : ETH-USD_UM ctMult 0,01 ETH, tick 0,2, minSz 1 (506–512 instruments) ; SOL-USD_UM 0,1 SOL (168–182, 4 échéances) ; BTC-USD_UM 0,01 BTC ; grille hebdo ETH 50 USD, mensuels 100 | a4 metadata/eea-OPTION-*-USD_UM.json (2026-10-17) ; K6-ops-metadata (2026-10-20) | mesuré |
| Marge vendeur options : IMR = [max(0,1 ; 0,15 − OTM/F) × MF × index + mark] × ctMult × q ; MMR = (0,05 × MF × index + mark) × … ; Margin Factor palier 1 = 1,00 ; PM = VIP3 | okx.com/help/ii-option-margin (màj 2026-08-05) ; K6-eco-metadata/position-tiers-OPTION-*.json | contractuel (applicabilité EEE UNKNOWN) |
| Frais EEE Regular : spot 0,08 / 0,10 % ; futures 0,02 / 0,05 % ; règlement 0,01 % ; options min(taux × notionnel ; 7 % prime) ; Spot-only 0,10 / 0,20 % | okx.com/en-eu/fees (extrait H3-A 2026-10-15 ; page non rendue depuis) ; trading-fee-rules-faq-eea (màj 2026-10-07) | contractuel (barème du compte UNKNOWN) |
| Kraken Pro niveau 1 : 0,40 % maker / 0,80 % taker ; FF_XBTUSD_261225 expire 2026-12-25T08:00Z ; intérêt sur pertes latentes « à partir de 0,0025 %/h », palier 0–100 k$ 0,0030 %/h ; conversion automatique 0,2 % | kraken.com/features/fee-schedule (2026-10-20) ; futures.kraken.com instruments (K4-ops-metadata) ; support.kraken.com 4844392809620 (2026-10-08) ; interest-and-conversions-unified-wallet (2026-10-08) | contractuel |
| CGU OEM (2026-10-02) : §1.9.1–1.9.5 gage et cascade, §1.11 Daily Compression, §12.1/12.2 sans préavis, §16.2 données, §19.10 ICS | okx-europe-market-ltd-oem-terms-of-service | contractuel |
| Simple Earn EEE : 15 % des Returns, plancher 0,01 % APR, rachat « delayed or restricted », fonds non appariés non rémunérés, hors MiCA par déclaration | eea-simple-earn-user-agreement (màj 2026-08-28) ; eea-simple-earn-faq (2026-08-05) | contractuel |
| Bonus de dépôt : « Balances held before enrollment cannot count » ; deux sessions closes ; aucune ouverte au 2026-10-20 | deposit-match-rewards-session-terms-and-conditions-eea (màj 2026-10-16) ; learn/rewardmaxxing ; learn/mica-deposit-bonus-campaign | contractuel |
| Taux fiat : SOFR 3,89 % (2026-10-15), 3,88 % (2026-10-16), base actual/360 ; €STR 2,440 % (2026-10-15), 2,441 % (2026-10-19) ; BCE EUR/USD 1,1225 (2026-10-19) ; Livret A 1,70 % (arrêté 28/07/2026, → 2027-01-31) ; LEP 2,50 % ; PFU 31,4 % | a4 metadata/sofr-last-20261017.json ; newyorkfed.org ; ecb.europa.eu ; service-public F2365, F2367, A18796 | mesuré / contractuel |
| API Agreement OKX §1.8, §9.2, §9.4 (usage personnel, pas de redistribution) | okx.com/en-eu/help/okx-api-agreement (màj 2026-07-28) | contractuel |
| Circulaire MFSA 2026-03-09 / ESMA35-243228190-8024 (perpétuels « likely » CFD, KID exigé) | sources citées dans K1-ops, K3-ops | contractuel (portée exacte sur OEM UNKNOWN) |

Non vérifié et décisif (UNKNOWN de règle, tranchable seulement au compte) : spot du même actif comme Selected Margin d'un
short ; BETH/OKSOL dans la liste de marge X-Perps ; datés et options visibles au compte, bouton « Sell » ; devise de
règlement USD-M du sous-compte ; barème du compte ; disponibilité des paires BTC-USDC / ETH-USDC et du transfert
USDC → marge ; KID PRIIPs ; passeport France d'OEM sur le registre ESMA / AMF ; coût du One Way Convert.

---

## 5. Ce que la littérature dit (littérature, pas une prévision)

Source : `a3-literature/NOTES.md` (journal URL + heure UTC) et PDF dans `a3-literature/pdf/` (sha256 : He et al. arXiv
2212.06888v5 `c6d01e99…`, Borri et al. arXiv 2510.14435v4 `e955f3f8…`, Christin et al. CMU CarryTrade v1.0 `72e7557c…`,
Gornall-Rinaldi-Xiao AEA 2026 `f5f71f69…`, AFA 186844 `9e7df02c…`). Tous les chiffres ci-dessous sont des agrégats publiés
sur d'autres venues (Binance USDT, BitMEX, Deribit, Kraken) et d'autres périodes ; aucun ne décrit OKX Europe ni l'avenir.

- **(a) Carry des perpétuels (littérature, pas une prévision).** Le short perp couvert encaisse un flux positif en moyenne :
  BIS WP 1087 (Schmeling, Schrimpf, Todorov) « moyenne > 10 %/an », pics 40–60 % — période, venues et tableaux **UNKNOWN**
  (PDF bloqué) ; CMU (Binance, 2020-08 → 2022-06) BTC contrat Tether 21,84 %/an, par époque 39,0 → 19,6 → 14,7 → 7,6 %, ère de
  levier 125× 33,0 % vs ère 50× 10,2 % ; Borri et al. (Binance, 2020-08 → 2025-05) funding ≈ 8 %/an, Sharpe 6,45 plein
  échantillon, 4,06 depuis 2024, **négatif en 2025** ; He et al. (Binance, 2020-01 → 2024-03) composante funding BTC 5,06 pt/an,
  par année +7,68 / +15,31 / **−1,94 / −0,94** / +6,03 (2024 partiel). Le flux est épinglé au terme d'intérêt une grande partie
  du temps (CMU « souvent épinglé à 0,01 % par 8 h » ; BitMEX T3-2025 : exactement 0,01 % sur 78 % du trimestre pour BTC,
  88 % pour ETH — communiqué promotionnel). Régimes négatifs de 2 à 12 mois (K33 mai → juil. 2021 ; He 2022–23 ; Borri 2025) ;
  compression séculaire (écart −11 %/an, He). La composante funding est « sérialement corrélée » (CMU) : un filtre sur le
  funding passé gonfle la probabilité d'un PASS prospectif. Pièges : frais Binance 0,18–6,75 pb contre 10/5 pb ici ; survie des
  exchanges ignorée ; Sharpe in-sample.
- **(b) Cash-and-carry sur futures datés.** Base trimestrielle « grande, volatile, converge vers zéro à maturité » (Gornall) ;
  rendement de convenance dominé par la demande de levier et le capital d'arbitrage limité, pas par les taux (BIS) ; sur
  Kraken/BitMEX ≤ 2023 la base médiane à 90 jours est entre −1 % et +4 % non annualisée selon le régime (≈ −4 à +16 %/an), haute
  après les hausses, basse après les baisses ; les trimestriels « move 8–10 % more than the spot when shocks occur »
  (favorable au short daté en baisse, défavorable en hausse) ; spreads des trimestriels 53–71 % plus larges que les perps ;
  écart CME / ETF persistant 2,58 pt/an (Mallory 2026, n = 386). Conséquence pour K3/K4 : l'équilibre contre la trésorerie
  (hurdle ≈ 10–11 %/an sur le capital) n'est atteint, dans ces échantillons, qu'en régime d'euphorie.
- **(c) Spread perp vs daté.** Aucune étude ne documente une prime structurelle sur la différence ; les deux primes ont le même
  déterminant (demande de levier) et co-varient ; dominé par (b).
- **(d) Différentiels de funding inter-venues.** Taux « similaires entre exchanges par arbitrage », divergences seulement dans les
  épisodes extrêmes (mars 2020, FTX nov. 2022, He) ; écarts de prix inter-venues plus larges entre pays (Makarov-Schoar 2020) ;
  distribution 2024–2025 des différentiels **UNKNOWN**.
- **(e) Rééquilibrage.** Le rendement de diversification ≈ ½(Σ w_i σ_i² − σ_p²) existe comme écart géométrique (Willenbrock
  2011) mais n'augmente pas l'espérance de valeur terminale (Chambers-Zdanowicz 2014) ; corrélation intra-crypto 80–85 % ;
  net négatif avec 50 pb de coûts (Chen-Cai 2025).
- **(f) Prime de variance.** Sur options BTC Deribit : σ²_Q 0,72 vs σ²_P 0,58 (2017-2022, arXiv 2410.15195), prime dépendante du
  régime (plus faible en haute volatilité, négative mars–avril 2020) ; aucune étude ETH ni chaînes USDC EEE ; options BTC
  ≈ 10× plus illiquides que les options actions (Guo et al.) ; écart ATM ≈ 3 % de la prime (Chi & Hao). Box TradFi : SPX box rate
  = Treasury + 35 pb en moyenne 1996–2023 (NY Fed, van Binsbergen et al. 2021), garanti par une CCP — ce que l'OEM n'a pas.
- **(g) Contrepartie et ADL.** Fermeture d'exchanges 45 % de 40 (2010–2013, vie médiane 381 j, Moore & Christin 2013) ; 38/80
  fondés 2010–2015 fermés, piratage ⇒ ×13 (2018) ; FTX = commingling ; ADL = socialisation des pertes fermant d'abord les positions
  gagnantes (OKX, màj 2026-08-11 ; Chitra 2025 ; Campbell et al. 2026) ; Hyperliquid 2025-10-10 : 2,1 Md USD fermés en 12 min.
- **Limites de la lecture** : texte intégral du BIS WP 1087 non accessible (anti-bot) ; Werapun et al. 2025 et le rapport
  BitMEX « 9 ans » non accessibles ; EUR-Lex tronqué (art. 70/75 MiCA cités de mémoire) ; pages Coinbase (CFSE, INTX, CGU EEA) et
  Deribit en 403 (connues par résumés) ; Alexander & Imeraj sous embargo.

---

## 6. Ce qui n'a pas été fait, et les décisions attendues

### 6.1 Liste exacte de ce qui n'a PAS été fait

1. **Aucune donnée de marché lue** : aucune valeur de prix, rendement, base, prime, funding, OI, volume ou carnet d'aucun
   instrument crypto, sur aucune venue, courante ou historique ; aucun APY Simple Earn copié (page `okx.com/en-eu/earn` ouverte
   une fois par K8-ops le 2026-10-20 avec consigne d'ignorer tout taux ; seule la liste des actifs a été notée). Exceptions
   déclarées et héritées du brief : trois prix de conversion de taille lus une fois le 2026-10-16 par l'orchestrateur (BRIEF §3.2),
   volumes du 2026-10-14 (TRADABLE-INSTRUMENT-UNIVERSE), APY affichés le 2026-10-16 (a2, métadonnée produit, zone grise).
2. **Aucun gel** : aucun protocole ne porte de sha256 ; K8 est `DRAFT_NOT_FROZEN` ; CS-FUND reste `DRAFT_NOT_FROZEN` sur sa
   branche ; aucun amendement de CS-FUND n'a été écrit.
3. **Aucun Gate 1 exécuté**, rétrospectif ni fiat ; aucune ligne du registre H3-DATA-ELIGIBILITY lue ni créée.
4. **Aucun ordre, aucune souscription, aucune conversion, aucun virement, aucun stake, aucun dépôt** ; aucun compte Kraken ni
   Coinbase ouvert ; aucune clé API ; aucun drapeau `OKX_*` / `DEMO_*` / `HYPERLIQUID_*` touché.
5. **Aucune opération git**, aucune écriture dans le dépôt `tradingV3` (ce rapport est dans `tradingV3-private/…/synthesis/`) ;
   aucune commande Docker ou systemd ; aucun accès à `20261013T100000Z-forward-okx-r1/` ni aux services `fwd-okx-capture` /
   `fwd-okx-evaluate`.
6. **Aucun téléchargement de jeu de données** ; seuls cinq PDF de littérature (hashés) et des instantanés de **métadonnées**
   (instruments, paliers, décotes, champs de règle du funding, SOFR) ont été enregistrés, tous avec `SHA256SUMS`.
7. **Aucune vérification d'interface du compte** (réglages de marge, devise de règlement, onglet Options, barème, Simple Earn) :
   le desk n'a pas accès au compte ; tout ce qui en dépend est UNKNOWN.
8. **Aucune consultation fiscale ni juridique** ; aucun KID PRIIPs lu ; Annexe 2 des CGU OEM (PDF) non lue ; passeport France
   d'OEM non vérifié sur le registre ESMA / AMF ; `okx.com/en-eu/fees` non rendue (barème repris de l'extrait H3-A 2026-10-15).
9. **Incidents déclarés** : a1 a vu une fois un champ `settFundingRate` du X-Perp or en console (valeur non notée, fichier réécrit
   sans champs de valeur) ; a4 a reçu un HTTP 403 au premier essai `urllib` (aucune donnée reçue), relevé réussi via `curl` ;
   a2/a3 ont cité `git show` (contradiction §1/§6 du brief) ; a2 a réutilisé la liquidité du 2026-10-14 ; instantanés de
   métadonnées datés dans la fenêtre de quarantaine (autorisés comme métadonnées, à re-figer après le 2026-10-31).
10. **Aucune correction du dépôt** : la phrase « France is in its EEA list » de `FRANCE-EXCHANGE-ELIGIBILITY.md` §2 (Kraken) n'est
    supportée par aucune page Kraken citée (K4-ops) — correction à faire par le propriétaire, pas par le desk.

### 6.2 Décisions attendues du propriétaire (numérotées)

1. **Devise de compte** pour tous les tableaux (EUR ou USD) et, si EUR, acter que détenir 500 USDC est déjà une exposition de
   change non couverte de 100 % du capital (1 point = 4,45 EUR ≈ la limite totale de 1 %). (K8 P1 / A12.)
2. **K8** : geler ou non le brouillon après les prérequis P1–P13 et la check-list A1–A20 ; accepter sa nature (borne et contrôle,
   aucun PnL, aucun rendement réalisé mesuré), sa durée ≤ 13 semaines bornée au 2027-01-31 et la charge de 13 relevés
   hebdomadaires (P11, A19) ; consentir ou non à l'exécution du Gate 1 fiat (P12, A15). Date limite pour T0 = 2026-11-02 :
   gel avant le 2026-10-26.
3. **CS-FUND / K1** : décider de principe si le brouillon CS-FUND à 12 mois (BRIEF §4) est protocolé **tel quel** (sans Gate 1
   rétrospectif, sans Gate 2 court, avec règle écrite de dénouement forcé et d'ADL) ou **classé sans suite** ; accepter ou non de
   bloquer ≈ 465 USD et les deux créneaux de risque 12 mois pour un gain espéré de 0 à 25 USD (a) / 0 à 42 USD (c) selon un
   régime de funding non lu.
4. **Gate 1 rétrospectif `consumed_equivalent`** (K1/K2/K3/K5) : consentement écrit **ou refus**. En cas de consentement :
   fenêtre réelle = « ce que le point d'accès renvoie avant 2026-10-14T00:00Z » (profondeur probable ≈ 3 mois), code figé avec
   `after = 2026-10-14T00:00Z`, usage « tuer seulement », biais de persistance du funding déclaré, inscription des datés OKX
   Europe au registre avant tout gel.
5. **Vérifications d'interface sans ordre** (minutes ; les seules qui changent une économie) : (a) réglages de marge X-Perps du
   compte EEE : BTC, ETH spot, BETH, OKSOL présents dans la liste de marge ? décotes affichées ? un short BTC-USD_UM_XPERP
   peut-il être porté par du BTC spot posé en collatéral ? (tranche K1c et K3 auto-collatéralisé) ; (b) sous-compte X-Perps →
   « USD-M settlement currency » : USD ou USDC ? à fixer sur USDC avant toute position ; (c) datés BTC/ETH-USD_UM-261225 visibles
   et négociables ? modes de marge ? ; (d) onglet Options, chaîne ETH-USD_UM, bouton « Sell » ou « Buy » seul, marge affichée pour
   une jambe courte sans valider ; (e) page des frais : « Spot & derivatives » ou « Spot-only », niveau Regular ; (f) Simple Earn
   USDC listé, libellé du taux (sans valeur), rachat ; (g) paires BTC-USDC / ETH-USDC et transfert USDC Funding → Trading ;
   (h) KID PRIIPs X-Perps / datés / options dans l'espace documents ; (i) aperçu du One Way Convert pour 500 USDT **à T0
   seulement**, sans exécuter.
6. **Limites de risque des structures couvertes** (BRIEF §2) : écrire la règle — perte maximale par structure, distance de
   liquidation ou d'appel de marge (palier API ou clause OEM 30 %), stop de base ou pas de stop (convergence contractuelle),
   règle de dénouement forcé (ADL, suspension), coupe-risque sur le short seul ou sur la structure entière — sans quoi aucune
   règle de risque de K1–K5 n'est exécutable.
7. **Consultation fiscale** : qualification des gains/pertes X-Perp et datés (art. 150 ter CGI ?), du funding reçu, des récompenses
   de staking (BNC à la réception ?), de la jambe spot (art. 150 VH bis, sursis, seuil 305 €, pertes non reportables), des revenus
   Simple Earn et d'un bonus ; non-compensation entre régimes ; taux 2026 (PFU 31,4 % ? prélèvements sociaux 17,2 ou 18,6 % ?) ;
   option barème. Condition préalable à tout protocole couvert.
8. **Visibilité du dépôt `pivox/tradingV3`** (public ou privé) : conditionne où les séries de funding enregistrées et les tableaux
   chiffrés peuvent être écrits (API Agreement §9.4).
9. **Passeport France d'OKX Europe Markets Ltd** sur le registre ESMA ou AMF (M1, P4 ; la page d'éligibilité EEE ne nomme pas la
   France) ; même question pour Kraken EEE (aucune page ne liste les pays).
10. **Après le 2026-10-31** : re-figer `x-perps-contract-specifications` (màj 2026-10-19) et les métadonnées datées dans la
    quarantaine ; remesurer la liquidité ETH/SOL X-Perp et des datés avec un seuil déclaré **avant** lecture.
11. **Correction documentaire** (dépôt, par le propriétaire) : `FRANCE-EXCHANGE-ELIGIBILITY.md` §2 « France is in its EEA list »
    (Kraken) → « France non exclue, non nommée ».
12. **K6 / K7** : décider si la vérification d'interface (décision 5d) est faite ; sans « Sell » vérifié, aucune réouverture ;
    avec, au plus un relevé d'écarts cotés de 4 semaines dès 2026-11-02, explicitement non décisionnel ; K7 ne se rouvre que comme
    comparaison r_box vs r_base, après K6.
13. **K2, K4, K5, R13** : classement sans suite confirmé (aucune vérification ne change leur économie), sauf K2 comme ligne
    d'information de K1 (décision 5a) et K5 comme instrument de mesure si K1 et K3 étaient un jour tous deux instruits.

---

## 7. Sources de ce rapport et empreintes (sha256, calculées le 2026-10-20)

| Fichier | sha256 |
|---|---|
| `BRIEF.md` | (non figé par la mission ; lu en premier) |
| `merge/CANDIDATS.md` | `c3e178cf5f3202c823f82a6d427c7ba8274b2f727d0e7436deae9471c7de1765` (= `merge/SHA256SUMS`) |
| `a1-derivatives/NOTES.md` | `ba5609aa82578c53f0b566529f3df3b09f752991df86aeda5de9dddb8d667b0c` (= `a1-derivatives/SHA256SUMS`) |
| `a2-spot-yield-crossvenue/NOTES.md` | `2278846e6b91f2d9bcccc14d9aceb044715379e5196078b1fb4ab5f686719518` |
| `a3-literature/NOTES.md` | `6f1ac57ff1a51a78dc26c7ecdb4a84c43a62251fc062b4a220e6c811cae7342f` |
| `a4-redteam/NOTES.md` | `09ae438310fb76e46b6557bf6a61fc1380bc1bf7960cdf0f9bc9ae794d461f06` (= `a4-redteam/SHA256SUMS`, 34 783 octets) |
| `design/K8-PREREGISTRATION-DRAFT.md` | `6abbb6cbfbf39d0512223bc9ab2d3c423f9b2301d6524744ba140ff891b76fc2 (amendé le 2026-10-20 après la revue de la PR #519 ; ancien sha256 cbf3ed9c…)` (brouillon, pas un gel) |
| `review/K8-quant.md` | `48286302fc2c467ef488d0c89017810b4d89e62e185e0d6510a993b9bb52d122` |
| `review/K8-cto.md` | `422d0e459b87cb3048087698185479c1692e72fe7caf3be33fd995d56a79c885` |
| `skeptics/K1..K8-{eco,ops}.md` | voir `skeptics/SHA256SUMS` (K1-ops, K2-ops, K8-eco absents de ce fichier au 2026-10-20 : à compléter par leurs auteurs) |
| `eea-instruments-FUTURES-20261016.json` | `f8a7f046efb0ff4b64952edf76d604ecfb79d5fc04fa60a6d7e08721084be316` (= `SHA256SUMS` racine ; identique à `a4-redteam/metadata/eea-FUTURES.json`) |
| Référentiel des gates | `docs/handbook/research/RESEARCH-ELIGIBILITY-GATES.md` (lecture seule) |

Fin du rapport. Empreinte de ce fichier dans `synthesis/SHA256SUMS`.
