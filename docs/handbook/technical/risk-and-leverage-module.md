# Risk / Leverage module

## Objectif

PR07 introduit un module `App\TradingCore\Risk` explicite, testable et
preparatoire.

Cette PR ne branche pas le module dans `TradeEntry`, ne modifie pas les YAML et
ne change pas le comportement runtime. Elle formalise les contrats cibles pour
le calcul du risque, de la taille de position et du levier derive du stop.

## Source de verite actuelle

Le runtime legacy utilise encore le flux suivant :

```text
TradingDecisionHandler
  -> TradeEntryRequestBuilder
  -> TradeEntryRequest
  -> OrderPlanBuilder
  -> DynamicLeverageService
  -> ExecutionBox / ExchangeExecutionService
```

La source effective du risque pour la taille de position est actuellement :

```text
trade_entry.defaults.risk_pct_percent
  -> TradeEntryRequestBuilder
  -> TradeEntryRequest::riskPct
  -> OrderPlanBuilder
```

`OrderPlanBuilder` calcule ensuite :

```text
available_budget = min(initial_margin_usdt, preflight.available_usdt)
risk_usdt = available_budget * TradeEntryRequest::riskPct
size = floor(risk_usdt / (stop_distance * contract_size))
```

`risk.fixed_risk_pct` existe dans certains YAML historiques, mais il n'est pas
la source branchee du runtime TradeEntry actuel pour la taille de position.
Le nouveau `RiskConfigInterpreter` documente donc cette ambiguite au lieu de la
masquer.

## Champs legacy

| Champ | Statut PR07 | Interpretation |
| --- | --- | --- |
| `defaults.risk_pct_percent` | Source runtime actuelle | Converti en ratio dans `TradeEntryRequestBuilder`, puis utilise par `OrderPlanBuilder`. |
| `risk.fixed_risk_pct` | Canonical cible / non branche runtime | Champ explicite du module cible. A documenter comme non effectif tant que TradeEntry n'est pas migre. |
| `defaults.initial_margin_usdt` | Runtime actuel | Budget nominal borne par le solde disponible preflight. |
| `defaults.fallback_account_balance` | Runtime actuel partiel | Utilise seulement si `initial_margin_usdt <= 0`, pour reconstruire une marge initiale de fallback. |
| `defaults.stop_from` | Runtime actuel | Peut etre `risk`, `atr` ou `pivot` selon profil. |
| `defaults.atr_k` | Runtime actuel | Utilise pour le stop ATR et pour les approximations de levier dans certains contextes MTF. |
| `leverage.exchange_cap` | Runtime actuel | Cap config en plus des caps exchange preflight. |
| `leverage.per_symbol_caps` | Runtime actuel | Caps regex par symbole dans `DynamicLeverageService`. |
| `defaults.timeframe_multipliers` | Runtime actuel | Multiplicateur applique au levier dynamique dans `DynamicLeverageService`. |
| `leverage.timeframe_multipliers` | Runtime actuel | Multiplicateur applique plus tard a la taille et au levier dans les couches execution. |
| `leverage.confidence_multiplier` | Documente / peu ou pas branche | Garde pour contrat cible, sans effet PR07. |
| `leverage.conviction` | Documente / peu ou pas branche | Garde pour contrat cible, sans effet PR07. |
| `leverage.max_loss_pct` | Runtime execution | Cappe le multiplicateur de timeframe via taille max autorisee au SL. |
| `leverage.rounding.mode` | Runtime actuel | `ceil`, `floor` ou `round` selon etape. |

## Formule cible

Le module cible rend explicite :

```text
effective_risk_pct = fixed_risk_pct ou legacy risk_pct_percent
risk_usdt = capital_base * effective_risk_pct
stop_pct = abs(entry_price - stop_price) / entry_price, ou stop_pct fourni
position_notional = risk_usdt / stop_pct
quantity = position_notional / entry_price
raw_leverage = risk_pct / stop_pct
final_leverage = raw_leverage
  * timeframe_multiplier
  * liquidity_multiplier
  puis caps, floor et rounding
```

Dans le module pur, `fixed_risk_pct` est le champ canonique quand il est fourni.
Le mapper legacy peut cependant choisir de ne pas le passer au calcul tant que
le runtime reste base sur `defaults.risk_pct_percent`.
Dans PR07, `RiskConfigInterpreter` marque explicitement les requetes issues du
runtime legacy pour que `PositionSizer` preserve `defaults.risk_pct_percent`
quand les deux champs sont presents.

## Formule actuelle si differente

