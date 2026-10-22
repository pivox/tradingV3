# Ce que la théorie dit du scalping, et ce que nous avons manqué (2026-10-20)

> **Nature du document.** Rapport au propriétaire, issu de sa demande du 2026-10-20 (« on revient sur la théorie pour revoir
> ce que nous avons manqué »). Examen **desk** : taxonomie des sources de profit à court horizon (microstructure), audit
> méthodologique des 24 protocoles du programme, inventaire des déclencheurs de praticiens, croisement en gaps classés, deux
> relectures adversariales (quant, exécution). Aucune donnée de marché lue, aucun protocole gelé, aucun ordre ; `fwd-okx-r1`
> intact. Dossier complet (notes, GAPS.md, relectures) : `tradingV3-private/research/20261020T1226Z-scalping-theory-gap/`.
>
> **Vérification de l'orchestrateur sur la machine (§1 ligne 5 et P1).** Le rapport dit que la boucle vivante « ne poste
> probablement aucun ordre ». Vérifié le 2026-10-20 sur le code et sur le dernier journal : `MicrostructureProof::validate`
> exige un objet `_input_proof` (`CanonicalMicrostructureSnapshot`) que seul `StrictRuleEvaluator` (Paper / Shadow /
> Backtest) fournit ; `TimeframeValidationService::enrichMicrostructureContext` n'écrit que des valeurs numériques de spread
> et de déséquilibre de profondeur, jamais la preuve ; le test `MicrostructureConditionsTest` fixe la réponse sans preuve à
> `passed = false`, `proof_reason = microstructure_proof_snapshot_missing`. Le journal `mtf-2026-10-13.log` (dernier run,
> dry-run du 2026-10-13 16:04 UTC, hors quarantaine) montre les deux conditions 1 m `spread_bps_lte` et
> `order_flow_imbalance_gte` à `passed=false, value=null, threshold=null` pendant que les autres conditions 1 m passent :
> le `all_of` 1 m du profil `scalper_micro` 1.1.7 ne peut donc jamais être vrai en production. **Établi**, plus « probable ».
> Aucune boucle ne tourne par ailleurs depuis ce dry-run (aucun journal postérieur au 2026-10-13 ; aucun déclencheur
> Temporal / cron actif, cf. mémoire du 2026-10-13). Le diagnostic P1 reste utile pour la ventilation des raisons de
> rejet de la certification 1.1.0 et la durée du run.

Rapport au propriétaire — 2026-10-20 — mission `20261020T1226Z-scalping-theory-gap/`. Synthèse des trois dossiers de
théorie (T1 microstructure, T2 audit des protocoles, T3 praticiens), de la liste classée `gap/GAPS.md` et des deux
relectures adversariales (`review/quant.md`, `review/exec.md`), dont les corrections sont intégrées : ce que les relecteurs
retirent est retiré, ce qu'ils ajoutent est ajouté, et chaque changement est tracé en §3.1.

**Interdits respectés.** Desk research seule. Aucune série de prix, rendement, funding, base, volume, OI ou carnet lue ;
rien lu sous `20261013T100000Z-forward-okx-r1/` ni dans les services `fwd-okx-*` ; aucune donnée datée 2026-10-14..31 ;
aucun téléchargement, aucune simulation, aucun ordre, aucune clé, aucune commande git ; écriture dans `synthesis/` seulement.
**Conventions.** « programme » = chiffre recopié d'un rapport du dépôt (chemin cité), jamais recalculé ; « littérature » =
chiffre publié (DOI/URL) ; « calcul d'audit » = arithmétique sans donnée ; **UNKNOWN** = ni sourcé ni mesuré. Aucun
chiffre de marché courant ne figure ici. Rien dans ce rapport n'autorise un ordre, une démo, une capture ou un gel : ce
sont des hypothèses et des diagnostics soumis à décision (§6).

**Contexte fixe.** Compte retail français, 500 USDT, OKX Europe X-Perps, frais 2 pb maker / 5 pb taker (vérifiés à
l'interface du compte le 2026-10-15, `docs/handbook/research/H1-XPERP-ACCOUNT-CHECK.md` ; API non vérifiée), boucle REST
à la minute (TradingV3), profil actif `scalper_micro` 1.1.7 (contexte 5 m momentum + déclencheur 1 m VWAP/OFI/spread,
entrée limite 30 s), contrats versionnés `setup_contract/*/1.1.0.yaml`.

---

## 1. La réponse en dix lignes

1. **La théorie n'offre que trois poches de gain à court horizon** : une information que le prix n'a pas encore (Kyle 1985 ;
   Glosten-Milgrom 1985), une prime d'immédiateté payée par un impatient (Grossman-Miller 1988 ; Nagel 2012), une rente de
   structure (frais, règle, horloge). Un déclencheur d'**état** — au VWAP, MACD > 0, RSI, OFI d'une bougie 1 min — n'appartient
   à aucune : espérance brute nulle prédite, mesurée nulle sur ~1 870 configurations (signaux = contrôles appariés ;
   programme, `docs/handbook/reports/research-scalping-120-2026-10-13.md`). Il n'y a rien à régler dans les seuils.
2. **Six poches vivent sous la seconde** (rebond bid-ask, tenue de marché, OFI de carnet, microprix, lead-lag inter-venues,
   impulsion de news) : hors de portée d'une boucle à la minute par construction, et nous y sommes **payeurs** (sélection adverse
   1,1–1,5 pb par jambe passive ; programme, `research-execution-tick-2026-10-12.md`). C'est un résultat, pas un regret.
3. **Ce qui reste à un participant lent** : être payé pour l'immédiateté (ordres au repos pendant une excursion, en stress, en
   portefeuille), les flux forcés observables (cascades de liquidation, vagues de stops), les flux à heure connue (sessions,
   funding, minute 0), et la niche de capacité (carnets minces). Tous événementiels, tous conditionnels. **Aucun n'a de preuve
   publiée du signe au bon horizon** : mécanisme A, signe B/C/UNKNOWN (relecture quant §1 T1).
