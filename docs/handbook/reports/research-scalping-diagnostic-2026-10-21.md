# Pourquoi aucune configuration de scalping n'est validée — diagnostic du 2026-10-21

Rapport au propriétaire, en réponse à la mission du 2026-10-21 (développeur senior et chercheur quantitatif). Il distingue
quatre cas : un signal sans avantage, un avantage trop faible pour couvrir les coûts, un signal exploitable mal exécuté, un
résultat prometteur insuffisamment documenté. Il ne présume ni qu'une configuration rentable existe, ni que le scalping est
impossible.

Sources de ce rapport :

- l'audit chiffré des campagnes, chaque chiffre avec son fichier et sa ligne :
  `tradingV3-private/research/20261021T0700Z-scalping-diagnostic/audit/CAMPAIGNS.md` ;
- la vérification des sources scientifiques et de la documentation OKX :
  `tradingV3-private/research/20261021T0700Z-scalping-diagnostic/literature/SOURCES.md` ;
- la lecture du code (chemins cités) ;
- la branche de correction `claude/fix-microstructure-fail-closed` (commit `677eeef9`).

Aucune donnée de marché n'a été lue pour ce rapport. Le test forward `fwd-okx-r1` n'a pas été consulté.

---

## 1. La réponse courte

1. **Pour la plupart des familles testées, le signal n'a pas d'avantage.** Les déclencheurs d'état (VWAP, MACD, RSI, déséquilibre
   d'une bougie), le flux et le carnet à la minute ont fait jeu égal avec des entrées au hasard au même moment. C'est le cas de
   r1–r12 et des empilements 1.1.0 / 1.2.0 (r10 : 0 configuration sur 96).
2. **Une seule famille montre un contenu relatif : la réversion après excursion (S7, Q3).** Elle bat son contrôle dans 4 lectures
   hors échantillon sur 6, mais son signe change d'une fenêtre à l'autre. Elle couvre mal les coûts : environ +1,3 pb de brut
   moyen hors échantillon contre 4 à 5 pb de frais, soit environ −3,1 pb nets (calcul d'audit pondéré, configurations
   différentes). Elle tombe à la fois dans « avantage trop faible » et dans « signal instable ».
3. **L'exécution simulée par la recherche est réaliste et ne cache pas d'avantage.** Ordres maker au touch, latence 250 ms,
   remplissage pessimiste, sorties preneur au touch opposé, spread compté une fois. Mais les rapports n'ont jamais séparé le
   mouvement après le signal du mouvement après le remplissage : on ne peut pas exclure tout à fait une perte liée au choix
   d'exécution pour la réversion.
4. **La plateforme vivante n'a jamais pu tester le scalping.** Le profil `scalper_micro` ne peut poster aucun ordre : ses
   conditions 1 minute exigent une preuve microstructure que le chemin live ne fournit pas. Ce qui a tourné n'était pas un test.
5. **Un résultat est prometteur mais insuffisamment documenté** : la réversion longue Q3 (r8b), qui a gardé son signe sur trois
   fenêtres indépendantes avec une forte dispersion entre paires. Elle est en test forward (`fwd-okx-r1`, verdict après le
   2026-10-31). Aucune nouvelle expérience ne doit la toucher avant.
6. **La couverture des données est mince.** Les campagnes tick couvrent 187 jours répartis en 28 fenêtres, presque toutes de
   5 jours ouvrés, entre juillet 2023 et décembre 2025, soit environ 21 % des jours de la période. Aucune série continue ne
   dépasse 31 jours, et aucun week-end n'a été vu, sauf en septembre et octobre 2024. Ce n'est pas « plusieurs années ».
7. **Verdict** : abandonner les déclencheurs d'état à la minute ; attendre `fwd-okx-r1` pour la réversion ; **acquérir davantage de
   preuves** sur une seule hypothèse nouvelle, les flux à heure connue (G5), avec le protocole du §6.

---

## 2. Ce que montrent les campagnes

L'audit classe 27 campagnes et marque deux autres hors classement :