Le calcul live actuel contient des etapes supplementaires :

- le stop peut venir du risque, de l'ATR ou d'un pivot ;
- un stop pivot trop loin peut fallback vers ATR ou risk ;
- une garde minimale de distance stop a 0.5 % peut elargir le stop ;
- la taille est quantifiee et bornee par `minVolume`, `maxVolume` et
  `marketMaxVolume` ;
- le levier peut etre ajuste ensuite pour rapprocher la marge initiale de la
  marge cible ;
- les couches execution peuvent encore appliquer `leverage.timeframe_multipliers`
  et `max_loss_pct`.

PR07 ne deplace pas ces etapes.

## Caps de levier

Le nouveau `LeverageCapResolver` formalise les caps suivants :

```text
exchange_cap
profile_cap
symbol_cap
```

Le runtime actuel applique principalement :

- cap preflight exchange via `PreflightReport::maxLeverage` ;
- `TradeEntryRequest::leverageExchangeCap`, issu de `leverage.exchange_cap` ;
- `leverage.exchange_cap` dans `DynamicLeverageService` ;
- caps regex `leverage.per_symbol_caps` ;
- `floor` ;
- min/max exchange ;
- rounding.

`profile_cap` est present dans le contrat cible pour les futures configs
effectives, mais il n'est pas branche dans PR07.

## max_loss_pct

`leverage.max_loss_pct` n'est pas applique au `raw_leverage` dans le plan
initial.

Il intervient plus tard dans `ExecutionBox` et `ExchangeExecutionService` pour
limiter le multiplicateur de timeframe :

```text
max_loss_usdt = initial_margin_usdt * max_loss_pct
risk_per_contract = abs(entry - stop) * contract_size
max_size_allowed = floor(max_loss_usdt / risk_per_contract)
effective_multiplier = min(tf_multiplier, max_size_allowed / plan.size)
```

Le module PR07 le transporte et genere un warning pour rappeler qu'il s'agit
d'un cap execution-time, pas d'un composant du `raw_leverage`.

## Liquidation guard

Le guard live actuel (`App\TradeEntry\Policy\LiquidationGuard`) verifie surtout
que la distance entre entry et stop n'est pas nulle. La formule exacte de
liquidation reste a brancher.

PR07 ne modifie pas ce guard. La suite logique est PR08, qui doit traiter SLTP
et `LiquidationGuard` avec un contrat dedie.

## Contraintes avant branchement live

Avant de brancher ce module dans `TradeEntry`, il faudra :

- comparer les resultats du module avec les plans legacy sur un echantillon de
  decisions ;
- verifier que `defaults.risk_pct_percent` et `risk.fixed_risk_pct` ne creent
  pas deux sources contradictoires ;
- decider explicitement si le runtime migre vers `risk.fixed_risk_pct` ou garde
  `defaults.risk_pct_percent` comme source effective ;
- couvrir les ajustements de marge de `OrderPlanBuilder` ;
- couvrir les multipliers execution et `max_loss_pct` ;
- verifier que le levier final ne peut pas augmenter par rapport au legacy ;
- conserver les checks `mtf:run`, `/api/mtf/run`, container lint et mkdocs.

## Ajouts PR07

Namespace ajoute :

```text
App\TradingCore\Risk
```

DTOs et enum :

- `Dto\RiskCalculationRequest` ;
- `Dto\RiskCalculationResult` ;
- `Dto\LeverageCalculationRequest` ;
- `Dto\LeverageCalculationResult` ;
- `Enum\RiskSource`.

Services :

- `Service\PositionSizer` ;
- `Service\LeverageCalculator` ;
- `Service\RiskConfigInterpreter` ;
- `Service\LeverageCapResolver`.

## Non branche dans PR07

PR07 ne branche pas :

- `PositionSizer` TradingCore dans `OrderPlanBuilder` ;
- `LeverageCalculator` TradingCore dans `DynamicLeverageService` ;
- `RiskConfigInterpreter` dans `TradeEntryRequestBuilder` ;
- `EffectiveTradingConfigResolver` dans le runtime ;
- `LiquidationGuard` cible.

PR07 ne modifie pas :

- `mtf:run` ;
- `POST /api/mtf/run` ;
- Temporal ;
- les schedules ;
- les regles MTF ;
- les decisions `READY` / `REJECTED` ;
- EntryZone ;
- SL / TP ;
- ExecutionPort ;
- les valeurs risk/leverage dans les YAML ;
- Bitmart, OKX ou Hyperliquid live.

## Tests

Tests ajoutes :

