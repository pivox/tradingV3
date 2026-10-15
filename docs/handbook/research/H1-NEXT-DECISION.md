# H1 — état au 2026-10-15 et prochaine décision

**H1 est clos.** Le Gate 2 historique a été exécuté une fois et a donné `GATE2_FAIL` (PR #508, `H1-GATE2-RESULT.md`).
La règle d'arrêt du protocole s'applique :

- `PREREGISTRATION-H1.md` §17 indique « Gate 2 | Closed », avec « No set is re-read; no parameter is changed after a
  read » ;
- §15 dit : « If Gate 2 fails, H1 is closed » ;
- A3 §4 confirme : « A fail closes H1 (§17) ».

Le propriétaire l'a confirmé le 2026-10-15 à 19:05:53Z : « conserver H1 et H2 clôturées, puis examiner les éventuelles
propositions H3 avant d'autoriser une nouvelle campagne ».

Ce document ne lance rien. Il ne modifie aucun document gelé : v1 `10d4f0c3…`, A1 `5c04b1c6…`, A2 `b77d6fed…`,
A3 `41a8b614…`.

## 0. À lire en premier : un écart avec la mission reçue

La mission du 2026-10-15 (19:00:27Z) partait de l'idée que H1 en était encore à `GATE1_PASS` et attendait une
décision d'exécutabilité. Ce n'est plus le cas :

1. **Le feu vert du propriétaire au Gate 2 historique** (« H1 Gate 2 historique »), donné le 2026-10-15, est relayé
   dans l'en-tête d'A3.
2. **A3 a été gelé avant toute lecture**, à 09:03:56Z ; la claim a suivi à 09:04:19Z.
3. **Le Gate 2 a échoué.** La PR #508 a été fusionnée à 09:35:39Z.

La question « le Gate 2 historique mérite-t-il d'être préparé ? » n'a donc plus d'objet : il a déjà tourné.

**Un point de procédure à noter.** `H1-XPERP-DECISION.md` §1.6, condition 4, demandait que la vérification du compte
(qui comprend, selon son §7, une clé API en lecture seule) passe avant le Gate 2. Or le Gate 2 a été lancé sur le feu
vert explicite du propriétaire, après la seule vérification d'interface (#507, fusionnée à 08:52:36Z). Le volet API de
la condition 4 n'était pas fait. `H1-XPERP-ACCOUNT-CHECK.md` écrit encore que « The historical Gate 2 and amendment A3
are not authorised » : cette phrase a été dépassée par A3.

## 1. Statuts

| Couche | Statut | Source |
|---|---|---|
| Science, crible S_A (OKX) | `S_A_SCREEN_PASS` : 83 événements, S14 −11,4 %, instable dans le temps (positif en 2023–24) | `H1-SA-SCREEN-RESULT.md` |
| Science, Gate 1 décisif S_B (Binance) | **`GATE1_PASS` conservé** : 238 événements, S14 −16,1 %, IC 90 % [−20,9 ; −11,6] %, négatif dans 6 semestres sur 6 | `H1-SB-GATE1-RESULT.md` |
| Exploitabilité, Gate 2 historique (S_A ∪ S_B) | **`GATE2_FAIL`** | `H1-GATE2-RESULT.md` ; sortie `tradingV3-private/research/20261014T210000Z-h1-listing/gate2/gate2.json` (sha256 `1a00a39d…`) |
| Protocole | **H1 CLOS** (§15, §17 ; A3 §4) | `PREREGISTRATION-H1.md`, `AMENDMENT-H1-A3.md` |
| Exécutabilité sur X-Perps | `H1_XPERP_CONDITIONAL` (sans objet depuis le GATE2_FAIL) ; compte vérifié au niveau de l'interface seulement, API non vérifiée | `H1-XPERP-DECISION.md`, `H1-XPERP-ACCOUNT-CHECK.md` |
| Test prospectif S_C (§16) | **Jamais démarré**, et il ne le sera pas : il exigeait un Gate 2 réussi | `H1-GATE2-RESULT.md`, Conséquences 1 |

**Pourquoi le Gate 2 échoue.** Critères du §15, profil de coûts de base sauf mention :

- critère 5 : profit factor 1,10 < 1,2 ;
- critère 9 : pas assez de semestres positifs ;
- critère 10 : −0,023 R par trade une fois retirés les 5 % meilleurs trades ;
- stress pessimiste (glissement du stop × 2) : −0,007 R.

Le verdict ne dépend pas du seul critère 9.

**Ce qu'il reste en moyenne.** +0,027 R par trade, sur 70 trades exécutés pour 321 événements. Le contrôle fait
−0,094 R, soit un surplus de +0,121 R (p 0,005). Les 251 autres événements ont été écartés :

- 205 sous le minimum de 5 USDT de Binance ;
- 22 sous la taille minimale de contrat ;
- 24 parce que le budget de risque était plein.

**Le diagnostic OKX-first ne compte pas.** A3 D1 donne +0,092 R sur 52 trades (PF 1,43). Il n'est pas décisif : S_A
est un ensemble déjà lu, il reste sous le minimum de 60 trades, et `H1-GATE2-RESULT.md` précise qu'il « cannot rescue
H1 ».

## 2. Réponses aux dix questions de faisabilité du propriétaire (Phase D)

Les questions sont celles de la mission du 2026-10-15. Les réponses s'appuient uniquement sur les documents cités.
Aucune donnée nouvelle n'a été lue ; aucun ordre n'a été passé ; aucun secret n'a été demandé.

**1. Les nouveaux tokens obtiennent-ils réellement un X-Perp sur OKX Europe ?**

Oui, une partie. L'instantané du 2026-10-15T05:35Z compte 30 nouveaux tokens parmi 160 X-Perps crypto
(`H1-XPERP-LISTING-INVENTORY.md`).

**2. Ces instruments sont-ils disponibles à t0 + 24 h ?**

Pour 9 d'entre eux : GRAM, O, NES, AEON, GRVT, KII, XDP, CT et QUANT, listés entre le 2026-06-17 et le 2026-10-01.
Tous sont des cotations OKX-first. Si l'on accepte un X-Perp ouvert à T + 48 h, ce qui sort de la définition, on
arrive à 13.

`H1-EXECUTABILITY-REVIEW.md` §2 donnait environ 4 événements par mois, avec une autre définition (ouverture dans les
2 jours, liste qui inclut VVV). VVV ne figure pas parmi les 30 nouveaux tokens de l'inventaire ; l'écart n'est pas
expliqué.

**3. Le short est-il accessible à un compte français éligible ?**

Au niveau de l'interface, oui. Le short est autorisé, ainsi que la marge isolée, le levier 1×, les ordres TP/SL et
reduce-only. La marge est en USDC : l'USDT n'est pas accepté comme collatéral (`H1-XPERP-ACCOUNT-CHECK.md`,
`H1-XPERP-MARKET-ACCESS.md` §2.4). La protection contre le solde négatif s'applique aux clients de détail
(`H1-XPERP-MARKET-ACCESS.md` §2.2).

Au niveau réglementaire, l'entité OKX Europe Markets Ltd est vérifiée : entreprise d'investissement MiFID agréée par la
MFSA. En revanche, l'entrée « passeport France » n'a pas été vérifiée nommément (`H1-XPERP-MARKET-ACCESS.md` §1).

**4. Le trading API est-il confirmé ?**

**Non, il reste à vérifier.** La disponibilité publique de l'API ne confirme pas les permissions du compte. Le
conteneur PHP ne détient que des clés démo OKX EEA. Le script `tradingV3-private/tools/okx_xperp_readonly_check.py`
fait deux GET en lecture seule ; il n'y a **aucune preuve qu'il ait été exécuté**.

**5. Les tailles minimales sont-elles compatibles avec 500 USDT ?**

Tout juste. Aux prix du jour, 7 des 9 X-Perps acceptent la taille autorisée par un stop à 60 % (environ 4,1 USD de
notionnel). O et KII seraient écartés (`H1-XPERP-500USDT-FEASIBILITY.md` §2–3).

**6. Le risque nominal de 0,5 % par trade peut-il être respecté ?**

Oui, en tant que plafond : 0,5 % de 500 USDT font 2,50 USD par position. Mais la granularité d'un contrat entier ne
permet parfois d'utiliser que 50 à 90 % de ce budget de risque (`H1-XPERP-500USDT-FEASIBILITY.md`).

**7. Combien d'événements H1 réellement négociables peut-on identifier ?**

**9 au plus** (question 2), soit environ 2,2 par mois sur la période 2026-06-17 → 2026-10-15 (1,7 par mois depuis le
2026-07-01). À 500 USDT, 7 de ces 9 sont exécutables aux prix du jour.

**8. Un test prospectif de douze mois produirait-il assez de trades ?**

Non. Les trois scénarios de `H1-XPERP-DECISION.md` §1.5 donnent environ 9, 17 et 22 trades exécutés. Le §16 en exige
au moins 30, faute de quoi le test est `INCONCLUSIVE`, et sa durée de 12 mois ne peut pas être prolongée. La question
ne se pose de toute façon plus, puisque H1 est clos.

**9. Peut-on modéliser crédiblement les stops, le funding et les frais ?**

En partie seulement.

- **Frais** : connus, 0,02 % / 0,05 % (`H1-XPERP-ACCOUNT-CHECK.md`).
- **Funding, structure** : connue par le point d'accès public `funding-rate` du 2026-10-15
  (`H1-XPERP-MARKET-ACCESS.md` §2.8) :
  - les X-Perps de nouveaux tokens versent toutes les 4 h, avec un plafond de ±1 % ;
  - QUANT verse toutes les 8 h, avec un plafond de ±1,5 % ;
  - BTC verse toutes les 8 h, avec un plafond de ±0,375 % ;
  - l'intervalle se raccourcit (2 h ou 1 h) quand le plafond est touché.
- **Funding, effet mesuré** : A3 D2 a appliqué la structure X-Perp (4 h, ±1 %, raccourcissement) aux événements
  historiques. Résultat : +0,028 R, PF 1,11. Le funding enregistré atteignait rarement le plafond, et cette structure
  seule ne changerait pas le verdict (`H1-GATE2-RESULT.md`).
- **Funding effectivement payé** : non vérifié.
- **Stop dans un gap** : non documenté (`EXECUTION_RISK_UNVERIFIED`, `H1-XPERP-500USDT-FEASIBILITY.md`). Deux limites
  de prix sont connues :
  - pendant les **10 premières minutes** après la cotation, une bande `initPxLmtPct` de 4 % sur les nouveaux tokens ;
  - ensuite, un plafond `maxPxLmtPct` de 30 % sur l'écart d'un ordre à l'indice (`H1-XPERP-MARKET-ACCESS.md` §2.9).

**10. Existe-t-il un obstacle technique ou contractuel bloquant ?**

- **Technique : oui, pour TradingV3.** Le code OKX ne gère que l'USDT-SWAP. `instType=SWAP` est codé en dur à environ
  54 endroits ; le résolveur lève une exception sur les ids `_UM_XPERP` ; les normaliseurs ignorent tout ce qui n'est
  pas SWAP ; l'USDT est supposé partout. L'effort est estimé de M à L (`H1-XPERP-TECHNICAL-GAP.md` §3). Rien n'est
  lancé pour H1 (`H1-GATE2-RESULT.md`, Conséquences 1).
- **Contractuel et permissions** : le passeport France n'est pas vérifié nommément, et l'API n'est pas vérifiée.
- **Scientifique : bloquant.** H1 est clos par son protocole. Aucune variante (stop plus serré, autre horizon, S_A
  seul, mise plus élevée) n'est recevable sur ces données (§17 ; `H1-GATE2-RESULT.md`, Conséquences 3).

**Ce qu'il reste de H1 :**

- un résultat scientifique : la dérive des nouveaux perpétuels (`GATE1_PASS` S_B) ;
- des outils réutilisables : l'audit des cotations et l'inventaire des X-Perps ;
- un constat de méthode : la queue de squeeze impose des stops larges. Un quart des trades sont stoppés, ce qui coûte
  l'essentiel de la dérive. Le minimum Binance de 5 USDT a écarté 205 des 321 événements. L'effet propre de la taille
  des positions (environ 4 USDT) est **NON PROUVÉ**.

## 3. Idées H3 (trois au plus ; aucune n'est lancée, aucune promesse de performance)

Chaque idée doit respecter les critères imposés par le propriétaire :

- un mécanisme économique distinct de Q3, Swing, H1, H2 et de l'étude de levier ;
- une exécution possible sur OKX Europe (X-Perps, ou spot si pertinent) depuis la France ;
- une compatibilité avec 500 USDT ;
- **aucune donnée déjà consommée utilisée comme validation indépendante.**

Les archives OKX Global déjà lues (swing v2 OKX, H2) et les données Binance lues par H1 peuvent servir au
développement, jamais à la validation. Pour chaque idée, la validation indépendante ne peut être qu'un **test
prospectif sur des données X-Perp postérieures à un gel**. Les fenêtres W0 à W2 de `fwd-okx-r1` ne sont jamais
réutilisées.

À notre connaissance, aucun résultat n'a été calculé sur les bougies ni sur l'historique de funding des X-Perps : ces
données n'ont servi qu'à des relevés de métadonnées et de spécifications. Ce constat vaut pour les documents lus et
n'est pas prouvé de façon exhaustive.

Chaque idée devra franchir les gates M1 à M5 (`RESEARCH-ELIGIBILITY-GATES.md`) et être pré-enregistrée avant toute
lecture. **Le nombre d'événements ou de rebalancements attendus par mois est INCONNU pour les trois idées.** C'est ce
qui a coulé H1 (question 8). Il doit être estimé à partir des métadonnées seulement, comme prérequis M2, avant que le
propriétaire ne choisisse.

### H3-A — Portage du funding : short X-Perp et long spot du même actif

**Hypothèse à tester (non établie).** Une demande structurelle de levier acheteur maintiendrait un funding positif sur
les perpétuels. Si c'est vrai, un short X-Perp couvert par un achat spot du même actif sur OKX Europe encaisse ce flux
sans parier sur la direction du prix. Ce serait une prime de portage, pas une prédiction de prix. Rien ne montre
aujourd'hui que ce funding positif existe sur les X-Perps.

**Différence avec ce qui a été testé.** Le rapport de paysage ne mentionne pas le portage neutre en delta : il est non
testé (absent du rapport). Le « funding contrarian (D4) » qui y figure est un détecteur de régime directionnel.

**Données nécessaires (lecture seule) :**

- l'historique public du funding des X-Perps, servi depuis le 2026-03-31 (`H1-XPERP-MARKET-ACCESS.md` §2.7) ;
- les règles de funding (formule, plafonds, intervalles) ;
- la liste des actifs qui ont à la fois un X-Perp et une paire spot EUR ou USDC sur OKX Europe (**non vérifiée**) ;
- les frais spot de l'entité MiCA (non vérifiés).

**Compatibilité 500 USDT.** Deux jambes entièrement collatéralisées à 1× laissent environ 250 USD de notionnel par
jambe. Les minimums d'ordre X-Perp sont d'au plus 19,6 USD (`TRADABLE-INSTRUMENT-UNIVERSE.md` §3) ; les minimums spot ne
sont pas vérifiés.

**Risques principaux :**

- le funding de base (intérêt de 0,01 % par 8 h, plus la prime) peut être inférieur aux frais des quatre exécutions ;
- quand le funding est négatif, le short paie au lieu d'encaisser, et le spot ne se vend pas à découvert ;
- la jambe courte peut être liquidée vers +100 % à 1× isolé ;
- TradingV3 n'a ni chemin X-Perp ni chemin spot câblé ;
- l'historique X-Perp est court : environ 6 mois pour BTC, ETH et SOL ;
- l'accès spot (prestataire MiCA) demande sa propre vérification, distincte de celle des X-Perps.

### H3-B — Déblocages programmés de tokens

**Mécanisme proposé.** Un déblocage important et annoncé à l'avance (cliff de vesting) augmente l'offre en
circulation. Il pourrait provoquer des ventes ou des couvertures anticipées autour de la date. Le choc d'offre est
exogène et daté : ce n'est ni un signal de prix, ni un événement de cotation.

**Différence avec ce qui a été testé.** Le rapport de paysage note seulement qu'aucun événement de calendrier n'a servi
de déclencheur : c'est non testé (absent du rapport). H1 ne portait que sur les cotations.

**Données nécessaires :**

- un calendrier de déblocages daté a priori. Sa source et sa licence sont **inconnues** aujourd'hui (porte M3) ;
- les bougies OKX, pour le développement uniquement (déjà consommées au niveau des bougies) ;
- les X-Perps, en prospectif.

**Garde-fou contre la proximité avec H1.** Exclure les tokens récemment cotés. La durée de cette exclusion doit être
justifiée indépendamment de H1, et déclarée. Reprendre la fenêtre t0 + 31 j de S_A reviendrait à emprunter un
paramètre de H1 ; si elle est reprise, l'emprunt doit être déclaré comme tel.

**Compatibilité 500 USDT.** Les tokens concernés sont en général plus anciens que les nouvelles cotations, donc des
stops plus serrés sont envisageables. À un stop de 15 %, 159 X-Perps crypto sur 160 sont exécutables
(`TRADABLE-INSTRUMENT-UNIVERSE.md` §3). La couverture X-Perp des tokens concernés est inconnue.

**Risques principaux :**

- trop peu d'événements sur les X-Perps (porte M2 inconnue) ;
- un effet peut-être déjà anticipé par le marché ;
- une proximité de thème avec H1 (l'offre de tokens), qui exige une pré-inscription stricte.

### H3-C — Momentum en coupe transversale, long/short, à quelques semaines (classé en dernier)

**Mécanisme proposé.** La sous-réaction ou le comportement moutonnier ferait persister les classements relatifs sur
plusieurs semaines. On achète les meilleurs, on vend les pires, avec une exposition au marché proche de zéro.

**Déclaration obligatoire : idée influencée par les données de H2.** Le côté long de H2 a montré une continuation
(−177,3 pb, IC entièrement sous 0, lecture du 2026-10-15). Cela ressemble à la jambe courte d'un momentum. H3-C
présente donc un risque de récupération par inversion de signe. Le pré-enregistrement devrait :

- déclarer cette influence explicitement, sans la cacher ;
- ne compter aucune sortie de H2 comme preuve (`H2-CLOSURE-DECISION.md` §4) ;
- ne reprendre aucun paramètre de l'archive OKX au-delà du développement exploratoire.

**Différence avec les familles rejetées (porte M4).** Les familles voisines déjà rejetées ou explorées sont :

- les breakouts classés 4h de swing v2 (breakouts longs à 20 jours, détention 14 jours), sans candidat ;
- swing v2 OKX, rejeté sur la même archive OKX (#500) ;
- le momentum à 4 et 12 semaines comme détecteur de régime (regime-r2, D2) ;
- les « breakouts and trend stacks at 4h » du rapport de paysage, jugés assez explorés.

H3-C s'en distinguerait par un classement relatif neutre au marché, en coupe transversale. Ce n'est ni un breakout
d'une série isolée, ni un filtre de régime. Le rapport de paysage le classe « out of scope », donc non testé.

**Données nécessaires :**

- l'archive OKX, en développement seulement (déjà consommée par swing v2 OKX et par H2) ;
- les 29 X-Perps crypto à plus de 1 M USD par jour, en prospectif.

**Compatibilité 500 USDT.** Environ 3 positions longues et 3 courtes, d'environ 80 USDT chacune à 1×, au-dessus des
minimums d'ordre. Il faut un modèle de risque de portefeuille déclaré à l'avance, plutôt qu'un dimensionnement par
stop.

**Risques principaux :**

- l'exposition au régime, première cause d'échec selon le rapport de paysage ;
- les krachs du momentum ;
- une coupe transversale étroite (29 instruments) ;
- la contamination par H2 décrite ci-dessus.

## 4. Une seule action prioritaire (non lancée)

**Le propriétaire exécute lui-même `tradingV3-private/tools/okx_xperp_readonly_check.py`.**

- Le script fait deux GET en lecture seule : `/api/v5/account/config` et
  `/api/v5/account/instruments?instType=FUTURES`.
- Il ne passe aucun ordre et ne partage aucune clé.
- Le propriétaire rapporte seulement trois choses : le mode de position, la présence des X-Perps et les limites par
  instrument.

**Pourquoi cette action.** Le volet API de la porte M1 est le blocage commun à toute campagne future sur X-Perps. On
peut le lever sans lire aucune donnée de marché, donc sans rien consommer avant un pré-enregistrement.

**Sa limite.** Elle ne couvre que l'accès aux X-Perps (futures). Si le propriétaire retient H3-A, l'accès au spot
d'OKX Europe (prestataire MiCA) demandera une vérification séparée.

**Ensuite.** Le choix entre H3-A, H3-B et H3-C, ou aucun, revient au propriétaire. Il commencerait par un audit M1 à M3
limité aux métadonnées, sans lecture de rendements, et par une estimation du nombre d'événements par mois. Aucune
campagne ne sera lancée sans son autorisation.

## 5. Corrections documentaires (backlog ; aucun document gelé n'est touché)

1. **`RESEARCH-ELIGIBILITY-GATES.md`**, tableau « Application to current work ». Les lignes H1 et H2 sont mises à jour
   dans la même PR que ce document : H1 clos (GATE2_FAIL, #508) ; H2 Gate 1 exécuté une fois (A1), FAIL, clôture
   décidée par le propriétaire (#511, non fusionnée).
2. **`H1-XPERP-ACCOUNT-CHECK.md`** dit que le Gate 2 et A3 ne sont pas autorisés. Il faudrait ajouter un renvoi vers
   l'en-tête d'A3 (feu vert du propriétaire) et vers #508.
3. **Funding des X-Perps.** Deux documents donnent un plafond de ±0,375 % :
   - `TRADABLE-INSTRUMENT-UNIVERSE.md` (ligne 23) ;
   - `H1-EXECUTABILITY-REVIEW.md` (ligne 58).

   Deux autres indiquent seulement « 8 h » :
   - `FRANCE-EXCHANGE-ELIGIBILITY.md` (ligne 49) ;
   - `H1-XPERP-TECHNICAL-GAP.md` (ligne 15).

   Ces valeurs sont justes pour BTC, mais pas pour tous les X-Perps. Correction à apporter : les X-Perps de nouveaux
   tokens versent toutes les 4 h avec un plafond de ±1 % (QUANT : 8 h, ±1,5 %), et l'intervalle varie selon le contrat
   (`H1-XPERP-MARKET-ACCESS.md` §2.8).
