# LP-G2 — Capture de spread et mark-outs passifs sans signal sur carnets larges X-Perps (pré-enregistrement, brouillon)

**Statut : `DRAFT_NOT_FROZEN`.** Brouillon du 2026-10-20 (volet a3 du programme `20261020T1430Z-lp-programme`). Aucun sha256 de
gel, aucune donnée lue, aucune capture lancée, aucun ordre. Ce texte n'autorise rien par lui-même : il décrit ce qui sera gelé
si le propriétaire le décide, après les prérequis de §2. Les règles communes à tous les protocoles du programme sont dans
`LP-PROGRAMME-GATES.md` (porte économique, multiplicité, puissance, deux lectures, registre, interdits) ; ce document ne les
répète que là où G2 les instancie.

**Révision du 2026-10-20 (passe unique après les relectures `review/quant.md` et `review/cto.md`, verdicts `NEEDS_CHANGES`),
reprise le 2026-10-21 après interruption.** Statut inchangé. Les constats bloquants et majeurs sont appliqués dans le corps du
texte ; le détail constat → traitement est en §10 « Traitement de la revue », les refus y sont écrits « Réponse à la revue ». Les
corrections de **code** demandées par la relecture CTO (M1–M4, m1–m12) ne sont pas appliquées par cette passe de protocoles
(chemins `python-orchestrator/` hors de son périmètre d'écriture) : elles l'ont été par la passe parallèle `a5-corrections-cto`
(`tradingV3-private/research/20261020T1430Z-lp-programme/a5-corrections-cto/NOTES.md`), dont l'état a été **vérifié ici** le
2026-10-21 (lecture du code, digests, suite `tests/test_research_lp_*.py` : **40 passed**) et consigné en P-G2-4 (A) ; ce qui reste
à coder avant le gel est en P-G2-4 (B).

**Base.** Fiche G2 (§3.3, rang 1) et règles transversales §3.5 du rapport
`docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md` (branche `claude/research-scalping-theory-gap`, non fusionnée
à `main` au 2026-10-20 ; lue depuis le worktree `research-scalping-theory`). Part A du rapport
`docs/handbook/reports/research-execution-tick-2026-10-12.md` (le seul mark-out passif mesuré par le programme : BTC/ETH à
1 tick, OKX et Hyperliquid, 24 h).

**Nature.** G2 est une **mesure de coût**, pas une stratégie. Elle répond à une question que le programme n'a jamais posée au
bon endroit : sur un carnet dont le spread courant vaut plusieurs points de base, un ordre passif **sans signal** gagne-t-il,
en **aller-retour** (entrée passive, sortie passive ≤ 60 min, reliquat taker), après frais maker, sélection adverse mesurée et
funding, un net positif — et combien coûte la **lenteur** (pose à la minute sans recotation) par rapport à un recoteur ? Le
mark-out d'entrée à 60 s net du seul frais maker est une **sélection adverse nette d'entrée** (demi-transaction) : c'est l'entrée
de coût de G1/G4/G5, pas un PnL réalisable (relecture quant B1). Le livrable principal est une table « sélection adverse par tranche de spread courant,
par heure et par paire » qui devient l'**entrée de coût** de G1, G4 et G5. Un PASS n'autorise rien d'autre (§9).

---

## 0. Déclarations préalables

0.1 **Aucune donnée de marché X-Perps lue** par l'auteur de ce brouillon : aucun prix, spread, volume, carnet, funding, courant ou
historique, d'aucun X-Perp (`instType FUTURES`). Le spread courant des X-Perps est **UNKNOWN** et doit le rester jusqu'au gel
(règle 13 du rapport théorie). L'univers est donc figé par **métadonnées** (§3) et la tranche de spread est **mesurée pendant
la capture**, jamais avant.

0.2 **Expositions antérieures déclarées comme influence.** Part A (programme) : sous la règle `through`, mark-out net 60 s de
−3,05 à −3,49 pb sur BTC/ETH OKX USDT-swap et Hyperliquid, LB90 dans ±0,1 pb ; sous `touch`, −2,09 à −2,18 pb ; taux de fill
45–58 % (`through`) et 89–93 % (`touch`) à 45 s ; demi-spread 0,006–0,19 pb (`research-execution-tick-2026-10-12.md`, Part A).
Mid-caps Binance r6 : −10,7 pb médians de sortie taker, sans entrée passive mesurée (`research-midcap-tick-2026-10-13.md`,
cité par le rapport théorie). Ces chiffres servent à **calibrer le contrôle** (§6, C0), jamais à fixer un seuil de PASS.

0.3 **Métadonnées datées dans la quarantaine.** La liste des « 29 X-Perps crypto ≥ 1 M USD/jour » de
`TRADABLE-INSTRUMENT-UNIVERSE.md` est un relevé du **2026-10-14**, dans la fenêtre 2026-10-14..31 interdite (rapport structurel
§4.1 point 4). Elle **n'est pas** la liste de G2 : l'univers est reconstruit au gel par métadonnées (§3), la liquidité est
évaluée jour par jour dans la capture (§3.3).

0.4 **Littérature connue** (rapport théorie §2 ligne 3 et §3.3 G2) : Ho-Stoll 1981 ; Avellaneda-Stoikov 2008 ; Glosten 1994 ;
Sandås 2001 ; Tiniç et al. 2023 (sélection adverse ≈ 10 % du spread effectif sur majors Bitfinex). Aucun chiffre de cette
littérature n'entre dans un critère.

0.5 **Payeur nommé** (règle 1) : le preneur impatient qui paie le spread. **Preuve du signe** (règle 2) : A pour le mécanisme,
**UNKNOWN** sur alts minces (aucune étude de capture réalisée trouvée). Prior : moyen. **Condition déclarée** (règle 3) : le spread
courant à l'instant de la décision (tranches §5.4) ; le test est une mesure par tranche, pas une différence conditionnelle.

0.6 **Aucune prédiction de prix.** La règle ne regarde ni l'historique ni un indicateur : elle pose un ordre des deux côtés à
chaque instant de la grille.

0.7 **Interdits de la mission respectés** par ce brouillon : aucune lecture de marché, aucun WebSocket, aucune commande git,
Docker ou systemd, aucun `.env`, aucune clé, aucun drapeau ; rien lu sous `20261013T100000Z-forward-okx-r1/` ni dans `fwd-okx-*`.

0.8 **Capture antérieure au gel, déclarée** (quant m7). Le collecteur tourne avant le gel de ce texte (P3, puis G4). Les
**comptages** P3 par instrument (messages par canal, zéro valeur) sont un proxy de liquidité visible par qui gèle l'univers. Pour
que cette visibilité ne devienne pas une sélection : les filtres §3.2 sont figés **avant** toute lecture de la table P3 (ordre
d'application, liste d'exclusion, seuils de dates) et la sélection éventuelle de §3.4 est un classement mécanique par comptage,
haché, étiqueté `selection`.

0.9 **Profondeur RPI / ELP, déclarée** (quant B5). Docs-v5 (texte extrait par a4, `a4-data-desk/txt/docs-v5-en.txt`
l.29002–29006, 30520) : « The tradeable RPI quantity at a price level is derived as totalQty − nonRpiQty. A taker with RPI access
can execute against totalQty ; a taker without RPI access can execute only against nonRpiQty » ; « resting RPI orders are
currently hidden under the matching rules » ; `trades.source` « 0 normal / 1 ELP » ; `instruments.rpi` « 0/1/2 ». Un print
`source = 1` au prix de notre ordre a été exécuté contre une profondeur **cachée**, pas contre la file organique : il ne consomme
pas notre file. Tant que la tape ne porte pas `trades.source` et que la règle `queue` ne distingue pas les prints, le simulateur
a un **biais vers le PASS** (fills surestimés). De plus, si des teneurs RPI cotent un X-Perp, le flux retail manuel est servi par
eux et ce qui atteint le carnet organique est le flux API/pro : la sélection adverse du carnet organique est **structurellement
supérieure** à celle de Part A (USDT-swaps, 2026-10-02/03). Présence de teneurs RPI sur les X-Perps : **UNKNOWN**
(`instruments.rpi` relevé au gel, §3.1 ; part `totalQty − nonRpiQty` mesurée à P3, P-G2-9). Ce coût propre à OKX (« écrémage
RPI ») est ajouté à `LP-PROGRAMME-GATES.md` §5.

---

## 1. Hypothèse et question

**H-G2 (mesure).** Sur les X-Perps crypto d'OKX Europe dont le spread courant est ≥ 6 pb, un ordre passif post-only au touch,
posé **à la minute** (TTL 60 s, sans recotation) et sans signal, (i) a une **sélection adverse nette d'entrée** (mark-out au mid
à 60 s moins le frais maker de 2 pb, funding des règlements traversés inclus) **> 0** ; (ii) obtient en **aller-retour** (sortie
passive au touch opposé ≤ 60 min, reliquat taker, frais réels des deux jambes, funding réel) un `net_bp` **> 0** — le seul PnL
réalisable et le seul « net » qui entre dans la porte économique (§8.4) ; et (iii) l'écart entre règle `through` et règle `touch`
(le coût de la file) est **inférieur au demi-spread moins 2 pb**. Part A (programme) rappelle l'écart entre (i) et (ii) : mark-out
net 60 s −3,05 … −3,49 pb contre aller-retour 60 s à sortie taker −8,1 … −8,7 pb (`research-execution-tick-2026-10-12.md`
l.60–70, vérifié par la relecture quant).

