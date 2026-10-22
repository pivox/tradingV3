# LP-G4 — Retour du prix après une cascade de liquidations (pré-enregistrement, brouillon)

**Statut : `DRAFT_NOT_FROZEN`.** Brouillon du 2026-10-20 (volet a3 du programme `20261020T1430Z-lp-programme`). Aucun sha256 de
gel, aucune donnée lue (ni archive Hyperliquid, ni flux OKX), aucun téléchargement, aucun ordre. Ce texte n'autorise rien par
lui-même. Règles communes : `LP-PROGRAMME-GATES.md`.

**Révision du 2026-10-20 (passe unique après les relectures `review/quant.md` et `review/cto.md`, verdicts `NEEDS_CHANGES`).**
Statut inchangé. Constats bloquants et majeurs appliqués dans le corps ; détail constat → traitement en §10 « Traitement de la
revue » (refus écrits « Réponse à la revue »). Les corrections de code (détecteur, évaluateur) sont des prérequis du gel (P-G4-5),
pas des modifications faites par cette passe ; deux d'entre elles sont déjà dans le code par la passe parallèle `a5-corrections-cto`
(CTO m7 : un `side` inconnu compte dans L, jamais dans le notionnel signé ; CTO M2 : `arm_ts_us = end_ts_us = ts_us` pour une grappe —
état vérifié le 2026-10-21, `LP-G2` P-G2-4 (A)) ; le seuil relatif, `method = market`, `t_e`, le cool-down 60 min et le cluster
horaire de §3.3 restent à écrire (P-G4-5).

**Base.** Fiche G4 (§3.3, rang 2), corrections de la relecture quant (§3.1 : prior ramené à **moyen**, « prospectif seulement »
faux — l'archive de fills Hyperliquid porte un marqueur de liquidation, M1 ; transférabilité à déclarer, M9 ; mécanique du
moteur OKX UNKNOWN, M8) et règles §3.5 du rapport `docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md` (branche
`claude/research-scalping-theory-gap`, lue depuis le worktree `research-scalping-theory`). Décision 8 attendue du propriétaire
(§6 du même rapport) : autoriser ou non le Gate 1 historique sur l'archive Hyperliquid.

**Nature.** Deux portes, deux données, un seul mécanisme. **Gate 1 (historique, Hyperliquid, statistique seule)** : le mid
revient-il après une **cascade** de liquidations — définie relativement à la paire (§3.3), datée du dernier fill liquidé — et
davantage quand la cascade est plus intense, à amplitude et heure **réellement** égales (contrôle continu avec amplitude
continue **et** contrôle apparié au prix, co-primaires, §6) ? Aucune exécution simulée, aucun PnL : un signe et une forme, en
unités de volatilité locale. **Gate 2 (prospectif, OKX Europe X-Perps)** : un ordre passif posé à la minute suivante, côté
fournisseur, gagne-t-il un retour **≥ +10 pb nets et ≥ un seuil en σ** après maker/maker, funding, stress de spread et queue de
liquidation ? La transférabilité HL → X-Perps est **déclarée** (§3.4) et ne vaut que pour le signe : aucun chiffre d'Hyperliquid
n'entre dans un critère d'OKX, et un FAIL HL n'est pas une preuve d'absence sur OKX (§3.4, §8).

---

**Amendement 2026-10-22 (décision P-L2-1, D14 ; avant tout gel et toute lecture).** Statut inchangé. Gate 1 : aucun recouvrement
(archives ≤ 2026-09-30). Gate 2 : l'historique du percentile (2 016 fenêtres de 5 min, au moins 288), le σ₁₅ et le notionnel sur 24 h et
le contrôle apparié ne contiennent aucune fenêtre de l'intervalle exclu E = [2026-11-02T00:00Z ; 2026-11-03T12:00Z) du pilote de capture
L2 (`LP-PROGRAMME-GATES.md` §7.4) : `research_lp events` refuse les fichiers jour 2026-11-02 et 2026-11-03, l'historique commence au plus tôt
le 2026-11-04, les fenêtres antérieures sont absentes, jamais imputées (§4.2) ; T0 ≥ fin de P3, P3 commençant au plus tôt au
2026-11-03T12:00Z (§7.5). Durée de 90 jours, futilité et planchers inchangés ; sans effet au T0 du plan (≈ 2026-12-01 → 12-10).

## 0. Déclarations préalables

0.1 **Aucune donnée lue.** Aucun fill, carnet, prix, funding ou liquidation d'Hyperliquid ou d'OKX, courant ou historique, n'a été
lu par l'auteur. L'archive `hl-mainnet-node-data` n'a pas été ouverte ; aucun objet S3 n'a été listé ni téléchargé. Le canal
`liquidation-orders` d'OKX n'a pas été écouté.

0.2 **Expositions antérieures déclarées comme influence** (programme) : r13 O1 « flush de liquidation » par proxy d'OI Binance
à 5 min lu 15 min après, seuil 1,5 σ, entrée au touch : −18 à −38 pb, **sous** le contrôle apparié au prix
(`research-oi-positioning-2026-10-13.md`) ; r14 (`research-oi-buildup-2026-10-13.md`) : O2 positif à l'entraînement, négatif
en validation. Ces résultats portent sur un **proxy** et une exécution **preneur** ; ils ne sont ni un prior pour, ni contre
H-G4, et n'entrent dans aucun seuil. Littérature (citée par le rapport théorie, pas relue ici) : Cheng-Deng-Wang-Yu 2021
(anatomie des liquidations BitMEX) ; Lim 2026 (liquidité HL le 2025-10-10 : spreads et profondeur, pas le prix) ; Garcia Seuma
2026 (pas de signal d'alerte invariant). **Aucune étude du retour du prix après cascade trouvée** (vérifié deux fois par T3 et la
relecture quant) : preuve du signe **C/UNKNOWN**, prior **moyen**.

0.3 **Payeur nommé** (règle 1) : le liquidé, dont la position est fermée par le moteur sans égard au prix ; en second, le fonds
d'assurance / l'ADL. **Condition déclarée** (règle 3) : l'intensité de la cascade (notionnel liquidé par ordres **au marché**
dans une fenêtre de 5 min, rapporté à l'activité de la paire, §3.3), à amplitude du mouvement et heure égales. **Contrôle qui
n'efface pas l'événement** (règle 5) : version **continue** (retour ~ intensité, amplitude en continu dans les déciles) **et**
contrôle apparié au prix (même |A|, même heure, même paire, liquidation faible), jamais un contrôle binaire « chute sans aucun
print » (vide sur alts).

0.4 **Documentation officielle relue le 2026-10-20** (WebFetch ; extraits verbatim) :
- Hyperliquid, type WebSocket `WsFill` (docs « Subscriptions ») : `liquidation?: FillLiquidation` avec `liquidatedUser?: string ;
  markPx: number ; method: "market" | "backstop"` ; `crossed: boolean // whether order crossed the spread (was taker)` ;
  `dir`, `startPosition`, `closedPnl`, `fee`, `tid`, `time`. L'exemple `userFills` de la page « Info endpoint » **ne montre pas**
  ce champ. Présence effective dans l'archive : **UNKNOWN** avant l'échantillon de schéma (P-G4-3).
