# Datasets Paper market data

Les datasets Paper séparent explicitement la provenance réseau, la venue, la
qualité et l'état de certification. Un dataset Hyperliquid ne contient jamais
implicitement plusieurs réseaux.

## Réseaux et endpoints Hyperliquid publics

| Réseau | Info HTTP | WebSocket public |
|---|---|---|
| `mainnet` | `https://api.hyperliquid.xyz/info` | `wss://api.hyperliquid.xyz/ws` |
| `testnet` | `https://api.hyperliquid-testnet.xyz/info` | `wss://api.hyperliquid-testnet.xyz/ws` |

Ces endpoints sont publics et read-only. Le client Paper n'accepte aucun
credential, wallet, signature, flux utilisateur ou appel `post/action`.
`HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED` vaut `false` par défaut.

## Historique public

L'historique utilise exclusivement `candleSnapshot` pour BTC et ETH sur `1m`,
`5m`, `15m` et `1h`. Il ne fabrique pas de trades historiques. Le top-of-book
historique est un modèle prudent déclaré
`hl_candle_atr_top_v1` version `1.0.0`, avec la qualité
`public_historical_candles_modelled_book`. Le snapshot L2 courant n'est jamais
présenté comme profondeur historique.

## Capture live publique

Chaque connexion envoie exactement douze subscriptions :

| Coin | Flux |
|---|---|
| BTC | `trades`, `l2Book`, `candle` `1m`, `5m`, `15m`, `1h` |
| ETH | `trades`, `l2Book`, `candle` `1m`, `5m`, `15m`, `1h` |

Les trades, vrais tops-of-book et bougies closes sont normalisés dans le contrat
Paper commun. Une bougie en cours reste dans le checkpoint et n'est émise
qu'une fois qu'une bougie plus récente prouve sa clôture. L'identité d'un trade
inclut le réseau, le coin, le block time et `tid`.

Le checkpoint autoritatif est
`checkpoints/hyperliquid-live.json`. Il lie le dataset, le réseau, la
configuration, les ordinals, l'événement pending, les bougies courantes, les
frontières finalisées, les acquittements et un historique borné des identités
naturelles de trades. Son schéma v2 empêche notamment qu'un chevauchement de
batches réattribue de nouveaux ordinals aux mêmes trades. Sa publication est
atomique et protégée par un checksum SHA-256.

La capture applique :

- heartbeat après 45 secondes d'inactivité sortante et timeout pong de
  10 secondes ;
- reconnexions bornées à `1`, `2`, `4`, `8`, `15`, puis `30` secondes ;
- backpressure bornée en nombre de frames et en octets ;
- rejeu exact de l'événement pending après restart ;
- frontières `initial` et `reconnect` avant les données de carnet ;
- égalité déterministe entre capture complète et replay.

Une déconnexion ou un crash après l'entrée en phase `streaming` fait perdre la
continuité des trades publics. La reconnexion peut continuer à des fins de
diagnostic, mais le dataset ne peut plus être certifié ni entrer dans une
baseline moderne.

## Certification

Une capture live certifiable a la qualité
`recorded_public_book_and_trades`, un manifeste `complete`, un seul réseau, zéro
gap inexpliqué et un checkpoint terminal sain. Le verifier reconstruit
indépendamment les identités, ordinals, snapshots et fronts de bougies, puis les
compare au checkpoint. Il rejette notamment :

- un réseau mélangé ou différent du manifeste ;
- un book synthétique ou issu du modèle historique ;
- une bougie mutable ou non confirmée ;
- une frontière de bougie en régression ;
- une frontière de snapshot absente ;
- un événement pending, une continuité perdue ou un checksum corrompu ;
- un dataset incomplet demandé comme baseline.

Cette capacité ne change aucune readiness de trading Hyperliquid. Elle
n'autorise ni exécution mainnet, ni exécution testnet, ni fallback vers une
autre venue.

## Coordinateur d'exécution Paper

Le coordinateur consomme les événements normalisés et route exclusivement les
plans préparés vers `FakeExchangeAdapter`. Il est désactivé par défaut avec
`PAPER_EXECUTION_ENABLED=0`. Le sous-graphe d'exécution possède un registry
explicitement vide : aucun adaptateur d'exchange réel, credential, wallet,
signer, transport HTTP/WS privé ou appel exchange n'y est joignable.

Chaque événement marché est lui-même un effet durable appliqué au carnet Fake
avant l'éventuel ordre de la même source. Un top-of-book conserve exactement
ses bid/ask. Une bougie utilise uniquement sa clôture avec le modèle déclaré
`paper-candle-close-spread-v1` (2 bps) : le high/low ne sert jamais à inventer
un chemin intrabar. Les fenêtres MTF du contexte `FAKE` lisent directement les
klines projetées Paper, et non le provider Fake vide du mode démonstration.

Une cellule legacy conserve exactement l'identité v1 suivante :

```text
network + market_data_venue + configuration_snapshot_id + strategy_profile + run_id
```

Une cellule moderne utilise l'identité v2 distincte :

```text
network + market_data_venue + configuration_snapshot_id
+ mode_id + mode_version + setup_id + setup_version + side
+ config_hash + condition_catalog_hash + run_id
```

L'identifiant est le SHA-256 du tuple canonique complet. Aucun alias, profil par
défaut, version implicite ou fallback de venue n'est accepté. La configuration
Paper privée est normalisée dans `configuration_snapshot_id`; l'Effective
Config moderne est résolue avec la venue et le réseau exacts du dataset et lie
la cellule via `config_hash` et `condition_catalog_hash`. Aucun contenu de
configuration ni chemin n'est affiché par les commandes opérateur.