4. **Ce que nous avons manqué** : la seule famille à contenu relatif reproductible (réversion après excursion) a été testée en
   **chasseur** (entrée au touch après le mouvement, inconditionnelle, paire par paire) et non en **fournisseur** conditionné
   au stress ; les cascades ont été remplacées par un proxy d'OI à 15 min ; les effets d'horloge étaient **invisibles par
   design** (contrôles appariés à l'heure) ; la capture de spread n'a jamais été mesurée là où elle existe (proxies à 1 tick).
5. **Ce que nous avons mal lu, c'est la machine** (relecture exécution, code vérifié) : la boucle vivante **ne poste
   aucun ordre** (établi par le code et le journal du dernier dry-run, vérification de l'orchestrateur en en-tête) — les conditions 1 m `order_flow_imbalance_gte` / `spread_bps_lte` exigent une preuve canonique
   que seul le chemin Paper/Backtest fournit (à confirmer dans les journaux, zéro prix) ; `order_mode: 1` = limite GTC **sans
   post-only** (pas « taker ») ; stops/TP déclenchés au **mark** (pas au dernier prix) ; l'« OFI » déployé est un déséquilibre
   de **profondeur** (20 niveaux), pas de transactions ; frais internes 5/5 ; levier ×20 sur 1 m contre un plafond retail
   ≤ 10× ; univers = swaps USDT OKX Global, pas X-Perps. Net R ≥ 1,3 ⇒ ATR(1 m) ≥ ~25 pb (`NetRCostMath`, calcul d'audit).
6. **Des coûts oubliés dans toutes les fiches** : le funding (12–25 % de chance de traverser une photo sur 1–2 h de détention,
   plafond en stress), la marge gelée par les ordres au repos (≤ 4–5 ordres de 100 USDT vivants à 1× sur 500 USDT), la perte
   de fills post-only au croisement, la liquidation forcée facturée au taux taker ; et une **licence** (OKX API Agreement §9.4)
   à lire avant toute capture longue ou tout travail d'agents sur ces données.
7. **Après relecture, l'ordre de travail est** : trois préalables à coût nul (la machine poste-t-elle ? ; §9.4 + GET lecture
   seule au compte ; capture de **comptage** 24–72 h de la couverture X-Perps), puis G2 (mark-outs sur carnets larges) →
   G4 (Gate 1 **historique** sur l'archive de liquidations Hyperliquid, puis OKX) → G5 (horloge, signes pré-déclarés) → G1
   (réversion en fournisseur, **sur consentement**) ; G6 **suspendu** à une décision explicite (H2 §4) ; G7–G9 secondaires ;
   G10 en annexe.
8. **Priors ramenés à « moyen »** partout où la preuve du signe manque (G1, G4, G6) ; budget de multiplicité (Holm) sur les
   protocoles lancés ; porte économique par gap (fills/jour × notionnel × net pb → USD/an) et règle d'arrêt.
9. **Ordre de grandeur si tout passe** : de dizaines à quelques centaines d'USD par an à 500 USDT (calcul d'audit, facteur 4–15
   d'incertitude selon le taux de fills) — pas un revenu ; le coût fixe (collecteur X-Perps, moteur d'exécution fournisseur,
   licence) le dépasse probablement.
10. **Verdict** : le scalping « à la minute, en continu, sur états » est fermé par la théorie et par le programme ; ce qui
    reste est un programme de **fourniture de liquidité événementielle**, à mesurer en prospectif, dont rien ne dit qu'il paie
    après coûts sur OKX. Rien avant le verdict fwd-okx-r1 du 31 octobre ; confirmation au plus tôt sur `okx-xperp-prospective`
    ≥ 2026-11-02 ; les décisions sont listées en §6.

---

## 2. Taxonomie des sources de profit à court horizon

Lecture : **Preuve du signe** = A (payeur nommé **et** mesure publiée du signe au bon horizon), B (preuve indirecte : autre
marché, autre horizon, variable voisine), C (folklore ou preuve contraire), UNKNOWN (aucune étude trouvée) — colonne ajoutée
à la demande de la relecture quant, distincte du « payeur nommé ». **Statut** = TESTÉ (au bon horizon, négatif cohérent),
MAL TESTÉ (proxy, mauvais horizon, exécution ou contrôle aveugle), NON TESTÉ, INACCESSIBLE (prédictibilité < ~90 s), CLOS
(décision du propriétaire ou borne arithmétique). Sources de fond : T1 §2 (T1–T14), T2 §1 et §4 (M1–M12), T3 §1–2 (P1–P16).

| # | Source (mécanisme → payeur) | Horizon | Qui la capte | Preuve du signe | Statut pour nous |
|---|---|---|---|---|---|
| 1 | Information privée contre bruit (Kyle ; Glosten-Milgrom) → le non-informé qui trade un état public | secondes | informés, HFT | A (actions) ; sans objet pour un indicateur de prix | **TESTÉ** : contrôles appariés r1–r14 = signaux ; c'est exactement le test « information vs bruit » |
| 2 | Rebond bid-ask (Roll 1984) : une « hausse » 1 min sur dernier prix est en partie un passage bid→ask → le preneur qui suit | ~1 min | teneur de marché avec priorité | A (littérature : bruit visible de ~1 à 5 min sur BTC, arXiv 1904.12346) | **INACCESSIBLE** comme gain ; **NON TESTÉ** comme **coût** du déclencheur 1 m (diagnostic mid/last jamais fait ; magnitude ≤ demi-spread, ≤ ~4 pb au plafond admis — relecture quant) |
| 3 | Capture de spread / tenue de marché (Ho-Stoll ; Avellaneda-Stoikov ; Glosten 1994 ; Sandås) → le preneur | s → min | HFT avec priorité de file et rabais ; sur carnets larges, peut-être un petit compte patient | A mécanisme ; **UNKNOWN** sur alts minces (aucune étude de capture réalisée trouvée, T3 §5) | **TESTÉ** sur carnets à 1 tick (Part A, BTC/ETH : net −3,05 … −3,49 pb through, −2,09 … −2,18 touch ; programme) ; **MAL TESTÉ** sur carnets larges (proxies Binance à 1 tick ⇒ capture nulle par construction, T2 §P8) → G2 |
| 4 | Flux et carnet à la seconde : OFI (Cont-Kukanov-Stoikov), file/microprix (Stoikov 2018), icebergs, footprint/CVD → le coteur lent | 1 s → 1 min | HFT colocalisé | A pour le HFT (littérature : R² culmine à 1–2 changements de prix, Kolm-Turiel-Westray 2023) | **TESTÉ** au bon horizon (r1 60–300 s, r9 `bookTicker`, r8 profondeur : = contrôles ; programme) ; **INACCESSIBLE**. L'OFI déployé (profondeur 20 niveaux, carnet REST frais) vise ce mécanisme : zéro par construction |
| 5 | Prime d'immédiateté : réversion après excursion, concession payée par l'impatient (Grossman-Miller ; Nagel ; Hendershott-Menkveld) | 15 min → heures, **conditionnelle** au stress | fournisseur patient, rempli **pendant** l'excursion, sans stop serré, en portefeuille | A mécanisme (actions, horizon jour) ; **B** pour le transfert intraday crypto (hypothèse, pas un prior — relecture quant) | **MAL TESTÉ** : en chasseur (E1 au touch après l'excursion), inconditionnel, long seul ou poolé, porte « 70 % des paires » ; contenu relatif dans 7–8 fenêtres sur 9 avec une inversion hors échantillon (r7 déc. 2025 : −20,9 pb contre contrôle −15,8, programme `research-midcap-tick-2026-10-13.md`) → G1 |
| 6 | Réversion idiosyncratique en coupe transversale intraday (résidu net du marché ; Nagel ; Makarov-Schoar) → impatients des paires qui ont bougé seules | 1–4 h | fournisseurs couverts (jambe BTC) | **UNKNOWN** intraday ; journalier contraire pour les gros noms (Zaremba et al. 2021 : momentum chez les plus gros — littérature) | **NON TESTÉ** à l'intraday ; H2 l'a testé à 24 h / 3 j (FAIL, clos, `H2-CLOSURE-DECISION.md`) → G6, **suspendu** (H2 §4) |
| 7 | Continuation / métaordres / positionnement OI (Bouchaud-Farmer-Lillo ; r13 O2) → fournisseurs contrariens trop tôt | 15 min → h | qui distingue ouverture et fermeture de positions | B (mémoire du flux, prix quasi-martingale) | **TESTÉ**, **CLOS** : états = hasard (r10) ; O2-T1 +11,4 pb entraînement → −11,7 pb validation sous le contrôle apparié au prix (r14, programme) |
| 8 | Liquidations forcées et cascades, retour post-cascade (moteur de liquidation = demandeur non informé) → le liquidé, puis l'assurance/ADL | min → h | fournisseur présent **pendant** l'événement, capital disponible, pas de stop serré | A pour l'anatomie (Lim 2026 : spreads ; Garcia Seuma 2026 : alertes) ; **UNKNOWN pour le retour du prix** (aucune étude trouvée ; vérifié deux fois) | **MAL TESTÉ** : r13 O1 = proxy d'OI à 5 min lu 15 min après, entrée au touch (−18 … −38 pb, sous le contrôle ; programme) ; flux de liquidations réel jamais lu → G4 |
| 9 | Stop-runs, niveaux ronds, reclaim (Osler 2003/2005 : stops regroupés, déclenchés en vagues) → le stoppé | min → h | absorbeur de la vague | A pour le regroupement et la **continuation** ; **C** pour « sweep → retour » (Urquhart 2017 : aucun rendement anormal après le rond) | **NON TESTÉ** (aucune feature niveau/vague/reclaim ; H3 « rejected breakouts » jamais lancé) → G8 |
| 10 | Saisonnalité d'horloge : ouvertures 00:00/08:00/13:30–14:30 UTC, photos de funding 00/08/16 UTC, minute 0 (Hansen-Kim-Kimbrough) → flux calendaires forcés | min autour d'instants connus | quiconque a une horloge — latence sans importance | A volatilité/volume ; **B/UNKNOWN** rendement (Baur et al. 2019 : saisonnalité des rendements faible et instable ; dérive autour de la photo : aucune étude) | **MAL TESTÉ** (S3 opening drive : bougies 5 min, entrée marché, sorties taker ; seul setup du screen à battre son contrôle de ~0,1 R, programme `research-scalping-2026-10-12.md`) et **neutralisé** par les contrôles appariés à l'heure → G5 |
| 11 | Imbalance d'ouverture de quart d'heure → rendements 4–12 h (Kim & Hansen 2026, arXiv 2607.09426, six perps Binance, hors échantillon) → flux algorithmiques périodiques | **4–12 h** (pas un scalp) | quiconque lit l'imbalance d'ouverture | **A/B** (prédictibilité hors échantillon publiée ; coûts non traités — littérature) | **NON TESTÉ** ; ajouté par la relecture quant (M2) → sous-hypothèse de G5, hors scalp |
| 12 | Momentum intrajournalier en temps-volume (Shen-Urquhart-Wang 2022 ; Gao et al. 2018) → rééquilibreurs de fin de journée-volume | heures (1 décision/jour) | quiconque, à la minute | **A** (revue à comité, deux marchés ; coûts UNKNOWN ; BTC spot pré-2020) | **NON TESTÉ** ; hors scalp → G7 |
| 13 | Lead-lag inter-venues, arbitrage de latence (Budish et al. ; Albers et al. 2021 : 10–37 % de variance à 500 ms) → le coteur lent de la venue suiveuse (nous) | ms → s | arbitragiste colocalisé, deux venues | A | **INACCESSIBLE** ; **coût mesuré** (Part A) ; une seule venue autorisée depuis la France |
| 14 | Lead-lag BTC → alts minces intra-venue (Lo-MacKinlay ; Kurihara-Matsumoto 2026, résumé seul) → le coteur lent de l'alt | s ; **minutes ?** sur petites caps (UNKNOWN) | preneur rapide | B (résumé seul ; « bat le buy-and-hold » ≠ « bat les coûts ») | **NON TESTÉ** ; requalifié **défensif** (relectures) → G9 |
| 15 | Annonces macro (Benigno-Rosa 2023 ; Kroner et al. 2026) → le lent | s (saut) ; ~30 min (volatilité) | flux informé rapide | A réaction (mitigée) ; **UNKNOWN** dérive | **NON TESTÉ**, à raison (20–30 événements/an) ; **INACCESSIBLE** ; usage défensif |
| 16 | Dislocations mark/index/last, carry de funding, snipe de funding (He et al. ; OKX funding) → demandeur de levier | min → h / 8 h → j | arbitragiste spot-perp | A mécanisme ; gain borné ≈ \|F\| ≈ 1 pb hors stress | **CLOS** (desk 2026-10-16, R9/FUND-SETTLE ; D4 −802 pb, programme) ; les stops déployés sont **au mark** (correction G3) ; le funding reste un **coût** (toutes fiches) et un **événement** (G5) |
| 17 | Événements d'instrument : listing (heures), **délistings / changements de contrat X-Perps** → vendeurs structurels ; fermeture forcée à instant connu | h → j | positionnels | A listing au jour (Ante 2019) ; heures UNKNOWN ; délistings **UNKNOWN** (fréquence) | Listing jours **TESTÉ, CLOS** (H1 Gate 2 économique) ; heures **NON TESTÉ** (≈ 2,2/mois, ne pas lancer) ; délistings **NON TESTÉ**, ajouté par la relecture quant (M3) |
| 18 | Niche de capacité : carnets minces où le capital professionnel ne va pas (loi en racine, Tóth et al.) — amplificateur, pas une source | celui de la source | petit compte patient, maker/maker | — ; **UNKNOWN** si les teneurs de marché sous programme (rabais) y sont déjà (M4) | **PARTIEL** (mid-caps Binance r6–Q3 ; jamais sur OKX ; jamais avec sortie maker + condition d'état) |
| 19 | Toxicité conditionnelle du flux (VPIN, Easley-López de Prado-O'Hara) → fournir seulement quand la toxicité est basse | min (temps-volume) | teneurs de marché | A (actions) | **NON TESTÉ** comme filtre pré-enregistré ; ingrédient de G1/G2, pas une source |
| 20 | États d'indicateurs et empilements (5 m/1 m, 1.1.0/1.2.0, zone VWAP, grilles 15 m/4 h, swing) — hors théorie : aucun payeur | — | — | — | **TESTÉ, CLOS** : ~1 870 configurations, le contrôle de régime explique les gains d'entraînement (programme, paysage) |

Lecture du profil actif condition par condition (T1 §2.bis, corrigé par la relecture exécution) : les conditions 5 m sont
la ligne 20 ; `near_vwap` 0,12 % est la ligne 5 **inversée** (entrer au VWAP = là où il n'y a pas de concession) ;
`order_flow_imbalance_gte: 0.15` est la ligne 4 (profondeur de carnet, horizon de secondes) ; `spread_bps_lte: 8` est un
filtre de liquidité, pas de toxicité (ligne 19) ; `macd_hist_increasing_n: 1` sur dernier prix est exposé à la ligne 2.
**Aucun déclencheur praticien à mécanisme ne ressemble à ce profil** (T3 §3) : ceux qui ont un payeur entrent sur des
événements (stops, cascades, photo de funding, ouverture) ou des concessions (excursion absorbée), jamais sur un état.

---

## 3. Les gaps, classés après les deux relectures

### 3.1 Traçabilité : ce que les relectures retirent et ajoutent

**Retiré ou corrigé** (par rapport à `gap/GAPS.md`) :

| Point de GAPS | Correction | Source |
|---|---|---|
| G3(c) « stops/TP au dernier prix → sorties sur mèches » | **Faux** : `slTriggerPxType`/`tpTriggerPxType = 'mark'` ; seul résidu : le **niveau** du stop est calculé sur un ATR de klines dernier prix | quant §3 G3 ; exec §1.1 (`OkxActionFactory.php`) |
| G3(d) « `order_mode: 1` = taker par défaut ; repli marché à 25 s ; `fallback_taker` » | 1 = **GTC sans post-only** (maker si l'ordre repose, taker si le prix a bougé pendant la latence) ; le repli marché s'applique si la **zone** (TTL 180/240 s) expire sous 25 s et **lève** sur le chemin canonique ; `fallback_taker` est une clé **morte** ; à l'expiration (60 s + 10 s) l'ordre est annulé sans re-post ; « 10–18 pb taker/taker » devient une **borne haute** | exec §1.1 (`OrderPlanModel.php`, `ExecutionBox.php`, `LimitFillWatchMessageHandler.php`) |
| G3(e) borne « ATR(1 m) ≥ 11 pb » (risque brut) | Retirée : `NetRCostMath` ajoute les coûts au risque **et** les retranche de la récompense ⇒ d ≥ 4,6 c ⇒ ATR(1 m) ≥ ~25 pb à c = 8 pb (~31 pb avec provision de funding) — calcul d'audit | quant §3 G3 ; exec §1.3 |
| G3(e) « d'où 0 trade en certification » | **Non prouvé** : `recap-2026-10-08` ne documente aucune raison ; cause concurrente au moins aussi plausible : la couche déclencheur/confirmation (preuve microstructure, validité 5 s, `missing_data_policy: reject`) n'est jamais vraie | quant §3 G3 ; exec §1.2 |
| G1 « bat le hasard apparié dans **chaque** fenêtre », prior **fort** | **7–8 fenêtres sur 9** avec une inversion franche hors échantillon (r7 déc. 2025 ; r8b janvier : écart +1,8 pb, pas 15–30) ; le transfert Nagel (VIX → réversion journalière actions) à l'intraday crypto est une **hypothèse** ; prior → **moyen** | quant §3 G1 |
| G1 contrôle « ordres au repos à des instants aléatoires appariés » | **Retiré** : une échelle renouvelée chaque minute est toujours armée et se remplit sur les mêmes excursions que le contrôle ; garder « bande sans flux » (q3x) et, si besoin, un contrôle d'**ancre** | quant §3 G1 |
| G4 prior **fort** ; « capture prospective seulement » | Prior → **moyen** (mécanisme A, signe **C/UNKNOWN**) ; « prospectif seulement » est **faux** : archive de fills Hyperliquid avec objet `liquidation` (M1) | quant §3 G4 |
| G5 tel qu'écrit | **Non falsifiable** : ~60 cellules sans signe pré-déclaré ⇒ faux positif quasi certain ; signes et fenêtres à pré-déclarer ou correction de multiplicité | quant §3 G5 |
| G6 rang 6, prior **moyen-fort**, développement sur X-Perps `consumed_equivalent` | **Suspendu** à une décision explicite du propriétaire : H2 §4 interdit toute hypothèse dérivée « quelle que soit l'archive » et l'univers X-Perps 1 m a été **lu par H2** (univers secondaire) ; prior → **moyen** (Zaremba 2021) ; fusion avec G1(iii) | quant §3 G6 ; `H2-CLOSURE-DECISION.md` l.109–110 |
| G9 variante « swap global OKX → X-Perp même sous-jacent » ; prior faible-moyen | Variante **retirée** (écart intra-venue arbitré à la milliseconde = G13) ; prior → **faible** ; usage **défensif** uniquement, mesuré gratuitement par G2 si BTC est enregistré ; licence du signal OKX : couverte par §3.3/§9.4 « personal trading » (plus UNKNOWN) | quant §3 G9 ; exec §2 G9 |
| G10 (premières heures après listing) | **Déplacé en annexe** : déjà « ne pas lancer » ; le garder dans le tableau donne l'impression d'un backlog | quant ; exec §4 |
| G11–G13 dans le tableau classé | **Sortis** du classement : ce sont des **résultats** (inaccessibles), pas des manques ; description de G11 corrigée (OFI déployé = profondeur 20 niveaux d'un carnet REST frais ; test le plus proche = r8, pas r1/r9) | quant §3 ; exec §2 G11 |
| G15 note « O2-T2 à relire sur ≥ 100 paires » | **Supprimée** : réouverture déguisée d'une famille close (r13/r14) | quant §3 G15 |
| G8 citation de Mahadzva 2026 (non lu) en appui | **Retirée des priors** ; une source non lue n'est pas un prior | quant §3 G8 |
| §0.8 « dizaines d'USD/an » (recopie de K1, portage de funding) | Remplacé par un calcul explicite par gap (M5) ; incohérence d'un facteur 4–15 relevée | quant §1 T2 |
| Frais 2/5 pb « à vérifier au compte » | « **Vérifiés à l'interface du compte le 2026-10-15**, API non vérifiée » | exec §5 (`H1-XPERP-ACCOUNT-CHECK.md`) |

**Ajouté** (manques non listés par GAPS) :

- **G3′ « La machine poste-t-elle ? »** (exec, bloquant) : état réel de la boucle vivante depuis 1.1.7 ; **M7** ventilation des
  raisons de rejet de la certification 1.1.0 (quant) ; **durée du cycle MTF** et instant réel de décision (exec).
- **M1** archive historique des liquidations Hyperliquid comme donnée de **développement** pour G4 ; **M8** mécanique du moteur
  de liquidation OKX X-Perps ; **M9** déclaration de transférabilité (gate M3) pour tout développement HL/Binance → X-Perps (quant).
- **M2** imbalance d'ouverture de quart d'heure → 4–12 h (Kim & Hansen 2026) ; **M3** délistings / changements de contrat X-Perps ;
  **M4** asymétrie de frais face aux teneurs de marché sous programme (rabais maker, UNKNOWN pour OKX Europe) (quant).
- **M5** porte économique par gap et règle d'arrêt ; **M6** budget de multiplicité du programme (quant).
- **Funding comme coût d'exécution** dans chaque fiche ; **capital au repos / marge gelée** (simulation sans contrainte vs
  portefeuille 500 USDT) ; **post-only et annulation au croisement** ; **gardes de spread** de la plateforme (≤ 8 pb) à
  réconcilier avec la cible de G2 (≥ 6 pb) ; **mode de position** du compte et **levier ×20** du profil vs plafond ≤ 10× ;
  **licence OKX API Agreement §9.4** (28 juillet 2026) ; **couverture X-Perps** des canaux publics (`books5`, `trades`,
  `liquidation-orders`, OI) par une capture de **comptage** ; **collecteur X-Perps** à écrire (aucun outil ne lit
  `instType FUTURES` / `_UM_XPERP`) ; **chemin de confirmation** = capture publique + simulateur de recherche, jamais Paper
  certifié ni démo ; **liquidation forcée facturée au taux taker** comme coût de queue des règles sans stop (exec).
- G2 : variante **« pose à la minute, TTL 30–60 s, sans recotation »** ; enregistrer **BTC et le swap global** du même
  sous-jacent ; mark-out **jusqu'au fill opposé ou 60 min** avec funding (quant, exec). G5 : armer à **h−5 ou h−2** (pas h−1,
  minute 0 toxique), fenêtres 13:30/14:30 selon DST, **F dans le net** stratifié par signe (exec). G8 : variante d'entrée
  **taker** et taux de fill passif ; entrée strictement postérieure à la clôture définissante (quant, exec). G7 : partition
  **ex ante** du temps-volume, source de volume, funding (quant, exec).

### 3.2 Le classement révisé

Préalables = diagnostics et décisions sans hypothèse de marché, à coût nul ou faible, qui conditionnent la crédibilité et la
faisabilité de tout le reste. Hypothèses = classées par prior (après relecture) × faisabilité (minute, données non consommées,
500 USDT, OKX Europe). Les horizons inaccessibles (ex-G11–G13) et les familles closes (G14–G16) sont en §3.4, hors classement.

| Rang | Id | Gap | Type | Prior (après relecture) | Faisabilité | Test minimal (une ligne) |
|---|---|---|---|---|---|---|
| P1 | **G3′** | La machine poste-t-elle ? État réel de la boucle (preuve microstructure, ordres postés, raisons de rejet 1.1.0, durée du run, table « déclaré / réel », borne net R) | diagnostic | certain (c'est une mesure) | maximale (journaux en champs texte, zéro prix, zéro donnée nouvelle) | Compter les entrées dans `order-journey*` depuis 1.1.7 ; `proof_reason` / `rulesFailed` 1 m dans `mtf-runner.log` ; `rejected_by` de la certification ; `performance` de `/api/mtf/run` |
| P2 | **D-OWN** | Décisions et vérifications du propriétaire sans ordre : lecture de §9.4 ; GET lecture seule au compte X-Perps (mode de position, levier, permissions, règle de marge gelée, barème API, programme MM) | gouvernance | — | immédiate | Liste `H1-XPERP-MARKET-ACCESS.md` §3.5 ; réponse écrite oui/non sur §9.4 |
| P3 | **D-COUNT** | Capture de **comptage** 24–72 h : messages par instrument sur `books5`, `trades`, `liquidation-orders`, OI `rubik/stat` pour `instType FUTURES` X-Perps (zéro prix) ; collecteur X-Perps (S–M) | infrastructure | — | haute, après P2 | Table couverture × fréquence par canal et par instrument |
| 1 | **G2** | Capture de spread / mark-outs passifs sans signal sur carnets larges X-Perps (Part A rejouée hors de son domaine) | MAL TESTÉ | **moyen** — mesure de coût avant tout ; signe UNKNOWN sur alts minces | haute pour la mesure ; conditionnée à P2, P3 | Deux variantes (recoteur 5 s ; **minute** TTL 30–60 s post-only sans recotation), touch/through, mark-out 60/300/900 s **et** jusqu'au fill opposé, par heure et tranche de spread ; contrôle = BTC/ETH X-Perps de la même capture (doit reproduire Part A) ; BTC + swap global enregistrés |
| 2 | **G4** | Retour post-cascade de liquidation avec le **flux réel** | MAL TESTÉ | **moyen** (mécanisme A, signe C/UNKNOWN) | moyenne : Gate 1 **historique** sur Hyperliquid (M1), puis capture OKX longue (P2, P3) | Event study : seuil **absolu** (notionnel liquidé / profondeur) **et** percentile ; retour du mid 5/15/60 min après le pic ; version **continue** (retour ~ intensité, à amplitude et heure égales) ; entrée passive à la minute suivante, sortie 60 min, pas de stop < 3 σ ; funding + stress spread ×3 ; unité = instant, ≥ 100 |
| 3 | **G5** | Horloge comme signal : sessions 08:00 / 13:30–14:30 UTC, photos 00/08/16 UTC (dérive, pas snipe), minute 0 ; sous-hypothèse M2 (imbalance d'ouverture → 4–12 h, hors scalp) | MAL TESTÉ / NON TESTÉ | **moyen** (A volatilité ; signe B/UNKNOWN ; Kim-Hansen 2026 pour M2) | haute (instant connu ; dev sur X-Perps 1 m consommé-équivalent = 3–6 mois d'historique seulement) | **Un signe et une fenêtre pré-déclarés par événement** (ou Holm) ; phase screen étiquetée puis **un** test confirmatoire ; contrôle = mêmes mesures aux heures sans événement ; ordre armé à h−5/h−2 ; F dans le net par signe ; ≥ 100 instants par type (4–5 semaines) |
| 4 | **G1** | Prime d'immédiateté en **fournisseur**, conditionnée au stress | MAL TESTÉ (famille « suffisamment explorée ») | **moyen** (contenu relatif 7–8/9 ; transfert Nagel = hypothèse) | **moyenne** : moteur d'exécution à écrire ; échelle incompatible avec 500 USDT à 1× ; **consentement explicite** requis | Critère de passage = **stress − calme > 0** seul (un pass inconditionnel ne compte pas) ; contrôle « bande sans flux » ; post-only + modèle d'annulation ; funding ; deux lectures (simulation n ≥ 600 ; portefeuille 500 USDT) ; fills/jour estimés sur la capture G2 avant gel (ordre de grandeur r6 : ≈ 4 mois pour 600 en stress) ; pertes max par fill/jour et arrêt pré-déclarés |
| 5 | **G6** | Réversion en coupe transversale intraday, neutre en dollar (fusion de G1(iii)) | NON TESTÉ | **moyen** (Zaremba 2021 contraire pour les gros ; intraday UNKNOWN) | **suspendue** (H2 §4) ; données **hors H2** seulement ou prospectif pur ; déciles de 3 noms ; marge 1 200–2 400 USDT à 1× tel qu'écrit | Décision du propriétaire d'abord ; puis unité = **slot** (n_eff ≈ 100–150/semaine), puissance avec σ du résidu à 60 min et coût de deux jambes ≥ 6–8 pb ; portefeuille 500 USDT = un slot sur 4–8 ou 2 vs 2 ; funding |
| 6 | **G7** | Momentum intrajournalier en temps-volume | NON TESTÉ | moyen (A, coûts UNKNOWN, BTC pré-2020) | haute pour les données ; **faible** pour la puissance (1 décision/jour) ; hors scalp | Partition **ex ante** (volume cumulé des J derniers jours, erreur de calage) ; source de volume déclarée ; entrée passive ; funding ; n_eff ≥ 100 jours ≈ 3–4 mois ; soit fusion dans G5, soit hors périmètre « scalping » |
| 7 | **G8** | Stop-runs / niveaux ronds / reclaim | NON TESTÉ (H3 jamais lancé) | **moyen-faible** (A regroupement ; C retour) | haute (bougies) ; n ≥ 400 = des mois sur 29 paires | Contrôle n° 1 = **cassure réussie du même niveau**, n° 2 = touche sans pénétration ; entrée strictement postérieure à la clôture ; variantes passive **et** taker ; rendement 1 h / 4 h |
| 8 | **G9** | Lead-lag BTC → alts minces (défensif) | NON TESTÉ | **faible** (retard requis 10–25 pb en preneur) | gratuite si G2 enregistre BTC | Régression décalée de la **durée du run** ; usage = ne pas laisser d'ordre au repos après un saut de BTC ; aucun protocole séparé |
| 9 | **M3** | Délistings / changements de contrat X-Perps (fermeture forcée à instant connu) | NON TESTÉ | théorique (payeur forcé) ; signe et fréquence **UNKNOWN** | inventaire d'abord (`instruments` : `expTime`, état) | Consigner ; event study prospectif seulement si le flux d'événements est suffisant |

### 3.3 Fiches courtes : prior, faisabilité, test minimal

**P1 — G3′ « La machine poste-t-elle ? » (diagnostic, zéro donnée de marché).**
*Constat.* Lecture du code par la relecture exécution (§1.2) : dans le moteur `ConditionRegistry` utilisé en production, les
conditions 1 m `order_flow_imbalance_gte` et `spread_bps_lte` exigent une preuve canonique `_input_proof`
(`CanonicalMicrostructureSnapshot`) que seul `StrictRuleEvaluator` (Paper/Shadow/Backtest/Research) injecte ;
`TimeframeValidationService::enrichMicrostructureContext` ne la fournit pas ; le test `MicrostructureConditionsTest` fixe
la réponse sans preuve à `passed=false`, `proof_reason=microstructure_proof_snapshot_missing` ; la condition ne lève pas,
donc pas de repli YAML. Le `all_of` 1 m échouerait chaque minute : aucun ordre d'entrée du profil 1.1.7, avant tout effet de
frais, de Roll ou de minute 0. **Levée de réserve** : la relecture n'avait pas localisé le fichier de `conditionTimeframeEvaluator` et écrivait
« probable » ; l'orchestrateur a ensuite vérifié le code (`MicrostructureProof::validate`, `StrictRuleEvaluator` seul fournisseur
de `_input_proof`) et le journal `mtf-2026-10-13.log` (les deux conditions 1 m à `passed=false, value=null` pendant que les autres
passent) — le résultat est **établi** (en-tête).
*Prior.* Certain qu'il y a quelque chose à mesurer ; la seule question est laquelle des deux causes (déclencheur jamais vrai
vs géométrie net R) domine, et combien la machine perd en plus du zéro théorique.
*Faisabilité.* Maximale : champs texte des journaux (`order-journey*`, `mtf-runner.log` : `proof_reason`, `rulesFailed`),
`rejected_by` de la certification Paper #132 (12 cellules, 0 trade, raison absente de `recap-2026-10-08`), champ
`performance` de `/api/mtf/run` ; fichiers de configuration. Aucun prix, aucun ordre.
*Test minimal.* (i) nombre d'ordres postés depuis le déploiement de 1.1.7 ; (ii) ventilation des raisons de rejet
(production et certification) ; (iii) durée du run et instant réel de décision (hh:mm:00 + run + Messenger + REST) ;
(iv) table « configuration déclarée / comportement réel » (GTC sans post-only ; repli zone interdit en canonique ; clé
morte ; stop au mark ; fees 5/5 internes ; levier ×20 ; univers USDT swaps) ; (v) borne d ≥ 4,6 c par paire à partir de l'ATR
1 m des snapshots d'indicateurs (agrégats ; à faire valider comme conforme aux interdits) ; (vi) Roll sur run14/run15 en
indicatif seulement (deux majors, 24 h). Livrable : « ce que la machine fait » avant « ce qu'elle perd ».

**P2 — Décisions et vérifications du propriétaire (sans ordre).**
OKX API Agreement, version EEA, « Last Updated: 28 July 2026 » (exec §1.4) : §9.4 limite l'usage des données de marché à
« your own personal, non-commercial trading and account management », l'extraction automatisée au « reasonably necessary
for personal trading », exige une licence séparée pour l'accès « high-frequency or large-scale … for commercial or
institutional purposes », interdit d'entraîner ou d'évaluer un modèle ML/IA (formulation exacte de (c) à relire), et
s'applique aux endpoints publics. Une capture de 2–8 semaines sur 20–30 instruments pour une recherche personnelle est
défendable comme « personal, non-commercial » mais n'est pas « trading » et flirte avec « large-scale » : **décision du
propriétaire requise avant G2/G4**. Même liste pour le compte : mode de position (net vs long/short), levier effectif
et plafond, permissions API, règle de gel de marge des ordres au repos sur X-Perps, barème via API, existence d'un rabais
maker sous programme MM (M4 : un tiers cite ≤ 0,005 % sur les futures OKX, non vérifié ; `okx.com/fees` ne se rend pas).

**P3 — Capture de comptage et collecteur X-Perps.**
Aucun outil existant ne lit les X-Perps (`research_exec/forward_okx.py` codé `www.okx.com`/`SWAP` ; résolveur qui lève sur
`_UM_XPERP` ; `instType=SWAP` codé en dur ~54 fois ; marge X-Perps en USDC — `H1-XPERP-TECHNICAL-GAP.md`,
`TRADINGV3-EXCHANGE-COMPATIBILITY.md`). La couverture de `books5`, `trades`, `liquidation-orders` (disclaimer « doesn't
represent the total number of liquidations », lu par T3, non re-vérifié) et de `rubik/stat` OI pour `instType FUTURES` est
**UNKNOWN**, ainsi que la fréquence de `books5`. Prérequis de G2 et G4 : 24–72 h de **comptage** (messages par instrument,
zéro prix) avant tout gel. Les limites d'API ne contraignent aucun gap (1 000 requêtes d'ordre / 2 s par sous-compte ;
exec §1.7).

**1 — G2 Capture de spread sur carnets larges (mesure de coût d'abord).**
*Mécanisme.* Gain passif = demi-spread − sélection adverse − frais maker − inventaire (Ho-Stoll ; Avellaneda-Stoikov ;
Glosten 1994 ; Sandås 2001 : les ordres profonds sont ramassés par l'informé). Sur un tick de 0,006–0,19 pb (programme,
Part A) il n'y a rien ; sur un demi-spread de 3–10 pb, l'arithmétique change — au prix d'une sélection adverse plus forte,
UNKNOWN sur OKX (Tiniç et al. 2023 : ≈ 10 % du spread effectif sur majors Bitfinex — littérature).
*Pourquoi ça manque.* Part A n'a mesuré que BTC/ETH à 1 tick (hors domaine) ; toute la tape Binance est un proxy forcé à
1 tick (capture nulle par construction, T2 §P8) ; les mid-caps r6 ont payé −10,7 pb médians en sortie taker sans qu'une
entrée passive soit jamais mesurée (programme, `research-midcap-tick-2026-10-13.md`).
*Prior : moyen.* Mesure de coût qui borne G1, G4, G5 quel que soit le verdict ; un mark-out net positif inconditionnel
changerait l'économie des autres fiches, mais **incohérence à écrire** : les paires visées (spread ≥ 6 pb) sont celles
que la machine refuse (`spread_bps_lte: 8`, `spread_guard_bps 6`, `market_max_spread_pct 0,1 %`) — si G2 paie, les gardes
changent ; sinon G2 ne concerne pas la machine.
*Faisabilité.* Haute pour la mesure (capture publique, aucun ordre), après P2 (§9.4) et P3 (collecteur, comptage). Pas de
carnet historique X-Perps : prospectif ≥ 2 semaines à partir du 2026-11-02. Notre ordre de 50 USDT peut être une fraction
visible de la profondeur d'un X-Perp mince : à mesurer sur la capture.
*Test minimal.* Rejouer Part A en **deux variantes** : recoteur (toutes les 5 s, achat + vente au touch, 45–60 s) et
**minute** (pose à hh:mm:ss + durée du run, TTL 30–60 s, post-only, sans recotation) — l'écart est le coût de la lenteur ;
règles touch/through, latence 250 ms ; mark-out au mid à 60/300/900 s **et** jusqu'au fill opposé ou 60 min, funding
inclus ; par heure, par tranche de spread courant, par paire ; enregistrer BTC et le swap global du même sous-jacent (coût
nul ; absorbe G9). Contrôle = BTC/ETH X-Perps (doit reproduire Part A : −2 … −3,5 pb). Passe si le mark-out net à 60 s est
> 0 avec LB90 > 0 sur ≥ 1 000 fills par paire-tranche **pour la variante minute** (porte dépendante de la variante), et si
through − touch < demi-spread − 2 pb. Livrable secondaire : table « sélection adverse par spread courant et par heure »,
entrée de coût de G1/G4/G5.

**2 — G4 Retour post-cascade de liquidation avec la vraie donnée.**
*Mécanisme.* Le moteur de liquidation vend/achète au marché les positions à marge épuisée ; flux forcé non informationnel
⇒ impact transitoire, retour partiel après épuisement (Grossman-Miller). Cheng et al. 2021 : liquidations ≈ 3,51 % de
l'OI/jour côté long, 1,89 % côté short, BitMEX (littérature ; rien sur le retour) ; Lim 2026 : spread médian 9,4 pb au pic,
p95 87,5 pb, profondeur durablement à 66–79 % (littérature, Hyperliquid 2025-10-10 ; **liquidité**, pas prix) ; Garcia
Seuma 2026 : aucun signal d'alerte invariant (littérature). **Aucune étude du retour du prix après cascade trouvée**
(T3 §5 ; relecture quant) : prior **moyen**, pas fort.
*Pourquoi ça manque.* r13 O1 = variation d'OI Binance à 5 min, lue 15 min après, seuil 1,5 σ, entrée au touch : −18 … −38 pb,
sous le contrôle apparié au prix (programme, `research-oi-positioning-2026-10-13.md`) — le proxy confond fermetures
volontaires et liquidations, arrive après la cascade et paie la réversion au lieu de la recevoir.
*Faisabilité : moyenne.* **Gate 1 historique possible** sur Hyperliquid : fills publiés (`hl-mainnet-node-data/node_fills`,
`node_fills_by_block`, `node_trades`, doc officielle, requester-pays) avec, selon un guide tiers (Dwellir), un objet
`liquidation` {`liquidatedUser`, `markPx`, `method`} depuis 2025-03/05 — **schéma, dates et licence à vérifier sur un
échantillon de schéma, pas de valeurs** ; transférabilité HL → X-Perps à déclarer (M9 : moteur backstop/ADL on-chain vs
reprise par paliers OKX) ; mécanique du moteur OKX X-Perps (M8 : qui sert l'ordre au repos ?) UNKNOWN. Binance
`liquidationSnapshot` USD-M **discontinué** (vérifié, issue #337). OKX : canal WS partiel, pas d'historique ⇒ capture
longue (P2, P3). Fréquence : grappes moyennes quotidiennes, cascades majeures quelques-unes par an ; alts UNKNOWN.
*Test minimal.* Event study gelé : **événement** = fenêtre 5 min dont le notionnel liquidé dépasse un seuil **absolu**
pré-déclaré (notionnel / profondeur) **et** le p99 de la paire (le p99 seul produit ~3 fenêtres/jour/paire sans contenu) ;
**mesure** = retour du mid à 5/15/60 min après le pic (dire si l'effet vit dans les 60 premières secondes, hors de portée,
ou après) et mark-out d'un ordre passif posé à la minute suivante, sortie passive 60 min, pas de stop < 3 σ ; **contrôle** =
version **continue** (retour ~ intensité de liquidation à amplitude et heure égales ; le contrôle binaire « chute sans
aucun print » risque d'être vide sur alts) ; coûts = maker/maker + funding (une cascade à 23:50 UTC traverse la photo de
00:00 avec F au plafond) + stress spread ×3 + liquidation forcée au taux taker si la position est elle-même liquidée.
Passe si retour net ≥ +10 pb avec LB90 > 0 sur ≥ 100 **instants** indépendants (clustering par horodatage ; 1–3 instants/jour
⇒ 1–3 mois), positif à frais × 1,5 et sans le top 5 %. Livrable secondaire : distribution du retour par taille d'événement.

**3 — G5 Horloge comme signal.**
*Mécanisme.* Flux calendaires forcés : ouvertures 00:00 / 08:00 / 13:30 (EDT) ou 14:30 (EST) UTC ; photos de funding
00/08/16 UTC (OKX : « obligated to pay or receive … if you hold open positions at the point of fee assessment », évaluation
« may take up to a minute », vérifié ; X-Perps : même mécanisme et mêmes heures, nouveaux tokens à 4 h) ; minute 0 de
chaque heure (Hansen-Kim-Kimbrough : sursauts de volatilité liés au trading algorithmique et aux heures de funding —
littérature). Preuve A pour volatilité/volume (Eross et al. 2019 ; Wang-Liu-Hsu 2020), **B/UNKNOWN pour le rendement**
(Baur et al. 2019 : faible et instable ; dérive autour de la photo : aucune étude). **Kim & Hansen 2026** (arXiv 2607.09426) :
l'imbalance d'ouverture de quart d'heure prédit les rendements sur ~4–12 h hors échantillon sur six perps Binance (coûts
non traités) — déplace l'objet (imbalance, pas l'heure seule) et l'horizon (heures : **hors scalp**, mais dans la boucle minute).
*Pourquoi ça manque.* Tous les contrôles tick sont appariés à l'heure du jour : un effet d'horloge ne peut pas battre son
contrôle par construction (T2 §3.3) ; S3 opening drive (bougies 5 min, entrée marché, sorties taker) est le seul setup du
screen à battre son contrôle (~+0,1 R : −0,225 R vs −0,312 ; −0,285 vs −0,378 ; programme), jamais repris ; 08:00 et 14:30 UTC
jamais regardés ; le rejet desk FUND-SETTLE portait sur le **snipe** (encaisser F), pas sur la dérive de prix.
*Prior : moyen.* La valeur la plus sûre est celle d'un **filtre** (quand poster, quand ne pas poster) pour G1/G2/G4.
*Faisabilité : haute.* Seul gap où la lenteur est neutre. Développement sur X-Perps 1 m `consumed_equivalent` (la plupart
des contrats depuis juin–septembre 2026 ⇒ « stable par mois » = 3–6 mois au mieux), confirmation prospective. Le cron
actuel décide à hh:mm:00 ; armer avant l'heure demande un déclencheur à h−N (trivial dans Temporal) ; **ne pas armer à h−1**
(minute 0 = secondes toxiques, G3′) : h−5 ou h−2, vivre jusqu'à h+1.
*Test minimal.* **Un signe et une fenêtre pré-déclarés par événement** (ex. : si la prime publiée à h−1 est > 0, le mid baisse
sur [h−15 ; h] et remonte sur [h ; h+15] ; ouverture : le signe des 5 premières minutes se poursuit sur [h+5 ; h+60]) **ou**
correction de multiplicité déclarée (Holm) ; phase **screen** étiquetée comme telle sur données de développement, puis **un**
test confirmatoire ; contrôle = mêmes mesures aux heures sans événement (04/12/20 UTC et heures quelconques), jamais
apparié à l'heure ; **F dans le net**, stratifié par signe (une position ouverte à la photo le paie ou le reçoit) ; fenêtres
13:30/14:30 selon DST ; unité = **instant**, ≥ 100 instants par type d'événement (4–5 types ⇒ 4–5 semaines) ; passe si
(événement − contrôle) ≥ +10 pb maker/maker avec LB90 > 0, stable par mois. Sous-hypothèse M2 (imbalance → 4–12 h) à écrire
séparément, marquée hors scalp.

**4 — G1 Prime d'immédiateté en fournisseur, conditionnée au stress (sur consentement).**
*Mécanisme.* La concession est payée par l'impatient **pendant** son exécution ; celui qui l'encaisse est rempli dans le
mouvement, à un prix meilleur que le touch d'après (Grossman-Miller ; Hendershott-Menkveld). Nagel 2012 : le rendement de
l'apport de liquidité est **conditionnel** (fort quand la volatilité monte, nul en calme) et c'est un **facteur de marché**
— mesuré en actions à l'horizon jour ; le transfert à l'intraday crypto est une **hypothèse à geler**, pas un prior.
*Pourquoi ça manque.* S7/Q3 bat le contrôle apparié dans 7–8 fenêtres sur 9 (r3 +12,7 pb/361 ; r6 +10,2/1 698 échoue G5
seul ; r8b +18,6/151 janvier, +6,5/155 décembre à 9/20 paires ; Q3 +14,0/1 462) avec une inversion franche hors échantillon
(r7 déc. 2025 : −20,9 pb contre contrôle −15,8 ; Q3 V2 nov. 2024 : −20,7 pb) et une dépendance aux 5 % meilleurs trades
(q3x : −1,2 pb sans eux) — programme, `research-execution-tick-2026-10-12.md`, `research-midcap-tick-2026-10-13.md`,
`research-book-families-2026-10-13.md`, `research-q3x-2026-10-14.md`. Quatre défauts : entrée au touch après l'excursion ;
aucun conditionnement ; long seul ou poolé ; portes de densité. q3x : la moitié des trades passent 52 pb sous l'entrée, 27 %
plus de 100 pb — un stop serré coûte de l'espérance ; mais le contrôle « bande sans flux » gagne déjà +4,8 à +15,2 pb :
l'essentiel tient à l'état de bande apparié, et r12 (zone seule, passif, sortie au temps) est négatif hors échantillon.
*Prior : moyen.* La **seule** variable nouvelle admissible est le conditionnement (le volet « échelle vs E1 » est une variante
d'exécution d'une famille « suffisamment explorée », paysage) ; le résidu vs BTC est fusionné dans G6.
*Faisabilité : moyenne.* Poser des ordres au repos n'exige aucune latence, mais la plateforme n'a aucune brique (un ordre
par symbole, `max_concurrent_positions 3`, TTL 30 s, pas d'échelle, pas de renouvellement, pas de sortie passive à l'EMA ni
au temps) : moteur d'exécution à écrire. Une échelle 3 k × 2 côtés sur ≥ 20 paires immobilise de la marge bien au-delà de
500 USDT à 1× (≤ 4–5 ordres de 100 USDT vivants) ; « deux côtés » suppose le mode hedge (non vérifié, P2). Coûts omis :
funding (12–25 % de traverser une photo, F au plafond dans les régimes de stress visés), fills post-only annulés au
croisement, sélection adverse des carnets minces (G2), liquidation forcée au taux taker. La condition « volatilité 24 h >
médiane 30 j » exige 30 jours d'historique (tirés de `consumed_equivalent` comme entrée de filtre, à déclarer).
*Test minimal.* Pré-enregistrement gelé, **après consentement** portant sur la différence déclarée : échelle k σ15 ∈
{1,5 ; 2,0 ; 2,5} sous/sur l'EMA 15 min, renouvelée à la minute, post-only, through et touch, sortie passive à l'EMA ou au
temps 60–120 min, aucun stop < 3 σ15 ; condition pré-déclarée (vol. réalisée 24 h > médiane glissante 30 j ; variantes
spread courant, heures US) ; **critère de passage = stress − calme > 0 avec LB90 > 0** ; contrôle = « bande sans flux »
(q3x) et, si besoin, contrôle d'ancre (même distance depuis le mid courant ou une ancre aléatoire) ; **deux lectures** :
simulation sans contrainte de capital (n ≥ 600 fills, puissance) et portefeuille 500 USDT (exploitabilité, M5) ; funding
dans le net, frais × 1,5 **et** sélection adverse × 2 ; fills/jour estimés sur la capture G2 avant de fixer la durée (ordre
de grandeur de calcul à partir de r6 : ~11 fills/jour sur 29 paires, ~5 en stress ⇒ ≈ 4 mois pour 600) ; perte max par fill,
par jour, et règle d'arrêt pré-déclarées. Un échec sous cette forme ferme la famille, mécanisme compris.

**5 — G6 Réversion transversale intraday, neutre en dollar (suspendue).**
*Statut.* `H2-CLOSURE-DECISION.md` interdit de « changer rétroactivement les horizons », de « réutiliser les mêmes données
pour construire une nouvelle version supposée indépendante » (l.23–26) et étend l'interdiction « quelle que soit l'archive »
(l.109–110) ; l'univers X-Perps 1 m a été lu par H2 (univers secondaire). G6 est donc **suspendu à une décision explicite**
du propriétaire ; s'il l'autorise comme hypothèse nouvelle (choc 15–60 min, détention 1–4 h, coupe transversale), le
développement doit se faire sur des données **non lues par H2** (Binance hors programme, recherche seule) ou en prospectif pur.
*Prior : moyen.* Nagel et Makarov-Schoar (composante commune 80–85 %) sont A à l'horizon jour ; la seule coupe transversale
crypto trouvée est journalière et **contraire** pour les gros noms (Zaremba et al. 2021 : réversion chez les illiquides,
momentum chez les plus gros — les 29 X-Perps liquides sont les plus gros) ; intraday : aucune étude.
*Faisabilité : faible tel qu'écrit.* Déciles de 3 noms sur 29 (dérive mal retirée, résidu dominé par l'idiosyncratique) ;
6 jambes × 50 USDT par slot de 15 min × 4–8 slots ouverts = 1 200–2 400 USDT de marge à 1× ; unité statistique = le **slot**
(6 jambes = un tirage, autocorrélé ⇒ n_eff ≈ 100–150 par semaine) ; 2 jambes × (2 pb + sélection adverse) ≥ 6–8 pb ;
funding sur 1–2 h.
*Test minimal (si autorisé).* Deux lectures (simulation sans contrainte ; portefeuille 500 USDT = un slot sur 4–8, ou 2 vs 2,
ou levier déclaré) ; puissance calculée avec σ du résidu à 60 min (UNKNOWN, à estimer en développement) ; contrôle = rang
aléatoire et pool long+short sans rang ; concentration du décile déclarée ; résultat net du marché par construction.

**6 — G7 Momentum intrajournalier en temps-volume.** Prior moyen (Shen-Urquhart-Wang 2022 vérifié : « liquidity provision
rather than late-informed trading », gains en marché baissier ; coûts absents du résumé ; BTC spot pré-2020). Hors scalp
(une décision par jour, n_eff = jours, ≥ 100 jours ⇒ 3–4 mois prospectifs). À spécifier : partition **ex ante** (la
dernière demi-heure en temps-volume est définie ex post ; seuils cumulés sur les J derniers jours, erreur de calage mesurée),
source de volume (X-Perp mince ou swap global — signal OKX couvert par §9.4 pour le trading personnel), funding (une
« dernière demi-heure » peut durer des heures en calme). Contrôle = demi-heures aléatoires de la même journée. Soit fusion
dans G5 (horloge en temps-volume), soit sortie du périmètre « scalping ».

**7 — G8 Stop-runs / niveaux ronds / reclaim.** Prior moyen-faible (Osler A pour le regroupement et la continuation ;
Urquhart 2017 : aucun rendement anormal après le rond ; Mahadzva non lu, retiré). Faisabilité haute (bougies 1 m / 1 h ; les 20
paires du programme sont consommées ⇒ X-Perps développement ou Binance hors programme ; confirmation prospective) ; n ≥ 400
événements sur 29 paires = des mois. Test : pénétration d'un plus haut/bas N j (N ∈ {1, 3, 5}) ou d'un rond pré-déclaré
≥ x ATR puis clôture 1 h à l'intérieur ; **contrôle n° 1 = cassure réussie du même niveau**, n° 2 = touche sans pénétration ;
entrée **strictement postérieure** à la clôture définissante ; variantes passive au reclaim (fill incertain : le reclaim est
souvent une impulsion) **et** taker (+5 pb + spread) ; stop taker au-delà de la mèche (20–100 pb sur alts ⇒ R petit face à
8–9 pb) ; passe si (rejet − cassure réussie) ≥ +15 pb à 4 h avec LB90 > 0.

**8 — G9 Lead-lag BTC → alts minces (défensif).** Prior faible (Kurihara-Matsumoto 2026 résumé seul ; retard requis en
preneur 10–25 pb = 5 pb + spread 6–20 pb des minces, contre un mouvement BTC 1 min de quelques pb × β). Pas de protocole
séparé : si G2 enregistre BTC, la régression r_alt(1–5 min) ~ r_BTC(minute précédente, **décalée de la durée du run**) est
gratuite ; usage = ne pas laisser d'ordre au repos après un saut de BTC. Variante intra-venue retirée (= G13).

**9 — M3 Délistings / changements de contrat X-Perps.** Flux forcé à instant connu (fermeture obligatoire) : critère n° 3 du
verdict, omis par GAPS qui ne gardait que les listings. Fréquence et signe **UNKNOWN**. Inventaire d'abord (`instruments` :
`expTime`, état) ; event study prospectif seulement si le flux est suffisant.

### 3.4 Hors classement : résultats et familles closes

- **Horizons inaccessibles (ex-G11–G13), un résultat, pas un regret.** OFI de carnet, imbalance de file, microprix, icebergs,
  footprint/CVD (R² culmine à 1–2 changements de prix ; r1, r9, r8 = contrôles) ; tenue de marché sur carnets à 1 tick et rebond
  bid-ask (Part A : −2,09 … −3,49 pb nets) ; arbitrage de latence inter-venues et impulsion de news (ms ; une venue autorisée ;
  20–30 annonces/an). Usage **défensif** seulement : conditions 1 m sur le mid plutôt que sur le dernier prix (`near_vwap`,
  MACD) ; pas d'ordre au repos après un saut du meneur ni dans les 30 min autour des annonces (canal OKX `economic-calendar`
  comme filtre gratuit). Toute proposition de scalp à la seconde est hors périmètre tant que l'architecture est une boucle
  REST à la minute.
