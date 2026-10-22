# Pilote de capture L2 — OKX Europe X-Perps (préparé le 2026-10-22, non lancé)

**Objet.** Préparer et vérifier hors ligne un enregistreur de **profondeur du carnet (L2)** et des **trades publics** sur
deux X-Perps d'OKX Europe (BTC et ETH). Ces données serviront **plus tard** à étudier la faisabilité de take-profit et de
stop-loss adaptés au carnet. Rien n'est lancé aujourd'hui. Rien n'est planifié : ni cron, ni unité systemd, ni tâche
programmée.

**Nature.** Outil de recherche, données publiques. Aucun ordre, aucun drapeau, aucune clé, aucun appel à un endpoint OKX
pendant la préparation. Aucune donnée de marché lue. Seule la documentation officielle OKX a été lue (pages en ligne
`docs-v5/trick_en` le 2026-10-22, et copies locales du 2026-10-20 de la docs-v5, voir §2).

**Décision P-L2-1 (D14, 2026-10-22) — spec v2.** Le propriétaire réserve au pilote une courte fenêtre d'exploration de développement,
créneau 2026-11-02T00:00Z → 2026-11-03T00:00Z : un départ plus tardif raccourcit le pilote, ne décale jamais sa fin. Une marge de 12 h
complète la fenêtre : rien de [2026-11-02T00:00Z ; 2026-11-03T12:00Z) n'entre dans un test décisif du programme LP (G5 Gate 2, G2, G4 Gate 2,
comptages P3), lectures préalables comprises (`LP-PROGRAMME-GATES.md` §7.4 et `LP-OWNER-DECISIONS-2026-10-21.md` D14, branche
`claude/g5-gate2-prefreeze`). Le spec v1 reste tel quel ; le spec v2 ajoute la fin dure et le contrôle de la séparation (§2.3, §4, §7).

**Point de départ.** L'inventaire `BOOK-TPSL-INVENTORY-2026-10-22.md` (branche `claude/research-book-tpsl-inventory`) :
aucune donnée sur disque ne donne le carnet par niveau de prix. La seule voie pour les variantes « murs » est une capture
prospective L2 sur le produit exécutable.

## 0. Autorisation du propriétaire (2026-10-22)

Texte transmis, recopié tel quel :

> a public-data capture pilot, start no earlier than 2026-11-02 and respecting the LP programme prerequisites; two X-Perps
> contracts, BTC and ETH, selected by authorized metadata; max 24 h; at most 5 GiB written and at least 10 GiB kept free;
> clean stop as soon as a limit is hit; no automatic extension or restart.

> TODAY: prepare the tool and its exact command only; schedule nothing (no cron, no systemd unit, no scheduled task). No
> orders, no flags, no merge.

Le canal `books` (400 niveaux) n'est pas dans la capture continue D1 du programme LP. La règle D1 dit : « Tout allongement,
instrument, canal ou hôte ajouté est une nouvelle décision ». Cette autorisation est cette nouvelle décision, limitée au
pilote.

## 1. Définitions

| Terme | Sens dans ce document |
|---|---|
| **L1** | Le meilleur prix et sa taille, de chaque côté (meilleur bid, meilleur ask). |
| **L2** | Plusieurs niveaux de prix par côté, chacun avec sa taille agrégée et son nombre d'ordres. `books5` = 5 niveaux ; `books` = jusqu'à 400 niveaux. |
| **Profondeur visible** | La somme des tailles publiées à un prix ou jusqu'à un prix. C'est ce que montre le carnet public. |
| **Position réelle dans la file** | Le rang de **notre** ordre parmi les ordres au même prix. Elle **n'est pas observable** dans les données publiques : le carnet agrège les ordres d'un niveau, sans identité ni horodatage d'arrivée. On peut seulement borner la taille visible devant nous à l'arrivée et supposer un FIFO. |

### 1.1 Le chemin `books5` existant garde les cinq niveaux

Vérifié dans le collecteur LP (`python-orchestrator/scripts/research_lp/`) :

| Point | Référence |
|---|---|
| Branche `books5` : bids et asks passés entiers à `_pad_levels` | `xperp_capture.py:286-292` |
| `_pad_levels` garde `n = tape.LEVELS` niveaux, prix et taille | `xperp_capture.py:266-275` |
| `LEVELS = 5` | `tape.py:74` |
| Colonnes `bid_px`, `bid_sz`, `ask_px`, `ask_sz` de forme (5,) | `tape.py:79-80` |
| Test qui le vérifie | `tests/test_research_lp_l2_pilot.py::test_existing_books5_path_keeps_all_five_levels` |

