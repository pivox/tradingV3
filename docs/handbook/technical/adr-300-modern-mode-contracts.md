# ADR-300 — Contrats des modes modernes

Statut : accepté pour la frontière de contrat, non branché au runtime

Date : 2026-08-01

Issues liées : #300, puis #301, #310, #133, #302, #309

## Décision

La première vague contient exactement trois identités : `day_trading`,
`scalping` et `micro_scalping`. Leur première version est `1.0.0`. Le couple
`mode_id` / `mode_version` est une identité exacte : une valeur inconnue est
refusée et il n'existe ni alias, ni pointeur de compatibilité, ni recherche de
« dernière version ».

Les identifiants historiques `regular`, `scalper` et `scalper_micro` restent
des entrées du runtime historique. Ils ne sont jamais acceptés par
`ModeContractLoader` et ne sont pas convertis vers les nouvelles identités.
Le loader moderne lit uniquement
`trading-app/config/trading/mode_contract/{mode_id}/{mode_version}.yaml`. Les
fichiers `config/trading/mode/*.yaml` restent des couches legacy préparatoires
du `EffectiveTradingConfigResolver`; ils ne sont pas des contrats modernes.

`crash_short` reste une classification indécise jusqu'à #310. `swing_trading`
est une recherche séparée de #309. Aucun des deux n'est un mode exécutable de
cette vague.

Tous les contrats publiés ici sont `draft` et `executable: false`. Une limite
inconnue n'est pas remplacée par une valeur plausible : elle est matérialisée
par `{state: unresolved, value: null, unit, source, justification}`. Le document
reste structurellement complet, mais `ModeContract::isExecutable()` retourne
faux tant qu'une contrainte reste non résolue. Cela ne constitue ni un verdict
de profitabilité ni une autorisation d'écriture mainnet. Les écritures mainnet
restent interdites.

## Représentation et consommateurs

Le JSON Schema 2020-12
`trading-app/config/trading/schema/mode-contract.schema.json` est le contrat
inter-langages pour Symfony, l'orchestrateur Python et le backtest. Il ferme les
objets avec `additionalProperties: false`, fixe les enums et exige tous les
blocs. Les fixtures JSON montrent un document valide et le rejet d'un alias
legacy.

Symfony utilise `ModeContractLoader`, `ModeContractValidator` et le value object
readonly `ModeContract`. Le validateur PHP reproduit les invariants essentiels
du schéma et ajoute les invariants de domaine : namespace des setups, statut
draft/retired non exécutable, données manquantes refusées, version SemVer exacte
et absence de champs inconnus. #133 pourra brancher cette API; #300 ne re-route
aucun flux de trading.

Un consommateur Python valide d'abord le document contre le schéma, puis exige
le couple exact demandé. Il ne doit jamais utiliser `dict.update` récursif pour
fabriquer un mode effectif.

## Ownership sans recouvrement

| Owner | Paramètres possédés |
|---|---|
| Base | schéma, unités canoniques, conventions temporelles et barrières de sécurité ultimes |
| Portfolio | exposition agrégée inter-modes, capital agrégé et corrélation |
| Mode | horizon/session, rôles timeframe, cadence/validité, budget par trade, perte journalière, concurrence, exposition **du mode**, cap de levier demandé et politique d'ordre |
| Setup | hypothèse, côté, régime admissible, trigger, invalidation, zone d'entrée, stop et targets |
| Exchange | capacités d'ordre/marge, frais, funding, précision, ticks, tailles et limites exchange |
| Environment | allowlist, plafond notionnel d'environnement, dry-run, autorisation d'écriture et kill switch |

Le cap d'exposition d'un mode ne remplace pas le cap agrégé portfolio. Le cap de
levier demandé par un mode est encore borné par la capacité exchange et la
barrière base. Une politique d'ordre exprime une préférence; elle ne déclare
pas une capacité exchange. Aucun même paramètre ne peut apparaître chez deux
owners.

## Composition

La composition est nominative et monotone, pas un deep merge arbitraire :

1. le contrat base fournit unités, schéma et gardes ultimes immuables ;
2. un contrat mode exact fournit son enveloppe opérationnelle ;
3. un setup dont l'ID figure dans `compatible_setup_ids` fournit seulement les
   paramètres Setup ;
4. un contrat exchange vérifie capacités/limites et peut uniquement resserrer
   une demande ;
5. le portfolio peut uniquement resserrer risque/exposition ;
6. l'environnement peut désactiver, réduire le notionnel ou restreindre
   l'allowlist, jamais relâcher une garde ;