- **Familles closes (G14–G16), à ne pas rouvrir sans variable d'événement ou de positionnement nouvelle** : états
  d'indicateurs et empilements ; positionnement OI (r13/r14), funding contrarien D4 (−802 pb de surplus, programme), snipe de
  funding / FUND-SETTLE / EXPIRY-PIN, prime mark/index comme gain ; mèche / exhaustion / burst fade (S2 +2,0 pb/1 008 tr.,
  S6 +7,3 pb/837 : réels, trop petits ; programme), cartes de liquidation, murs de carnet (31 % « spoofables », Fabre-Challet
  2025 — littérature), aimant des ronds, « dip de hack » sur BTC, second move macro ; H1 à 14 j et H2 à 24 h / 3 j (clos par
  décision du propriétaire, interdiction de récupération a posteriori) ; levier > 2× (NOT_INFORMATIVE). Rappel de la relecture
  exécution : la liquidation forcée est facturée au taux **taker** du tier (FAQ frais EEA) — coût de queue de toute règle sans
  stop (G1, G4).
- **Annexe : G10 premières heures après listing X-Perp** : ne pas lancer (≈ 2,2 événements/mois, funding 4 h ±1 %, bandes,
  4 USD de notionnel au stop de 60 % à 500 USDT — `H1-XPERP-500USDT-FEASIBILITY.md`) ; consigner seulement.