- Hyperliquid, « Historical data » : `s3://hl-mainnet-node-data/node_fills_by_block` (« fills which are streamed via
  `--write-fills --batch-by-block` from a non-validating node »), « Older data is in a different format at
  `s3://hl-mainnet-node-data/node_fills` and `s3://hl-mainnet-node-data/node_trades` », « `node_fills` matches the API format,
  while `node_trades` does not » ; carnets : `s3://hyperliquid-archive/market_data/[date]/[hour]/[datatype]/[coin].lz4` ;
  contextes : `s3://hyperliquid-archive/asset_ctxs/[date].csv.lz4` ; « the requester of the data must pay for transfer costs » ;
  « There is no guarantee of timely updates and data may be missing ». **Aucune date de début, aucune licence, aucune mention de
  liquidation sur cette page** (UNKNOWN).
- Hyperliquid, « Liquidations » : « the positions are first attempted to be entirely closed by sending market orders to the
  book » ; positions > 100 k USDC : « only 20% of the position will be sent as a market liquidation order to the book »,
  puis 30 s de pause ; « If the account equity drops below 2/3 of the maintenance margin without successful liquidation through
  the book », liquidation de **backstop** par le « liquidator vault » (composante de HLP) ; « Unlike CEXs there is no clearance
  fee on liquidations » ; ADL non mentionné.
- OKX : canal WS `liquidation-orders` — **établi** par le desk sur la docs-v5 téléchargée et hachée (`LP-DATA-DESK-2026-10-20.md`
  §1.3, `a4-data-desk/txt/docs-v5-en.txt` ; quant m1) : souscription par `instType` ∈ « SWAP FUTURES MARGIN OPTION » (pas
  d'`instId`), champs `details[]` `side` (« buy sell — Applicable to FUTURES/SWAP »), `posSide`, `bkPx` (« Liquidation mark
  price. The price of the transaction with the system's liquidation account »), `sz` (« For FUTURES/SWAP, the unit is
  contract »), `bkLoss`, `ts` (« Liquidation time ») ; disclaimer « This data doesn't represent the total number of liquidations
  on OKX » ; « Liquidation data comes from different data sources, so the updated data is not necessarily in chronological
  order » ; historique REST retiré (changelog 2023-04-03). Seule la **part des X-Perps** dans le flux reste **UNKNOWN** (P3,
  P-G4-8). Liquidation forcée « charged according to the taker fee rate of your current level »
  (FAQ frais EEE, màj 2026-10-07, relue par A1). ADL : file par « leverage PnL% », fermeture au mark ou au prix de faillite,
  « no warnings are issued prior to ADL occurring » (page ADL, relue par A1). Mécanique du moteur de liquidation des X-Perps
  (reprise de la position, paliers, qui sert l'ordre au repos) : **M8 UNKNOWN**.

0.5 **Aucune prédiction** autre que le mécanisme : la règle ne lit ni indicateur ni tendance ; elle réagit à un flux forcé observé.

0.6 **Interdits respectés** : voir `LP-G2-DRAFT-PREREGISTRATION.md` §0.7 (identiques).

---

## 1. Hypothèses (deux portes)

**H-G4-1 (Gate 1, Hyperliquid, signe et forme).** Sur les perpétuels Hyperliquid, dans les fenêtres de 5 min où le notionnel
liquidé **par ordres au marché** est une **cascade relative à la paire** (§3.3 : ≥ p99,5 glissant **et** ≥ k × médiane des
fenêtres non nulles des 7 jours **et** ≥ x % du notionnel échangé des 24 h précédentes), le mid **revient** dans le sens opposé
au flux forcé sur les 15 minutes qui suivent le **dernier fill liquidé** de la fenêtre, en unités de volatilité locale σ_15 ; ce
retour **croît avec l'intensité** de la cascade à amplitude du mouvement et heure réellement égales (β_intensité > 0, amplitude en
continu) **et** dépasse celui des fenêtres appariées au prix sans cascade (contrôle apparié co-primaire, §6).

**H-G4-2 (Gate 2, OKX X-Perps, exploitabilité).** Sur les X-Perps crypto d'OKX Europe, un ordre passif post-only posé au touch
côté fournisseur à la minute suivant une telle fenêtre, tenu au plus 60 min avec sortie passive, sans stop plus serré que 3 σ,
obtient un **retour net ≥ +10 pb et ≥ +0,25 σ_60** (σ_60 = volatilité locale de l'horizon aller-retour, §7.4) après frais
maker/maker, funding réel, stress de spread × 3 sur le reliquat taker, et liquidation forcée au taux taker si la position est
elle-même liquidée.

**Une seule fenêtre et un seul signe par event study** (règle 10) : horizon primaire **15 min** (Gate 1) et **aller-retour ≤ 60 min**
(Gate 2) ; le côté est celui du fournisseur (opposé au flux forcé dominant). Les horizons 60 s, 5 min et 60 min sont rapportés
pour dire **où vit l'effet** (si tout tient dans les 60 premières secondes, il est hors de portée d'une pose à la minute) — ils ne
sont pas testés.

---

## 2. Prérequis bloquants (responsable entre parenthèses)

### 2.1 Pour le Gate 1 (Hyperliquid)