| Classement | Nombre | Campagnes |
|---|---|---|
| Négatif | 16 | Part A (exécution), r1 (deux fois), r2, r3, r5, r7, r9, r10, r11, r12, r14, Q3 V2 C4-SL, day trading, sélection v2 à 15 min, scalping sur bougies |
| Non concluant | 7 | r4, r6, r8-Q3, r10x, r13-O2, q3x, levier > 2× |
| Bloqué techniquement | 3 | Q3 V2 C2-SL (contrôle de comparabilité), certification Paper #132 (0 trade sur 12 cellules), profil vivant `scalper_micro` 1.1.7 |
| Prometteur non confirmé | 1 | r8b Q3 long, d'où vient `fwd-okx-r1` |
| Hors classement | 2 | `fwd-okx-r1` (en cours, non lu), programme LP (brouillons) |

**Les deux chiffres cités dans la mission sont exacts.**

- **r10.** 0 configuration sur 96 passe l'entraînement : 24 règles × 4 sorties
  ([research-scalping-120-2026-10-13.md](research-scalping-120-2026-10-13.md), ligne 5). La validation n'a pas été lancée.
- **Q3 V2.** C4-SL fait +13,0 pb nets à l'entraînement, puis −20,7 pb nets en validation sur novembre 2024 : brut −15,8 pb, frais
  4,9 pb, 317 trades, PF 0,71, 5 paires positives sur 20
  ([research-scalp-q3-r2-validation-2026-11-2024.md](research-scalp-q3-r2-validation-2026-11-2024.md), lignes 30–31). Le brut est
  déjà négatif : **le signal lui-même a échoué**, pas seulement les coûts.

**Variantes et hypothèses.** À court horizon, environ 1 943 variantes pré-enregistrées et 1 497 exploratoires ont été testées. Elles
ne portent que sur 7 mécanismes : états d'indicateurs, flux et impulsion, carnet et file, réversion, open interest, horloge, avantage
maker. Seuls trois de ces mécanismes ont un payeur plausible pour une boucle à la minute : la réversion, l'open interest et
l'horloge. Seule la réversion a été testée à fond ; l'horloge ne l'a été que par 8 variantes de bougies 5 minutes. Les totaux
publiés dépassent de 204 la somme des fichiers de résultats (1 871 contre 1 667), pour une raison inconnue.

---

## 3. Signal, exécution, coûts, net : le tableau

### 3.1 Par famille

| Famille | Signal contre contrôle | Exécution | Coûts | Net | Cas |
|---|---|---|---|---|---|
| États d'indicateurs à la minute (r10, r11, sélection v2, bougies) | égal au contrôle | réaliste | 4–10 pb | négatif | signal sans avantage |
| Flux et carnet à la seconde (r1, r2, r8, r9) | égal au contrôle ; r9 négatif **avant** frais (−2,1 pb médian) | réaliste | 4–10 pb | négatif | signal sans avantage à notre horizon |
| Avantage maker inconditionnel (Part A, captures OKX et Hyperliquid de 24 h) | — | mark-out net à 60 s −3,1 (OKX) / −3,4 pb (HL), dont sélection adverse 1,1–1,5 pb | frais maker 2 pb inclus | négatif | coût d'exécution, pas de signal |
| Réversion après excursion (r3–r8b, Q3 V2) | bat le contrôle 4 fois sur 6 hors échantillon | réaliste ; A et B jamais séparés | ≈ 4–5 pb | ≈ −3 pb en moyenne, signe instable | avantage trop faible et instable |
| Open interest (r13, r14) | build-up bat le contrôle à l'entraînement | réaliste | ≈ 5 pb | −11,7 pb en validation | signal sans avantage hors échantillon |
| Horloge (S3 opening drive, bougies 5 min) | ≈ +0,1 R contre contrôle | preneur, bougies | 10 pb et plus | négatif | insuffisamment documenté |

### 3.2 Réversion, fenêtre par fenêtre (hors échantillon)

