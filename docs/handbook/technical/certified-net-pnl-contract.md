# Contrat PnL net certifie v1

Ce contrat versionne la premiere surface `position_trade_analysis_v2` capable d'exposer un PnL net certifie. Une ligne certifiee exige un matching exact deja fourni par `internal_trade_id`/FIFO, des fills complets, des quantites coherentes et toutes les composantes de cout normalisees en USDT.

## Formule

Convention de signe du funding : credit positif, debit negatif.

```text
net_pnl_usdt =
  gross_realized_pnl_usdt
  - entry_fee_usdt
  - exit_fee_usdt
  - other_trading_fees_usdt
  + funding_usdt
  - spread_cost_usdt
  - slippage_cost_usdt
  - borrow_cost_usdt
  - liquidation_fee_usdt
```

`total_known_cost_usdt` est l'impact net des couts connus sur le brut : `gross_realized_pnl_usdt - net_pnl_usdt`. Un credit de funding reduit donc ce total.

## Certification

`net_pnl_usdt` reste `NULL` sauf si toutes ces conditions sont vraies :

- `gross_realized_pnl_usdt` est present et demontre par fills ;
- tous les couts obligatoires sont presents, avec `0` uniquement quand explicitement connu/non applicable ;
- les montants sont normalises en USDT ;
- les fills d'entree et de sortie sont complets ;
- `entry_qty = exit_qty`, `remaining_qty = 0`, et `position_fully_closed=true` ;
- `quantity_status=complete` dans l'agregat `FillQuantityAggregationService` ;
- aucun `quantity_quality_flags` bloquant (`fill_conflict`, `exit_qty_exceeds_entry_qty`, `missing_entry_fill`, `missing_exit_fill`, `position_not_fully_closed`) ;
- aucun `identifier_conflict` ;
- le lineage est suffisant.

Sinon `cost_completeness` vaut `partial` ou `unknown`, `net_pnl_usdt` vaut `NULL`, et `pnl_quality_flags` liste les raisons (`missing_entry_fee`, `quantity_mismatch`, `position_not_fully_closed`, etc.).

Les valeurs numeriques legacy ou provider qui ne sont pas parseables sont traitees comme inconnues. Elles ne doivent jamais faire echouer la lecture de `position_trade_analysis_v2` et ne peuvent pas produire un PnL net certifie.

Aucun flag provider du type `quantity_coherent=true` ne remplace cette preuve quantitative. Les TP1, trailing du reliquat, scale-out, partial stop et partial entry fills doivent etre reduits au meme triplet `entry_qty`, `exit_qty`, `remaining_qty` avant certification.

## Audit des sources