Les cinq prix et les cinq tailles de chaque côté sont gardés. Le nombre d'ordres par niveau et le champ déprécié `"0"` ne
sont pas gardés. L'ancien enregistreur paper, lui, réduit au L1 :
`trading-app/src/Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizer.php:319-333` (`bestBid()`, `bestAsk()`
seulement).

Le pilote ne passe **pas** par ce chemin : il garde la trame brute entière (§3).

## 2. Choix des canaux (gelé)

### 2.1 Profondeur

| Canal | Niveaux | Cadence | Accès | Retenu |
|---|---|---|---|---|
| `books` | 400 à l'instantané, puis incrémental | 100 ms | public, sans connexion | **oui, primaire** |
| `books5` | 5, instantané complet à chaque envoi | 100 ms | public | **oui, repli et contrôle** |
| `books50-l2-tbt` | 50, incrémental | 10 ms | connexion + VIP4 (erreur 64003) | non |
| `books-l2-tbt` | 400, incrémental | 10 ms | connexion + VIP4 (VIP5 selon la page trick) | non |
| `bbo-tbt` | 1 | 10 ms | public | non : L1 seulement |
| `books-rpi` | 400 avec la profondeur RPI | 100 ms | public | non : hors autorisation |

Sources :

- docs-v5, « WS / Order book channel » : copie EEE `my-docs-v5-en.txt` l.15095-15349, copie globale `docs-v5-en.txt`
  l.30510-30766. URL path `/ws/v5/public`. « books: 400 depth levels will be pushed in the initial full snapshot.
  Incremental data will be pushed every 100 ms ». « Only API users who are VIP4 and above in trading fee tier are allowed
  to subscribe to "books-l2-tbt" 400 depth channels. »
- docs-v5, « Sequence ID » : EEE l.15352-15371, globale l.30767-30786 (règles de §5).
- Changelog du 2026-06-23, « Deprecation of checksum field in order book channels » (`docs-v5-log_en.txt` l.2212-2228) :
  la somme de contrôle de `books` vaut toujours 0 ; il faut vérifier la continuité par `seqId` / `prevSeqId`. `books5` n'a
  pas de somme de contrôle.
- Page `https://www.okx.com/docs-v5/trick_en/` (lue le 2026-10-22) : « books publishs the changes in the order book every
  100ms » ; `books-l2-tbt` et `books50-l2-tbt` exigent une connexion et un palier VIP.
- La page `https://www.okx.com/docs-v5/en/` en ligne est tronquée avant la section Market Data par l'outil de lecture
  (406 161 caractères) ; les sections citées viennent des copies locales du 2026-10-20 (sha256 dans le spec).

**Limites déclarées.**

- Accès des X-Perps à `books` : la documentation ne l'exclut pas et ne le confirme pas nommément (même situation que
  `books5`, `LP-DATA-DESK` §0 point 3). C'est vérifié au lancement par l'accusé de souscription.
- Si `books` est refusé pour un instrument, le pilote continue avec `books5` et `trades` pour lui, et l'écrit dans le
  manifeste (`depth_status = books5_only`). Pas de bascule automatique vers un autre canal, un autre hôte ou une connexion.
- `books` ne contient pas les ordres RPI (ex-ELP) : « books/books5/bbo-tbt/books-l2-tbt/books50-l2-tbt don't return ELP
  orders » (EEE l.15349).
- Un changement A → B → A pendant un intervalle de 100 ms n'est pas envoyé (page trick).

### 2.2 Trades

| Canal | Contenu | URL path | Retenu |
|---|---|---|---|
| `trades` | un message par ordre preneur, prix et source ; champ `count` ; `tradeId` = dernier de l'agrégat ; `seqId` | `/ws/v5/public` | **oui** |
| `trades-all` | un trade par message | `/ws/v5/business` | non |

Sources : « WS / Trades channel », EEE l.14750-14930, globale l.30165-30345 ; « WS / All trades channel », EEE l.14931-15094.
Raison : `trades` passe par la même connexion que le carnet, et la doc écrit « Order book and the aggregated trades data are
still published sequentially » (EEE l.14928). `trades-all` exige une seconde connexion, sans ordre garanti entre les deux.