| Fenêtre | Trades | Brut (pb) | Net (pb) | Contrôle (pb) | Paires positives |
|---|---:|---:|---:|---:|---|
| r3, novembre 2025 | 94 | +2,4 | −1,8 | −22,4 | 3 ou 4 sur 10 |
| r7, novembre 2025 | 206 | +17,1 | +12,8 | −16,7 | 10 sur 10 |
| r7, décembre 2025 | 145 | −16,5 | −20,9 | −15,8 | 1 sur 10 |
| r8b, janvier 2025 | 151 | +22,7 | +18,6 | +5,5 | 14 sur 20 |
| r8b, décembre 2024 | 155 | +10,6 | +6,5 | −26,4 | 9 sur 20 |
| Q3 V2, novembre 2024 | 317 | −15,8 | −20,7 | −8,1 | 5 sur 20 |

Lecture : le brut est « après remplissage », pas « après signal ». Les configurations diffèrent d'une ligne à l'autre. Les frais
représentent 4 à 5 pb par trade ; le contrôle est une entrée au hasard dans le même état de marché.

---

## 4. Ce que montre le code

### 4.1 Deux grandeurs portent le nom « OFI »

| Chemin | Grandeur calculée | Preuve | Fichier |
|---|---|---|---|
| Canonique (Paper, Backtest, Shadow, via `StrictRuleEvaluator`) | ratio de volume agresseur acheteur / vendeur des transactions publiques d'une fenêtre (`aggressor_volume_ratio.v1`), avec un carnet horodaté | instantané `CanonicalMicrostructureSnapshot` : fraîcheur du carnet, âge et trous des transactions, nombre minimal, identité du marché, égalité exacte de la valeur | `trading-app/src/TradingCore/Microstructure/CanonicalMicrostructureEngine.php`, `trading-app/src/Indicator/Condition/MicrostructureProof.php` |
| Live (boucle MTF, `TimeframeValidationService`) | déséquilibre de la **profondeur affichée** sur 20 niveaux d'un carnet REST non horodaté | aucune | `trading-app/src/MtfValidator/Service/TimeframeValidationService.php`, `enrichMicrostructureContext` |

Ni l'une ni l'autre n'est l'OFI de Cont, Kukanov et Stoikov, qui somme les **événements** au meilleur bid et au meilleur ask
(variations de prix et de taille) et dont la relation au prix est **contemporaine**, pas prédictive. Sur des actions américaines,
pas de 10 secondes, il explique 65 % de la variance (Table 2). Le déséquilibre des transactions seul n'en explique que 32 %
(Table 4). On ne le reconstruit pas avec deux instantanés REST à une minute d'écart.

**Conséquence vérifiée.** Sur le chemin live, les conditions `spread_bps_lte` et `order_flow_imbalance_gte` échouent toujours,
faute de preuve (`proof_reason = microstructure_proof_snapshot_missing`). Le journal du dernier dry-run
(`trading-app/var/log/mtf-2026-10-13.log`) les montre à `passed=false, value=null` pendant que les autres conditions 1 minute
passent. Le profil actif ne peut donc jamais entrer. L'échec est fermé, ce qui est correct pour la sécurité ; le défaut est de
configuration et de nommage, pas de validation.

### 4.2 Le moteur de repli échouait ouvert

Quand le moteur compilé lève une exception, la validation passe au moteur YAML
(`trading-app/src/MtfValidator/Service/Rule/YamlRuleEngine.php`). Celui-ci renvoyait **vrai** pour tout bloc qu'il ne
comprenait pas, avec ce commentaire : « on retourne true pour ne pas bloquer toute la validation ». Il renvoyait aussi vrai pour
une comparaison de champs mal formée. Les conditions prouvées, dont le spread et l'OFI, passaient alors sans donnée ni preuve.

Le défaut était latent :

- sur les profils livrés, le repli de `scalper_micro` s'arrête avant, sur une règle nommée inconnue (`rsi_bullish`) ;
- les journaux ne contiennent aucun repli.

### 4.3 Exécution et coûts de la plateforme