- `tests/TradingCore/Risk/RiskConfigInterpreterTest.php` ;
- `tests/TradingCore/Risk/PositionSizerTest.php` ;
- `tests/TradingCore/Risk/LeverageCalculatorTest.php`.

Ils couvrent :

- source runtime legacy `defaults.risk_pct_percent` ;
- fallback `risk.fixed_risk_pct` si la source legacy est absente ;
- warning quand deux champs de risque coexistent ;
- sizing depuis `risk_usdt / stop_pct` ;
- rejet d'un `stop_pct` nul ;
- levier brut `risk_pct / stop_pct` ;
- caps exchange/profil/symbole ;
- floor et rounding ;
- representation de `max_loss_pct` sans changer le calcul initial ;
- absence de levier arbitraire sans stop valide.

## #304 Lot A - autorite de risque canonique

Le namespace `App\TradingCore\Risk\Canonical` est la nouvelle autorite pure du
chemin moderne. Les classes historiques `PositionSizer`, `LeverageCalculator`
et `RiskConfigInterpreter` restent reservees au chemin legacy explicite ; elles
ne sont ni adaptees ni utilisees comme fallback par le moteur canonique.

`CanonicalRiskPolicyCompiler` lit exclusivement le snapshot effectif immuable,
recalcule son hash canonique avec le hash du catalogue de conditions et rejette
toute divergence. Le constructeur de la politique est prive : aucun appelant
ne peut injecter directement un taux interne sans passer par cette compilation.
Il exige `risk.trade_budget` avec l'unite
`percent_equity_per_trade`, puis convertit les points de pourcentage une seule
fois : `0.4` devient le taux interne `0.004`. Les alias historiques et toute
seconde source de risque sont rejetes.

`CanonicalRiskEngine` dimensionne ensuite la quantite a partir de la perte
totale au stop :

```text
risk_budget_quote = equity_quote * risk_rate
total_stop_loss = gross_stop_loss
  + entry_fee + stop_exit_fee
  + spread_cost + slippage_cost + adverse_funding_cost
```

La quantite est bornee par les caps de volume, de notional et de levier, puis
arrondie vers le bas au pas exchange. Le minimum notional exchange n'est jamais
atteint en arrondissant la quantite vers le haut : le plan est rejete. Les taux
de frais d'entree et de sortie doivent correspondre au bareme maker/taker du
snapshot compile. Tous les composants sont recalcules avec
la quantite finale. Une decision n'est produite que si la perte finale reste
inferieure ou egale au budget et si le levier entier final reste inferieur ou
egal a chacun des caps applicables. Aucun multiplicateur de timeframe,
confiance ou liquidite n'existe dans ce contrat.

Les couts inconnus sont rejetes ; une valeur zero doit etre fournie
explicitement lorsqu'un cout vaut reellement zero. Les roles maker/taker de
l'entree et du stop sont explicites et les taux de frais sont derives du bareme
compile. Spread et slippage sont comptabilises separement a l'entree et a la
sortie stop. Le funding positif est adverse au long, le funding negatif au
short. Les pas de quantite inferieurs a `1e-12` ou non representables avec au
plus 12 decimales sont rejetes. Les minima et tous les caps
sont reverifies apres quantification sur une grille entiere mise a l'echelle.

Les autorites pures des Lots A, B et C ne sont pas encore branchees au runtime
TradeEntry. Les blockers modernes restent donc en place jusqu'aux versions de
mode resolues et a leur integration explicite. Mainnet reste public/read-only
et aucune execution privee mainnet n'est activee.

## #304 Lot B - EntryZone et OrderPlan canoniques

Le namespace `App\TradingCore\OrderPlan\Canonical` consomme exclusivement un
`EffectiveTradingConfigSnapshot` executable et authentifie. Le compilateur
exige des decisions `defined` pour l'EntryZone, le stop, les targets, le R net,
l'invalidation, le time stop et le contrat de couts. Les formes et unites sont
exactes : une cle supplementaire, un alias legacy, une valeur non resolue ou
une modification non couverte par `config_hash` rejette la politique.

L'EntryZone est calculee depuis des observations horodatees portant venue,
symbole, source, timeframe et hash d'entree. L'ancre et l'ATR doivent
correspondre exactement au contrat compile. Les donnees futures ou trop
anciennes sont rejetees. La largeur ATR est bornee, l'asymetrie est appliquee
selon le side, puis les limites sont quantifiees vers l'exterieur. Le prix
d'entree est quantifie de maniere conservative et doit rester dans la zone. Le
resultat conserve les timestamps d'observation, calcul et expiration ainsi que
le lineage complet des inputs.