Les profils legacy actuellement enregistrés sont `reference_only`. Leurs
trades peuvent prouver le fonctionnement technique de la chaîne, mais sont
exclus de toute baseline moderne et de toute agrégation certifiée.

### Effet durable canonique moderne

Le contrat `paper-canonical-prepared-effect.v1` conserve le plan canonique
complet, la preuve d'admission portefeuille, la lineage Effective Config,
l'identité durable de l'`OrderIntent` et la provenance v2 de la cellule. La
réservation portefeuille n'est jamais sérialisée comme objet PHP : la preuve
v2 authentifie l'instant réel d'admission et le decoder la recrée à cet instant
depuis la preuve, le plan et la politique recompilée depuis le snapshot de
lineage, puis exige le même hash d'état initial. Les preuves v1 historiques
restent lisibles et vérifiables avec leur réservation existante, mais ne peuvent
pas ouvrir seules une nouvelle réservation.

Le champ `exchange` de la provenance d'exécution vaut toujours `fake`. Le champ
`market_data_venue`, le plan et l'Effective Config conservent la venue publique
du dataset (`okx` ou `hyperliquid`) ; aucune réécriture vers le modèle legacy
`OrderPlanModel` n'est autorisée. Le réseau et le namespace de compte du scope
portefeuille doivent également correspondre exactement à la cellule v2. Un
écart de plan, preuve, décision, cellule, réseau, venue, version, side ou hash échoue avec
`paper_canonical_prepared_effect_payload_invalid`.

L'assemblage des inputs marché/risque, la réservation d'intent, le dispatcher
Fake canonique et la reprise idempotente consomment désormais tous ce contrat.
Une cellule moderne résolue et exécutable peut donc être marquée
`baseline_eligible`. Ce statut ne certifie ni son PnL ni sa représentativité :
les preuves de fermeture, coûts, lineage et le seuil de 50 trades restent des
gates indépendants. Les cellules historiques `reference_only` ne sont jamais
promues par backfill.

### Stockage et reprise

La base doit être une PostgreSQL Paper dédiée dont le nom se termine par
`_paper_test` en test et `_paper` ailleurs. Une base avec des migrations en
retard ou contenant un compte non-Paper est refusée. Le compte Fake est isolé
par cellule sous :

```text
var/paper-fake-state/<cell-sha256>.dat
```

Le journal applique trois phases durables :

1. claim de la source, append de l'effet marché puis, si une décision existe,
   réservation de l'`OrderIntent`, création de la lineage et append de l'effet
   d'ordre préparé ;
2. effets idempotents et ordonnés sur le compte Fake de la cellule ;
3. projection atomique des orders, lifecycle, fills et coûts, puis
   acknowledgement et avancement du checkpoint.

Un restart rejoue d'abord tout effet pending. Les cinq frontières de crash
avant/après la phase 1, après l'effet Fake et avant/après la phase 3 convergent
vers un seul ordre Fake et les mêmes faits métier canoniques. La provenance
réseau, venue, snapshot, cellule, profil, run et éligibilité suit
l'`OrderIntent`, la lineage, les événements et le ledger de coûts.

Le kill switch est persistant par cellule. Une cellule tuée reste bloquée après
restart ; sa reprise exige une action explicite et ne change jamais son tuple
d'identité.

### Rejeu opérateur

Le runtime-check read-only et le rejeu exigent le même tuple, sans valeur
implicite :

```bash
php bin/console app:paper-market:runtime-check \
  --dataset=/chemin/absolu/dataset \
  --configuration=/chemin/absolu/configuration.json \
  --strategy-profile=scalper_micro \
  --run-id=run-20260801-001
```

Le résultat JSON `paper-replay-readiness-v1` ne contient aucune configuration
ni aucun chemin. `ready=true` prouve la disponibilité technique de la source,
de l'horloge, de la base et de la frontière Fake-only. Le champ indépendant
`baseline_eligible` reste faux pour tous les profils legacy `reference_only`.
Pour une identité moderne complète et exécutable, il vaut vrai afin que les
trades puissent entrer dans la campagne de certification #132 ; l'export rejette
néanmoins toute ligne qui ne satisfait pas aussi ses autres preuves.
Le check refuse aussi une cellule tuée, un binding dataset divergent et un
dataset au-delà de la limite effective du reader. Une reprise existante doit
pointer vers le même événement, index et timestamp dans le dataset vérifié,
sans avancer l'horloge. Le check ne crée ni snapshot persistant, ni cellule, ni
checkpoint, ni effet.

Le rejeu réutilise exactement cette préparation avant toute écriture :

```bash
php bin/console app:paper-market:replay \
  --dataset=/chemin/absolu/dataset \
  --configuration=/chemin/absolu/configuration.json \
  --strategy-profile=scalper_micro \
  --run-id=run-20260801-001
```

Les chemins relatifs et symlinks sont rejetés. Le fichier de configuration doit
être privé, borné et sans clé sensible. Le réseau et la venue viennent
uniquement du manifeste vérifié ; un dataset v1 `legacy_unknown` reste lisible
par les outils historiques mais ne peut lancer cette nouvelle baseline. La
sortie ne contient que les identités, la position suivante et l'état du kill
switch.

Les codes de refus stables incluent notamment
`paper_execution_cell_killed`, `paper_execution_dataset_mismatch`,
`paper_execution_network_mismatch`, `paper_execution_venue_mismatch`,
`paper_execution_source_gap`, `paper_execution_source_out_of_order` et
`paper_execution_provenance_invalid`.

La promotion vers une baseline moderne reste conditionnée par les modes
exécutables #306, #307 et #308, puis par la génération des populations. Le
retrait BitMart #305 reste hors de ce chantier.