| Provider | champ financier | endpoint/source | brut ou net | signe | disponibilite | granularite | fiabilite | nullable | fixture | tests |
|---|---|---|---|---|---|---|---|---|---|---|
| Fake/Paper | entry/exit fill price/qty | `FakeExchangeMatchingEngine` events + `getFillsSnapshot()` | brut fill | prix/qty positifs | au fill | fill | complet pour fixtures | non | fill event `fake_paper_fill_ledger_v1` | `FakeExchangeAdapterTest`, `NetPnlCertificationServiceTest` |
| Fake/Paper | maker/taker | metadata fill/order | classification execution | `maker`/`taker` | au fill | fill | partiel, derive ordre | oui | metadata | test service |
| Fake/Paper | fee par fill/devise | fee deterministe `notional * 0.0005`, `USDT` | cout explicite | positif | au fill | fill | complet | non | fill fee USDT | `testFakeFillsExpose...` |
| Fake/Paper | funding/spread/slippage/borrow/liquidation | lifecycle `extra` explicite fixture | couts normalises | funding credit positif/debit negatif | a la cloture | trade | complet si fourni | non pour certification | `position_trade_analysis_v2` fixture | PostgreSQL view test |
| Bitmart | order fill price/qty | `OrderDto` / `/contract/private/order*` (`deal_avg_price`, `deal_size`) | brut order | positif | apres fill/order history | ordre | partiel | oui | payload brut metadata | adapter tests existants |
| Bitmart | fee par fill/devise | `/contract/private/trades` (`paid_fees`, `fee_currency`) | cout fill | provider brut, non normalise garanti | apres REST sync | fill | partiel, pas relie au trade logique complet | oui | `FuturesOrderTrade` | projection tests partiels |
| Bitmart | realized PnL provider | `/contract/private/transaction-history` flow_type=2 | inconnu brut/net | montant provider | apres cloture | transaction | non certifie | oui | transaction raw | sync tests existants |
| Bitmart | funding | `/contract/private/transaction-history` flow_type=3, contract funding metadata | funding provider | non certifie ici | apres transaction | transaction | partiel | oui | raw transaction | aucun contrat net |
| Bitmart | spread/slippage/borrow/liquidation | logs/config ou absent | absent | n/a | n/a | n/a | non disponible | oui | aucun | aucun |
| OKX | fills price/qty/fee/devise | `/api/v5/trade/fills` | fill brut + fee | fee provider, devise fournie | REST reconciliation | fill | partiel, non relie v2 au trade complet | oui | adapter rows | `OkxExchangeAdapterTest` |
| OKX | realized/position PnL | `/api/v5/account/positions` (`realizedPnl`, `upl`) | provider, brut/net non prouve | provider | position ouverte | position | non certifie | oui | adapter row | mapping tests |
| OKX | funding/spread/slippage/borrow/liquidation | non persiste dans v2 | absent | n/a | n/a | n/a | non disponible | oui | aucun | aucun |
| Hyperliquid | fills price/qty/fee/devise | `userFills` | fill brut + fee | fee USDC | REST reconciliation | fill | partiel, devise non USDT | oui | adapter rows | adapter tests |
| Hyperliquid | position PnL | `clearinghouseState` (`unrealizedPnl`) | provider position | provider | position ouverte | position | non certifie | oui | state raw | adapter tests |
| Hyperliquid | realized/funding/spread/slippage/borrow/liquidation | non persiste dans v2 | absent | n/a | n/a | n/a | non disponible | oui | aucun | aucun |

Conclusion : seul Fake/Paper peut servir de reference complete dans ce lot. Les providers reels restent `partial` ou `unknown` tant qu'un ledger fill/cout persistant et relie au trade logique n'est pas livre.

## Ledger fills/couts

Le ledger persistant v1 est documente dans `docs/handbook/technical/fill-cost-ledger.md`.

Il introduit la table `fill_cost_ledger`, reliee au trade logique par `internal_trade_id` lorsque le lineage exact est disponible. L'idempotence est portee par `exchange + market_type + exchange_fill_id` quand l'exchange fournit un identifiant de fill, sinon par un identifiant interne deterministe documente.

Les couts absents restent `NULL`. Les rows sans lineage exact restent visibles avec `quality_flags=["missing_lineage"]` et ne doivent pas etre considerees comme net PnL certifie.

La quantite residuelle est calculee par `FillQuantityAggregationService` sur `internal_trade_id + exchange + market_type`. Le certificateur peut consommer le resultat via `certifyWithQuantityAggregation(...)`; si l'agregat n'autorise pas la certification, `net_pnl_usdt` et `realized_net_pnl_R` restent `NULL` meme lorsque les listes de fills passees au calcul semblent equilibrees.

## MFE / MAE

La vue conserve les champs historiques `mfe_pct` et `mae_pct` provenant du listener lifecycle. Leur source actuelle est best-effort via klines 1m (`high/low`) entre ouverture et cloture. Cette source n'est pas une certification microstructure : gaps, donnees manquantes, mark/mid/last et scale-in restent a versionner separement.

## Backfill

Aucun backfill heuristique n'est autorise. Les anciennes lignes peuvent exposer `recorded_pnl_usdt` ou `estimated_net_pnl_usdt`, mais `net_pnl_usdt` reste `NULL` sans les champs explicites du contrat.