### 2.3 Spec gelé

| Élément | Valeur |
|---|---|
| Fichier | `python-orchestrator/scripts/research_l2/specs/l2_pilot_spec_v2.json` (spec par défaut du code depuis P-L2-1) |
| Version | `l2-pilot-spec/2`, `2.0.0`, statut `FROZEN` (2026-10-22) |
| sha256 | `8ac57f2c601e04d113bf054a07aa1518ecaf3d12ce592db2c7442b050bca9c2c` (fichier voisin `.sha256`) |
| Fenêtre | départ ≥ `2026-11-02T00:00:00Z`, fin dure `2026-11-03T00:00:00Z` ; durée = min(24 h ; fin dure − départ) |
| Séparation | bloc `pilot_separation` du spec Gate 2 de G5 (≥ `0.1.8`), sha256 canonique `4b96e2bd3987e123b597b6534a9c464580d560db399401849cf992af004d489e`, recopié dans `separation.g5_gate2_block` |
| Spec v1 | `l2_pilot_spec_v1.json`, `l2-pilot-spec/1`, sha256 `16b1a6941f539c0a5a918d1a811ebbf6c291aedfe784f536a29ad675d52515a3` : gardé tel quel, refusé pour un run (`spec_superseded` : ni fin dure ni contrôle de séparation) |
| Hôte | `wss://wseea.okx.com/ws/v5/public` (sans port : le port 8443 ferme le 2026-10-31, changelog « Upcoming Changes ») |
| Instruments | REST `https://eea.okx.com/api/v5/public/instruments?instType=FUTURES`, relevé **au lancement** |
| Sélection | `instType = FUTURES`, `ruleType = xperp`, `state = live`, `uly` ∈ {`BTC-USD`, `ETH-USD`}, id `<BASE>-USD_UM_XPERP-<AAMMJJ>` ; **exactement un** par sous-jacent, sinon refus |
| Canaux | `books`, `books5`, `trades` |

Le spec fige aussi les plafonds (§4), les jetons de chemins protégés (§4) et les critères de qualité (§6). Le changer
demande une nouvelle version et un nouveau sha256.

## 3. Le module

Paquet séparé : `python-orchestrator/scripts/research_l2/`. Il n'est pas dans `scripts/research_lp/` : le digest de code
de G5 (`research_lp.cli.code_digest()`, recopié au gel de G5 dans `freeze_fill.code_digest_sha256`) hache chaque `*.py` de
ce paquet. Un module ajouté là changerait le digest de G5. Le pilote importe seulement des constantes et des aides de
`research_lp` (plancher de quarantaine, hôtes EEE, journal de santé). Il ne modifie rien du collecteur LP.

| Module | Rôle |
|---|---|
| `book.py` | en-tête de trame (identifiants, `seqId`, `prevSeqId`, horodatage, nombres de niveaux ; jamais un prix), suivi de séquence, carnet `books`, somme de contrôle CRC32 |
| `pilot.py` | spec gelé et vérifié, gardes, sélection, écriture des segments, manifeste, boucle WebSocket |
| `replay.py` | `verify`, résumé qualité sur les en-têtes seuls, relecture du carnet dans l'ordre de disponibilité |
| `cli.py` | `spec-hash`, `plan`, `capture`, `verify`, `quality`, `replay-check` |

**Séparation d'avec la capture G5.** Processus séparé, dossier séparé, verrou séparé (`.l2-pilot.lock`, pas
`.lp-capture.lock`), connexion séparée. Le comportement par défaut de `xperp_capture` ne change pas (test
`test_lp_collector_defaults_and_digest_scope_unchanged`).

**Ce qui est écrit**, dans `<out>/` :

| Fichier | Contenu |
|---|---|
| `segments/seg-NNNNNN.jsonl.gz` | une ligne JSON par trame reçue : `rx` (ordre de réception), `recv_ns` (horloge murale locale, ns), `mono_ns`, `conn`, `h` (en-tête sans prix), `raw` (la trame **telle que reçue**) ; plus des lignes `meta` (connexion, souscription envoyée, resouscription, déconnexion, arrêt) |
| `manifest.json` | schéma `l2-pilot-manifest/1` : spec et sha256, digest du code, autorisation, bornes (début, fin au plus tard, fin réelle), plafonds, segments (sha256, octets, enregistrements, premier et dernier `rx`), octets écrits, espace libre vu, raison d'arrêt, trous, resouscriptions, connexions, erreurs |
| `instruments-futures-raw.json` | la réponse REST des instruments au lancement (métadonnées), hachée |
| `instruments-selected.json` | les deux lignes retenues et leurs unités (`ctVal`, `ctMult`, `ctValCcy`, `tickSz`, `lotSz`, `minSz`…) |
| `pilot-spec.json` | copie du spec gelé |
| `health.log` | journal de santé |

