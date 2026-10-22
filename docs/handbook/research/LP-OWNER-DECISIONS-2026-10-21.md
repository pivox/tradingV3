# Programme LP — décisions du propriétaire (2026-10-21)

Réponses du propriétaire, reçues le 2026-10-21, aux quatre points posés après le plan
[LP-PROGRAMME-PLAN-2026-10-20.md](LP-PROGRAMME-PLAN-2026-10-20.md). Texte reçu, recopié tel quel :

> 1 ok go
> 2 spot admin en marge
> 3 ok
> 4 ok

Le mandat qui encadre ces décisions est celui du 2026-10-20 : « Ok go. Mais ta mission reste de trouver comment faire un pnl
positif avec le scalping (même sur des périodes précises) ». Le scalping reste l'objectif ; G5 (périodes et régimes déclarés à
l'avance) passe en tête de la piste L.

## D1 — Licence OKX API Agreement §9.4 : accordé

- **Objet accordé.** Capture publique continue, sans ordre, du 2026-11-02 00:00 UTC au plus tard le 2027-03-31, sur au plus
  40 X-Perps crypto et 2 swaps globaux de calibration, par l'hôte EEE et, pour les deux swaps, par l'hôte global. Le
  propriétaire la considère comme une préparation de son propre trading, au sens de « personal, non-commercial trading and
  account management » (texte recopié dans [LP-DATA-DESK-2026-10-20.md](LP-DATA-DESK-2026-10-20.md) §2).
- **Couvert aussi.** La lecture historique unique de G5 Gate 1 : bougies 1 minute dernier prix, mark et indice, et historique de
  funding des 29 X-Perps liquides, du listing au 2026-10-13 inclus, fin dure codée au 2026-10-14 00:00 UTC.
- **Règles prudentes conservées par défaut.** Séries, tables chiffrées et fichiers de capture restent dans `tradingV3-private`.
  Le dépôt `pivox/tradingV3` ne reçoit que des agrégats statistiques sans prix. Le travail d'agents sur ces données n'entraîne
  aucun modèle commercialisé ni partagé. Tout allongement, instrument, canal ou hôte ajouté est une nouvelle décision.

## D2 — Spot admis en marge : piste S ouverte

- **Déclaration du propriétaire, niveau interface, 2026-10-21** : le spot est admis en marge (« Selected Margin ») sur son
  compte X-Perps. C'est la condition S-O1 de [LP-PROGRAMME-GATES.md](LP-PROGRAMME-GATES.md) §10.2, qui sépare la variante
  auto-collatéralisée K1(c) de la variante K1(a), réfutée à 500 USDT.
- **À relever au gel, sans ordre** : les décotes BTC et ETH affichées dans les réglages de marge X-Perps ; la confirmation qu'un
  short `BTC-USD_UM_XPERP-310404` est porté par du BTC spot ; la clause d'appel de marge « < 30 % de la valeur du contrat ».
- **Conditions restantes avant tout gel de la piste S** : S-O2 devise de règlement USD-M sur USDC (USDC vu à l'interface le
  2026-10-15, [H1-XPERP-ACCOUNT-CHECK.md](H1-XPERP-ACCOUNT-CHECK.md)) ; S-O3 paires BTC-USDC et ETH-USDC négociables ; S-O4
  barème du compte ; S-O5 amendement CS-FUND (A-S1 à A-S4, en rédaction) ; S-O6 conseil fiscal ou risque accepté ; S-O7 devise de
  compte ; S-O8 verdict `fwd-okx-r1`, après le 2026-10-31.

## D3 — Gate 1 Hyperliquid pour G4 : consenti

- **Objet consenti.** Une lecture unique, statistique, de l'archive publique des exécutions Hyperliquid (`hl-mainnet-node-data`,
  champ `liquidation`), pour mesurer le signe du retour du prix après une cascade de liquidations, sous le pré-enregistrement
  [LP-G4-DRAFT-PREREGISTRATION.md](LP-G4-DRAFT-PREREGISTRATION.md) gelé. Données de développement seulement : elles ne
  confirment jamais une affirmation sur les X-Perps (déclaration de transférabilité, desk §4.3).
- **Ce qui reste nécessaire.** L'archive est « requester-pays » : le transfert est facturé au compte AWS du demandeur. Le
  propriétaire fournit un profil AWS. Avant tout transfert, le script liste les objets et affiche le coût estimé ; il s'arrête
  si l'estimation dépasse le plafond. **Plafond provisoire : 10 USD**, à relever par le propriétaire. Les conditions d'utilisation
  de l'archive et son schéma sont vérifiés sur un échantillon de schéma avant toute lecture de valeurs.
- **Hyperliquid n'est pas une place d'exécution** pour ce compte : aucune autorisation dans l'UE
  ([FRANCE-EXCHANGE-ELIGIBILITY.md](FRANCE-EXCHANGE-ELIGIBILITY.md), « Hyperliquid: not retained for real trading »). Les
  drapeaux Hyperliquid restent à 0.

## D4 — Porte économique : 97 USD par an

```
S_éco = 5 × SOFR × capital = 5 × 3,89 % × 500 USDT ≈ 97 USD par an
```

Seuil sous lequel une famille n'est pas poursuivie même si elle passe statistiquement ([LP-PROGRAMME-GATES.md](LP-PROGRAMME-GATES.md)
§1.2). Il est inscrit dans chaque protocole avant son gel et ne change plus après une lecture.

## Inchangé

