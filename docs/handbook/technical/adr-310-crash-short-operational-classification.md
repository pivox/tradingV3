# ADR-310 — Classification opérationnelle de `crash_short`

Statut : accepté pour le contrat canonique, bloqué et non branché au runtime

Date : 2026-08-01

Issues liées : #310, #303, #304, #132, #191

## Décision

`crash_short` requiert une **enveloppe opérationnelle distincte**. Il n'est
compatible avec aucune version actuelle des contrats `day_trading`, `scalping`
ou `micro_scalping`. Cette classification décrit une incompatibilité
opérationnelle, elle ne crée pas un quatrième ou cinquième mode et ne renomme
jamais le profil legacy en mode `crash`.

Le régime strict 4h/1h ressemble à `day_trading`, tandis que la confirmation
15m puis l'exécution volatile 5m ou 1m ressemble à `scalping`. Aucun de ces
rapprochements ne suffit : horizon complet, validité, risque propriétaire,
coûts nets, politique de fill fail-closed, invalidation et sorties ne sont pas
résolus. Le legacy contredit en plus les deux enveloppes par ses multiplicateurs
timeframe appliqués à plusieurs étages, sa perte de profil dans les chemins de
levier/TP-SL et ses fallbacks maker vers taker/market pendant la volatilité.

La décision est publiée dans `crash_short@1.1.0`. `crash_short@1.0.0` reste
l'extraction historique immuable où la compatibilité était `unresolved`.
`1.1.0` est `blocked`, `executable: false`, non publiable et conserve
`compatible_modes: []`. Le chargement reste exact : aucun alias `latest`, aucun
fallback de version et aucune résolution BitMart implicite.

## Hypothèse canonique corrigée

Une occurrence n'est jamais un RSI 1m isolé. Elle exige :

1. un régime baissier strict en 4h ;
2. un contexte crash baissier en 1h ;
3. une confirmation baissière 15m combinant EMA, sous-VWAP, MACD et ATR ;
4. soit la variante observable `execution_variant=5m_default` avec volume et
   garde RSI anti-mèche terminale, soit
   `execution_variant=1m_extreme` avec sous-VWAP, MACD, ATR, volume et RSI
   extrême.

La branche legacy `crash_short_entry_1m` est
`crash_short_pattern_1m AND crash_pullback_ready`. Dans le même `OR`, l'autre
branche exige déjà `crash_short_pattern_1m`. Par absorption booléenne,
`A OR (A AND B) = A` : la branche pullback est donc strictement redondante.
`1.1.0` la retire sans créer une neuvième identité.

Le signal `crash_pullback_ready` est en outre haussier (croisement MA9 vers le
haut ou proximité VWAP) et ne contient aucune reprise baissière explicite. Le
retest n'appartient donc pas à l'hypothèse canonique exécutable. Une observation
de retest pourtant suivie d'une reprise reste `block`, avec une trace dédiée,
jusqu'à la condition typée de #303 et l'enveloppe de #304.

## Inventaire et provenance

Les empreintes des deux sources au moment de la décision sont :

- `src/MtfValidator/config/validations.crash.yaml`, commit
  `d1d9a174960660e88f84c54850ef61181d39a880`, SHA-256
  `5dd5cbf03cdbcb804cd664e47c0dce4007438bbce973af027a05e7155b2c10e2` ;
- `config/app/trade_entry.crash.yaml`, commit
  `6ff8ab88e1bb9465f92f39424ae64305ca20ee0d`, SHA-256
  `722bd2ee013a24ae86ffae2aa846437db7a51898ef8de4a0cd58e693a8ffb90f`.

| Élément canonique | Source legacy | Décision |
|---|---|---|
| identité short, TF contexte/exécution, défaut 5m | `validations.crash.yaml:5-16` | une identité `crash_short`, variantes d'exécution tracées 5m/1m |
| régime 4h | `validations.crash.yaml:259-264` | `price_regime_ok_short`, strict |
| contexte 1h | `validations.crash.yaml:265-269` | `crash_context_ok`, strict |
| confirmation 15m | `validations.crash.yaml:178-184,271-276` | EMA20&lt;50, sous VWAP, MACD décroissant, ATR borné |
| exécution 5m par défaut | `validations.crash.yaml:186-194,278-285` | contexte + pattern, volume et RSI floor inclus |
| timing 1m extrême | `validations.crash.yaml:196-203,293-300` | contexte + pattern complet, jamais RSI seul |
| retest/pullback | `validations.crash.yaml:205-214,302-305` | omis : OR absorbé et reprise baissière absente |
| zone d'entrée | `trade_entry.crash.yaml:189-205` | inventoriée, mais non certifiée et `unresolved` |
| stop | `trade_entry.crash.yaml:34-42` | pivot/ATR legacy non propagé avec preuve, `unresolved` |
| targets / R | `trade_entry.crash.yaml:30-49,79-85` | valeurs conflictuelles et R brut nominal, `unresolved` |
| time-stop | `trade_entry.crash.yaml:79-85` | 45 minutes imbriquées ne définissent pas l'horizon complet, `unresolved` |
| politique d'ordre | `trade_entry.crash.yaml:51-58,139-183` | maker contre fallbacks taker/market, `unresolved`, inconnu rejette |
| risque / levier | `trade_entry.crash.yaml:19-28,65-128` | multiplications multiples et ownership invalide, borné seulement par une future enveloppe |
| frais, spread, slippage, funding net | absents des deux sources | `unresolved`, inconnu rejette ; jamais 0/0 |
| invalidation, identité/durée complète | absentes des deux sources | `unresolved`, rejet fail-closed |

Les chemins et justifications machine-readable figurent dans le tableau
`provenance` du YAML `1.1.0`. Les valeurs legacy sont des sources d'audit, pas
des seuils certifiés ni une autorisation de tuning.

## Fixtures de décision

`tests/Fixtures/TradingCore/Setup/crash-short-1.1.0-scenarios.json` contient des
vecteurs de contrat uniquement. Ils ne prétendent pas qu'un évaluateur runtime
existe :

| Scénario | Verdict | Raison |
|---|---|---|
| crash complet | `pass` | correspondance de l'hypothèse complète, pas autorisation d'ordre |
| faux crash | `fail` | le contexte 4h/1h manque ; RSI seul interdit |
| mèche terminale | `fail` | la garde RSI 5m échoue |
| retest valide observé | `block` | reprise non typée et enveloppe absente |
| retest invalide | `fail` | signal haussier sans reprise baissière |

## Critères de reconsidération

Une nouvelle version sémantique pourra reconsidérer la compatibilité seulement
après :

- #303 : évaluateur typé, traces distinctes 5m/1m et, si le retest est retenu,
  trigger explicite de reprise baissière avec fixtures ;
- #304 : enveloppe propriétaire complète pour horizon, validité, risque,
  concurrence, levier, EntryZone, ordre/fill, invalidation, stop, targets et
  time-stop, sans fallback venue implicite ;
- #132 et #191 : preuves Paper certifiées, coûts complets maker/taker, spread,
  slippage et funding, puis minimum net R démontré ;
- revue risque indépendante et preuve que le profil, le setup ID et la version
  traversent les chemins levier/TP-SL sans perte ;
- comparaison explicite aux versions alors courantes des modes. Si aucune ne
  convient, l'enveloppe distincte reste une composition opérationnelle et ne
  devient toujours pas un nouveau mode par renommage.

Aucun mainnet, tuning de seuil, wiring runtime, moteur risque #304 ou évaluateur
#303 n'est autorisé par cette ADR.