Rotation : toutes les heures, ou à 256 Mio compressés. Chaque segment fermé est synchronisé sur disque puis haché. Un
`flush` de synchronisation a lieu toutes les 5 s : après un arrêt brutal, le segment ouvert reste lisible jusque-là.
Aucune réduction au L1 : la trame brute porte tous les niveaux, `seqId`, `prevSeqId`, `checksum` et `ts`, tels que livrés.

## 4. Gardes

| Garde | Règle | Code de sortie |
|---|---|---|
| Date | refus avant `2026-11-02T00:00:00Z` ; un spec dont le plancher est sous `QUARANTINE_FLOOR` est refusé | 2 |
| Fin dure (P-L2-1) | refus à partir de `2026-11-03T00:00:00Z` (`after_hard_end`) ; un départ plus tardif raccourcit le run, l'arrêt a lieu à la fin dure (`hard_end`) ; jamais de prolongation | 2 au départ ; arrêt `hard_end` |
| Séparation (P-L2-1) | `--separation-spec` : spec Gate 2 de G5 d'identifiant `LP-G5-GATE2-2026`, version ≥ `0.1.8`, `requires` contenant `pilot_separation_floor`, bloc `pilot_separation` de sha256 canonique égal à celui du spec du pilote ; `--separation-ref` commençant par `P-L2-1` ; sinon `separation_unresolved` (rien n'est écrit, aucun appel réseau) ; même contrôle pour `plan` et `replay-check` | 4 |
| Spec | sha256 = fichier voisin = `--expect-spec-sha256` ; statut `FROZEN` ; clés connues | 4 |
| Code | `--expect-code-digest` = digest du paquet `research_l2` | 4 |
| Autorisation | `--authorization-ref` non vide, recopié au manifeste | 4 |
| Durée | ≤ min(24 h ; fin dure − départ) ; horloge monotone **et** horloge murale (une mise en veille compte) | arrêt `max_duration` (ou `hard_end`) |
| Octets écrits | arrêt quand le total atteint 5 Gio − 64 Mio de marge ; tout compte : segments, manifeste, journal, instantanés | arrêt `max_bytes` |
| Espace libre | ≥ 10 Gio avant le départ (sinon refus) ; contrôlé toutes les 30 s | 5 au départ ; arrêt `min_free_space` |
| Reconnexions | au plus 48 dans le run | arrêt `max_reconnects` (code 6) |
| Signaux | `SIGINT`, `SIGTERM` : arrêt propre, raison écrite | arrêt `SIGINT` / `SIGTERM` |
| Erreur locale | une erreur d'écriture n'est jamais retentée comme une erreur réseau | arrêt `fatal_error` (code 6) |
| Fichier `STOP` | `touch <out>/STOP` | arrêt `stop_file` |
| Dossier de sortie | doit être neuf ou vide ; jamais de reprise ni d'extension | 4 |
| Chemins protégés | refus si un composant du chemin résolu (liens suivis) contient `forward-okx`, `fwd-okx`, `g5`, `scalp-q3`, `scalping-diagnostic`, `sealed` ou `okx-xperp-capture` en tête ou après `-`, `_`, `.` ; les lecteurs appliquent la même règle | 4 |
| Verrou | `flock` sur `<out>/.l2-pilot.lock` | 3 |

Les plafonds viennent du spec. La ligne de commande peut seulement les durcir (durée ou octets plus bas, espace libre plus
haut). Une valeur plus lâche est refusée.

Un arrêt est définitif. Un second run demande une nouvelle commande et un nouveau dossier. Aucun redémarrage automatique.
Les reconnexions WebSocket à l'intérieur du run sont permises ; elles ne prolongent pas la durée, qui court depuis le départ.

## 5. Relecture hors ligne

