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
frontières finalisées et les acquittements. Sa publication est atomique et
protégée par un checksum SHA-256.

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
autre venue. Le prochain lot de la baseline #132 reste le coordinateur Paper,
routé exclusivement vers `FakeExchangeAdapter` avec une base PostgreSQL Paper
dédiée. Le retrait BitMart #305 reste hors de ce chantier.