| Point | Ce que fait le code | Statut |
|---|---|---|
| Type d'ordre | `order_mode: 1` = limite GTC **sans** post-only ; post-only seulement pour `order_mode: 4` (`trading-app/src/TradeEntry/Execution/ExchangeExecutionService.php`, ligne 435) | le commentaire disait « taker » : corrigé |
| Frais modélisés | 5 pb en maker et 5 pb en taker (`trading-app/config/app/trade_entry.scalper_micro.yaml`, lignes 26–27) contre 2 / 5 pb au barème OKX regular | signalé, **non modifié** : c'est un réglage de la porte de risque |
| Annulation et remplacement | perte de la place dans la file (documentation OKX) ; un post-only qui croiserait est annulé (code 31) | conforme aux simulateurs de recherche |
| Déclenchement des protections | stops et TP au **mark** | correct |
| Produit | connecteur OKX USDT-SWAP global, non accessible à un compte français ; X-Perps non pris en charge par le code | écart connu, hors périmètre de cette correction |

### 4.4 Les simulateurs de recherche

`python-orchestrator/scripts/research_exec/scalp_engine.py` et `q3r2_engine.py` :

- entrée maker au touch ou un tick à l'intérieur ;
- latence de 250 ms ;
- annulation si l'ordre croise à l'activation ;
- remplissage par la règle « through », pessimiste ;
- TTL de 30 s ;
- sorties preneur au touch opposé avec latence ;
- frais de 2 / 5 pb ;
- funding traversé compté ;
- stop-market avec glissement mesuré.

Le spread est compté une seule fois, à travers les prix de touch. Deux limites restent :

- les séries Binance sont des proxies dont le spread vaut un tick par construction, ce qui annule toute capture de spread ;
- aucune campagne n'a séparé le mouvement après le signal du mouvement après le remplissage.

---

## 5. Corrections apportées

Branche isolée `claude/fix-microstructure-fail-closed`, commit `677eeef9`, poussée, sans PR ouverte. Aucune validation de preuve n'est
retirée.

| Correction | Fichier | Test |
|---|---|---|
| Le moteur de repli échoue fermé sur tout bloc non pris en charge et sur une comparaison mal formée, et journalise le nom du bloc | `YamlRuleEngine.php` | `tests/MtfValidator/Service/Rule/YamlRuleEngineFailClosedTest.php` (6 tests) |
| Le déséquilibre de profondeur du chemin live s'appelle `book_depth_imbalance_20` et n'est plus présenté comme un flux d'ordres ; les conditions prouvées restent en échec fermé, avec la raison | `TimeframeValidationService.php` | `tests/MtfValidator/Service/TimeframeValidationServiceLiveMicrostructureTest.php` |
| Commentaires `order_mode` corrigés | `trade_entry.yaml`, `trade_entry.scalper_micro.yaml` | — |

**Vérifications** :

- **les 5 tests de comportement** échouent sur le code principal et passent sur la branche ;
- **`tests/MtfValidator` et `tests/Indicator`** : mêmes erreurs et échecs préexistants que la base, aucun ajout ;
- **le test d'autowiring du conteneur** passe ;
- **PHPStan** : nombre d'erreurs inchangé sur les fichiers modifiés.

Ces corrections ne prouvent aucune rentabilité. Elles empêchent une condition non vérifiée de passer et rendent le diagnostic
lisible.

---

## 6. L'expérience proposée : une hypothèse, les flux à heure connue (G5)

Protocole complet : [LP-G5-DRAFT-PREREGISTRATION.md](../research/LP-G5-DRAFT-PREREGISTRATION.md), avec l'amendement A1 du
2026-10-21, écrit avant tout gel et toute lecture. Spécifications machine : `g5_gate1_spec.json` (0.3.0-draft, sha256
`2ab0073c…`) et `g5_gate2_spec.json` (0.1.3-draft, sha256 `f506f157…`). Évaluateur : 121 tests hors réseau verts.

**Pourquoi celle-là.**