Ordre de disponibilité = ordre de réception du processus (`rx`, qui est aussi l'ordre d'écriture). La relecture est une
seule passe : une observation ne dépend que des enregistrements qui la précèdent. Une horloge murale qui recule est
comptée (`recv_clock_backwards`), jamais réordonnée.

Règles de séquence par instrument (`book.SeqTracker`) :

| Cas | Verdict | Effet |
|---|---|---|
| `action = snapshot` | snapshot | carnet remplacé, **VALIDE** |
| `prevSeqId` = dernier `seqId`, `seqId` > `prevSeqId` | continu | appliqué |
| `prevSeqId` = `seqId` = dernier `seqId` (asks et bids vides) | battement | appliqué (rien) |
| `prevSeqId` = dernier `seqId`, `seqId` < `prevSeqId` | remise à zéro documentée | appliqué |
| paire (`prevSeqId`, `seqId`) déjà appliquée depuis l'instantané | doublon | ignoré, compté, validité inchangée |
| `seqId` < dernier `seqId`, ni doublon ni remise à zéro | hors d'ordre | ignoré, compté, **INVALIDE** |
| tout autre écart | trou | **INVALIDE** |
| `seqId` ou `prevSeqId` absent | malformé | **INVALIDE** |
| carnet INVALIDE et message non instantané | `update_while_invalid` | ignoré jusqu'au prochain instantané |

Le carnet devient aussi INVALIDE sur une connexion, une déconnexion, une resouscription, l'accusé d'un désabonnement et un
échec de somme de contrôle. Pas de tampon de réordonnancement : on n'attend jamais un message futur. Aucune vue du carnet
n'est exposée pendant une période INVALIDE.

Pendant la capture, un trou déclenche une resouscription de `books` pour l'instrument (désabonnement puis abonnement : OKX
renvoie un instantané complet), au plus une toutes les 5 s par instrument et 120 par heure.

**Somme de contrôle.** L'algorithme officiel est codé (`book.okx_checksum` : CRC32 signé des 25 premiers niveaux, prix et
taille tels que livrés). Il n'est encore écrit dans la docs-v5 que dans la section Spread Trading (`sprd-books-l2-tbt`,
globale l.38728-38742). L'ordre alterné bid, ask, bid, ask vient du texte OKX antérieur : il n'est plus relisible dans la
copie actuelle. Comme `books` livre 0 depuis le 2026-06-23, la vérification ne s'applique qu'à une valeur non nulle ; les
valeurs nulles sont comptées (`checksum_zero_or_absent`).

## 6. Commande exacte (non exécutée)

À lancer à la main, une fois, dans la fenêtre réservée (au plus tôt le 2026-11-02 00:00 UTC, avant le 2026-11-03 00:00 UTC ; un départ
tardif raccourcit le run), depuis le commit relu de cette branche :

```bash
cd /home/haythem/workspace/perso/tradingV3/.worktrees/l2-capture-pilot/python-orchestrator
PY=/home/haythem/workspace/perso/tradingV3-private/research/20261013T100000Z-forward-okx-runtime/bin/python
G5SPEC=/home/haythem/workspace/perso/tradingV3/.worktrees/g5-gate2-prefreeze/python-orchestrator/scripts/research_lp/specs/g5_gate2_spec.json
AUTH="owner 2026-10-22: public-data L2 capture pilot, BTC and ETH X-Perps, max 24 h, <= 5 GiB, >= 10 GiB free"
SEP="P-L2-1 (D14, owner 2026-10-22): reserved window 2026-11-02T00:00Z -> 2026-11-03T00:00Z, excluded until 2026-11-03T12:00Z"

# 1. vérification sans réseau ni écriture (mêmes gardes et même code de sortie que capture)
$PY -m scripts.research_l2.cli plan \
  --out /home/haythem/workspace/perso/tradingV3-private/research/l2-capture-pilot/r1 \
  --authorization-ref "$AUTH" --separation-spec "$G5SPEC" --separation-ref "$SEP"

# 2. le pilote (premier plan ; Ctrl-C ou touch <out>/STOP pour l'arrêter proprement ; arrêt automatique à la fin dure)
$PY -m scripts.research_l2.cli capture \
  --out /home/haythem/workspace/perso/tradingV3-private/research/l2-capture-pilot/r1 \
  --spec scripts/research_l2/specs/l2_pilot_spec_v2.json \
  --expect-spec-sha256 8ac57f2c601e04d113bf054a07aa1518ecaf3d12ce592db2c7442b050bca9c2c \
  --expect-code-digest a7bdb560beace7261b8a1ce7a92dd155567365ecd2757aa910794a1d4e241b4e \
  --authorization-ref "$AUTH" --separation-spec "$G5SPEC" --separation-ref "$SEP"
```