**Question secondaire (coût de la lenteur).** La différence de mark-out net entre la variante **recoteur** (toutes les 5 s,
TTL 45 s, le dispositif de Part A) et la variante **minute** est le prix que paie une boucle à la minute pour fournir de la
liquidité ; elle est **rapportée**, pas testée.

**Ce que G2 ne teste pas.** Aucune hypothèse directionnelle, aucun conditionnement à la volatilité ou à l'heure (c'est G1 et
G5), aucune cascade (G4). Les stratifications par heure et par signe de funding sont descriptives et **conditionnées à P-G2-10**
(signes de G5 gelés avant la lecture de G2 ; sinon elles sont un screen de G5, §7.6).

---

## 2. Prérequis bloquants (tous requis avant le gel ; responsable entre parenthèses)

| Id | Prérequis | Responsable | Preuve attendue | Sans quoi |
|---|---|---|---|---|
| **P-G2-1** | **Licence** : lecture par le propriétaire de l'OKX API Agreement EEE, « Last Updated: 28 July 2026 », §1.8 et §9.4 (texte relu le 2026-10-20, recopie intégrale dans `LP-DATA-DESK-2026-10-20.md` §2.1 : « You may access and use Market Data solely for your own personal, non-commercial trading and account management purposes » ; « The restrictions in this Section 9.4 apply equally to Market Data accessed through public endpoints » ; **§9.4 « You may not » (c)** : « use Market Data to train, fine-tune, or evaluate any machine learning model or AI system that is intended to be commercialized or made available to third parties » ; **§9.4 « Automated Scraping and Circumvention » (a)–(c)** : « at a rate or scale that: (a) exceeds what is reasonably necessary for your personal trading use; (b) is intended to circumvent OKX's commercial data licensing requirements; or (c) imposes unreasonable load on OKX's infrastructure » ; accès « high-frequency or large-scale … for commercial or institutional purposes » ⇒ licence séparée) et **décision écrite sur l'échelle réelle du programme**, pas sur une fenêtre (quant B4) : **une capture publique continue du 2026-11-02 au plus tard le 2027-03-31** (P3, G2, G4 Gate 2, G5 ; `LP-PROGRAMME-GATES.md` §1.3), **≤ 40 X-Perps crypto + 2 swaps globaux** (`BTC-USDT-SWAP`, `ETH-USDT-SWAP`, §3.5), canaux `trades`, `books5`, `bbo-tbt`, `mark-price`, `funding-rate` par instrument et `liquidation-orders`, `instruments`, `books-rpi` (P3 seulement) par `instType FUTURES`, cadences documentées `books5` ≤ 10/s, `bbo-tbt` ≤ 100/s, `mark-price` ≤ 5/s (`LP-DATA-DESK` §6), **une** connexion WS par hôte, stockage dans `tradingV3-private` seulement, travail d'agents (LLM) sur ces données pour la recherche personnelle du propriétaire. **Question jointe, hôte global** : la capture des deux swaps globaux passe par `wss://ws.okx.com/ws/v5/public`, hôte que la docs-v5 déclare inadapté aux utilisateurs EU pour les « API calls » (« Using openapi.okx.com will not work for the regions below », `LP-DATA-DESK` §1.1 ; WS public : UNKNOWN) — le propriétaire dit si cette capture est acceptable ; sinon C0 porte sur les X-Perps BTC/ETH seuls (§6.1). **Tout allongement** au-delà du 2027-03-31, tout instrument, canal ou hôte ajouté = **nouvelle décision**. | propriétaire | phrase datée dans le registre du programme, reprenant l'échelle ci-dessus | pas de capture, pas de gel |
| **P-G2-2** | **Verdict `fwd-okx-r1`** rendu (après le 2026-10-31) ; rien de G2 ne démarre avant | propriétaire | date du verdict | T0 repoussé |
| **P-G2-3** | **P3 — capture de comptage** 24–72 h (`research_lp count`, zéro valeur persistée) : couverture de `trades`, `books5`, `funding-rate`, `mark-price` pour `instType FUTURES` ; cadence de `books5` ; hôte WS public EEE exact ; charge d'une capture longue sur N instruments | a2 / a4 (exécution), propriétaire (lancement) | table couverture × fréquence par canal et instrument, dans `a4-data-desk/` | univers réduit aux canaux couverts ; si `books5` ou `trades` ne servent pas `FUTURES`, G2 est **NOT_MEASURABLE** |
| **P-G2-4** | **Simulateur, collecteur et détecteurs gelés** : `python-orchestrator/scripts/research_lp/{tape,xperp_capture,lp_sim,events,cli}.py`. **(A) Fait dans le code** (passe parallèle `a5-corrections-cto`, 2026-10-20, après la relecture CTO ; **vérifié le 2026-10-21** : suite `tests/test_research_lp_*.py` rejouée hors réseau avec le runtime `forward-okx-runtime` — Python 3.12.3, numpy 2.5.4, pytest 9.1.1, `--noconftest -p no:cacheprovider` — **40 passed in 4.87 s** ; digests sha256 `cli.code_identity()` : `__init__.py 225e8273…`, `cli.py b82dcd22…`, `events.py 53252f3f…`, `lp_sim.py a75eefd2…`, `tape.py 98267c53…`, `xperp_capture.py b1a09f70…`, identiques à ceux consignés dans `a5-corrections-cto/NOTES.md` §5) : **(vii) garde de quarantaine** (CTO M1) — `QUARANTINE_FLOOR = "2026-11-02T00:00:00Z"`, `--not-before` sous le plancher refusé dans **tous** les modes (`NotBeforeGuard.assert_above_floor`, exit 2, `not_before_below_floor`, rien écrit), `--allow-before` supprimé au profit de `--allow-before-reason TEXT` obligatoire (`allow_abbrev=False`), enregistrement `guard` dans `meta` des résumés `simulate`/`events` et des npz + `WARN event=guard_lifted` sur stderr (CTO m3), hook `CaptureConfig(floor_override_for_tests=True)` jamais posé par la CLI et divulgué dans la session du manifeste ; **(viii) `ctVal` obligatoire** (CTO M3) — `SystemExit "ct_val_missing"` sans `ctVal` ni `--ct-val`, pour `simulate` **et** `events --kind liquidation` ; `ct_val_source` (`instruments_file` \| `cli`) et `tick_source` (`instruments_file` \| `cli` \| `inferred`) par instrument et au `meta` ; **(ix) arrêt propre** (CTO M4) — `loop.add_signal_handler(SIGTERM/SIGINT)` → `request_stop`, lecture WS interrompue sans attendre la trame suivante, `close()` idempotent (une seule ligne `stopped`), `checkpoint_every_s` par défaut **90 s** en valeurs / 600 s en comptage (`DEFAULT_CHECKPOINT_S`), fenêtre de perte résiduelle documentée dans `tape.py` ; **(x) horloge** (CTO M2, pour le futur brouillon G5) — `lp_sim.event_decisions` : décision à `arm_ts_us` quand `arm_ts_us < ts_us` (fenêtre programmée) et TTL par intention `ttl_us = end_ts_us − arm_ts_us` (`INTENT_DTYPE`, `ORDER_DTYPE`, utilisé par `simulate` et `portfolio_admit`), sinon minute suivante et TTL globale ; `events.liquidation_clusters` pose `arm_ts_us = end_ts_us = ts_us` (une grappe n'est actionnable qu'à la clôture de sa fenêtre — G4 §5.3) ; instants de règlement lus **par instrument** dans la tape (`events.funding_times_from_tape`, `funding_source: tape \| fixed`, repli `--funding-hours` ; `events__all` n'est plus produit — CTO m5) ; **(xi) mineurs CTO** — m1 `WS_PUBLIC_EEA = "wss://wseea.okx.com/ws/v5/public"` (sans port) ; m2 scan de finalisation ≤ 1/min (`finalize_every_s = 60`) et refinalisation d'un jour final ≤ 1/h (`refinalize_after_s = 3600`, compteur `refinalizations`, drapeau `refinalized`) ; m4 clé `books5` = `(ts_us, seq_id, bid_px[0], ask_px[0])` toujours, `missing_seq_id` compté par cellule au comptage ; m6 optimisme de `queue_ahead → 0` documenté ; m7 `side == 0` dans le total, jamais dans le signé ; m8 souscription `liquidation-orders` par `instType` documentée + option `--liq-selected-only` (compteur `dropped_unselected`) ; m9 `instruments-futures.json` **fusionné** (lignes par `instId`, `selected` = union, `updated_at`) ; m10 `frame_nbytes` en octets UTF-8 ; m11 exception numpy documentée, `value` d'un événement re-sommé par `fsum` ; m12 `atomic_write_text` / `save_rows` : `tmp → flush → fsync → rename → fsync du répertoire`. **(B) Reste à coder avant le gel** (tests dédiés pour chaque point, digest `cli.code_identity()` **recalculé** après (B) et inscrit au gel) : (i) une tranche de spread bornée à **6 pb** (au 2026-10-21 `SPREAD_TRANCHES_BP` coupe à 1/2/4/8, `lp_sim.py` l.65), (ii) un décalage de grille `grid_offset_s` pour la variante minute, (iii) la stratification des résumés par tranche × heure × instrument, (iv) le test « rejeu = direct » ; **(v) RPI** (quant B5) : `trades.source` capturé (`TRADES_DTYPE` ne le porte pas), nombre d'ordres par niveau de `books5` capturé (`BOOKS5_DTYPE` ne le porte pas), règle `queue` primaire = prints `source = 0` seuls, variante « tous prints » rapportée, champ `rpi` recopié dans `instruments-futures.json` ; **(vi) `bbo-tbt`** capturé (layout 1 niveau, 10 ms) et utilisé pour `touch`/`through`/`m_0`, `books5` pour la file (quant m3) ; **(xii)** compteur `queue_unknown` et variante « dernière valeur vue » (§5.3) ; **(xiii)** marquage `size_visible` et éligibilité instrument-jour D−1 dans `simulate` (§3.3, §5.7) ; **(xiv)** repli REST `funding-rate-history` quotidien (P-G2-5) ; **(xv)** `band_rejected` sur `maxPxLmtPct` (§5.6) ; **(xvi)** lecture portefeuille sous les deux hypothèses de `posMode` (§5.7). Tant que (v) n'est pas fait, le biais vers le PASS de §0.9 est déclaré dans tout résumé. | a2 | tests verts (`tests/test_research_lp_*.py`), digest recalculé | gel impossible |
| **P-G2-5** | **Funding dans la bande** : le canal WS `funding-rate` sert `instType FUTURES` — **établi** par le desk (changelog docs-v5 2026-03-31 : « Funding rate endpoints now support querying X-Perps futures contracts » ; canal `funding-rate`, `instType` « FUTURES: X-Perps futures », `LP-DATA-DESK-2026-10-20.md` §1.3 ; quant m2) ; P3 ne vérifie plus que la **cadence effective** (« in 30s to 90s ») et la couverture des instruments de l'univers. Repli de sécurité conservé : interrogation REST quotidienne `public/funding-rate-history` par le collecteur, après T0 seulement, règlements `fundingTime < T0` rejetés par le code | a2 (code), P3 (constat) | lignes `funding` du tape ou journal REST | funding compté au terme d'intérêt seul : **net surévalué**, à déclarer |
| **P-G2-6** | **Registre** : ligne `okx-xperp-prospective-G2` créée (`LP-PROGRAMME-GATES.md` §5) avec statut `prospective`, dates, instruments, hash du snapshot de métadonnées | a3 / propriétaire | ligne de registre | gel impossible |
| **P-G2-7** | **Porte économique et règle d'arrêt du programme** fixées par le propriétaire (`LP-PROGRAMME-GATES.md` §1 ; décision 9 du rapport théorie) | propriétaire | valeurs écrites | le verdict économique de §8.4 ne peut pas être rendu |
| **P-G2-8** | **P1 fait** (rapport théorie §6.1 : journaux, champs texte, zéro prix) : durée réelle du run MTF et instant réel de décision, qui fixent le décalage `d` de la variante minute (§5.2). P1 est à coût nul et la seule mesure de l'instant réel de décision : il est **bloquant** (quant m14), plus « souhaité » | propriétaire (ou agent habilité) | valeur de `d` écrite avec sa source | `d = 5 s` resterait une valeur de conception ; gel refusé |
| **P-G2-9** | **RPI relevé** (quant B5) : champ `rpi` (0/1/2) de chaque instrument dans le snapshot §3.1 (métadonnée) ; au comptage P3, canal `books-rpi` ajouté pour mesurer la **part `totalQty − nonRpiQty` au touch** (comptage de lignes et part moyenne par instrument, aucune valeur de prix persistée) ; si `books-rpi` n'est pas servi pour `FUTURES`, la part est **UNKNOWN** et déclarée | a2 / a4 | colonne `rpi` du snapshot ; table P3 | biais vers le PASS de §0.9 non borné |
| **P-G2-10** | **Signes et fenêtres de G5 gelés avant la lecture de G2** (quant M10) : le brouillon G5 (Gate 1 historique puis prospectif, `LP-PROGRAMME-GATES.md` §8) est gelé **avant** la première exécution de l'évaluateur G2 ; sinon la stratification §7.6 par heure/session est étiquetée « screen G5 », G5 devient post-screen et le screen compte dans **m** | a3 / propriétaire | ligne FROZEN de G5 antérieure à la lecture de G2 | §7.6 dégradé en screen |

Non bloquants mais souhaités avant T0 : GET lecture seule au compte (`posMode`, barème API, règle de gel de marge) — sans effet
sur la mesure, nécessaires à la lecture « portefeuille » (§5.7 : rapportée sous les deux hypothèses de `posMode` tant qu'il n'est
pas lu).

---

## 3. Instruments (figés par métadonnées au gel)

3.1 **Snapshot.** `GET https://eea.okx.com/api/v5/public/instruments?instType=FUTURES`, relevé le jour du gel (≥ 2026-11-01),
fichier brut haché (sha256) dans `tradingV3-private/research/20261020T1430Z-lp-programme/a3-protocols/metadata/`. Champs
utilisés : `instId`, `ruleType`, `state`, `listTime`, `expTime`, `ctVal`, `ctType`, `tickSz`, `lotSz`, `minSz`, `settleCcy`,
`baseCcy`/`uly`, `rpi` (0/1/2, P-G2-9), `floatPxLmtPct`, `maxPxLmtPct` (bandes de prix, §5.6). Ce sont des métadonnées, pas des
données de marché (convention du rapport structurel, §0).

3.2 **Filtres (ordre d'application).**
1. `ruleType = xperp` (changelog docs-v5 2026-03-31 : « xperp: perpetual-style futures ») ; exclut `pre_market`,
   `rebase_contract`, `normal` (datés).
2. `state = live`.
3. identifiant de forme `<BASE>-USD_UM_XPERP-<YYMMDD>` (regex `XPERP_RE` de `xperp_capture.py`).
4. sous-jacent **crypto** : exclusion par allowlist négative des bases TradFi, or, matières premières et pré-market telles
   que listées dans le snapshot (rapport structurel §4.1 point 8 : terme d'intérêt nul sur ces familles) ; la liste
   d'exclusion est écrite au gel.
5. `listTime ≤ T0 − 30 jours` (évite les premières semaines après cotation, G10 en annexe du rapport théorie).
6. `expTime ≥ T0 + 365 jours` (évite les fins de contrat, M3).
7. `ctType` : si le champ est servi, `linear` requis (`OkxMetadataProvider` refuse `inverse`) ; s'il est absent pour les
   X-Perps (UNKNOWN, A1 §1), la devise de règlement `settleCcy` doit être USD/USDC et le fait est déclaré.

3.3 **Liquidité, point-dans-le-temps (règle « ≥ 1 M USD/jour »).** Aucun volume n'est lu avant la capture. Un **instrument-jour**
D est éligible si le notionnel échangé du jour UTC D−1, calculé par le simulateur sur le layout `trades` du tape
(Σ `size` × `ctVal` × `price`), est ≥ 1 000 000 USD. Le premier jour de capture est un jour de **rodage** (classification
seule, jamais scoré). Un instrument peut entrer et sortir de l'univers d'un jour à l'autre ; le résumé rapporte le nombre
d'instrument-jours par instrument.

3.4 **Plafond du collecteur.** Si P3 montre que le collecteur ne tient pas tous les X-Perps crypto vivants (`books5` à ~100 ms
× N instruments), la sélection se fait **une fois**, à T0 − 1 jour (≥ 2026-11-01), par les **comptages P3** (nombre de messages
`trades` par instrument sur la fenêtre de comptage — zéro valeur de prix, de taille ou de volume), fichier haché, étiqueté
`selection` au registre (ce n'est pas un résultat) : les N X-Perps crypto les plus actifs passant les filtres 3.2, **N = 40 au
plus** (quant M6 : l'univers est élargi dès le gel, pas allongé ensuite). Aucun relevé de `volCcy24h` : un volume est une Market
Data (§1.8) et `LP-PROGRAMME-GATES.md` §9.5 en interdit la lecture avant le gel (quant M11). Sans ce plafond, tous les
instruments de 3.2 sont capturés. Les filtres 3.2 sont figés avant la lecture des comptages (§0.8).

3.5 **Toujours inclus, quel que soit le filtre** : `BTC-USD_UM_XPERP-<…>` et `ETH-USD_UM_XPERP-<…>` (contrôle C0, §6) ; et, pour
calibration exacte de Part A, les swaps globaux `BTC-USDT-SWAP` et `ETH-USDT-SWAP` (`instType SWAP`, hôte public global),
enregistrés mais **hors univers** (non négociables depuis la France ; rapport théorie §3.3 G2 : « enregistrer BTC et le swap
global du même sous-jacent »). Ils servent aussi, gratuitement, à la régression défensive G9 (§8.5). Leur capture passe par
l'hôte **global** `wss://ws.okx.com/ws/v5/public` depuis un compte EEE : acceptabilité **UNKNOWN**, question posée au
propriétaire dans P-G2-1 (quant B4) ; si elle est refusée, les swaps ne sont pas capturés et C0 porte sur les X-Perps BTC/ETH
seuls (§6.1).

3.6 **Spécifications figées** : `InstrumentSpec` (`lp_sim.InstrumentSpec.from_okx_row`) par instrument : `tick`, `ct_val`,
`lot`, `min_size`. Le conflit « 0,0001 BTC (API) vs 0,01 BTC (page OEM) » est tranché **par l'API** pour la simulation (la
quantité envoyée par API suit `ctVal`), et déclaré.

---

## 4. Données et registre

4.1 **Source unique** : capture publique `research_lp capture` (WebSocket public OKX, hôte EEE `wss://wseea.okx.com/ws/v5/public`
dans sa forme documentée **sans port** (`LP-DATA-DESK` §1.1 ; CTO m1) si P3 le confirme, sinon global ; aucune authentification),
canaux `trades`, `books5`, `bbo-tbt`, `funding-rate`, `mark-price` par instrument, `liquidation-orders` et `instruments` par
`instType FUTURES` (capturés pour G4 et les métadonnées, **non lus par G2** ; la souscription par `instType` persiste les
liquidations de **tout** instrument `FUTURES`, datés inclus, sous leur `instId` — choix déclaré, CTO m8). Layouts `tape.py`
**visés** après P-G2-4 (B) — au 2026-10-21, `TRADES_DTYPE` ne porte pas `source`, `BOOKS5_DTYPE` ne porte pas le nombre d'ordres
et le layout `bbo` n'existe pas : `trades` (`ts_us`, `recv_us`, `price`, `size`, `taker_buy`, `trade_id`, `count`, **`source`**),
`books5` (5 niveaux, tailles en contrats, **nombre d'ordres par niveau**), `bbo` (1 niveau, 10 ms), `funding` (`funding_rate`,
`funding_time_us`, `sett_funding_rate`, `max_rate`, `min_rate`, `interest_rate`…), `mark` (`mark_px`), `liquidations`. Un npz par
instrument-jour, manifeste `manifest-lp.json` avec sha256 par fichier, segments de reprise (jamais d'écrasement ; `tmp → fsync →
rename → fsync du répertoire`, `tape.atomic_write_text` — CTO m12 fait). Fenêtre de perte résiduelle en cas d'arrêt **brutal**
(SIGKILL, OOM) = au plus `checkpoint_every_s`, **90 s par défaut en mode valeurs**, 600 s en comptage (`DEFAULT_CHECKPOINT_S`,
CTO M4 fait) ; un arrêt systemd (SIGTERM) ou Ctrl-C ferme proprement (lecture WS interrompue, tampon RAM vidé dans un dernier
segment, une seule ligne `stopped reason=SIGTERM`, manifeste sauvé). Option `--liq-selected-only` (CTO m8) : ne persister que les
liquidations des instruments sélectionnés — **inactive** par défaut dans ce protocole (G4 veut l'univers large). Budget disque (calcul d'audit a2 §3.1, repris par la
relecture CTO §4.3) : ≈ 170 Mo/jour/instrument de `books5` non compressé à 10 instantanés/s ; 40 instruments × 150 jours ≈ 1 To
brut, compression `savez_compressed` **UNKNOWN** (à mesurer à P3) — inscrit au coût fixe de la porte économique.

4.2 **Garde temporelle.** `--not-before 2026-11-02T00:00:00Z` : le collecteur refuse de démarrer avant et rejette toute ligne
dont l'horodatage d'exchange précède cet instant. Les commandes `simulate` et `events` refusent les jours antérieurs. Depuis la
correction CTO M1 (P-G2-4 vii ; code vérifié le 2026-10-21), la garde est un **plancher**, pas un paramètre :
`QUARANTINE_FLOOR = "2026-11-02T00:00:00Z"` (`xperp_capture.py` l.87, `DEFAULT_NOT_BEFORE = QUARANTINE_FLOOR`) ; toute valeur de
`--not-before` sous le plancher est refusée dans **tous** les modes, comptage inclus (`NotBeforeGuard.assert_above_floor` avant le
verrou et toute écriture ; exit 2, `event=refused reason=not_before_below_floor` ; `cli capture` refuse avant même l'appel REST des
instruments) ; `--allow-before` n'existe plus (`allow_abbrev=False` : pas de préfixe accepté) ; la lecture de jours antérieurs
(tapes synthétiques, données explicitement libérées) exige `--allow-before-reason TEXT`, recopié avec `not_before`,
`quarantine_floor`, `allow_before` et la liste `dates_before_not_before` dans l'enregistrement `guard` du `meta` de chaque résumé
`simulate`/`events` et des npz produits, plus une ligne `WARN event=guard_lifted` sur stderr — une lecture sous garde levée est
toujours distinguable après coup (CTO m3). **Réponse à la revue** (CTO m3 demandait aussi `health.log`) : les commandes de lecture
n'écrivent rien dans le dossier de la tape (`load_day` : « nothing is written ») ; la trace vit dans les artefacts produits par la
lecture, pas dans le journal du collecteur — écart assumé par la passe code, accepté ici. La quarantaine 2026-10-14..31 est donc
**empêchée** par le code pour toute capture de valeurs et toute lecture sans raison écrite (plus seulement traçable par
`not_before` et `argv` du manifeste) ; le seul chemin sous le plancher est le hook de test `CaptureConfig(floor_override_for_tests=True)`,
jamais posé par la CLI et divulgué dans la session du manifeste (`floor_override_for_tests`, `quarantine_floor`).

4.3 **Statut au registre** : `prospective` (jamais lu, produit cible, période postérieure au gel). La capture peut commencer
avant le gel de ce texte (le collecteur tourne pour P3 et pour G4), mais **aucun jour n'est scoré s'il précède le gel** ; les
jours capturés avant le gel servent au plus au rodage de la classification 3.3. Après le verdict, les jours scorés passent au
statut `consumed_equivalent` pour G2 (« lus pour un résultat ») : ils peuvent servir au **développement** de G1/G4/G5 (entrée
de coût), jamais à leur validation.

4.4 **Qualité, règles fixes.** Un instrument-jour est **inexploitable** si la couverture `books5` (minutes avec ≥ 1 snapshot)
< 90 % ou si une période `stale` (A1 : `health.log`) > 10 min le traverse ; il est retiré avant tout calcul et compté. Si plus
de 20 % des instrument-jours de la fenêtre sont inexploitables, verdict `G2_INCONCLUSIVE` (§7.4).

4.5 **Fenêtres et données interdites** : W0–W2 (2026-10-14→16, 19→23, 26→30), tout ce qui est daté 2026-10-14..31, le dossier
`20261013T100000Z-forward-okx-r1/`, les services `fwd-okx-*`, les captures Paper `run14`/`run15` (sauf **lecture indicative**
des chiffres déjà publiés de Part A, §0.2), toute fenêtre du tableau §3 de `RESEARCH-CAMPAIGN-STATUS.md`.

4.6 **Stockage et licence.** Tape et résumés chiffrés dans `tradingV3-private` seulement (§9.4 : aucune redistribution). Ce que
le dépôt `tradingV3` reçoit (protocole, verdict, agrégats par tranche sans série) est une **décision du propriétaire** (visibilité
du dépôt ; « display » de §9.4(a) ; `LP-DATA-DESK` §2.3 Q3 ; rapport structurel §6.2 décision 8), pas une règle de ce protocole :
par défaut, tant qu'elle n'est pas prise, les agrégats chiffrés restent dans `tradingV3-private` et le dépôt ne reçoit que le
protocole et le verdict qualitatif (quant m8).

---

## 5. Règle simulée (fixée)

5.1 **Variante R — recoteur (dispositif de Part A, pour la calibration et le coût de la lenteur).** Toutes les 5 s
(`grid_s = 5`), un achat et une vente au touch (`placement = touch`), taille = notionnel 100 USD arrondi au lot
(`order_notional = 100`), latence 250 ms, post-only (annulé si l'ordre croiserait à l'activation : statut `crossed`),
TTL 45 s, pas de recotation à l'intérieur d'un TTL (un nouvel ordre est posé à la grille suivante).

5.2 **Variante M — minute (la variante décisive).** Décision à chaque minute hh:mm:00 + **d** secondes, où d est le décalage
« durée du run » : d = valeur mesurée par P1 si disponible au gel, sinon **d = 5 s** (valeur de conception) ; sensibilité d = 30 s
rapportée. Achat et vente au touch, 100 USD, latence 250 ms, post-only, **TTL 60 s (primaire)**, TTL 30 s (secondaire),
**aucune recotation**. C'est la seule variante qu'une boucle REST à la minute peut matérialiser (A1 §1.2).

5.3 **Règles de fill** (`lp_sim`, trois règles, toutes calculées) : `queue` = **règle primaire** (taille affichée au prix à
l'activation devant nous ; prints opposés au prix **de `source = 0` seulement** consomment la file puis l'ordre — un print RPI
(`source = 1`) s'exécute contre la profondeur cachée et ne consomme pas notre file, §0.9 ; variante « tous prints » rapportée ;
`partial` possible ; borne haute des annulations devant par la taille affichée et, après P-G2-4 (v), par le nombre d'ordres au
niveau) ; `through` = borne pessimiste (print strictement au-delà du prix ou carnet traversé) ; `touch` = borne optimiste (tête
de file). Tolérance de prix = ½ tick. **Sources et asymétrie déclarée** (quant m3 ; desk §6) : touch/through et `m_0` sont lus
sur `bbo-tbt` (1 niveau, 10 ms) ; la file (`queue`) sur `books5` (5 niveaux, 100 ms) — un `through` daté par `bbo-tbt` peut
précéder de ≤ 100 ms la mise à jour de la file ; en l'absence de `bbo-tbt` pour un instrument (P3), `books5` sert aux deux et
l'instrument-jour est marqué `bbo_missing`. **File hors affichage** (CTO m6) : quand le niveau sort des 5 niveaux ou est
consommé, `queue_ahead` tombe à 0 (optimiste ; la passe code l'a **documenté** dans la docstring de `lp_sim` — « the true queue is
between 0 and the last value seen ») ; le résumé devra compter ces cas (`queue_unknown`) et rapporter la variante « dernière
valeur vue » comme borne haute — **prérequis** P-G2-4 (B) (xii), non encore codé.

5.4 **Tranche de spread courant (la condition de G2).** À chaque décision, spread courant s = (ask₀ − bid₀) / mid en pb, lu
sur le dernier `books5` **antérieur** à l'instant de décision (jamais postérieur). Strates : S0 [0 ; 2), S1 [2 ; 4), S2 [4 ; 6),
**S3 [6 ; 8)**, **S4 [8 ; 16)**, **S5 [16 ; ∞)**. **Cellule primaire = S3 ∪ S4 ∪ S5 (s ≥ 6 pb)**. Les bornes 6 et 8 reproduisent
les gardes de la plateforme (`spread_guard_bps 6`, `spread_bps_lte: 8`), pour que la réponse à « si G2 paie, les gardes
changent » soit lisible. Si `books5` est `stale` (> `stale_s = 10` s) à la décision, l'intention est `stale` et non posée.

5.5 **Mark-outs et sortie.** `m_0` = demi-spread capturé ; `m_h = side × (mid(τ + h) − p) / p` en pb à **h = 60 s (primaire)**,
300 s, 900 s ; **et** aller-retour : sortie passive au touch opposé posée à τ + latence sous la même règle, jusqu'à
`exit_max_s = 3 600` s, reliquat sorti **taker** au touch opposé à 60 min (`exit_kind` maker / taker / mixed, `exit_truncated`).

5.6 **Coûts (pessimistes, dans le net).** `fee_bp` = 2 pb maker à l'entrée + 2 pb (maker) ou 5 pb (taker) à la sortie, pondérés
par la part exécutée ; `funding_bp = −side × Σ taux des règlements traversés` (`first_fill < T ≤ exit`), taux **réels** du tape
(signe réel : un long paie un taux positif) — jamais un prorata (quant M3 : même espérance, variance et lecture par signe
différentes) ; l'intervalle de funding est **lu dans la tape** (`funding_time_us`, `next_funding_time_us`) par instrument, son
raccourcissement en stress sur les X-Perps est **UNKNOWN** (quant M8) ; **stress funding** = pour la sensibilité, chaque
règlement traversé compté au plafond `max_rate` de l'instrument contre la position ; sélection adverse **mesurée** (c'est le
mark-out lui-même), jamais supposée — l'**écrémage RPI** (§0.9) est dans la mesure, pas ajouté ; glissement maker nul par
construction ; **liquidation forcée** : sans objet à 1× avec sortie ≤ 60 min (à 1× isolé avec `mmr` 0,65 %, une liquidation
exigerait un mouvement de l'ordre de **99 %** en une heure, calcul d'audit ; quant m4) — déclaré, pas modélisé ; **bandes de
prix** (`floatPxLmtPct`, `maxPxLmtPct` « hard cap on an order's deviation from the index », `H1-XPERP-MARKET-ACCESS.md`
l.58–60) : un post-only au touch peut être **rejeté** par la bande dans un carnet disloqué ; non modélisé, déclaré comme **biais
vers le PASS** (quant m4 : les décisions dont le touch est hors bande seraient refusées en direct, la simulation les pose et les
score) — le résumé compte ces décisions (`band_rejected`, détectées sur `maxPxLmtPct` du snapshot `instruments` ; l'indice n'est
pas capturé : approximation par le mark, déclarée) et le net est rapporté avec et sans elles. Robustesse **frais × 1,5**
(3/7,5 pb) rapportée pour chaque critère.

5.7 **Deux lectures** (`LP-PROGRAMME-GATES.md` §4). *Simulation sans contrainte* : toutes les intentions, puissance. *Portefeuille
500 USDT* : `capital = 500`, `leverage = 1`, `max_live = 4`, ordres admis dans l'ordre des décisions tant que < 4 engagements
vivants et que Σ notionnels ≤ capital ; un engagement dure jusqu'à la sortie, à l'activation `crossed` ou au TTL. **Mode de
position** (quant M13) : deux fills opposés dans la même minute (capture de spread réussie) donnent deux engagements de 100 USD
avec deux sorties ; en `net_mode` ils se compensent (marge libérée, position nette nulle), en `long_short_mode` non. `posMode` du
compte est **UNKNOWN** (P2) : la lecture portefeuille est rapportée **sous les deux hypothèses** (`net_mode` : engagements opposés
du même instrument nettés ; `long_short_mode` : comptés séparément) et la lecture économique §8.4 donne les deux bornes.
Résultats =
ceux de la lecture sans contrainte (aucun impact de marché supposé : avec 100 USD face à des carnets de plusieurs milliers,
hypothèse à **vérifier** : si la taille affichée au touch est < 10 × notre taille sur plus de 20 % des décisions d'une cellule,
la cellule est marquée `size_visible` et le résultat `queue` est la seule lecture retenue pour elle).

---

## 6. Contrôle et calibration

6.1 **C0 — Reproduction de Part A (calibration du couple capture + simulateur ; quant M1).** Variante R, règle `through`,
horizon 60 s. **Attendu de signe (exigé)** : sélection adverse nette d'entrée **négative, UB90 < 0**, sur la cellule S0 (spread
< 2 pb) des X-Perps BTC/ETH **et**, s'ils sont capturés (P-G2-1, hôte global), sur `BTC-USDT-SWAP` / `ETH-USDT-SWAP`.
**Attendu de magnitude (exigé seulement sur le swap global effectivement capturé)** : dans l'intervalle [−5 ; −1] pb (Part A :
−3,05 / −3,10 ; tolérance ± 2 pb pour une autre période ; mêmes carnets que Part A). Sur les X-Perps BTC/ETH, la magnitude est
**rapportée**, pas exigée : Part A tient sur un demi-spread de 0,006–0,19 pb et le tick comme le spread des X-Perps BTC/ETH sont
UNKNOWN ; exiger le même intervalle fabriquerait un `INCONCLUSIVE` par construction. **UB90 ≥ 0 sur une cellule de signe, ou
magnitude hors intervalle sur le swap global capturé** ⇒ le dispositif de mesure n'est pas celui de Part A ⇒ `G2_INCONCLUSIVE`
(pas de PASS, pas de FAIL) : le défaut est cherché dans le collecteur ou le simulateur, et une nouvelle fenêtre exige un nouveau
pré-enregistrement.

6.2 **Contrôle interne par tranche.** La cellule S0 (spread < 2 pb) des X-Perps liquides joue le rôle de « carnet à 1 tick » :
son net 60 s doit lui aussi être ≤ 0 (UB90 ≤ 0) ; sinon, même conséquence que C0. La **différence S≥6 − S0** est rapportée
(descriptive : c'est la « capture de spread » au sens strict).

6.3 **Pas de contrôle d'événement** : G2 n'a pas d'événement (règle 5 du rapport théorie sans objet) ; la mesure est
inconditionnelle par tranche.

---

## 7. Unité d'observation, puissance, critères

7.1 **Unité** = le **fill** (une intention remplie, totalement ou `partial` pondéré). Les fills sont groupés dans le temps :
l'incertitude est calculée par **bootstrap par blocs** de 600 s d'horodatage de fill (`BLOCK_S = 600`, 1 000 rééchantillons,
graine `SEED = 20261102`, `block_bootstrap_lower_bound`), **par paire** puis **poolé** : le pool traite un bloc de 10 min comme
une unité, tous instruments confondus (les fills de paires différentes au même instant sont corrélés par le marché).
Toute intention non remplie (`unfilled`, `crossed`, `stale`, `no_inside`, `skipped_size`) est comptée dans le **taux de fill**,
qui entre dans l'économie (§8.4), pas dans le mark-out.

7.2 **Porte de puissance** (règle 9) : la cellule primaire (variante M, règle `queue`, S≥6, poolée) doit compter **≥ 1 000 fills**
et au moins **5 paires** avec ≥ 1 000 fills chacune sur leur cellule S≥6 ; les cellules paire-tranche sous 1 000 fills sont
`NOT_INFORMATIVE` et exclues de la lecture par paire (jamais fusionnées a posteriori). Justification : avec σ du mark-out 60 s
**UNKNOWN** sur carnets larges, 1 000 fills bornent l'erreur type à σ/√(1 000 × 1/DE) où DE est l'effet de plan du
regroupement ; Part A obtenait des LB90 à ±0,1 pb avec des milliers de fills et σ ≈ quelques pb ; à σ ≈ 20 pb (ordre de
grandeur plausible sur un spread de 6–16 pb, hypothèse de conception) et DE ≈ 2, 1 000 fills donnent une demi-largeur LB90
≈ 1,2 pb, suffisante pour trancher un net de ±2 pb. La porte est un **plancher** ; elle n'est jamais abaissée. **Relaxation
déclarée** (quant M14) : le rapport théorie §3.3 G2 exige « ≥ 1 000 fills par paire-tranche » ; ce protocole poole les tranches
S3 ∪ S4 ∪ S5 et exige 1 000 fills par paire sur leur cellule S≥6 fusionnée — cellule hétérogène (6 pb à ∞). En conséquence, le
verdict **nomme la strate** (S3, S4 ou S5) qui porte ≥ 50 % des fills de la cellule primaire, le signe de C1 et de C2 est rapporté
**par strate**, et un PASS dont le signe n'est positif que dans une strate est publié « PASS, porté par S_k ».

7.3 **Critères (variante M, règle `queue` sur prints `source = 0`, cellule S≥6, poolé ; évalués une seule fois à la fin de la
fenêtre ; tous requis pour `G2_PASS`)** :
- **C1 — sélection adverse nette d'entrée** : `m_60s − fee_bp(entrée) + funding_bp`, où `funding_bp` compte les **règlements
  traversés** (`first_fill < T ≤ exit`, comme le code `lp_sim.FundingSchedule.crossed` ; jamais un prorata, quant M3) ;
  **> 0 avec LB90 > 0**. C1 est une demi-transaction : il mesure la sélection adverse et devient l'**entrée de coût** de
  G1/G4/G5 ; il n'entre pas dans la porte économique (quant B1) ;
- **C2 — aller-retour** : `net_bp = rt_bp − fee_bp(entrée + sortie) + funding_bp` (sortie passive au touch opposé ≤ 60 min,
  reliquat taker, §5.5–5.6) **> 0 avec LB90 > 0**. C2 est le seul PnL réalisable et le seul `net_pb` de §8.4 ; l'horizon 300 s
  est **descriptif** (quant M2 : « un autre horizon au choix » offrait deux chances sous H0) ;
- **C3** **coût de file** : `through` − `touch` (mark-out 60 s, même cellule) **< demi-spread médian de la cellule − 2 pb** ;
- **C4** robustesse : C1 **et** C2 tiennent à **frais × 1,5** ; C1 et C2 tiennent **sans les 5 % meilleurs fills** ; la médiane
  des estimations **par paire** (paires informatives) de C2 est > 0 ; C2 tient sous la variante `queue` « tous prints » (sinon
  le PASS est publié « PASS, dépendant du traitement RPI ») ;
- **C5** C0 et 6.2 satisfaits (sinon `G2_INCONCLUSIVE`, pas de verdict).
`G2_FAIL` si C1 ou C2 échoue avec la porte de puissance atteinte ; `G2_NOT_INFORMATIVE` si la porte n'est pas atteinte (aucune
prolongation sur résultat ; voir §7.5). Le **test confirmatoire primaire** de G2 dans la famille Holm (`LP-PROGRAMME-GATES.md`
§2) est **C2** ; C1 est un critère de PASS et une mesure de coût, pas le test de la famille.

7.4 **`G2_INCONCLUSIVE`** : C0/6.2 hors intervalle ; > 20 % d'instrument-jours inexploitables ; funding absent du tape pour
> 10 % des règlements traversés sans repli REST ; changement de barème, de règle d'ordre ou de spécification d'un instrument
du contrôle pendant la fenêtre. Un INCONCLUSIVE ne se corrige pas : nouvelle fenêtre, nouveau pré-enregistrement.

7.5 **Fenêtre et futilité.** T0 = premier 00:00 UTC ≥ max(2026-11-02 ; fin de P3 ; gel + 24 h) ; **rodage** = jour T0
(classification 3.3) ; **scoring** = 14 jours UTC complets, T0 + 1 → T0 + 14 ; **prolongation** uniquement **pour des trous de
données** (jour inexploitable 4.4 ⇒ un jour ajouté, au plus 7), jamais sur le résultat ; fin dure T0 + 21. **Futilité** : au
7ᵉ jour scoré, si le net 60 s poolé de la cellule primaire a **UB90 < −2 pb** et ≥ 500 fills, l'évaluation peut être close
(`G2_FAIL_FUTILITY`) — jamais un PASS anticipé. Lecture intermédiaire limitée à ce seul chiffre, par un script qui n'écrit que
le verdict de futilité (booléen et date) ; **l'auteur du verdict final ne voit ni la valeur ni le résumé intermédiaire** avant la
fin de la fenêtre — seule la ligne `FUTILITY: yes/no` est publiée (quant m11).

7.6 **Lectures secondaires (descriptives, jamais décisives)** : par strate S0…S5 ; **par heure UTC (24 cases) et par session
(00/08/13:30–14:30/16 UTC ± 30 min vs reste) — produite seulement si P-G2-10 est satisfait** (signes et fenêtres de G5 gelés
avant la lecture de G2) ; sinon cette stratification est le **screen de G5** : elle est étiquetée « screen G5 » dans le résumé,
G5 est alors un test post-screen et le screen compte dans **m** (`LP-PROGRAMME-GATES.md` §2.1 ; quant M10) ; par signe du
prochain funding publié ; variante R vs M (coût de la lenteur) ;
TTL 30 vs 60 ; d = 5 vs 30 s ; règle `touch` / `through` / `queue` ; `partial` ; part de `crossed` ; distribution des
`exit_kind` ; mark-out jusqu'au fill opposé ; lecture portefeuille (fills admis/jour, notionnel vivant moyen, PnL net USD).

---

## 8. Ce que le résultat autorise et rapports obligatoires

8.1 **`G2_PASS` autorise exactement deux choses** : (i) l'utilisation de la table « sélection adverse nette d'entrée (C1) par
tranche × paire (× heure seulement si P-G2-10 est satisfait) » comme **entrée de coût** des protocoles G1, G4, G5 (valeurs de
conception de leurs sensibilités) ; (ii) une **proposition écrite**
de révision des gardes de spread de la plateforme (`spread_guard_bps`, `spread_bps_lte`, `market_max_spread_pct`), à décider par
le propriétaire. **Il n'autorise ni** une stratégie de tenue de marché, ni un ordre (démo ou réel), ni un chemin d'exécution,
ni une relecture de la tape pour une autre hypothèse sans nouveau pré-enregistrement (la tape devient `consumed_equivalent`).

8.2 **`G2_FAIL`** : la fourniture passive **inconditionnelle** sur carnets larges X-Perps ne paie pas à la minute ; la famille
« capture de spread » est **close** pour cette architecture (déjà close à 1 tick par Part A). La table de coût reste valide et
obligatoire pour G1/G4/G5 : un FAIL de G2 **n'arrête pas** le programme, il en fixe les coûts (règle 7).

8.3 **`G2_NOT_INFORMATIVE` / `G2_INCONCLUSIVE`** : aucune conclusion sur le signe ; nouvelle fenêtre seulement par un nouveau
pré-enregistrement et après diagnostic écrit ; la fenêtre lue est consommée.

8.4 **Lecture économique obligatoire** (règle 11, `LP-PROGRAMME-GATES.md` §1) : plafond USD/an = fills admis/jour (lecture
portefeuille) × notionnel moyen × **`net_bp` aller-retour (C2)** × 365 / 10 000, avec les bornes LB90/UB90 de C2 — jamais C1
(quant B1 : le mark-out net d'entrée surévaluerait le plafond de 2 à 5 pb par fill plus la sélection adverse de sortie, un
changement de signe à l'échelle visée). Rapportée sous les deux hypothèses de `posMode` (§5.7). Comparée à la porte économique
fixée par le propriétaire (P-G2-7 ; « poursuivi » ⇔ point ≥ S_éco **et** UB90 ≥ 2 S_éco ou LB90 > 0, `LP-PROGRAMME-GATES.md`
§1.2) et au coût fixe déclaré (collecteur, disque, supervision, simulateur, heures du propriétaire). Même un PASS sous la porte
économique est rapporté « PASS, non poursuivi ».

8.5 **Rapports secondaires** : table de coût (§8.1) ; coût de la lenteur R − M par strate ; régression défensive G9
(r_alt(1–5 min) ~ r_BTC(minute précédente, décalé de d), descriptive, « ne pas laisser d'ordre au repos après un saut de
BTC ») ; distribution de la taille affichée au touch rapportée à 100 USD (`size_visible`) ; part des décisions tombées à
`stale`/`no_inside` ; couverture par canal (retour vers P3).

---

## 9. Risques, bornes, et ce que ce protocole ne fait jamais

- **Aucun ordre, aucune démo, aucun drapeau** : la confirmation de ce programme est « capture publique + simulateur de
  recherche » (règle 13) ; le chemin Paper certifié et la démo ne sont **pas** des véhicules de G2.
- **Perte maximale** : nulle (simulation). Lecture portefeuille : PnL net simulé, borné par 4 × 100 USD × mouvement en 60 min.
- **Biais déclarés vers le FAIL** : (i) files d'attente — `queue` borne les annulations devant par la taille affichée (borne
  haute) ; (ii) impact — aucun ; (iii) survie — l'univers point-dans-le-temps n'exclut pas un délisting intra-fenêtre (compté) ;
  (iv) période unique de 2 semaines : la stabilité « par semaine » est rapportée, pas exigée ; (v) le swap global sert à la
  calibration, pas à l'économie (non négociable depuis la France).
- **Biais déclarés vers le PASS** (quant B5, m4 ; CTO m6) : (vi) prints RPI comptés comme consommant la file tant que
  `trades.source` n'est pas capturé et utilisé (P-G2-4 v) ; (vii) `queue_ahead = 0` hors affichage ; (viii) rejet par bande de
  prix non modélisé (§5.6, `band_rejected` compté) ; (ix) écrémage RPI : la sélection adverse mesurée est celle du carnet
  organique **tel qu'il est** — elle est dans la mesure, mais sa comparaison à Part A (RPI présent ou non sur les USDT-swaps de
  2026-10-02/03 : UNKNOWN) n'est pas un étalon.
- **Dépendance aux UNKNOWN** : couverture `FUTURES` des canaux (P3) ; cadence `books5`/`bbo-tbt` ; hôte WS EEE (forme sans port à
  essayer d'abord) et acceptabilité de l'hôte global ; `ctType` ; `ctVal` 0,0001 vs 0,01 BTC (tranché par l'API) ; présence et part
  des teneurs RPI (`instruments.rpi`, `books-rpi`) ; `posMode` ; raccourcissement du funding sur X-Perps ; durée du run (d, P1).

---

## 10. Traitement de la revue (2026-10-20)

Relectures : `tradingV3-private/research/20261020T1430Z-lp-programme/review/quant.md` (B1–B5, M1–M14, m1–m14) et
`review/cto.md` (M1–M6, m1–m12). Convention : **appliqué** = texte modifié ; **appliqué au code** = fait par la passe parallèle
`a5-corrections-cto` (hors du périmètre d'écriture de cette passe), **vérifié ici** le 2026-10-21 ; **prérequis** = exigé au gel
(P-G2-4 (B), non codé) ; « **Réponse à la revue** » = refus ou traitement différent, motivé.

| Constat | Traitement |
|---|---|
| quant **B1** — C1 est une demi-transaction ; §8.4 le convertissait en USD/an | **Appliqué** : C1 renommé « sélection adverse nette d'entrée » (entrée de coût de G1/G4/G5, hors porte économique) ; C2 = aller-retour `net_bp` seul, critère de PASS et test confirmatoire de la famille ; §8.4 et H-G2 réécrits ; `LP-PROGRAMME-GATES.md` §1.1 : `net_pb` est toujours un aller-retour |
| quant **B4** — licence demandée sur « 2–4 semaines » ; hôte global ; numérotation « (c) » | **Appliqué** : P-G2-1 réécrit sur l'échelle réelle (capture continue 2026-11-02 → ≤ 2027-03-31, ≤ 40 X-Perps + 2 swaps, canaux et cadences nommés, stockage, agents) ; question de l'hôte global ajoutée (P-G2-1, §3.5) ; tout allongement = nouvelle décision ; « You may not (c) » et « Automated Scraping (a)–(c) » cités séparément (m9) |
| quant **B5** — prints RPI et nombre d'ordres jetés ; biais vers le PASS non déclaré ; écrémage RPI | **Appliqué / prérequis** : §0.9 (déclaration, biais vers le PASS), §5.3 (`queue` sur `source = 0`, variante « tous prints »), P-G2-4 (v), P-G2-9 (`instruments.rpi`, `books-rpi` à P3), §9 ; coût « écrémage RPI » ajouté à `LP-PROGRAMME-GATES.md` §5. **Réponse à la revue** sur un point : l'écrémage RPI n'est **pas ajouté** au net comme un coût séparé — il est **dans** la sélection adverse mesurée sur le carnet organique (§5.6) ; ce qui est déclaré est que cette mesure n'est pas comparable à Part A |
| quant **M1** — C0 fragile (intervalle exigé sur X-Perps ; hôte global UNKNOWN) | **Appliqué** : §6.1 — signe (UB90 < 0) exigé sur S0 des X-Perps BTC/ETH et sur le swap global s'il est capturé ; intervalle [−5 ; −1] exigé sur le swap global capturé seulement ; magnitude rapportée sinon |
| quant **M2** — C2 « 300 s ou aller-retour » = deux chances | **Appliqué** : C2 = aller-retour seul ; 300 s descriptif |
| quant **M3** — « funding au prorata » vs règlements traversés | **Appliqué** : C1 et §5.6 alignés sur le code (`FundingSchedule.crossed`) ; « prorata » retiré |
| quant **M6** (part G2) — élargir l'univers dès le gel | **Appliqué partiellement** : plafond porté à N = 40 (§3.4) et « tous les instruments de 3.2 si le collecteur tient ». **Réponse à la revue** : le plafond reste conditionné à la capacité du collecteur (fait de P3) et à l'enveloppe de licence (P-G2-1 : ≤ 40) ; au-delà, c'est une nouvelle décision du propriétaire, pas un choix du protocole |
| quant **M8** — raccourcissement du funding X-Perps = UNKNOWN | **Appliqué** : §5.6 (intervalle lu dans la tape, raccourcissement UNKNOWN) ; `LP-PROGRAMME-GATES.md` §5.1 corrigé |
| quant **M10** — §7.6 est le screen de G5 | **Appliqué** : P-G2-10 (G5 gelé avant la lecture de G2) ; sinon §7.6 étiqueté « screen G5 » et compté dans m ; §1 et §8.1 alignés |
| quant **M11** — `volCcy24h` contredit GATES §9.5 | **Appliqué** : sélection par comptages P3 (zéro valeur), plus aucun relevé de volume (§3.4) ; `LP-PROGRAMME-GATES.md` §6.1/§9.5 redéfinissent `selection` sans volume |
| quant **M13** — inventaire et `posMode` | **Appliqué** : §5.7 et §8.4 rapportés sous les deux hypothèses de mode |
| quant **M14** — relaxation « par paire-tranche » non déclarée | **Appliqué** : §7.2 — relaxation déclarée, strate porteuse nommée, signe par strate, « PASS, porté par S_k » |
| quant **m2** — `funding-rate` pour FUTURES établi | **Appliqué** : P-G2-5 résolu (desk, changelog 2026-03-31), repli REST conservé |
| quant **m3** — `bbo-tbt` pour touch/through ; asymétrie | **Appliqué / prérequis** : §4.1, §5.3, P-G2-4 (vi) |
| quant **m4** — « 90 % » → ≈ 99 % ; rejet par bande | **Appliqué** : §5.6 (chiffre corrigé ; bande déclarée biais vers le PASS, `band_rejected` compté) ; §3.1 ajoute `floatPxLmtPct`/`maxPxLmtPct` |
| quant **m7** — comptages P3 visibles avant le gel | **Appliqué** : §0.8 ; filtres §3.2 figés avant lecture de P3 |
| quant **m8** — agrégats dans le dépôt = décision du propriétaire | **Appliqué** : §4.6 (défaut : agrégats dans `tradingV3-private`) |
| quant **m11** — lecture de futilité | **Appliqué** : §7.5 (l'auteur du verdict final ne la voit pas) |
| quant **m14** — P1 bloquant | **Appliqué** : P-G2-8 |
| CTO **M1** (plancher `QUARANTINE_FLOOR`), **M3** (`ctVal` obligatoire), **M4** (SIGTERM, checkpoint), **M2** (`arm_ts_us`, pour G5), **m1–m12** | **Appliqué au code** par la passe parallèle `a5-corrections-cto` (2026-10-20), hors du périmètre d'écriture de cette passe ; **vérifié ici** le 2026-10-21 : lecture du code (`QUARANTINE_FLOOR` l.87, `cli._guard_tape`, `cli._ct_val`, `lp_sim.event_decisions`, `_install_signal_handlers`, `DEFAULT_CHECKPOINT_S`, `tape.DEDUPE_KEYS`, `write_instruments`, `tape.atomic_write_text`, `events.funding_times_from_tape`), digests identiques à ceux de `a5-corrections-cto/NOTES.md` §5, suite `tests/test_research_lp_*.py` rejouée hors réseau : **40 passed in 4.87 s**. État consigné en P-G2-4 (A) ; §4.1–4.2 réécrits (la quarantaine est **empêchée** par le code, plus seulement traçable). Écarts de la passe code vis-à-vis de la lettre des corrections, **acceptés** (« Réponse à la revue ») : m3 — la raison n'est pas écrite dans `health.log` (les lecteurs n'écrivent rien dans la tape) mais dans `meta.guard` de chaque résumé et sur stderr ; m2 — pas de segment `late` nommé, les lignes tardives restent en segments ordinaires fusionnés ≤ 1/h ; m9 — fusion de `instruments-futures.json` (`selected` = union) plutôt qu'un fichier par session ; m6 — optimisme documenté seulement, le compteur `queue_unknown` reste un prérequis (P-G2-4 xii) ; M2 — pour une grappe de liquidations `arm_ts_us = ts_us` (clôture de la fenêtre), ce qui évite un look-ahead (G4 §5.3). Reste à coder : P-G2-4 (B) |
| CTO **§4.3** — budget disque, supervision | **Appliqué** : §4.1 (ordre de grandeur, compression UNKNOWN) ; coût fixe de `LP-PROGRAMME-GATES.md` §1.2 |

---

## 11. Procédure de gel

1. Tous les P-G2-1…10 satisfaits et consignés (`a3-protocols/NOTES.md`, registre du programme) ; verdict `fwd-okx-r1` rendu.
2. Snapshot `instruments` relevé, filtré selon §3.2, liste d'instruments et liste d'exclusion écrites dans une section
   « Annexe I — instruments figés » ajoutée **au-dessus** de cette section ; sha256 du snapshot inscrit.
3. Code : `python -m scripts.research_lp.cli` — digest canonique de `cli.code_identity()` (sha256 de chaque module) inscrit ;
   version de Python et de numpy inscrites.
4. Remplacer `DRAFT_NOT_FROZEN` par `FROZEN <horodatage UTC>` dans l'en-tête ; écrire la ligne
   `FROZEN <UTC> body sha256 <…> code sha256 <…> runtime <…> (T0 = <date> ; not executed)` sous cette section ; le corps haché
   est le contenu du fichier **jusqu'à la ligne « ## 11. Procédure de gel » incluse et la ligne vide suivante** (convention
   de `PREREGISTRATION-H2-GATE1.md` §15) ; écrire `LP-G2-DRAFT-PREREGISTRATION.sha256` (corps, code, horodatage, T0, statut).
5. Publier (PR) **avant T0** ; aucune lecture de la tape avant la publication.
6. Toute modification après le gel = **amendement** écrit, daté, publié **avant toute lecture**, avec sa règle d'acceptation ;
   une seule exécution de l'évaluateur, sur la fenêtre déclarée, dont la sortie (résumés JSON, verdict) est hachée et jointe
   à `LP-G2-RESULT.md`.

Fin du brouillon. `DRAFT_NOT_FROZEN` — rien n'est gelé, rien n'est lancé.
