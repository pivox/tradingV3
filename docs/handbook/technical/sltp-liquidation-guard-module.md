# SLTP / LiquidationGuard module

## Objectif

PR08 introduit un module `App\TradingCore\SlTp` explicite, testable et
preparatoire.

Cette PR ne branche pas le module dans `TradeEntry`, ne modifie pas les YAML et
ne change pas le comportement runtime. Elle formalise le contrat cible pour le
stop-loss, le take-profit, la validation du plan de protection et le
`LiquidationGuard`.

## Regle de protection

Aucun plan executable ne doit etre considere valide sans stop-loss automatique.
Le stop-loss doit couvrir la taille complete de la position.

Cette regle est representee par `ProtectionPlanValidator` :

```text
ProtectionPlan invalide si stop_loss absent
ProtectionPlan invalide si stop_loss non full size
ProtectionPlan invalide si stop_pct <= 0
ProtectionPlan invalide si liquidation guard unsafe
ProtectionPlan warning si TP net incoherent
```

Le module produit aussi des warnings quand le TP net devient incoherent apres
frais, spread ou slippage. Ces warnings ne changent pas le runtime dans PR08.

## Source runtime actuelle

Le runtime legacy reste le suivant :

```text
TradingDecisionHandler
  -> TradeEntryRequestBuilder
  -> TradeEntryRequest
  -> BuildOrderPlan
  -> OrderPlanBuilder
  -> ExecutionBox / ExchangeExecutionService
  -> ProtectionEnforcer
```

Les classes effectives restent :

- `App\TradeEntry\RiskSizer\StopLossCalculator` ;
- `App\TradeEntry\RiskSizer\TakeProfitCalculator` ;
- `App\TradeEntry\Policy\LiquidationGuard` ;
- `App\TradeEntry\OrderPlan\OrderPlanBuilder` ;
- `App\TradeEntry\Execution\ExchangeExecutionService` ;
- `App\TradeEntry\Execution\ProtectionEnforcer`.

PR08 ne remplace pas ces classes dans le flux d'execution.

## Stop-loss legacy

Le stop runtime est calcule dans `OrderPlanBuilder`.

Champs legacy principaux :

| Champ | Statut PR08 | Interpretation |
| --- | --- | --- |
| `defaults.stop_from` | Runtime actuel | Source du stop : `pivot`, `atr` ou `risk`. |
| `defaults.stop_fallback` | Runtime actuel | Fallback pivot vers `atr`, `risk` ou `none` selon profil. |
| `defaults.atr_k` | Runtime actuel | Multiplicateur ATR pour distance SL. |
| `defaults.pivot_sl_policy` | Runtime actuel | Selection pivot, avec normalisation legacy `nearest_below`/`nearest_above`. |
| `defaults.pivot_sl_buffer_pct` | Runtime actuel | Buffer autour du pivot SL. |
| `defaults.pivot_sl_min_keep_ratio` | Runtime partiel | Ratio de preservation documente par la config legacy. |
| `defaults.sl_full_size` | Runtime protection / cible | Le SL doit couvrir toute la position. |

Le runtime actuel applique aussi une garde minimale de distance stop a 0.5 %
dans `OrderPlanBuilder`. PR08 documente ce comportement mais ne le deplace pas.

## Take-profit legacy

Le TP runtime est calcule en R puis peut etre aligne sur pivot.

Champs legacy principaux :

| Champ | Statut PR08 | Interpretation |
| --- | --- | --- |
| `defaults.r_multiple` | Runtime actuel | R cible principal pour le TP. |
| `defaults.tp1_r` | Runtime partiel / deux targets | R de TP1 lorsque le flux deux targets est utilise. |
| `defaults.tp_policy` | Runtime actuel | Politique TP, par exemple `pivot_conservative`. |
| `defaults.tp_buffer_pct` | Runtime actuel avec pivot | Buffer TP applique lors de l'alignement pivot. |
| `defaults.tp_min_keep_ratio` | Runtime actuel | Garde pour ne pas reduire trop fortement le R theorique. |
| `defaults.tp_max_extra_r` | Runtime actuel | Cap du surplus de R accepte par rapport au TP theorique. |

`TakeProfitCalculator` du module cible represente :

```text
tp1_price = entry +/- tp1_r * risk_distance
tp2_price = entry +/- r_multiple * risk_distance si disponible
expected_r = R effectif du TP1
expected_net_r = expected_r - couts(fees, spread, slippage) si calculable
```

PR08 ne deplace pas les TP live.

## Frais, spread et slippage

Le module transporte explicitement :

- `fees_bps` ;
- `spread_bps` ;
- `slippage_bps`.