`$PY` est l'interpréteur Python 3.12 du programme LP (`websockets`, `numpy`) ; seul le binaire est utilisé, aucun fichier du
dossier `forward-okx-runtime` n'est lu. `$G5SPEC` est lu pour quatre champs seulement (`spec_id`, `spec_version`, `requires`,
`pilot_separation`) ; toute version ultérieure du spec Gate 2 qui garde le même bloc convient (le bloc est vérifié par sha256, la
version est un plancher). Le digest de code vaut pour le commit de ce document ; toute correction du paquet change le digest et la
commande doit le recopier.

**Avant de lancer (vérifié à la main, hors code) :**

| # | Prérequis | Source |
|---|---|---|
| 1 | date dans la fenêtre réservée [2026-11-02 00:00 UTC ; 2026-11-03 00:00 UTC) (aussi gardé par le code) | autorisation, P-L2-1 |
| 2 | verdict `fwd-okx-r1` rendu (« rien ci-dessous avant le verdict fwd-okx-r1 ») ; le pilote ne le lit pas | `LP-PROGRAMME-GATES.md` §8 |
| 3 | lignes `okx-xperp-l2-pilot-r1` et `okx-xperp-capture-excluded-2026-11-02T00..2026-11-03T12` présentes au registre LP (écrites le 2026-10-22) | §7 |
| 4 | décision P-L2-1 enregistrée (D14 ; registre §6) et bloc présent dans `$G5SPEC` (vérifié par le code) | §7 |
| 5 | aucune capture de comptage P3 en cours (P3 commence au plus tôt le 2026-11-03T12:00Z ; `research_lp count` refuse avant) | `LP-PROGRAMME-GATES.md` §7.4 |
| 6 | ≥ 16 Gio libres conseillés (10 Gio gardés + 5 Gio écrits ; sinon arrêt anticipé `min_free_space`) | autorisation |
| 7 | commit relu ; digest de code et sha256 du spec recopiés | §4 |

## 7. Registre et protection (écrits avant toute collecte)

**Ligne ajoutée au registre LP** (`tradingV3-private/research/20261020T1430Z-lp-programme/REGISTRY.md`, ajout en fin de
fichier, 2026-10-22) :

| Identifiant | Statut | Usage permis |
|---|---|---|
| `okx-xperp-l2-pilot-r1` | `prospective` | développement et exploration de la faisabilité de TP/SL adaptés au carnet ; **jamais une confirmation** |

**Pourquoi une séparation.** Le jour du pilote tombait dans les fenêtres prospectives du programme ou dans leurs lectures préalables :
Gate 2 de G5 (T0 « atteignable » au 2026-11-03 dans le brouillon, classifieur sur les 480 minutes avant chaque décision, descriptifs lus
dès le 2026-11-02), G2 (comptages P3 de sélection), Gate 2 de G4 (historique de 7 jours) et P3 lui-même (même hôte, même IP, même départ).
L'univers de ces protocoles garde BTC et ETH, et le carnet `books` contient l'information de `books5` aux mêmes instants. Tableau complet :
`LP-PROGRAMME-GATES.md` §7.4.

**Décision P-L2-1 (D14, 2026-10-22).** Fenêtre réservée [2026-11-02T00:00Z ; 2026-11-03T00:00Z), marge 12 h, intervalle exclu
[2026-11-02T00:00Z ; 2026-11-03T12:00Z) : aucune observation de cet intervalle n'entre dans un calcul décisif, ses empreintes, ses lectures
préalables, rodages, calibrations, règles de qualité ou sélections. Codé dans `research_lp` (chargeur et lecteur du Gate 2 de G5, T0 ≥
2026-11-04, `g5_index_fetch`, `simulate` / `events` / `count`) et vérifié par le pilote (bloc du spec Gate 2 de G5, sha256).

**Règle de lecture.**

1. Tant que la séparation n'est pas enregistrée (D14, registre §6, bloc dans le spec Gate 2 de G5), seuls sont permis les résumés de
   **qualité et de volume** : `verify` et `quality` (en-têtes seuls, aucun prix).