7. toute collision d'owner, clé inconnue, unité incompatible ou valeur absente
   provoque un rejet.

Une nouvelle valeur exige une nouvelle version du contrat owner. Le rollback
épingle explicitement une version approuvée; il n'existe pas de fallback
automatique. Le résolveur legacy `base < mode < exchange < mode_exchange < env`
n'est donc pas réutilisé à cette frontière.

## Lifecycle, promotion, suspension et rollback

Le lifecycle autorise `draft`, `shadow`, `paper`, `candidate`, `active` et
`retired`. `draft` et `retired` sont toujours non exécutables. Chaque contrat
contient ses règles, avec le minimum commun suivant :

- promotion vers `shadow` seulement avec preuves #132/#191 attachées, revue
  risque indépendante et zéro contrainte non résolue ;
- suspension immédiate lors d'un kill switch, d'une rupture du data contract,
  du cap journalier ou d'une invalidation des preuves ;
- rollback vers une version explicitement nommée après régression de promotion,
  rupture d'invariant risque ou défaut de schéma/provenance ;
- promotion et correction publient une nouvelle version SemVer; une version
  publiée n'est pas mutée silencieusement.

Les transitions au-delà de `shadow` devront conserver leurs preuves et
approbations. Aucun seuil de cette ADR n'est qualifié de profitable avant les
résultats certifiés de #132/#191.

## Matrice des sources et provenance

Chaque ligne donne la source, l'unité et la raison de la reprise. Les chemins
exacts sont aussi présents dans le tableau `provenance` de chaque YAML.

| Mode / valeur | Source | Unité | Justification |
|---|---|---|---|
| `day_trading` régime `4h`, contexte `1h` | `validations.regular.yaml:7-12` | timeframe | Deux contextes actifs, du plus haut au plus bas. |
| `day_trading` trigger/default `15m`, descente `5m/1m` | `validations.regular.yaml:7-12`, `trade_entry.regular.yaml:125-147` | timeframe | `15m` est le défaut; les exécutions autorisées incluent `15m/5m/1m`. |
| `day_trading` risque `5%`, caps jour `6%` et `30 USDT`, concurrence `4` | `trade_entry.regular.yaml:77-84` | percent equity/trade, percent equity/day, USDT/day, positions | Extraction directe de `risk`; aucune affirmation de performance. |
| `scalping` régime `1h`, contexte `15m` | `validations.scalper.yaml:7-14` | timeframe | Liste active des contextes. |
| `scalping` trigger/default `5m`, exécution `5m/1m` | `validations.scalper.yaml:7-14` | timeframe | Valeurs explicites actives. |
| `scalping` risque par trade non résolu (`7%` defaults contre `2%` risk), caps jour `6%` et `40 USDT`, concurrence `6` | `trade_entry.scalper.yaml:26,73-78`, `src/TradeEntry/Builder/TradeEntryRequestBuilder.php:80` | percent equity/trade, percent equity/day, USDT/day, positions | Le conflit du consommateur actif est conservé comme contrainte non résolue; aucune affirmation de performance. |
| `micro_scalping` contexte/régime `5m`, trigger/exécution `1m` | `validations.scalper_micro.yaml:10-18` | timeframe | Seules les lignes actives comptent; le bloc `15m` commenté n'est pas un comportement. |
| `micro_scalping` risque `0.4%`, caps jour `2.5%` et `50 USDT`, concurrence `3` | `trade_entry.scalper_micro.yaml:29-35,79-83` | percent equity/trade, percent equity/day, USDT/day, positions | Extraction directe; aucune affirmation de performance. |
| Setups compatibles | issue #300, catalogue anticipé de #301 | setup ID | Identités stables uniquement; les hypothèses et règles restent hors scope. |
| Horizon de détention, session, cadence, validité, exposition mode, levier moderne | absence de source propriétaire non ambiguë; décisions #132/#191/portfolio | unités indiquées dans les YAML | Contraintes draft explicites et non exécutables, sans déduire l'horizon depuis le nom du mode ou ses timeframes. |

## Catalogue compatible réservé à #301

- `day_trading.trend_continuation.long`
- `day_trading.trend_continuation.short`
- `scalping.trend_continuation.long`
- `scalping.pullback.long`
- `scalping.trend_momentum.short`
- `micro_scalping.momentum_ofi.long`
- `micro_scalping.momentum_ofi.short`

Cette liste ne définit pas les setups. Hypothèse, side, régime, trigger,
invalidation, zone, stop et targets seront contractés dans #301.