Ces champs permettent d'estimer un `expected_net_r`. Si le R net devient
inferieur ou egal a zero, le resultat genere un warning.

PR08 ne change pas les seuils de spread, les frais exchange ou les conditions
MTF.

## LiquidationGuard

Le guard live actuel `App\TradeEntry\Policy\LiquidationGuard` verifie surtout
que la distance entre entry et stop n'est pas nulle. La formule exacte de
liquidation reste dependante des specs exchange et des donnees disponibles.

Le nouveau `App\TradingCore\SlTp\Service\LiquidationGuard` formalise le contrat
cible :

```text
entry_price
stop_price
leverage
maintenance_margin_rate si disponible
liquidation_price si fourni par l'exchange
min_distance_ratio
```

Le resultat expose :

```text
is_safe
liquidation_price
liquidation_distance_pct
stop_to_liquidation_ratio
reason_if_unsafe
warnings
```

Si aucun prix de liquidation n'est fourni et que le levier est absent, le
module ne pretend pas que le plan est safe. Il retourne `is_safe=false` avec
`insufficient_liquidation_data`.

## Ajouts PR08

Namespace ajoute :

```text
App\TradingCore\SlTp
```

DTOs et enum :

- `Dto\StopLossRequest` ;
- `Dto\StopLossResult` ;
- `Dto\TakeProfitRequest` ;
- `Dto\TakeProfitResult` ;
- `Dto\ProtectionPlan` ;
- `Dto\LiquidationCheckRequest` ;
- `Dto\LiquidationCheckResult` ;
- `Enum\ProtectionPlanStatus`.

Services :

- `Service\StopLossCalculator` ;
- `Service\TakeProfitCalculator` ;
- `Service\LiquidationGuard` ;
- `Service\ProtectionPlanValidator`.

## Non branche dans PR08

PR08 ne branche pas :

- `StopLossCalculator` TradingCore dans `OrderPlanBuilder` ;
- `TakeProfitCalculator` TradingCore dans `OrderPlanBuilder` ;
- `LiquidationGuard` TradingCore dans `BuildOrderPlan` ;
- `ProtectionPlanValidator` dans `ExecutionBox` ou `ExecutionPort` ;
- `EffectiveTradingConfigResolver` dans le runtime.

PR08 ne modifie pas :

- `mtf:run` ;
- `POST /api/mtf/run` ;
- Temporal ;
- les schedules ;
- les regles MTF ;
- les decisions `READY` / `REJECTED` ;
- EntryZone ;
- Risk / Leverage ;
- ExecutionPort ;
- les valeurs SL/TP dans les YAML ;
- Bitmart, OKX ou Hyperliquid live.

## Contraintes avant branchement live

Avant tout branchement runtime, il faudra :

- comparer les resultats SL/TP TradingCore avec `OrderPlanBuilder` sur un
  echantillon de decisions ;
- verifier que le SL reste full size dans les payloads exchange ;
- verifier les cas ou l'exchange refuse l'attachement de protection ;
- aligner le calcul de liquidation sur les specs OKX, Hyperliquid et Bitmart
  legacy ;
- integrer `ProtectionPlan` au futur `OrderPlan` cible ;
- conserver les checks `mtf:run`, `/api/mtf/run`, container lint et mkdocs.

## Suite PR09

PR09 doit traiter `OrderPlan / ExecutionPort`.

Objectif recommande :

- faire recevoir a l'execution un plan deja valide ;
- rendre impossible un `OrderPlan` cible executable sans `ProtectionPlan`
  valide ;
- isoler les mappings exchange dans les gateways ;
- garder Bitmart legacy tant que son retrait n'est pas planifie dans une PR
  dediee.

## Tests

Tests ajoutes :

- `tests/TradingCore/SlTp/StopLossCalculatorTest.php` ;
- `tests/TradingCore/SlTp/TakeProfitCalculatorTest.php` ;
- `tests/TradingCore/SlTp/LiquidationGuardTest.php` ;
- `tests/TradingCore/SlTp/ProtectionPlanValidatorTest.php`.

Ils couvrent :

- SL long sous entry ;
- SL short au-dessus entry ;
- buffer pivot SL ;
- rejet d'un SL du mauvais cote de l'entry ;
- TP en R long et short ;
- preservation de `tp1_r` et `r_multiple` ;
- representation du buffer TP ;
- warning de TP net incoherent apres couts ;
- liquidation safe ;
- liquidation unsafe ;
- liquidation indeterminee sans donnees suffisantes ;
- plan invalide sans SL ;
- plan invalide si SL non full size ;
- plan invalide si `stop_pct <= 0` ;
- plan valide avec SL full size, TP coherent et liquidation safe.