### 3.5 Règles transversales à écrire dans tout pré-enregistrement (GAPS §6, amendé par les relectures)

1. **Nommer le payeur** ; un état d'indicateur n'en a pas.
2. **Deux colonnes de prior** : « payeur nommé » et « **preuve du signe** (A/B/C/UNKNOWN) » ; « fort » exige les deux.
3. **Déclarer la condition** (volatilité, spread, heure, toxicité) avant la lecture ; le test est la différence conditionnelle.
4. **Retirer la dérive du marché** à l'intraday (résidu ou portefeuille) ; le pool long+short ne suffit pas.
5. **Un contrôle qui n'efface pas l'événement** : apparié à l'heure pour un flux ; « mêmes règles à d'autres heures » pour
   l'horloge ; « cassure réussie » pour les stops ; **version continue** pour les cascades ; « bande sans flux » (ou ancre)
   pour la réversion.
6. **Une exécution cohérente avec le mécanisme** : fournisseur (ordres au repos, **post-only**, modèle d'annulation au
   croisement) pour une concession ; preneur pour un retard — et le coût mesuré (G2), pas supposé.
7. **Tous les coûts** : frais 2/5, sélection adverse mesurée, **funding** (probabilité de traverser une photo, F par signe,
   plafond en stress), **marge gelée** (deux lectures : simulation / portefeuille 500 USDT), liquidation forcée au taux taker.