Aucun ordre, aucun drapeau, aucune clé avec droit de trading. `fwd-okx-r1` et ses fenêtres W0–W2 restent intacts. Aucune
donnée datée 2026-10-14 → 2026-10-31 n'est lue. La capture prospective ne démarre pas avant le 2026-11-02 00:00 UTC, plancher
inscrit dans le code (`QUARANTINE_FLOOR`).

---

# Décisions du 2026-10-21, second lot (G5, piste S, G4)

Réponses du propriétaire, reçues le 2026-10-21 vers 06:40 UTC, aux cinq points posés pour le gel de G5. Texte reçu, recopié
tel quel :

> 1 je confirme
> 2 oui
> 3 Nn
> Clore s
> 5 ok

## D5 — Échelle de la lecture G5 Gate 1 : confirmée (P-G5-1)

Lecture unique de 25 X-Perps : funding (≈ 50 requêtes, scellé, avant le gel), puis bougies 1 minute dernier prix, mark et
indice (≈ 58 300 requêtes en tout), une IP, stockage `tradingV3-private`. Le propriétaire n'a pas choisi la cadence :
**facteur 0,4 retenu par défaut** (≈ 5,6 h, proposition du protocole, la plus prudente vis-à-vis d'OKX). Le texte de D5 devient
le `--decision-ref` du fetcher.

## D6 — Liste des 25 instruments : dérogation accordée (P-G5-4)

Le propriétaire accepte que l'univers figé reprenne la liste D1, issue d'un relevé de volume du 2026-10-14 (quarantaine,
sélection sur la liquidité de fin de fenêtre), avec les biais déclarés au protocole §3.1. Le snapshot d'instruments relevé après
le 2026-10-31 reste exigé ; aucun instrument n'est ajouté par un volume.

## D7 — Gate 2 de G5 : « non » (P-G5-10)

- **(i) Fin de fenêtre de T05 au Gate 2** : refusée. La fenêtre s'arrête au 2027-03-31 ; avec ≈ 92 instants < 100, T05 au
  Gate 2 est `NOT_INFORMATIVE` **par construction**, comme le protocole l'écrit d'avance.
- **(ii) Source d'indice à la minute pour le Gate 2** : la réponse « Nn » est lue comme un refus portant sur le point 3 entier ;
  une confirmation explicite est demandée, car sans source d'indice T01 au Gate 2 est `INCONCLUSIVE` par construction (§10).

## D8 — Piste S : close (S-O5)

Le propriétaire classe la piste S **sans suite** : à 500 USDT, le portage couvert auto-collatéralisé ne franchit pas la porte
économique, même dans le meilleur cas ([LP-S-CSFUND-K1C-AMENDMENT-DRAFT.md](LP-S-CSFUND-K1C-AMENDMENT-DRAFT.md) §11). Le
conflit P-S3 disparaît : **BTC et ETH restent dans l'univers de G5 Gate 1**. Aucun enregistreur de funding n'est lancé pour la
piste S. La réouverture exigerait une décision nouvelle (porte en dollars absolus et capital plus élevé).

## D9 — Source du Gate 1 de G4 : dYdX v4 (décision 2 du rapport DEX)

- dYdX v4 devient la source principale du Gate 1 de G4 ; Hyperliquid (D3) reste le repli.
- Accords associés (rapport DEX §7, décision 3) : données de l'indexeur public sans licence écrite, pour une recherche
  personnelle non redistribuée ; échantillon de schéma autorisé ; amendement de `LP-G4-DRAFT-PREREGISTRATION.md` §5.2 (proxy
  de mid déclaré, biais déclaré) avant tout gel.
- **Échantillon de schéma pris le 2026-10-21 à 06:39 UTC** (`indexer.dydx.trade/v4/trades/perpetualMarket/BTC-USD`, trois pages
  de 100 trades, toutes datées avant le 2026-10-14) : champs `createdAt`, `createdAtHeight`, `id`, `price`, `side`, `size`,
  `type` ; historique servi au moins jusqu'au 2023-12-31. Aucun prix, aucune taille, aucun décompte n'a été affiché ni stocké.
  La valeur `LIQUIDATED` n'apparaît pas dans ces 300 trades, ce qui est attendu pour un événement rare : sa présence est vérifiée
  au fetch du protocole gelé. Fichier : `tradingV3-private/research/20261021T-dex-alternatives/dydx-schema-sample/`
  (sha256 dans `SHA256SUMS`).

## D10 — Source d'indice du Gate 2 de G5 : les deux, choix sur la qualité (P-G5-10 (ii))

Réponse du propriétaire, 2026-10-21 : « On essaye des deux un par un puis on décide ».

- **(a)** Le canal public `index-tickers` est ajouté à la capture continue (extension de D1 au titre du §9.4 : un canal public de
  plus, mêmes instruments, même hôte, même période).
- **(b)** L'historique `history-index-candles` est récupéré une fois après la fin de la fenêtre du Gate 2.
- **Règle de choix, écrite avant la capture et jamais révisée après une lecture des résultats de T01** : la source primaire est
  (a) si sa couverture est d'au moins 98 % des minutes de la fenêtre et si, sur les minutes communes, l'écart médian absolu entre
  (a) et (b) est inférieur ou égal à 0,5 pb ; sinon (b). Le choix est fait et haché sur les seules mesures de couverture et
  d'accord entre séries, **avant** toute évaluation de T01. L'autre source sert de sensibilité rapportée.
- Ce qui reste interdit : comparer les résultats de T01 obtenus avec chaque source pour choisir.