La protection possede deux branches exclusives :

- un stop ATR exige l'observation ATR exacte et interdit un pivot concurrent ;
- un stop pivot exige l'identifiant exact et interdit tout fallback ATR.

Le stop est quantifie en s'eloignant de l'entree. Les targets sont derivees de
la distance exacte entre l'entree et le stop quantifies, puis quantifiees vers
l'entree. La polarite long/short est reverifiee apres ces arrondis.

Le calcul de R net utilise la meme decision de sizing et le meme snapshot de
couts que le chemin stop : frais maker/taker authentifies, spread et slippage
explicites pour entree/stop/chaque target, funding adverse et nombre
d'intervalles derive du time stop. Le calcul decimal rejette le plan complet si
une seule target passe son R brut mais reste sous `minimum_net_r` apres couts.

`CanonicalOrderPlanBuilder` assemble enfin uniquement les decisions acceptees.
Le plan immuable est toujours `limit`, contient les versions mode/setup, le
hash de config, les hashes d'inputs, les timestamps, les caps, tous les couts et
le R net de chaque target. Son propre hash couvre ce contenu. Le validateur
final recalcule expiration, containment de zone, grilles prix/quantite,
polarite, budget de risque, caps de levier/notional et minimum de R net.

Ce Lot B est une autorite pure et non branchee : il ne depend pas de
`TradeEntryConfig`, `ExecutionBox`, des mappers legacy, de Doctrine, Messenger
ou d'un provider. Il ne rend aucun setup actuellement non resolu executable,
ne retire aucun blocker portefeuille tant que le contrat correspondant reste
non resolu et n'active aucune execution mainnet.

## #304 Lot C - portefeuille, reservations et partial fills

Le namespace `App\TradingCore\Risk\Canonical\Portfolio` compile une politique
portefeuille uniquement lorsque le contrat effectif definit explicitement les
deux caps journaliers, la devise quote, la timezone et la frontiere du jour,
le traitement de l'unrealized, la concurrence incluant ou non les ordres en
attente, et le cap d'exposition du mode. Les contrats `1.0.0` actuels ne
possedent pas toutes ces decisions : ils restent bloques et aucune valeur par
defaut n'est inferee.

Le snapshot atomique est scope par reseau, venue, environnement, compte, mode
et devise. Il conserve sa source/version, son horodatage, son hash et sa version
d'etat. L'admission utilise le plus restrictif du cap journalier en pourcentage
et du cap absolu, ajoute le risque deja reserve, compte les positions et entrees
pending selon le contrat, puis projette l'exposition du plan. Le plan, son
equity et sa devise doivent correspondre exactement au snapshot frais.

Une admission acceptee produit une reservation liee au `decisionKey`, au
`configHash`, au `planHash`, au hash portefeuille et a sa version compare-and-
swap. Le store de reference persiste plan et reservation dans la meme operation.
Chaque fill est lie au meme scope et recalcule en decimal exact risque rempli,
risque residuel, notionals, frais et quantite protegee. Un reliquat hors budget
est reduit ou annule ; une quantite remplie non protegee ou deja hors budget
demande la compensation et interdit tout fill supplementaire. Cancel et close
liberent les reservations de facon idempotente. La quantite encore ouverte sur
la venue reste distincte du reliquat autorise jusqu'a l'accuse de reduction :
les fills recus pendant cette fenetre sont donc toujours comptabilises. Chaque
transition porte le hash exact de son etat precedent avant le compare-and-swap.
Un fill confirme ayant deja traverse le stop est lui aussi comptabilise, puis
force immediatement la compensation ; il n'est jamais ignore comme input invalide.
La distance brute au stop est accumulee fill par fill afin qu'un fill traverse
ne puisse jamais annuler le risque deja charge par un fill precedent.

La decision d'admission possede un constructeur prive. Sa seule factory publique
rejoue l'evaluateur canonique avec la request complete ; un appelant ne peut donc
pas fabriquer une reservation en recopiant seulement le plan et une state version.
Sa serialisation et son hydration PHP sont interdites explicitement pour que
`unserialize()` ne puisse pas contourner cette frontiere readonly.

Les adapters minces runtime, Fake, Paper et backtest consomment le meme snapshot
et deleguent tous au meme
moteur et au meme store atomique. Les golden tests imposent des hashes d'admission
et d'etat identiques, ainsi que les memes reason codes, pour des inputs identiques.
Ils n'importent aucune couche legacy ni port d'execution prive mainnet.