8. **Un carnet réel de la place cible** (capture X-Perps), jamais un proxy de prints à 1 tick ; **transférabilité** déclarée
   (M9) pour tout développement sur Hyperliquid ou Binance.
9. **Porte de puissance calculée** (≈ 600 trades pour +10 pb à σ ≈ 100 pb, calcul d'audit ; unité = instant ou slot avec
   clustering) sur un univers assez large ; **pas** de porte « 300 trades / 20 paires / 4 semaines » ni « 70 % des paires ».
10. **Budget de multiplicité** (Holm sur les protocoles lancés : neuf tests unilatéraux à 5 % sans correction ⇒ ~37 % de
    chance d'un faux pass) ; **un signe et une fenêtre pré-déclarés** par event study ; phase screen étiquetée.
11. **Porte économique** : plafond USD/an = fills/jour × notionnel × net pb, et règle d'arrêt, écrits avant le gel.
12. **Pas de stop serré** sur une règle de fourniture de liquidité ; la taille et des bornes de perte pré-déclarées portent
    le risque.
13. **Gel sha256 avant toute lecture ; une seule exécution ; jamais W0–W2 ni les fenêtres consommées** (`RESEARCH-CAMPAIGN-STATUS.md`
    §3) ; **confirmation = `okx-xperp-prospective` ≥ 2026-11-02 = capture publique + simulateur de recherche**, jamais Paper
    certifié, démo ou ordre, tant que l'écart technique X-Perps n'est pas comblé par des travaux M–L et une décision séparée.
14. **Séquence** : P1 → P2 → P3 → G2 → G4 (Gate 1 HL) → G5 → G1 (consentement) ; G6 sur décision ; G7–G9, M3 seulement s'il reste
    une marge ; rien avant le verdict fwd-okx-r1 du 31 octobre.

---

## 4. Méthode : ce que le programme a bien fait, et ce qu'il aurait dû faire autrement

### 4.1 Bien fait (à garder)

- **Pré-enregistrements gelés** (sha256) avant lecture, holdouts jamais relus, registre des fenêtres consommées, verdicts
  négatifs publiés tels quels, décisions de clôture écrites par le propriétaire (H1, H2) : la gouvernance est propre et
  c'est elle qui rend ce rapport possible (T2 §3.8 : « les pré-enregistrements sont gelés et honnêtes »).
- **Contrôles appariés** au régime et à l'heure : c'est précisément le test « information vs bruit » (ligne 1 de la
  taxonomie) ; ils ont correctement fermé les états d'indicateurs et les stacks (« the stacks equal random timing »).
- **Contrôle apparié au prix** (r13/r14) : le meilleur contrôle du programme ; il a tué proprement le positionnement OI.
- **Tests au bon horizon pour les poches HFT** (r1 à 60–300 s, r9 sur `bookTicker`) : négatif attendu, cohérent avec la
  théorie ; rien à regretter.
- **Corrections honnêtes en cours de route** : σ en temps calendaire corrigé (Q3L), amendement déclaré du contrôle (Q3 V2),
  analyse de puissance pré-enregistrée (H1, H2), reconnaissance des limites (« G5 is unreachable at ~4 trades per series »).
- **Aucun ordre, aucune démo**, flags à 0, données réservées respectées (W0–W2, fwd-okx-r1).

### 4.2 Aurait dû faire autrement

1. **Tester des mécanismes à payeur, pas des états.** 12 protocoles sur 24 ont testé des états d'indicateurs et 7 des variantes
   d'une même réversion (T2 §0–2) ; peu d'hypothèses nouvelles de mécanisme, beaucoup de variantes de la même.
2. **Conditionner et retirer la dérive.** La seule famille à contenu (réversion) est prédite **conditionnelle** et **nette du
   marché** (Nagel) ; elle a été exigée inconditionnelle, absolue, paire par paire (T2 §3.6–3.7) — et le signe a suivi le mois.
3. **Exécuter en fournisseur quand le mécanisme est une concession.** Entrée au touch après l'excursion = mesurer la réversion
   depuis le touch en payant la sélection adverse, pas recevoir la concession (T1 §3.1).
4. **Choisir des contrôles qui n'effacent pas l'événement.** Les contrôles appariés à l'heure ont rendu tout effet d'horloge
   invisible par construction (T2 §3.3) ; le seul signal à contenu du screen bougies (S3) a été mangé par l'exécution la plus
   pessimiste et jamais repris.
5. **Utiliser un carnet réel de la place cible.** Proxies de prints Binance forcés à 1 tick (capture de spread nulle par
   construction), 30–59 % de secondes sans print sur mid-caps (σ biaisé), Part A sur les deux carnets les plus serrés du
   marché (T2 §3.2) ; tout sur Binance, la place la moins propice aux inefficacités que la théorie attend sur les places minces.
6. **Calibrer les portes sur la puissance, pas sur la densité.** G1 (300 trades / 20 paires / 4 semaines) et G5 (70 % des
   paires) ont éliminé mécaniquement les règles rares à forte espérance ; validations d'une semaine ou d'un mois à 50–70 % de
   puissance pour +10 pb (T2 §3.4–3.5) ; la vérification « ≥ 10 paires sur 20 » à ~8 trades par paire laisse 10–20 % de faux
   négatif même si l'effet est réel (calcul d'audit T2 §P11). La réponse jamais essayée : **élargir l'univers** plutôt
   qu'allonger les fenêtres.