| Id | Prérequis | Responsable | Preuve | Sans quoi |
|---|---|---|---|---|
| **P-G4-1** | **Consentement écrit** au Gate 1 historique sur l'archive Hyperliquid (décision 8 du rapport théorie) ; Hyperliquid est « non retenu » à M1 (`RESEARCH-ELIGIBILITY-GATES.md`) : donnée de **développement** seulement, jamais un produit cible | propriétaire | phrase datée au registre | pas de Gate 1 ; G4 démarre directement au Gate 2 (plus long, moins puissant) |
| **P-G4-2** | **Licence et coût** : la page « Historical data » ne porte aucune condition d'usage (UNKNOWN) ; le propriétaire décide si l'absence de licence publiée suffit pour une recherche personnelle non redistribuée ; **plafond de coût** « requester-pays » écrit (octets maximum et budget USD), fenêtre choisie **avant** tout téléchargement (§4.1), jamais après un résultat | propriétaire | décision + plafond | pas de téléchargement |
| **P-G4-3** | **Échantillon de schéma** (après P-G4-1/2) : un script qui liste les objets S3 (noms, tailles, dates) et lit, pour **un** fichier par type et par mois, les **noms de champs** et les horodatages min/max, **sans afficher ni écrire aucune valeur** ; établit : présence des champs `liquidation` et `liquidation.method` dans `node_fills_by_block` / `node_fills` et leur **première date** T_liq ; format et cadence des `market_data` L2 (`[datatype]`) ; champs de `asset_ctxs` (mark/oracle) ; trous | a4 (script), propriétaire (lancement) | note de schéma hachée dans `a4-data-desk/` | fenêtre et mesure du mid indéfinies |
| **P-G4-4** | **Déclaration de transférabilité M9** écrite et acceptée (§3.4 de ce texte, complétée par P-G4-3 et P3) | a3 / propriétaire | section signée | Gate 1 sans valeur pour OKX |
| **P-G4-5** | **Chargeur et évaluateur gelés** : chargeur archive HL → layouts `tape` (`liquidations` : fills porteurs de `liquidation`, `side` = côté de l'ordre de liquidation, `size`, prix ; `books5`/mid depuis les snapshots L2 ; `funding` depuis `asset_ctxs` ou `misc_events_by_block`), détecteur `events.liquidation_clusters` **modifié** (seuil relatif §3.3, `method = market` seul en primaire, `t_e` = dernier fill liquidé, cool-down 60 min, cluster horaire — à écrire ; `side == 0` exclu du notionnel signé — CTO m7, **déjà fait** par la passe parallèle `a5-corrections-cto`, `events.liquidation_notionals`), évaluateur du contrôle continu (régression avec A et A² en continu dans les déciles, bootstrap par blocs) **et** de l'appariement au prix (§6.2), σ_15 locale — **à écrire**, digest au gel (`cli.code_identity()` recalculé après ces modifications ; valeurs du 2026-10-21 dans `LP-G2` P-G2-4 (A)) | a2 / a4 | tests sur séries synthétiques (le détecteur ne tire rien sur H0) | gel impossible |
| **P-G4-6** | **Registre** : ligne `hl-fills-G4-gate1` créée, statut `unread`, fenêtre déclarée §4.1, passage à `consumed_equivalent` à la lecture | a3 | ligne | gel impossible |

### 2.2 Pour le Gate 2 (OKX X-Perps)

| Id | Prérequis | Responsable | Preuve | Sans quoi |
|---|---|---|---|---|
| **P-G4-7** | **Gate 1 rendu** : `G4_GATE1_PASS` (ou consentement explicite à sauter le Gate 1, P-G4-1 refusé) | — | `LP-G4-GATE1-RESULT.md` | pas de Gate 2 |
| **P-G4-8** | **P3 — couverture** : `liquidation-orders` sert `instType FUTURES` par documentation (établi, §0.4) ; P3 établit seulement si les **X-Perps de l'univers y apparaissent** et à quelle fréquence (comptage 24–72 h : messages par `instId`, zéro valeur), ainsi que la couverture de `books5`, `bbo-tbt`, `trades`, `funding-rate` (idem G2). Le **ratio de couverture** du flux (part des liquidations réelles servies) reste **UNKNOWN** par construction (disclaimer) et est déclaré tel | a2 / a4 | table de P3 | aucun X-Perp dans le flux ⇒ `G4_NOT_MEASURABLE_ON_OKX` : G4 s'arrête au Gate 1 |
| **P-G4-9** | **Licence §9.4** décidée (P-G2-1) ; **verdict `fwd-okx-r1`** rendu (P-G2-2) | propriétaire | idem G2 | pas de capture scorée |
| **P-G4-10** | **Table de coût G2** disponible (`G2_PASS` ou `G2_FAIL`) **ou** déclarée absente : alors la sensibilité « sélection adverse » du Gate 2 utilise la valeur de conception **−3,5 pb par jambe** (borne haute de Part A), écrite ici | a3 | référence | sensibilité non calculable |
| **P-G4-11** | **Porte économique** du programme fixée (`LP-PROGRAMME-GATES.md` §1) | propriétaire | valeurs | lecture économique impossible |

---

## 3. Instruments et transférabilité

3.1 **Hyperliquid (Gate 1).** Univers = tous les perpétuels de la liste `meta.universe` (métadonnées, instantané haché au gel ;
hôte API public, aucune valeur de marché), **hors** actifs HIP-3 (préfixe de dex dans `coin`, docs « Info endpoint ») et hors
actifs dont la cotation commence après le début de la fenêtre ou cesse avant sa fin (survie **déclarée**, nombre d'exclus
rapporté). Les 24 h de `run15` (BTC/ETH, 2026-10-02/03) ne sont pas utilisées.

3.2 **OKX X-Perps (Gate 2).** Même univers et mêmes filtres que `LP-G2-DRAFT-PREREGISTRATION.md` §3 (métadonnées au gel,
liquidité point-dans-le-temps ≥ 1 M USD/jour, BTC/ETH toujours inclus), **élargi dès le gel à tous les X-Perps crypto éligibles**
que le collecteur tient (G2 §3.4 : N ≤ 40 par l'enveloppe de licence P-G2-1) — la réponse à une puissance insuffisante est
l'univers, jamais l'allongement après lecture (quant M6 ; règle 9). `InstrumentSpec` par l'API (`ctVal`, `tickSz`, `lotSz`,
`minSz`). Notionnel liquidé d'une ligne = `sz × ctVal × bkPx` (repli : `mark_px` le plus proche si `bkPx` est vide).

3.3 **Paramètres communs figés** (`events.liquidation_clusters`, à modifier selon P-G4-5 ; quant B2). `bin_s = 300` (fenêtres
alignées UTC) ; `lookback_bins = 2 016` (7 jours), `min_history_bins = 288` (1 jour). **Événement = cascade relative à la
paire**, trois conditions simultanées calculées sur l'archive elle-même au moment de l'exécution, aucune lue avant le gel :
(a) L ≥ **p99,5** des 2 016 fenêtres précédentes (fenêtres nulles incluses) ; (b) L ≥ **k × médiane des fenêtres non nulles**
des 7 jours précédents, **k = 20** (valeur de conception) ; (c) L ≥ **x % du notionnel échangé** des 24 h précédentes (tous
fills de la paire), **x = 0,5 %** (valeur de conception ; sensibilités {0,25 ; 1} rapportées). `abs_notional = 50 000 USD`
n'est plus la définition : il reste un **plancher de bruit** (fenêtres sous ce montant jamais retenues, quelle que soit la
paire). **L = notionnel liquidé par ordres au marché** (`method = "market"`) seuls — les fills `method = "backstop"` (vault,
hors carnet) sont **exclus** de l'intensité primaire et rapportés à part (quant B2.3/M12) ; sur OKX, où `method` n'existe pas,
L = `Σ sz × ctVal × bkPx` du flux (déclaré, §3.4). Côté : forced sells (liquidations de longs) dominants ⇒ **+1 (acheter)** ;
forced buys dominants ⇒ **−1 (vendre)** (convention `events.py`) ; un `side` inconnu (0) compte dans L mais pas dans le
notionnel signé (CTO m7). **Instant de l'événement t_e = horodatage du dernier fill liquidé de la fenêtre** (archive HL : `time`
du fill ; OKX : `liquidation-orders.ts`), **pas** la fin de la fenêtre alignée (quant M4 : si la grappe tombe en début de
fenêtre, jusqu'à 5 min de retour étaient déjà dans l'amplitude et hors de R_15, biais vers FAIL non déclaré) ; l'écart `fin de
fenêtre − t_e` est rapporté. **Cool-down** : 60 min par instrument (seule la première fenêtre d'une séquence compte). **Grappe
inter-instruments** : les événements de paires différentes dont les t_e tombent dans la même heure UTC forment **un seul
cluster** (unité d'indépendance, §7). Les mêmes **formules** s'appliquent au Gate 2 sur la base observable OKX ; aucun
**chiffre** HL n'est reporté (§3.4).

3.4 **Déclaration de transférabilité Hyperliquid → OKX X-Perps (M9 ; règle 8 du rapport théorie ; reprend
`LP-DATA-DESK-2026-10-20.md` §4.3, à compléter par P-G4-3 et P3).**

*Ce qui se transfère (hypothèse de mécanisme, pas de magnitude — desk §4.3).* (1) Un **flux forcé non informationnel**
déclenché par le mark price, dont le liquidé ne choisit ni l'instant ni le prix. (2) Le flux **frappe le carnet** et paie la
liquidité (HL « sending market orders to the book » ; OKX « into the market […] at the entrusted price », « liquidation taker
fee ») : le fournisseur de l'autre côté est maker dans les deux cas. (3) La **forme de l'event study** (instant du dernier fill,
fenêtres 5/15/60 min, contrôle continu, appariement au prix, clustering horaire) et la **direction attendue**. (4) La
**fragmentation** des grosses positions (HL 20 % + 30 s ; OKX paliers) : l'unité d'événement est une fenêtre, pas un print.

*Ce qui ne se transfère pas (desk §4.3, recopié ; aucun chiffre HL n'entre dans un critère OKX).*

| Dimension | Hyperliquid (documenté) | OKX X-Perps (documenté / UNKNOWN) | Conséquence déclarée |
|---|---|---|---|
| Moteur | exécution déterministe, publique, ordres **market** pour toute la taille ; tranches de 20 % + 30 s au-dessus de 100 k USDC ; backstop par le liquidator vault (HLP, hors carnet) sous 2/3 de la MM ; pas d'ADL ; pas de frais de compensation | annulation d'ordres d'abord ; reprise par le moteur à un « entrusted price » calculé « based on current market depth, basis, and mark price » (pas un ordre market) ; paliers ; fonds de sécurité ; ADL au mark par leverage PnL % ; frais taker + frais de compensation ; qui sert l'ordre au repos : **M8 UNKNOWN** | l'empreinte prix d'une liquidation OKX de même notionnel est **structurellement différente** (plus lente, plus « passive ») ; HLP absorbe et capture une part de la réversion sur HL ; le Gate 1 ne transfère que le **signe du retour du mid**, ni la magnitude ni le taux de fill |
| Donnée | chaque fill liquidé porte `liquidation {liquidatedUser, markPx, method}` ; archive complète par construction (présence du champ : P-G4-3) | flux « doesn't represent the total number », « not necessarily in chronological order », sans identifiant, sans historique (REST retiré 2023), `bkPx`/`bkLoss`/`sz` | la base OKX observée est un **sous-échantillon de ratio inconnu** ; aucun seuil calibré sur HL n'est **reportable** : les formules §3.3 sont les mêmes, les **valeurs** sont recalculées sur la base OKX observable ; β_intensité peut être atténué (biais vers FAIL, déclaré) |
| Profondeur, participants | carnets profonds, HLP teneur de marché protocolaire, levier jusqu'à 40× (BTC) | place EEE retail, « Up to 10x », ordres RPI/ELP, programme de teneurs de marché **UNKNOWN**, carnets X-Perps jamais lus | plus de levier ⇒ plus de liquidations par unité de mouvement ; moins de profondeur ⇒ plus d'impact par unité liquidée : effets en sens contraire qui **ne se compensent pas de façon connue** |
| Distance de liquidation | MM = ½ IM au levier max (1,25 % BTC) | paliers `position-tiers` (non lus) ; clause OEM d'appel de marge à 30 % du collatéral | fréquence et taille des cascades non comparables |
| Frais | pas de « clearance fee » ; maker/taker 1,5 / 4,5 pb (`TRADABLE-INSTRUMENT-UNIVERSE.md`, sans objet au Gate 1) | liquidation forcée au taux **taker** ; frais de compensation ; 2 / 5 pb | les coûts du Gate 2 sont ceux d'OKX uniquement |
| Funding | horaire ; plafond 4 %/h | 8 h (BTC) / 4 h (certains), plafonds par contrat ; raccourcissement 2 h/1 h : application aux X-Perps **UNKNOWN** | le funding n'entre pas au Gate 1 (mid seul) ; il entre au Gate 2 avec les taux lus dans la tape OKX |
| Instrument, bandes | perpétuel | future à 5 ans « perpetual-style » (`ruleType xperp`) avec base ; bandes `initPxLmtPct` / `floatPxLmtPct` / `maxPxLmtPct` pouvant bloquer un ordre au repos loin du mark | sous-jacents communs (BTC, ETH, SOL…) ; le mécanisme ne dépend pas de l'échéance ; rejet par bande déclaré au Gate 2 (§5.4) |
| Clientèle, horloge | 24/7 crypto-native mondial | OKX Europe EEE : structure horaire des liquidations **UNKNOWN** | la stratification horaire du Gate 1 n'est pas transférée |

Lecture (quant M5) : un `G4_GATE1_PASS` dit « le retour post-cascade existe quelque part où il est observable » ; il **autorise**
le Gate 2 et rien d'autre. Un `G4_GATE1_FAIL` dit « le signe n'a pas été trouvé là où le flux est entièrement visible et traverse
le carnet » ; ce n'est **pas** une preuve d'absence sur OKX, dont le moteur, la profondeur et la clientèle diffèrent
structurellement (table ci-dessus). La conséquence est une **décision de ressources**, pas un verdict scientifique sur OKX :
« G4 **non poursuivi faute de preuve de développement** » (§8). Un Gate 2 OKX sans Gate 1 reste possible sur décision écrite du
propriétaire (P-G4-1 refusé ou Gate 1 FAIL), avec le seuil relatif §3.3 calculé sur la base OKX seule.

---

## 4. Données et registre

4.1 **Hyperliquid (Gate 1).** Jeux : `node_fills_by_block` (et `node_fills` pour la période ancienne si le champ y existe),
`market_data/[date]/[hour]/l2Book` (ou le `[datatype]` établi par P-G4-3) pour le mid, `asset_ctxs` pour le mark/oracle.
**Fenêtre déclarée avant tout téléchargement** : du **premier jour où le champ `liquidation` est présent** (T_liq, établi par le
schéma, P-G4-3) au **2026-09-30 inclus** ; rien après (le mois d'octobre 2026 n'est pas lu : 2026-10-14..31 est en quarantaine
sur toute venue, et le 1ᵉʳ–13 octobre reste vierge pour un éventuel usage ultérieur déclaré). Si le plafond de coût (P-G4-2) ne
permet pas toute la fenêtre, la **sous-fenêtre est choisie par le calendrier** (les N mois les plus récents ≤ 2026-09-30),
écrite au gel, jamais choisie sur un résultat. Statut au registre : `unread` → `consumed_equivalent` (G4, développement) à la
première lecture d'une valeur. Robustesse « par moitiés » : les deux moitiés calendaires de la fenêtre sont évaluées
séparément (§7.3 C4), pas comme entraînement/validation (une seule lecture).

4.2 **OKX (Gate 2).** Capture `research_lp capture` (identique à G2 : hôte, canaux, layouts, `--not-before`, segments de reprise,
manifeste) ; layout `liquidations` (`ts_us`, `recv_us`, `side`, `pos_side`, `size`, `bk_px`, `bk_loss`), `books5`, `trades`,
`funding`, `mark`. Statut `prospective`. **Fenêtre de scoring** : du **gel du Gate 2** (jamais avant, même si la capture tourne
depuis T0 de G2) au gel + 90 jours ; les jours capturés avant le gel servent **uniquement** à l'historique du percentile
(`lookback`), pas au scoring ; depuis P-L2-1 (D14), jamais les jours 2026-11-02 et 2026-11-03 (intervalle exclu du pilote L2,
`research_lp events` les refuse) : l'historique commence au plus tôt le 2026-11-04. Lecture unique après la fin de la fenêtre (futilité §7.5 exceptée).

4.3 **Qualité** : (HL) jour manquant dans l'archive ⇒ exclu et compté ; si > 10 % des jours de la fenêtre manquent, le Gate 1 est
`INCONCLUSIVE` ; (OKX) règles de `LP-G2-DRAFT-PREREGISTRATION.md` §4.4 ; en plus, toute déconnexion du canal `liquidation-orders`
> 10 min invalide les fenêtres de 5 min qu'elle couvre (on ne sait pas si rien ne s'est passé).

4.4 **Interdits** : identiques à G2 §4.5 ; en plus, aucune lecture de l'archive HL hors de la fenêtre déclarée, aucun fill
Hyperliquid d'octobre 2026.

4.5 **Stockage** : archive HL téléchargée et tape OKX dans `tradingV3-private`. Ce que le dépôt `tradingV3` reçoit (protocole,
verdicts, agrégats sans série tels que la distribution du retour par taille d'événement) est une **décision du propriétaire**
(`LP-PROGRAMME-GATES.md` §6.3 et décision 14 ; « display » de §9.4(a) ; quant m8), pas une règle de ce protocole : par défaut, tant
qu'elle n'est pas prise, les agrégats chiffrés restent dans `tradingV3-private` et le dépôt ne reçoit que le protocole et le verdict
qualitatif.

---

## 5. Événement, mesures, règle simulée

5.1 **Événement** (§3.3) : fenêtre de 5 min d'un instrument dont le notionnel liquidé par ordres au marché L satisfait les trois
conditions relatives (p99,5 ; k = 20 × médiane non nulle 7 j ; x = 0,5 % du notionnel échangé 24 h) et le plancher de bruit
50 000 USD, après 288 fenêtres d'historique ; `value = L`, `aux` = rang dans l'historique ; côté s_e ∈ {+1, −1} ; **t_e =
dernier fill liquidé** de la fenêtre. **Amplitude** A_e = s_e × (mid(t_e) − mid(t_e − 300 s)) / mid(t_e − 300 s) en pb (négatif
si le prix a été poussé contre le fournisseur, cas attendu), aussi exprimée en σ_15 (A_e / σ_15). **Intensité** I_e = ln(L /
médiane non nulle 7 j) (sans dimension ; la forme `ln(1 + L / 50 000)` est rapportée en sensibilité). **Volatilité locale**
σ_15 = écart-type des rendements du mid à 1 min sur les 24 h précédant la fenêtre (fenêtre exclue) × √15, en pb.

5.2 **Mesure du Gate 1 (mid seul)** : R_h(e) = s_e × (mid(t_e + h) − mid(t_e)) / mid(t_e), en pb, h ∈ {60 s, 5 min, **15 min**
(primaire), 60 min}, et sa version **sans dimension** Z_h(e) = R_h(e) / σ_15(e) (quant B2.4 ; desk §4.3 : « porte formulée en
unités sans dimension »). Mid = (bid₀ + ask₀)/2 du dernier snapshot L2 antérieur à l'instant ; si aucun snapshot dans les 60 s
précédentes, l'observation est manquante (comptée).

5.3 **Mesure du Gate 2 (exécution simulée, `lp_sim.event_intents`, `next_minute = True`)** : décision au premier hh:mm:00 + d
> **clôture de la fenêtre** de 5 min (`ts_us`), d comme G2 §5.2 — dans un détecteur à fenêtres closes, la grappe n'est connue qu'à la
clôture : `events.liquidation_clusters` pose `arm_ts_us = end_ts_us = ts_us` et le simulateur décide à la minute suivante avec la TTL
globale (CTO M2 appliqué au code, `LP-G2` P-G2-4 (A)) ; décider à la minute suivant t_e serait un **look-ahead** quand t_e précède la
clôture. L'écart `décision − t_e` (entre d et 5 min + d) est **rapporté** : la mesure R_h du Gate 1 part de t_e (§5.2), la règle
simulée du Gate 2 part de la décision ; ordre au touch côté s_e, 100 USD, post-only, latence 250 ms, **TTL 60 s** ; non rempli ⇒ pas
d'observation de mark-out (compté dans le taux de fill et dans l'économie) ; sortie passive au touch opposé posée à τ + latence,
règle `queue`, jusqu'à **60 min**, reliquat **taker** à 60 min. **Aucun stop** dans l'analyse primaire (règle 12 : la taille porte
le risque) ; lecture portefeuille : coupe de catastrophe à **3 σ_h** au mark, σ_h = écart-type des rendements du mid à 1 min sur
les 24 h précédant t_e × √60 ; jamais plus serrée. Règles de fill : `queue` primaire, `through`/`touch` rapportées.

5.4 **Coûts du Gate 2 (pessimistes, dans le net)** : maker 2 pb entrée ; sortie 2 pb (maker) ou 5 pb (taker) pondérés ; **stress
de spread × 3** : le reliquat taker paie 3 × le demi-spread observé à 60 min (au lieu de 1 ×) ; **funding réel** du tape
(`funding_bp = −s_e × Σ taux traversés`, une cascade à 23:50 UTC traverse la photo de 00:00) ; sensibilité **funding au plafond**
(`max_rate` contre la position) ; **sélection adverse** : mesurée par le mark-out lui-même ; sensibilité « −3,5 pb par jambe » si
la table G2 est absente (P-G4-10) ; **liquidation forcée** : si, en lecture portefeuille, le mark atteint la distance de
liquidation d'une position 1× isolée (palier 1, `mmr` de l'instrument), la position est fermée au mark avec frais **taker** —
déclaré ; à 1× isolé avec `mmr` 0,65 % il faut un mouvement de l'ordre de **99 %** en 60 min (calcul d'audit ; quant m4), cas
compté s'il survient ; **bandes de prix** : après une cascade, un post-only au touch peut être **rejeté** par `floatPxLmtPct` /
`maxPxLmtPct` (« hard cap on an order's deviation from the index », `H1-XPERP-MARKET-ACCESS.md` l.58–60) — non modélisé,
déclaré **biais vers le PASS** (les pires instants ne seraient pas posés en direct ; la simulation les pose), décisions
`band_rejected` comptées sur `maxPxLmtPct` du snapshot `instruments` (indice approché par le mark, déclaré), net rapporté avec
et sans ; **règle de fill** : `queue` sur prints `source = 0` seuls (G2 §5.3, biais RPI G2 §0.9) ; robustesse **frais × 1,5**
(3/7,5 pb).

5.5 **Deux lectures** : simulation sans contrainte (puissance) ; portefeuille 500 USDT (`capital 500`, `leverage 1`, `max_live 4`,
admission par ordre de décision ; un événement arrivé alors que 4 engagements vivent est **non admis**, compté).

---

## 6. Contrôle

6.1 **Contrôle continu (co-primaire, Gate 1 et Gate 2 ; quant B2.2).** Sur **toutes** les fenêtres de 5 min à liquidation non
nulle (pas seulement les événements), régression Z_h = α + β·I + Σ_k γ_k·1[A ∈ décile k] + **γ_a·A + γ_b·A²** + Σ_j δ_j·1[heure
= j] + Σ_i μ_i·1[instrument = i] + ε, où Z_h = R_h / σ_15, A (en σ_15) et I sont signés par le côté du flux forcé dominant de la
fenêtre ; variante à 40 quantiles d'amplitude rapportée. Les termes A et A² **dans** les déciles retirent l'amplitude résiduelle
intra-décile corrélée à I : sans eux, β > 0 pouvait être porté par « plus la fenêtre a bougé, plus elle revient », la réversion
ordinaire après mouvement (famille close r1–r12). Test : **β > 0 avec LB90 > 0**, erreur type par **bootstrap par blocs** de
60 min (1 000 rééchantillons, graine 20261102). Lecture : à amplitude et heure réellement égales, plus la liquidation est intense,
plus le mid revient.

6.2 **Contrôle apparié au prix (co-primaire ; quant B2 — le meilleur contrôle du programme, r13/r14).** Pour chaque événement,
fenêtres du **même instrument**, même heure UTC ± 1 h, |A| dans ± 25 % (en σ_15), notionnel liquidé < p50 de l'historique
(liquidation faible, pas nulle) ; différence D_h = Z_h(événement) − médiane Z_h(appariés) ; test : **moyenne de D_15 > 0 avec
LB90 > 0** par bootstrap par blocs sur les clusters. Si aucun apparié n'existe pour > 30 % des événements (alts), la lecture est
marquée `sparse` et le Gate 1 est `INCONCLUSIVE` sur ce critère (jamais remplacé par 6.1 seul). **Le signe doit tenir sur les
deux contrôles** (6.1 et 6.2) pour un PASS.

6.3 **Jamais** : un contrôle apparié à l'heure seule (effacerait G5, hors sujet ici) ; un contrôle « sans aucun print » ; une
sélection a posteriori des événements par taille.

---

## 7. Unité d'observation, puissance, critères, futilité

7.1 **Unité** = l'**instant** (cluster : événements de la même heure UTC, tous instruments) ; n = nombre de clusters. Les
fenêtres non-événements de la régression 6.1 sont des observations auxiliaires regroupées par blocs de 60 min.

7.2 **Porte de puissance** (règle 9) : **n ≥ 100 instants indépendants** par porte. **Effet minimal détectable, en unités de σ**
(calcul d'audit, `LP-PROGRAMME-GATES.md` §3.2 ; quant M6) : à n = 100, α = 0,10 unilatéral, puissance 0,80, DE ≈ 1,3, l'effet
détectable est δ ≈ (1,28 + 0,84) × σ / √100 × √1,3 ≈ **0,24 σ** de l'unité (retour 15 min au Gate 1 ; aller-retour 60 min au
Gate 2). En pb, cela vaut ≈ +24 pb si σ ≈ 100 pb — mais σ d'un aller-retour de 60 min **après cascade** sur un X-Perp est très
probablement ≫ 100 pb (volatilité post-événement) : l'effet détectable en pb est plutôt 40–75 pb, et « ≥ +10 pb avec LB90 > 0 »
exige un point ≈ 1,28 σ/√n. **Déclaration** : si l'effet réel est inférieur à ≈ 0,25 σ, `NOT_INFORMATIVE` est l'issue
**probable** du Gate 2 à n = 100 ; le protocole l'accepte plutôt qu'une extension, et c'est pourquoi l'univers est élargi dès le
gel (§3.2). Le seuil +10 pb est un seuil **économique**, pas de détection. Sous 100 instants : `NOT_INFORMATIVE`, jamais
d'extension sur résultat. Au-dessus : la porte n'est pas un objectif de collecte, la fenêtre reste celle de §4.

7.3 **Critères Gate 1 (Hyperliquid ; évalués une fois ; tous requis pour `G4_GATE1_PASS`)** :
- **C1** β_intensité (h = 15 min, régression 6.1 avec A et A² en continu) > 0 avec **LB90 > 0** ;
- **C1b** contrôle apparié au prix (6.2) : moyenne de D_15 > 0 avec **LB90 > 0** ;
- **C2** retour moyen des événements **sans dimension** Z_15 = R_15 / σ_15 ≥ **+0,25** avec LB90 > 0 (valeur de conception égale
  à l'effet détectable à n = 100, §7.2 ; quant B2.4 : la justification par les coûts OKX — « maker/maker 4 pb + funding + stress »
  — est **retirée** d'un Gate « statistique seule » ; R_15 en pb est rapporté, pas testé) ;
- **C3** Z_60 (point) du même signe que Z_15 (l'effet ne s'inverse pas dans l'heure) ;
- **C4** C1, C1b et C2 tiennent **sans les 5 % d'événements les plus intenses** et le signe de β est le même dans les **deux
  moitiés** calendaires de la fenêtre ;
- n ≥ 100 instants (sinon `G4_GATE1_NOT_INFORMATIVE`).
`G4_GATE1_FAIL` sinon ; `G4_GATE1_INCONCLUSIVE` si > 10 % de jours manquants, si le champ `liquidation` ou `method` est absent
sur une part de la fenêtre non déclarée au gel, si le mid n'est pas disponible à ≤ 60 s pour > 20 % des événements, ou si 6.2
est `sparse`.

7.4 **Critères Gate 2 (OKX ; évalués une fois à la fin de la fenêtre ; tous requis pour `G4_GATE2_PASS`)** :
- **C1** retour net moyen (`net_bp` = aller-retour − frais + funding, coûts §5.4) **≥ +10 pb avec LB90 > 0** **et**, en unités
  sans dimension, moyenne de `net_bp` / σ_60 ≥ **+0,25** avec LB90 > 0 (σ_60 = écart-type des rendements du mid à 1 min sur les
  24 h précédant t_e × √60 ; quant M6 : porte sur R/σ_h **et** net ≥ +10 pb) sur **≥ 100 instants** remplis (lecture sans
  contrainte, règle `queue` sur prints `source = 0`, G2 §5.3) ;
- **C2** C1 tient à **frais × 1,5** ;
- **C3** C1 tient **sans les 5 % meilleurs instants** ;
- **C4** β_intensité > 0 avec LB90 > 0 sur les fenêtres OKX (6.1, Z_15 du mid) **et** D_15 > 0 avec LB90 > 0 (6.2) : le mark-out
  doit venir du mécanisme déclaré, pas de la réversion ordinaire ;
- **C5** stress funding au plafond : point estimate de C1 reste > 0.
`G4_GATE2_FAIL` sinon ; `G4_GATE2_NOT_INFORMATIVE` sous 100 instants remplis ; `G4_GATE2_INCONCLUSIVE` selon §4.3 ou si P3 se
révèle faux en cours de fenêtre (canal muet > 20 % du temps).

7.5 **Fenêtres et futilité.** Gate 1 : une exécution, pas de futilité (lecture unique d'une archive). Gate 2 : T0 = gel du Gate 2
(≥ `G4_GATE1_PASS`, ≥ 2026-11-02, ≥ P3) ; fin = T0 + **90 jours** ; **futilité** à T0 + 45 jours **ou** au 50ᵉ instant rempli
(le premier atteint) : si le net moyen a **UB90 < +10 pb** et point ≤ 0, verdict `G4_GATE2_FAIL_FUTILITY` ; lecture intermédiaire
limitée à ces deux chiffres par un script qui n'écrit que le verdict ; jamais de PASS anticipé. Aucune prolongation sur résultat ;
prolongation **pour trous** (jours invalides 4.3) : jour pour jour, au plus 14.

7.6 **Lectures secondaires (descriptives)** : distribution du retour par taille d'événement (déciles de L) et par `method`
(HL : market/backstop, backstop hors intensité primaire) ; R_60s depuis t_e (part de l'effet dans la première minute, hors de
portée d'une pose à la minute) ; écart `fin de fenêtre − t_e` ; R_5 ; R_h en pb et en σ_15 ; sensibilités du seuil relatif
(p99 ; k ∈ {10 ; 40} ; x ∈ {0,25 % ; 1 %}) et de la forme de I ; taux de fill de l'ordre à la
minute suivante par tranche de spread courant (lien avec G2) ; part des événements traversant une photo de funding et leur
signe ; sensibilités du plancher `abs_notional` ∈ {25 k ; 100 k} et `bin_s` ∈ {60 ; 900} ; `through`/`touch` ; `queue` « tous
prints » ;
lecture portefeuille (événements admis, PnL net USD, coupes 3 σ déclenchées, liquidations simulées).

---

## 8. Ce que chaque verdict autorise

- **`G4_GATE1_PASS`** autorise **une seule chose** : geler le Gate 2 (OKX prospectif) après P-G4-7…11. Il n'autorise ni ordre,
  ni démo, ni usage de l'archive HL pour une autre hypothèse (statut `consumed_equivalent`), ni réglage d'un paramètre OKX sur HL.
- **`G4_GATE1_FAIL`** : G4 est **« non poursuivi faute de preuve de développement »** (quant M5) — décision de ressources, pas
  preuve d'absence sur OKX (§3.4) : aucun Gate 2 n'est gelé **par ce protocole**, aucune variante de seuil, de bin ou d'horizon
  n'est relue sur HL ; un Gate 2 OKX **sans** Gate 1 reste possible sur décision écrite du propriétaire (nouveau
  pré-enregistrement, seuil relatif calculé sur la base OKX seule) ; les événements de liquidation subsistent comme **filtre
  défensif** descriptif pour G1/G2/G5 (ne pas poster pendant une grappe) — sans test.
- **`G4_GATE1_NOT_INFORMATIVE` / `INCONCLUSIVE`** : aucune conclusion ; une nouvelle fenêtre HL n'existe pas avant que le temps en
  crée une (données ≥ 2026-10-01 non déclarées) ; décision du propriétaire sur un Gate 2 **sans** Gate 1 (P-G4-1 refusé).
- **`G4_GATE2_PASS`** autorise une **proposition écrite** au propriétaire : Phase 4 de l'architecture A1 (`lp-executor-architecture.md`
  §5 : profil produit X-Perp, échelle post-only, sorties, gouverneur de risque, write kinds, **démo seulement**), avec la lecture
  économique (§8 ci-dessous) et le budget de multiplicité du programme (`LP-PROGRAMME-GATES.md` §2 : un `PASS_LOCAL` n'est un
  `PASS_PROGRAMME` que s'il survit à Holm). **Il n'autorise aucun ordre**, démo ou réel, par lui-même (M5 : décision séparée,
  drapeaux du propriétaire).
- **`G4_GATE2_FAIL` / `FAIL_FUTILITY`** : la famille « retour post-cascade » est **close** pour cette architecture ; la tape OKX
  reste `consumed_equivalent` pour G4 et utilisable en développement (pas en validation) des autres fiches.
- **Lecture économique obligatoire** (règle 11) : instants admis/an (lecture portefeuille, sous les deux hypothèses de `posMode`,
  G2 §5.7) × 100 USD × `net_bp` aller-retour (C1 du Gate 2) / 10 000 → USD/an avec LB90/UB90 ; comparée à la porte économique
  (`LP-PROGRAMME-GATES.md` §1.2 : « poursuivi » ⇔ point ≥ S_éco et UB90 ≥ 2 S_éco ou LB90 > 0) ; un PASS sous la porte est
  « PASS, non poursuivi ».

---

## 9. Risques, biais déclarés, ce que ce protocole ne fait jamais

- Aucun ordre, aucune démo, aucun drapeau ; confirmation = capture publique + simulateur (règle 13).
- **Biais vers FAIL déclarés** : observabilité partielle du canal OKX ; cool-down 60 min ; cluster horaire inter-instruments ;
  stress × 3 sur le reliquat taker ; absence de stop (queues épaisses non bornées **dans le net**, bornées par la taille en lecture
  portefeuille).
- **Biais vers PASS déclarés** : survie de l'univers HL (actifs délistés exclus) ; `queue` borne haute des annulations devant ;
  prints RPI (G2 §0.9) tant que `trades.source` n'est pas utilisé ; rejet par bande non modélisé (§5.4) ; aucun impact de marché ;
  au Gate 1, aucun coût simulé (le Gate 1 est une mesure de signe en σ, pas un PnL).
- **Fréquence des cascades** : **UNKNOWN** (quant m5 : le « 1–3 instants/jour » du rapport théorie n'est pas sourcé) ; avec le
  seuil relatif §3.3, le nombre d'instants par jour sur l'univers est un **résultat** du Gate 1 et de P3, pas une hypothèse ; le
  protocole accepte `NOT_INFORMATIVE` plutôt qu'une extension.
- **Dépendance aux UNKNOWN** : présence et date des champs `liquidation` / `method` dans l'archive ; cadence des snapshots L2 HL ;
  licence HL ; part des X-Perps dans `liquidation-orders` et ratio de couverture ; M8 ; hôte WS EEE ; `ctType` ; `posMode` ;
  fréquence des cascades (HL et OKX).

---

## 10. Traitement de la revue (2026-10-20)

Relectures : `review/quant.md` et `review/cto.md` (dossier `20261020T1430Z-lp-programme/review/`). Convention : **appliqué** =
texte modifié ; **prérequis** = exigé au gel (code hors du périmètre de cette passe) ; « **Réponse à la revue** » = refus ou
traitement différent, motivé.

| Constat | Traitement |
|---|---|
| quant **B2.1** — « 50 000 USD et p99 » n'est pas une cascade sur les majors HL ; filtre effectif = p99 ≈ 3 bins/jour/paire | **Appliqué** : §3.3, §5.1, H-G4-1 — seuil **relatif à la paire** (p99,5 **et** k = 20 × médiane des bins non nuls 7 j **et** x = 0,5 % du notionnel échangé 24 h), valeurs de conception pré-déclarées, calculées sur l'archive à l'exécution. **Réponse à la revue** : `abs_notional = 50 000 USD` est conservé comme **plancher de bruit** (jamais comme définition) pour exclure les paires où la médiane non nulle est de quelques centaines d'USD ; il n'ajoute aucune sélection sur les majors |
| quant **B2.2** — l'amplitude intra-décile reste corrélée à I ; β > 0 peut être la réversion ordinaire | **Appliqué** : §6.1 ajoute A et A² en continu dans les déciles (variante 40 quantiles) ; §6.2 contrôle apparié au prix promu **co-primaire** (C1b) ; le signe doit tenir sur les deux |
| quant **B2.3 / M12** — fills `method = backstop` dans L | **Appliqué** : intensité primaire = `method = market` seul ; backstop descriptif ; sur OKX (sans `method`), déclaré |
| quant **B2.4** — C2 en pb absolus justifiée par des coûts OKX | **Appliqué** : C2 = Z_15 = R_15 / σ_15 ≥ +0,25 (valeur de conception = effet détectable à n = 100) ; justification par coûts OKX retirée ; R_15 en pb rapporté |
| quant **M4** — t_e = fin de bin aligné ; retour déjà consommé | **Appliqué** : t_e = dernier fill liquidé de la fenêtre (HL `time`, OKX `ts`) ; écart fin de bin − t_e rapporté ; Gate 2 inchangé (minute suivante) |
| quant **M5** — « FAIL HL ferme G4 » surjoue la transférabilité négative ; « `abs_notional` identique par conception » | **Appliqué** : §3.4 recopie la déclaration M9 du desk (ce qui se transfère / ne se transfère pas) ; FAIL HL ⇒ « non poursuivi faute de preuve de développement » (§3.4, §8) ; seuil OKX propre (mêmes formules, valeurs recalculées sur la base OKX) ; mention « identique par conception » supprimée |
| quant **M6** — puissance surestimée ; unités de σ ; univers | **Appliqué** : §7.2 (effet détectable ≈ 0,24 σ ; `NOT_INFORMATIVE` issue probable sous 0,25 σ) ; §7.4 C1 porte sur net ≥ +10 pb **et** net/σ_60 ≥ +0,25 ; §3.2 univers élargi dès le gel à tous les X-Perps éligibles tenus par le collecteur |
| quant **m1** — faits `liquidation-orders` établis par le desk | **Appliqué** : §0.4 réécrit ; P-G4-8 ne porte plus que sur la couverture (part des X-Perps, ratio UNKNOWN) |
| quant **m4** — « 90 % » → ≈ 99 % ; bandes de prix | **Appliqué** : §5.4 (chiffre ; `band_rejected`, biais vers le PASS) |
| quant **m5** — « 1–3 instants/jour » non sourcé | **Appliqué** : §9 → UNKNOWN ; la fréquence est un résultat de P3 et du Gate 1 |
| quant **B5 / M13** (via G2) — prints RPI ; `posMode` | **Appliqué** : §5.4 (`queue` sur `source = 0`), §8 (lecture portefeuille sous deux hypothèses de mode) |
| CTO **m7** — `side = 0` compté comme achat forcé | **Appliqué au code** par la passe parallèle `a5-corrections-cto` (`events.liquidation_notionals` : signe `+1 / −1 / 0`, vérifié le 2026-10-21) ; §3.3 déclare la règle (`side == 0` dans L, hors notionnel signé) ; P-G4-5 n'en porte plus que la trace |
| CTO **m8** — `liquidation-orders` par `instType` persiste tout FUTURES | **Appliqué** : déclaré via G2 §4.1 (même collecteur) ; G4 en bénéficie (univers large pour le percentile) |
| CTO **M2** — `event_intents` et `arm_ts_us` | **Appliqué au code** (passe parallèle) : pour une grappe, `arm_ts_us = end_ts_us = ts_us` (clôture de la fenêtre) et décision à la minute suivante avec la TTL globale — `next_minute` reste le bon comportement pour G4 (§5.3). **Réponse à la revue** (croisement quant M4 × CTO M2) : t_e = dernier fill liquidé est l'instant de **mesure** du Gate 1 (§3.3, §5.2) ; la **décision** du Gate 2 reste à la minute suivant la clôture de la fenêtre, pas suivant t_e (look-ahead sinon) ; l'écart `décision − t_e` est rapporté |

---

## 11. Procédure de gel

Deux gels, même méthode (`PREREGISTRATION-H2-GATE1.md` §15) :

1. **Gel du Gate 1** : P-G4-1…6 consignés ; instantané `meta.universe` haché ; fenêtre [T_liq ; 2026-09-30] et sous-fenêtre
   éventuelle écrites en « Annexe I — Gate 1 » **au-dessus** de cette section ; digest du code (`cli.code_identity()` étendu au
   chargeur HL et à l'évaluateur) ; remplacement de `DRAFT_NOT_FROZEN` par `FROZEN_GATE1 <UTC>` ; ligne `FROZEN_GATE1 <UTC> body
   sha256 <…> code sha256 <…> runtime <…> (window …; not executed)` ; corps haché = fichier jusqu'à la ligne « ## 11. Procédure
   de gel » incluse et la ligne vide suivante ; fichier `LP-G4-DRAFT-PREREGISTRATION.sha256` ; publication **avant** tout
   téléchargement de valeur. Exécution unique ; sortie hachée dans `LP-G4-GATE1-RESULT.md`.
2. **Gel du Gate 2** : après `G4_GATE1_PASS`, P-G4-7…11 consignés ; snapshot `instruments` OKX et liste figée en « Annexe II —
   Gate 2 » ; T0 et fin écrits ; `FROZEN_GATE2 <UTC> …` ; nouveau `.sha256` ; publication avant le premier jour scoré. Exécution
   unique ; `LP-G4-GATE2-RESULT.md`.
3. Toute modification après un gel = amendement écrit, daté, publié **avant toute lecture**, avec règle d'acceptation.

Fin du brouillon. `DRAFT_NOT_FROZEN` — rien n'est gelé, rien n'est téléchargé, rien n'est lancé.