2. Ensuite, le **contenu** (prix, carnets, trades) peut être ouvert pour le **développement** seulement : `replay-check` exige
   `--separation-spec` (le bloc vérifié) et `--unsealed-ref` commençant par `P-L2-1`. Jamais une confirmation, jamais la source d'un seuil,
   d'une fenêtre, d'un univers ou du plafond du collecteur d'un test décisif.
3. Tout protocole gelé après l'ouverture du contenu la déclare parmi ses expositions antérieures.
4. Au jour de la lecture du contenu, la ligne passe à `consumed_equivalent` (règle du registre).

**Séparer l'exploration des confirmations.**

- Une fenêtre d'**exploration** TP/SL (développement) ne recouvre aucune fenêtre de G2, G4 ou G5, lectures préalables
  comprises, ni une fenêtre `reserved` ou `quarantine`. Elle est inscrite au registre **avant** sa capture.
- Toute **confirmation** d'une règle TP/SL se fait sur une fenêtre **postérieure**, disjointe de toute exploration, gelée
  par un pré-enregistrement (sha256) avant sa capture, et lue une seule fois.
- Les données du pilote, une fois lues, ne confirment jamais une règle TP/SL : elles ne servent qu'à la développer.

## 8. Contrôle qualité après la capture

Commandes (volumes seulement, aucun prix) :

```bash
$PY -m scripts.research_l2.cli verify  --run <out>
$PY -m scripts.research_l2.cli quality --run <out> --out-json <out>/quality.json
```

Critères, gelés dans le spec (`quality_criteria`), par instrument où `books` a été servi :