7. **Lire la machine avant de la modéliser.** La boucle vivante ne poste pas (preuve microstructure absente ; établi, en-tête) ;
   `order_mode` mal compris ; fees internes 5/5 contre un barème 2/5 ; OFI = profondeur, pas transactions ; stops au mark ;
   levier ×20 ; univers USDT swaps ; aucun chemin X-Perps. Le profil actif n'a **jamais** été porté tel quel (r10 a porté
   `scalping.*` à la cadence 5 min). La seule fiche vérifiable sur le code de GAPS contenait des erreurs : c'est la
   relecture qui l'a vu.
8. **Compter tous les coûts.** Funding, marge gelée, post-only/annulation, liquidation forcée au taux taker : absents de
   toutes les fiches jusqu'à la relecture exécution.
9. **Budgéter la multiplicité et l'économie.** ~1 870 configurations + 180 exploratoires sur 20 paires (T2 §3.8) ; neuf gaps à
   5 % unilatéral sans correction ; aucun gap chiffré en USD/an avant la relecture quant.
10. **Vérifier la licence et la couverture avant de capturer** (§9.4 ; canaux X-Perps UNKNOWN) et **ne jamais citer une
    source non lue comme prior** (Mahadzva 2026, Kurihara-Matsumoto 2026 résumé seul).