- **Payeur nommé.** Elle a un payeur nommé (la photo de funding toutes les 8 h, l'ouverture des actions US à 13:30 UTC).
- **Instant connu d'avance.** Le seul cas où une boucle à la minute n'est pas en retard.
- **Jamais testée correctement.** Les contrôles appariés à l'heure l'effaçaient par construction.
- **Données du produit visé.** Elle se mesure sur les X-Perps d'OKX Europe.
- **Aucun paramètre à choisir.** Signes, fenêtres et seuils sont déclarés d'avance.

**Mécanisme.**

- **T01.** Quand le funding sort de sa zone morte, les détenteurs du côté payeur ferment avant la photo et rouvrent après.
  Un ordre maker posé à h−5 du côté opposé capterait ce retour sur [h ; h+15].
- **T05.** Le sens des 5 premières minutes après l'ouverture US se poursuit jusqu'à h+60.

**Ce qui l'invaliderait.** L'écart événement − contrôle sans borne basse positive, ou un net négatif à l'événement.

**Ce qui la distingue des familles testées.** Elle ne lit aucun indicateur, et son contrôle est pris aux heures sans
événement, pas apparié à l'heure.

**Mesures séparées, pour chaque type, aux événements et aux contrôles :**

| Mesure | Définition |
|---|---|
| A, signal | mouvement du prix de référence sur la fenêtre pré-déclarée, pour tous les instants classés, remplis ou non |
| B, exécution | le même mouvement aux seuls instants remplis (proxy « through » sur bougies au Gate 1, simulation sur carnet réel au Gate 2) |
| C, net | B + funding réel − coûts, et lecture portefeuille 500 USDT (au plus 4 ordres vivants à 1×, coupe de catastrophe à 3 σ₁₅) |

S'y ajoutent l'analyse des non-remplis (A remplis contre A non remplis, signature de la sélection adverse), une variante preneur
descriptive de T01, la stabilité par mois et par moitié de fenêtre.

**Coûts, sans double compte.** Pour T01, la sélection adverse de l'entrée est mesurée par le conditionnement au remplissage : le
coût supposé passe de 13 à 9,5 pb (frais 4, sortie 3,5, glissement 2), stress 14,25 pb. L'ancien total reste en sensibilité.
T05 reste à 15,5 / 23,25 pb ; un recouvrement partiel du demi-spread avec le dernier prix est déclaré, biais vers l'échec.

**Classement diagnostique, écrit d'avance, jamais un verdict** :

1. insuffisamment documenté (non informatif, ou intervalle trop large) ;
2. sans avantage (borne basse de A événement − A contrôle ≤ 0) ;
3. mal exécuté (remplis pires que non-remplis, net ≤ 0, variante preneur > 0) ;
4. trop faible pour les coûts (signal > 0, net ≤ 0).

**Validation chronologique.**

- **Gate 1** : une lecture historique unique des X-Perps, de leur listing au 2026-10-13, sans aucun paramètre à sélectionner.
  Les instants dont la fenêtre croise la fin des données ou le début d'éligibilité sont exclus et comptés.
- **Gate 2** : confirmation prospective sur la capture à partir du 2026-11-02, période finale intacte, mêmes signes et fenêtres.
- Les données déjà vues ne sont jamais présentées comme preuve indépendante.

**Critères (inchangés par A1, sauf le coût de T01).**

- **Seuil de passage.** Événement − contrôle ≥ +10 pb, borne basse à 90 % > 0.
- **Net positif à l'événement.** Avec les coûts, puis à coûts × 1,5.
- **Multiplicité.** Holm sur les deux types.
- **Taille minimale.** Au moins 100 instants.
- **Porte économique.** 97 USD par an.

**Budget.** Environ 58 300 requêtes REST publiques, environ 6 heures à la cadence prudente, quelques centaines de Mo (59 Go
libres). Une seule évaluation, calcul inférieur à une heure, aucune recherche de paramètres.

**Limites.**

- **Puissance faible.** Environ 160 à 210 instants pour T01, 105 pour T05. L'issue la plus probable est « non informatif »,
  écrite d'avance.
- **Bougies sans carnet** au Gate 1.
- **Défaut connu, à corriger avant le gel** : l'instant T01 qui ouvre la fenêtre de couverture du funding ne se voit compter
  aucun funding (un instant sur environ 211, léger biais vers le succès).

**Règle d'arrêt.**

- **FAIL d'un type** : il est clos dans sa forme testée.
- **Non informatif** : il passe au Gate 2.
- **Rien sous la porte économique** : rien n'est poursuivi.
- **Aucun succès au Gate 2** : G5 n'ouvre aucune démo.

**Bloqué par.**

- **Le verdict `fwd-okx-r1`**, après le 2026-10-31. Rien de G5 ne tourne avant.
- **Ta réponse sur la source d'indice du Gate 2.** Sans elle, T01 n'est pas testable au Gate 2.

---

## 7. Méthodologie : diagnostic de fragilité ou critère éliminatoire

| Critère | Usage dans le programme | Lecture |
|---|---|---|
| Part des paires positives (≥ 50 % ou 70 %) | éliminatoire dans r6, r8, r8b | **diagnostic de fragilité**. À environ 8 trades par paire, il rejette 10 à 20 % des effets réels (calcul d'audit). Il ne doit pas être rendu non éliminatoire après coup pour r8b : la règle était figée. |
| Dépendance aux meilleurs trades (sans les 5 % meilleurs) | rapportée ; q3x devient négatif (−1,2 pb) | diagnostic : il signale une moyenne portée par la queue, pas une preuve d'absence d'effet |
| Taille et indépendance de l'échantillon | portes de 200 à 300 trades ; fenêtres de 5 jours | avec un écart-type d'environ 100 pb par trade, détecter +10 pb demande environ 600 trades ; les trades d'un même jour sont corrélés |
| Contrôles | entrées au hasard dans le même état de marché, appariées à l'heure | bons contre les états d'indicateurs ; ils effacent par construction tout effet d'horloge |
| Robustesse aux coûts | frais × 1,5 | correcte ; la sélection adverse n'était pas mesurée hors de Part A |
| Multiplicité | portes fixes par configuration | environ 3 400 variantes au total ; le seuil de Sharpe attendu par hasard est 1,94 fois plus haut à N = 1 870 qu'à N = 15 (Deflated Sharpe Ratio, Bailey et López de Prado) ; aucun test du programme ne l'a corrigé avant le programme LP |

Aucun résultat passé n'est requalifié par ce tableau. Les protocoles futurs déclarent les résultats déjà connus et choisissent
leurs critères avant toute lecture.

---

## 8. Ce que la littérature permet de transférer

| Source | Ce qu'elle montre | Ce qu'elle ne montre pas | Transfert vers nous |
|---|---|---|---|
| Wen, Bouri, Xu, Zhao (NAJEF 2022) | momentum et réversion intrajournaliers ensemble sur Bitcoin, 2013–2020 (résumé seul vérifié) | aucun coût, aucun résultat net ; place et fréquence non vérifiées | autre période, autre place ; une décision par jour au mieux |
| Cont, Kukanov, Stoikov (2014) | OFI d'événements au meilleur bid / ask, relation contemporaine, R² 65 % | aucune prévision, aucune stratégie, aucun coût | horizon de 10 s sur actions ; inaccessible à une boucle à la minute |
| Kolm, Turiel, Westray (2023) | le flux d'ordres prédit mieux que le carnet brut ; horizon d'environ deux changements de prix (résumé seul vérifié) | aucun résultat net | horizon très inférieur à la minute |
| Documentation OKX | limite qui croise = taker ; post-only qui croiserait = annulé ; annulation = perte de file | — | conforme aux simulateurs |
| Bailey et López de Prado (DSR, 2014) | correction du Sharpe pour le nombre d'essais | — | s'applique au programme entier |

Aucune de ces publications ne démontre une rentabilité nette.

---

## 9. Verdict

| Hypothèse | Verdict | Motif |
|---|---|---|
| Déclencheurs d'état à la minute (`scalper_micro`, empilements 1.1.0 / 1.2.0) | **abandonner** | signal égal au contrôle partout ; le profil vivant ne peut d'ailleurs pas entrer |
| Flux et carnet à la seconde | **abandonner** pour cette infrastructure | effet réel mais contemporain ou de quelques secondes ; négatif avant frais à notre horizon |
| Réversion après excursion (Q3 long) | **acquérir davantage de preuves, par le test déjà en cours** | prometteur non confirmé ; aucune nouvelle expérience avant le verdict `fwd-okx-r1` |
| Flux à heure connue (G5) | **acquérir davantage de preuves** selon le protocole du §6 | mécanisme avec payeur nommé, jamais testé correctement ; puissance historique faible |

Rien de ce rapport ne promet une rentabilité.
