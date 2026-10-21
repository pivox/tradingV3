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