---

## 5. Verdict global honnête : le scalping pour un retail à la minute, à 2/5 pb

1. **Le scalping à la minute sur états est fermé**, par la théorie (aucun payeur) et par le programme (~1 870 configurations,
   signaux = contrôles). Le profil `scalper_micro` et les contrats 1.1.0 décrivent le marché, ils ne le prédisent pas ; aucun
   seuil ne change cela. Et la machine déployée, en l'état, ne poste pas (établi par le code et le journal, en-tête ; P1 reste utile pour les
   raisons de rejet de la certification et la durée du run) ; corrigée, elle
   paierait 6–7 pb maker/maker (ou jusqu'à 10–18 pb avec des entrées non post-only et des sorties taker) pour une espérance
   brute nulle.
2. **Les poches rapides sont hors de portée**, et nous y sommes payeurs : la sélection adverse de 1,1–1,5 pb par jambe passive
   (programme) est le tribut versé aux acteurs à la seconde. « Et si on allait plus vite ? » est fermé pour cette architecture.
3. **Ce qui reste est un programme de fourniture de liquidité événementielle**, pas un scalp : être payé pour l'immédiateté
   en stress, en portefeuille, sans stop serré ; absorber des flux forcés observables (cascades, stops) ; se placer autour
   d'instants connus (sessions, funding). Pour chacune de ces poches la théorie nomme un payeur, mais **aucune n'a de preuve
   publiée du signe au bon horizon** (A mécanisme, C/UNKNOWN signe) ; les priors sont moyens, pas forts.
4. **Les contraintes du compte pèsent autant que le signal** : 500 USDT à 1× = 4–5 ordres de 100 USDT au repos en tout ;
   funding traversé 12–25 % du temps sur 1–2 h, au plafond dans les régimes de stress visés ; frais 2 pb sans rabais face à
   des teneurs de marché peut-être à 0 ou négatifs (M4 UNKNOWN) ; aucun chemin d'exécution X-Perps ; licence §9.4 à décider.
5. **L'ordre de grandeur économique, si tout passe** : avec les hypothèses du test G1 (600 fills en 8 semaines × ~100 USDT ×
   +10 pb nets) ≈ 350–400 USD/an ; avec 2 slots de 50 USDT et 5 fills/jour ≈ 90 USD/an (calcul d'audit, relecture quant).
   Des dizaines à quelques centaines d'USD par an, avec un facteur 4–15 d'incertitude selon le taux de fills, des queues
   épaisses non bornées par un stop, et un coût fixe (collecteur X-Perps, moteur d'exécution fournisseur, capture longue,
   temps du propriétaire) qui dépasse probablement ce plafond. **Ce n'est pas un revenu** ; au mieux une mesure.
6. **Ce que ce rapport ne dit pas** : qu'une de ces poches est rentable sur OKX après coûts. Il dit où la théorie désigne un
   payeur et où nous n'avons pas regardé avec la bonne donnée, la bonne exécution et le bon contrôle — et il dit que la
   probabilité a priori d'un succès exploitable à 500 USDT est modeste. La valeur principale du travail restant est de
   **clore proprement** la question du scalping retail à la minute, mécanisme compris, plutôt que de la laisser ouverte sur
   des règles mal testées.

---

## 6. Décisions attendues du propriétaire

1. **Autoriser P1 (diagnostic de la machine, zéro donnée de marché)** : la boucle poste-t-elle depuis 1.1.7 ? ventilation des
   raisons de rejet (production et certification Paper #132) ; durée du run et instant réel de décision ; table
   « configuration déclarée / comportement réel ». Lecture de champs texte des journaux par le propriétaire ou un agent
   habilité ; livrable avant toute autre action.
2. **Lire l'OKX API Agreement §9.4 (EEA, 28 juillet 2026) et décider** si une capture publique de 2–8 semaines sur 20–30
   X-Perps pour recherche personnelle, et le travail d'agents sur ces données, sont acceptables — avant G2/G4. Formulation
   exacte de 9.4(c) (ML/IA) à relire dans le texte intégral.
3. **Faire les GET en lecture seule au compte X-Perps** (liste `H1-XPERP-MARKET-ACCESS.md` §3.5) : mode de position, levier
   effectif et plafond, permissions API, règle de gel de marge des ordres au repos, barème via API, programme MM / rabais
   maker. Aucun ordre.
4. **Trancher le vocabulaire et l'objectif** : le standing objective « scalping / micro-scalping » devient-il « fourniture de
   liquidité événementielle à la minute », ou la question est-elle close ? Les items du programme (préalables, G2, G4, G5)
   sont des mesures, pas des stratégies.
5. **Consentement explicite ou refus pour G1** (réversion en fournisseur conditionné), famille « suffisamment explorée » :
   le consentement porte sur la **différence déclarée** (ordres au repos avant le touch, post-only, sans stop serré,
   critère stress − calme seul), en ayant vu que q3x attribue l'essentiel du gain à l'état de bande et que r12 est négatif
   hors échantillon.
6. **Décision explicite sur G6** au regard de `H2-CLOSURE-DECISION.md` §4 : hypothèse nouvelle autorisée sur données non lues
   par H2 (ou prospectif pur), ou refus. Sans décision, G6 reste suspendu et n'apparaît dans aucun plan.
7. **Autoriser ou non P3** (collecteur X-Perps, effort S–M ; capture de comptage 24–72 h, zéro prix) — après la décision 2 et
   après le verdict fwd-okx-r1 du 31 octobre.
8. **Autoriser ou non le Gate 1 historique de G4 sur l'archive Hyperliquid** (statistique seule, licence et schéma à
   vérifier sur un échantillon de schéma, transférabilité HL → X-Perps déclarée, M9).
9. **Fixer la porte économique et la règle d'arrêt du programme** : plafond USD/an en dessous duquel une famille n'est pas
   poursuivie même si elle passe ; budget de multiplicité (Holm) ; durée maximale du programme de mesures.
10. **Décider des corrections de configuration indépendantes de toute hypothèse** (post-only, modèle interne de frais 5/5 →
    barème réel, levier ×20 vs plafond retail, univers USDT swaps vs X-Perps, gardes de spread) — en sachant qu'elles ne rendent
    pas le profil rentable (le déclencheur reste un état) ; ou les différer jusqu'à la décision 4.
11. **Confirmer le calendrier** : rien avant le verdict fwd-okx-r1 (après le 31 octobre) ; confirmation uniquement sur
    `okx-xperp-prospective` ≥ 2026-11-02 ; W0–W2 et les fenêtres consommées jamais réutilisées.
12. **Dire si les hypothèses hors scalp** (M2 imbalance d'ouverture → 4–12 h ; G7 temps-volume ; M3 délistings) entrent dans
    ce programme ou dans un autre, ou nulle part.

---

## 7. Sources

**Dossiers de la mission (privé, lecture seule)** : `../t1-microstructure/NOTES.md` (taxonomie T1–T14, §3) ;
`../t2-audit-protocoles/NOTES.md` (P1–P24, §3.1–3.9, §4–5) ; `../t3-praticiens/NOTES.md` (P1–P16, §2–4) ; `../gap/GAPS.md`
et `../gap/NOTES.md` ; `../review/quant.md`, `../review/NOTES.md` ; `../review/exec.md`, `../review/NOTES-exec.md`. Les
chiffres « programme » cités ici sont ceux recopiés et contre-vérifiés par ces dossiers (grep dans les rapports) ; je n'ai
relu aucune série.

**Dépôt (lecture seule)** : `docs/handbook/research/{RESEARCH-CAMPAIGN-STATUS, RESEARCH-ELIGIBILITY-GATES,
TRADABLE-INSTRUMENT-UNIVERSE, H2-CLOSURE-DECISION (l.23–26, 109–110 vérifiées), H1-XPERP-TECHNICAL-GAP, H1-XPERP-ACCOUNT-CHECK,
H1-XPERP-MARKET-ACCESS, research-new-market-hypotheses}.md` ; `docs/handbook/reports/{research-strategy-landscape-2026-10
(§ Sufficiently explored, l.120–128 vérifiées), research-execution-tick-2026-10-12, research-midcap-tick-2026-10-13,
research-book-families-2026-10-13, research-scalping-2026-10-12, research-scalping-120-2026-10-13, research-zone-only-2026-10-13,
research-oi-positioning-2026-10-13, research-q3x-2026-10-14}.md` ; `docs/handbook/recap-2026-10-08-okx-hyperliquid.md`.
Faits de code : attribués aux relectures (`review/quant.md` §6, `review/NOTES-exec.md` §2 : `OkxActionFactory.php`,
`OrderPlanModel.php`, `ExchangeExecutionService.php`, `NetRCostMath.php`, `TimeframeValidationService.php`,
`MicrostructureProof.php`, `StrictRuleEvaluator.php`, `LimitFillWatchMessageHandler.php`, `MicrostructureConditionsTest.php`,
`manage_scalper_micro_schedule.py`) ; je n'ai pas relu le code.

**Documentation officielle (lue par T3, GAPS, les relectures ; dates du 2026-10-20)** : OKX « perps funding fee mechanism »
(https://www.okx.com/help/perps-funding-fee-mechanism) ; OKX X-Perps guide (https://www.okx.com/en-eu/help/x-perps-guide) ;
OKX API Agreement EEA (https://www.okx.com/en-eu/help/okx-api-agreement, « Last Updated: 28 July 2026 ») ; OKX FAQ frais EEA
(https://www.okx.com/en-eu/help/trading-fee-rules-faq-eea) ; OKX docs-v5 (https://www.okx.com/docs-v5/en/, pages tronquées,
canal `liquidation-orders` non re-vérifié) ; OKX « Mark price and Last price » (https://www.okx.com/help/6974312839693) ;
`okx.com/fees` : **non rendue** ; Hyperliquid historical data (https://hyperliquid.gitbook.io/hyperliquid-docs/historical-data)
et guide tiers Dwellir (champ `liquidation`, dates : **à vérifier sur schéma**) ; binance-public-data issue #337
(https://github.com/binance/binance-public-data/issues/337 : `liquidationSnapshot` discontinué).

**Littérature (DOI/URL, telles que vérifiées par T1–T3 et les relectures)** : Kyle 1985 (Econometrica 53(6), JSTOR 1913210) ;
Glosten-Milgrom 1985 (DOI 10.1016/0304-405X(85)90044-3) ; Roll 1984 (DOI 10.1111/j.1540-6261.1984.tb03897.x) ; Grossman-Miller
1988 (JF 43(3), NBER w2641) ; Nagel 2012 (DOI 10.1093/rfs/hhs066) ; Hendershott-Menkveld 2014 (DOI 10.1016/j.jfineco.2014.08.001) ;
Ho-Stoll 1981 (JFE 9(1)) ; Avellaneda-Stoikov 2008 (DOI 10.1080/14697680701381228) ; Glosten 1994 (DOI 10.1111/j.1540-6261.1994.tb02450.x) ;
Sandås 2001 (RFS 14(3)) ; Easley-López de Prado-O'Hara 2012 (DOI 10.1093/rfs/hhs053) ; Cont-Kukanov-Stoikov 2014 (DOI 10.1093/jjfinec/nbt003) ;
Kolm-Turiel-Westray 2023 (DOI 10.1111/mafi.12413) ; Stoikov 2018 (DOI 10.1080/14697688.2018.1489139) ; Bouchaud-Farmer-Lillo 2009
(arXiv 0809.0822) ; Osler 2003 (DOI 10.1111/1540-6261.00588) et 2005 (JIMF 24(2), FRBNY SR 150) ; Urquhart 2017 (DOI 10.1016/j.econlet.2017.07.035) ;
Cheng-Deng-Wang-Yu 2021 (arXiv 2102.04591) ; Garcia Seuma 2026 (arXiv 2607.27070) ; Lim 2026 (J. FinTech & Digital Assets 1(2),
journals.lincoln.ac.nz/index.php/JFDA/article/view/1395) ; Budish-Cramton-Shim 2015 (DOI 10.1093/qje/qjv027) ; Makarov-Schoar 2020
(DOI 10.1016/j.jfineco.2019.07.001) ; Albers et al. 2021 (arXiv 2108.09750) ; Hansen-Kim-Kimbrough (arXiv 2109.12142 ; J. Fin.
Econometrics 22(1) 2024) ; **Kim & Hansen 2026 (arXiv 2607.09426)** ; Shen-Urquhart-Wang 2022 (DOI 10.1111/fire.12290) ; Gao-Han-Li-Zhou
2018 (DOI 10.1016/j.jfineco.2018.05.009) ; Heston-Korajczyk-Sadka 2010 (DOI 10.1111/j.1540-6261.2010.01573.x) ; Baur-Cahill-Godfrey-Liu
2019 (FRL 31) ; Eross-McGroarty-Urquhart-Wolfe 2019 (RIBAF 49) ; Wang-Liu-Hsu 2020 (FRL 34) ; He-Manela-Ross-von Wachter (arXiv
2212.06888) ; Benigno-Rosa 2023 (FRBNY SR 1052) ; Zaremba et al. 2021 (IRFA, via résumé — relecture quant) ; Tiniç et al. 2023
(DOI 10.1111/jfir.12317) ; Bianchi-Babiak-Dickerson 2022 (CERGE-EI WP 730) ; Kurihara-Matsumoto 2026 (DOI 10.1007/s10690-026-09589-z,
**résumé seul**) ; Fabre-Challet 2025 (arXiv 2504.15908) ; Ante 2019 (BRL WP) ; Mahadzva 2026 (SSRN 7430998, **non lu — retiré des priors**).

**Recherches sans résultat (consignées par T3 et les relectures)** : étude du retour du prix après cascade de liquidation sur
CEX ; dérive de prix autour de la photo de funding ; réversion transversale **intraday** en crypto ; capture réalisée de spread
sur alts minces ; rentabilité des « failed breakouts » à comité de lecture ; termes officiels du programme market maker OKX.

**UNKNOWN laissés tels quels** : durée du run MTF et instant réel de l'ordre ; nombre d'ordres postés depuis 1.1.7 ; règle de
gel de marge, mode de position, levier effectif du compte X-Perps ; couverture et fréquence des canaux publics pour `instType
FUTURES` ; formulation exacte de §9.4(c) ; schéma, dates et licence de l'objet `liquidation` Hyperliquid ; mécanique du moteur
de liquidation OKX X-Perps ; rabais maker sous programme MM OKX Europe ; taux de fill à k σ15 et sélection adverse des carnets
minces X-Perps ; distribution du retour post-cascade par taille ; fréquence des délistings X-Perps ; spread courant des X-Perps
(non lu, interdit).