| Critère | Seuil |
|---|---|
| Hachages : manifeste, segments, spec, instantané d'instruments | tous vérifiés |
| Part du temps où le carnet `books` est VALIDE | ≥ 95 % |
| Minutes avec au moins un message `books` | ≥ 99 % |
| Ruptures de séquence (trous, hors d'ordre, malformés) | ≤ 24 en 24 h |
| Délai d'un nouvel instantané après une rupture (p95) | ≤ 10 s |
| Messages hors d'ordre | 0 |
| Échecs de somme de contrôle (si une valeur non nulle est livrée) | 0 |
| Reconnexions | ≤ 24 |

Verdict :

| Verdict | Condition |
|---|---|
| `PASS` | tous les critères tenus sur les deux instruments, arrêt à 24 h |
| `PASS_WITH_RESERVATIONS` | critères tenus sur ce qui a été capturé, mais arrêt avant 24 h (plafond, signal), ou `books5` seul pour un instrument, ou sommes de contrôle non nulles à vérifier par `replay-check` |
| `FAIL` | un critère manqué ou un hachage faux |

Le résumé donne aussi : octets par segment, messages et lignes par canal et instrument, nombre maximal de niveaux vus,
instantanés, verdicts de séquence, resouscriptions, erreurs, couverture et part VALIDE par heure UTC. Un `FAIL` ne relance
rien : un second run est une nouvelle décision du propriétaire.

## 9. Hypothèses TP/SL que ces données permettront de tester plus tard

En développement seulement, après la levée du scellé :

| # | Hypothèse | Mesure possible |
|---|---|---|
| H1 | TP posé juste devant un « mur » visible à l'entrée | distance au premier niveau de grande taille dans les 400 niveaux ; touché ou traversé par les trades |
| H2 | SL posé derrière un mur visible | fréquence de traversée des niveaux de grande taille contre des niveaux ordinaires |
| H3 | Durée de vie d'un mur | taille à un prix suivie à chaque mise à jour ; part consommée par des trades contre part retirée |
| H4 | Mur retiré avant d'être touché | fréquence et délai, à 100 ms près |
| H5 | Déséquilibre de profondeur sur N niveaux comme sortie anticipée ou maintien du TP | déséquilibre L2 à la mise à jour, aligné sur les trades |
| H6 | Glissement d'un SL exécuté au marché | prix moyen en parcourant le carnet visible pour une taille donnée (100 USD, 500 USDT) |
| H7 | Taille visible devant un TP passif | taille au prix à l'arrivée, puis épuisement par les trades (borne, pas notre rang) |
| H8 | Ce que perd `books5` contre `books` | part des murs au-delà du 5ᵉ niveau |

## 10. Ce que ces données ne permettent pas

| Limite | Pourquoi |
|---|---|
| Position réelle de notre ordre dans la file | le carnet public agrège un niveau ; ni identité ni ordre d'arrivée |
| Liquidité cachée, icebergs, profondeur RPI | non publiés dans `books` (RPI exclu, doc EEE l.15349) |
| Murs au-delà des 400 niveaux (5 en repli `books5`) | hors de la profondeur capturée |
| Changements à l'intérieur de 100 ms | A → B → A non envoyé (page trick) |
| Exécution réelle sur X-Perps | aucun ordre : pas de remplissage, de frais au rôle, de rejet, de déclenchement d'algo sur le prix mark, ni de fenêtre annuler-replacer |
| Latence de TradingV3 | la plateforme lit le carnet en REST, boucle à 60 s, recalcule le TP/SL toutes les 3 min (inventaire §2.4) |
| Impact de nos propres ordres | non observable sans ordre |
| Conclusion statistique | 24 h, deux instruments, un seul régime : puissance faible, développement seulement |

## 11. Tests et vérifications (2026-10-22)

| Vérification | Résultat |
|---|---|
| `tests/test_research_lp_l2_pilot.py` (fixtures synthétiques, garde réseau) | 48 tests, tous verts ; **65** depuis P-L2-1 (spec v2) |
| Suite `tests/test_research_lp_*.py` (dont les 132 tests existants) | 180 tests, tous verts ; **197** depuis P-L2-1 |
| Syntaxe Python 3.11 du paquet et du test (`ast.parse`, `feature_version=(3, 11)`) | 6 fichiers, sans erreur |
| `mkdocs build --strict -d /tmp/mk-l2` | sans avertissement |
| Digest du paquet `research_l2` | `6a0e06952b1646f5d1e901a9384c53e234dea20ff6946ed8acb9fc6c639c9068` (spec v1) ; **`a7bdb560beace7261b8a1ce7a92dd155567365ecd2757aa910794a1d4e241b4e`** depuis P-L2-1 (spec v2) |

Les tests couvrent : cinq niveaux gardés par le chemin `books5` existant ; 400 niveaux et trames brutes gardés ;
reconstruction instantané + incréments ; trou, hors d'ordre, malformé, reconnexion, désabonnement, somme de contrôle
fausse ⇒ INVALIDE jusqu'au prochain instantané ; doublons ; battement et remise à zéro ; aucune information future
(invariance par préfixe) ; plafonds de durée (horloge monotone et murale), d'octets et d'espace libre (horloge, compteurs
et espace simulés) ; refus avant le 2026-11-02 ; refus des chemins protégés, liens compris ; dossier non vide et verrou ;
plafonds seulement durcis ; hachage du spec ; intégrité du manifeste et des segments ; résumé qualité sans prix et
critères ; boucle WebSocket sur connecteur factice ; erreur d'écriture locale ⇒ arrêt `fatal_error` sans reconnexion ;
collecteur LP inchangé. Depuis P-L2-1 (spec v2) : v1 gardé et refusé pour un run ; refus à partir de la fin dure ; refus tant que la
séparation n'est pas enregistrée (dix cas : spec absent, illisible, autre identifiant, version 0.1.7, capacité absente, bloc absent ou
différent, référence absente ou autre) ; départ tardif tronqué à la fin dure (18 h, 30 s), départ à l'heure : 24 h ; `replay-check` refusé
sans la séparation ; vérification manuelle du spec réel `g5_gate2_spec.json` `0.1.8-draft` de la branche `claude/g5-gate2-prefreeze` :
bloc reconnu (`verify_separation`).

## 12. Ce qui n'est pas fait

- Aucun lancement, aucune planification, aucun appel réseau à OKX.
- L'accès des X-Perps au canal `books` n'est pas prouvé avant le lancement (accusé de souscription).
- Les volumes réels (messages par seconde, octets par heure) ne sont pas estimés : ils seront mesurés par le pilote. Le
  plafond de 5 Gio tient quel que soit le débit.
- La décision P-L2-1 est prise et enregistrée (D14) ; son application dans G5, G2, G4 et P3 vit sur la branche
  `claude/g5-gate2-prefreeze` (non fusionnée) : le pilote doit lire un spec Gate 2 de G5 qui porte le bloc (`--separation-spec`).
