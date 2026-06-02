# TradeEntry et Risque

TradeEntry transforme un signal `READY` en plan executable.

## Workflow

| Etape | Objet | Role |
| --- | --- | --- |
| Requete | `TradeEntryRequest` | Symbol, side, timeframe, profil, dry-run et contexte MTF. |
| Preflight | `BuildPreOrder` | Specs exchange, balance, carnet, pivots, mark price. |
| Plan | `BuildOrderPlan` | Entry zone, prix candidat, stop, TP, sizing, levier. |
| Execution | `ExecuteOrderPlan` | Levier, ordre principal, watchers et protections. |
| Protection | `AttachTpSl` / `TpSlTwoTargetsService` | Stop-loss et take-profit. |
| Surveillance | `LimitFillWatchMessage`, `OutOfZoneWatchMessage`, `CancelOrderMessage` | Annulation, fallback et timeout. |

## Entry zone

La zone d'entree combine:

- pivot principal (`vwap`, `sma21` ou autre ancre configuree);
- largeur ATR bornee par `w_min` et `w_max`;
- biais asymetrique selon le side;
- quantification au tick exchange;
- TTL de validite.

Le prix final est rejete si la zone est trop eloignee du mark/current book selon `zone_max_deviation_pct`.

## Risque

| Concept | Description |
| --- | --- |
| `risk_pct_percent` | Pourcentage du capital risque par trade. |
| `r_multiple` | Objectif de rendement relatif au risque. |
| `stop_from` | Source du stop: ATR, pivot ou politique hybride. |
| `pivot_sl_policy` | `nearest`, `strongest`, `s1..s6`, `r1..r6`. |
| levier dynamique | Calcule selon distance stop, marge disponible et caps config. |

## Persistences liees

- `order_intent`;
- `order_protection`;
- `futures_order`;
- `futures_plan_order`;
- `trade_lifecycle_event`;
- `trade_zone_events`;
- `entry_zone_live`.
