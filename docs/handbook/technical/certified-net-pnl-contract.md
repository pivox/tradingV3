# Contrat PnL net certifie v1

`position_trade_analysis_v2` est l'unique autorite publique pour le PnL net
certifie. Le ledger brut et sa vue d'agregation sont des preuves internes et des
surfaces de diagnostic : aucun export, service ou dashboard ne doit les
re-agreger pour creer une seconde certification.

Un consommateur de KPI utilise exclusivement :

- `canonical_net_pnl_usdt` pour le PnL net USDT ;
- `canonical_realized_net_pnl_r` pour le PnL net en R ;
- `cost_completeness`, `pnl_quality_flags` et `lineage_classification` pour la
  qualite et le lineage.

`recorded_pnl_usdt`, `estimated_net_pnl_usdt`, `net_pnl_usdt` non canonique et
`pnl_r` restent auditables, mais ne sont jamais des fallbacks d'un KPI certifie.

## Formule et convention de signe

Le funding est signe du point de vue de la position : un credit est positif et
un debit est negatif.

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

`total_known_cost_usdt = gross_realized_pnl_usdt - net_pnl_usdt`. Un credit de
funding diminue donc le cout total ; un debit l'augmente.

Le brut est reconstruit depuis les notionnels du ledger :

- long : `exit_notional - entry_notional` ;
- short : `entry_notional - exit_notional`.

Le risque utilise pour `canonical_realized_net_pnl_r` est exclusivement
`risk_usdt_at_entry`, capture a l'entree et strictement positif. Un deplacement
ulterieur du stop ne reecrit pas ce denominateur.

## Identite exacte de l'agregat

La vue interne `position_trade_ledger_aggregate_v1` groupe les lignes
`fill_cost_ledger` sur le tuple exact suivant :

```text
internal_trade_id
+ exchange
+ market_type
+ symbol
+ market_data_venue
+ paper_execution_cell_id
+ configuration_snapshot_id
+ paper_network
+ paper_eligibility
```

L'agregat n'est rattache a une analyse que si ce tuple correspond exactement a
l'entree et a la cloture. Les deux evenements doivent aussi porter le meme
`internal_trade_id`, le meme lineage lifecycle complet et coherent, et la meme
provenance paper. Il n'existe aucun matching par symbole seul, fenetre de temps,
position approximative ou identifiant provider ambigu.

Si le meme `internal_trade_id` expose un autre agregat sous une identite marche
ou une provenance Paper differente, la contradiction bloque la certification,
meme lorsqu'un agregat exact existe par ailleurs.

Les lignes marquees `fill_cancelled`, `fill_corrected`, `fill_reversed` ou
`voided` sont exclues de l'agregat. Toute autre ligne retenue doit avoir un
tableau `quality_flags` vide et un `fill_role` parmi `entry`, `exit` ou
`funding`. Pour un fill d'entree ou de sortie, quantite et prix doivent etre
finis et strictement positifs ; le notionnel, s'il est fourni, doit aussi etre
fini, strictement positif et coherent avec `price * quantity` a `1e-8` relatif.
L'egalite des quantites utilise une tolerance absolue de `0.00000001`.

## Champs d'execution exposes par v2

Les dix champs de synthese des fills exposes par la vue publique sont :

1. `entry_first_fill_at` ;
2. `entry_last_fill_at` ;
3. `entry_qty` ;
4. `entry_vwap` ;
5. `exit_first_fill_at` ;
6. `exit_last_fill_at` ;
7. `exit_qty` ;
8. `exit_vwap` ;
9. `remaining_qty` ;
10. `quantity_status`.

Pour une ligne certifiee, ces valeurs proviennent de l'agregat exact. Sur une
ligne non certifiee sans agregat, `entry_vwap` peut encore contenir le fallback
historique de snapshot ; cette valeur diagnostique ne constitue pas une preuve
de fill. `quantity_status` vaut `missing_entry_fill`, `open_position`,
`invalid_fill_quantity`, `quantity_mismatch`, `partial_exit` ou `complete`.
Seul `complete` donne `position_fully_closed=true`.

## Applicabilite des couts

Une absence est `NULL`, jamais un zero implicite. Lorsqu'un composant ne
s'applique pas, le producteur doit persister un `0` explicite et normalise en
USDT. Les regles sont :

- `entry_fee_usdt` et `exit_fee_usdt` : `fee_usdt` fini, positif ou nul, present
  sur chaque fill d'entree et de sortie ; les champs provider bruts
  `fee_amount`/`fee_currency` ne certifient rien a eux seuls ;
- `spread_cost_usdt` et `slippage_cost_usdt` : valeur finie, positive ou nulle,
  explicite sur chaque fill d'entree et de sortie ;
- `other_trading_fees_usdt` : valeur finie, positive ou nulle, explicite sur la
  cloture ;
- `funding_usdt` : si une ligne `funding` est persistee, chaque settlement doit
  porter un montant signe normalise ; une ligne de settlement sans montant
  bloque la certification et interdit tout fallback vers la cloture. Seule la
  somme des lignes `funding` est alors retenue : les allocations eventuelles
  portees par les fills `entry`/`exit` ne sont pas additionnees. En
  l'absence de ligne `funding`, la somme signee des fills est utilisee quand
  elle existe, sinon la valeur signee explicite de la cloture ; `0` signifie
  explicitement non applicable ou aucun funding ;
- `borrow_cost_usdt` et `liquidation_fee_usdt` : somme ledger finie, positive ou
  nulle quand elle existe, sinon valeur explicite finie, positive ou nulle de la
  cloture.

`spread_cost_usdt` et `slippage_cost_usdt` sont des couts d'execution : ils ne
peuvent etre portes que par `entry` ou `exit`. Leur presence sur une ligne
`funding` invalide le ledger.

Tous les composants doivent etre disponibles pour certifier le net. La presence
d'un PnL realise provider, d'un flag `quantity_coherent=true`, ou d'un montant
dans les extras lifecycle ne remplace ni les fills exacts ni les couts
normalises.

## Deux portes distinctes

La porte **core evidence** prouve l'execution : cloture presente, identites
interne/marche/paper/lifecycle completes et coherentes, agregat present,
quantites fermees, rows ledger valides, et sides coherents (`BUY -> SELL` pour
un long, `SELL -> BUY` pour un short). Elle autorise la projection du brut, des
frais de fills et des couts de microstructure issus du ledger.

La porte **all-cost** exige en plus que tous les couts applicables soient
explicites. Techniquement, `pnl_quality_flags` doit etre exactement `[]`. C'est
seulement a cette seconde porte que :

- `cost_completeness = complete` ;
- `net_pnl_usdt` et `realized_net_pnl_r` deviennent non nuls.

Une preuve core valide mais un cout manquant donne `partial`. Une cloture sans
preuve financiere utilisable donne `unknown`. Une position sans cloture donne
`not_applicable`. Les valeurs invalides sont masquees ; elles ne font pas
echouer la lecture de la vue.

## Flags qualite stables

`pnl_quality_flags` est une liste machine-readable. Les codes produits par ce
contrat sont stables :

- matching et provenance : `unmatched`, `missing_internal_trade_identity`,
  `missing_paper_provenance`, `ledger_market_identity_mismatch`,
  `ledger_paper_provenance_mismatch`, `ledger_quantity_aggregate_missing`,
  `ledger_lifecycle_identity_mismatch` ;
- fills et quantites : `missing_entry_fill`, `missing_exit_fill`,
  `invalid_fill_quantity`, `quantity_mismatch`, `partial_exit`,
  `ledger_unknown_fill_role`, `ledger_notional_mismatch`,
  `ledger_quality_invalid`, `ledger_side_missing`, `ledger_side_mismatch` ;
- finances : `missing_gross_pnl`, `missing_entry_fee`, `missing_exit_fee`,
  `missing_other_trading_fees`, `ledger_funding_unavailable`,
  `missing_funding`, `missing_spread_cost`, `missing_slippage_cost`, `missing_borrow_cost`,
  `missing_liquidation_fee`.

`ledger_unknown_fill_role` signale une ligne retenue hors contrat qui aurait pu
etre ignoree par les sommes. `ledger_notional_mismatch` signale un notionnel
persistant incoherent avec son fill. `ledger_funding_unavailable` distingue une
ligne de settlement presente mais non normalisee d'une absence de settlement ;
les trois bloquent le net certifie.

Les consommateurs doivent exposer ces codes sans les supprimer ni en reclasser
un sous-ensemble comme non bloquant. Un tableau non vide bloque toujours le net
certifie.

## Separation du lineage #302

La certification financiere #190 et le lineage canonique #302 sont deux preuves
orthogonales. La premiere est calculee dans la source interne de v2 ; le wrapper
public #302 classe ensuite la ligne `canonical`, `legacy` ou `incomplete` depuis
l'identite lifecycle.

Le wrapper n'expose `canonical_net_pnl_usdt` et
`canonical_realized_net_pnl_r` que si `lineage_classification = canonical`.
Ainsi, meme une ligne financierement complete ne devient pas un KPI canonique
si le mode, le setup, le side, les versions/hashes, les identifiants de decision,
la provenance paper ou la coherence entree/cloture sont absents ou divergents.

Pour les campagnes de certification, la cellule exacte est :

```text
paper_network x market_data_venue x mode_id x setup_id x canonical_side
```

`mode_version`, `setup_version`, `canonical_config_hash` et
`condition_catalog_hash` restent exportes pour l'audit exact. Une cellule de
moins de 50 trades certifies reste sous-echantillonnee et est exclue des KPI ;
elle ne doit jamais etre fusionnee avec une autre cellule pour atteindre le
minimum.

## Statut des providers

| Provider | Evidence disponible aujourd'hui | Statut de certification |
| --- | --- | --- |
| Fake/Paper | fills persistants, frais USDT, spread/slippage explicites, funding signe et provenance paper versionnee dans les scenarios complets | peut etre `complete` uniquement quand chaque preuve du contrat est presente |
| Bitmart | fills/frais et transactions provider disponibles partiellement ; couts et rattachement au trade logique pas toujours complets | `partial` ou `unknown` tant que le ledger normalise exact est incomplet |
| OKX | fills, frais/devise et PnL provider disponibles, mais chaine de couts v2 complete non persistee | `partial` ou `unknown` |
| Hyperliquid | fills et frais USDC disponibles, mais USDC n'est pas implicitement traite comme USDT et les autres couts ne sont pas tous persistes | `partial` ou `unknown` |

Le provider n'est pas en lui-meme un critere de confiance. Toute execution,
Fake comprise, certifie uniquement avec des fills et couts complets, finis,
normalises en USDT et relies par l'identite exacte. Un provider reel pourra
devenir `complete` sans changer la formule des qu'il persistera cette evidence.

## Regle pour les consommateurs

Une ligne entre dans un KPI certifie seulement si toutes les conditions
suivantes sont vraies :

```text
lineage_classification = canonical
analysis_status = matched_closed
close_match_status = matched
cost_completeness = complete
pnl_quality_flags = []
position_fully_closed = true
canonical_net_pnl_usdt IS NOT NULL
canonical_realized_net_pnl_r IS NOT NULL
```

Le rapport `bad-trades-baseline-v2.sql` applique exactement ce contrat. Il peut
joindre `order_intent` et `trade_zone_events` pour enrichir le diagnostic, mais
il ne lit pas `fill_cost_ledger`, ne recertifie aucune ligne et ne remplace
jamais un canonical net absent par `recorded_pnl_usdt` ou
`estimated_net_pnl_usdt`.

## MFE / MAE et backfill

Les champs MFE/MAE conservent leur contrat separe. Seule une qualite
`mfe_mae_data_quality=complete` et une provenance temporelle complete permettent
une interpretation forte ; ils ne participent pas a la certification du PnL
net.

Pour les nouveaux trades disposant d'un ledger complet, la fenêtre est désormais
fixée par `entry_first_fill_at` et `exit_last_fill_at`, et le prix de référence est
le VWAP des fills d'entrée. Le lifecycle persiste explicitement
`mfe_mae_window_source=fill_cost_ledger_v1` et
`mfe_mae_entry_price_source=fill_cost_ledger_v1`. La vue ne conserve une qualité
`complete` que si ces deux provenances et les deux bornes correspondent exactement
à l'agrégat ledger. Une ancienne fenêtre provider ou une fenêtre incohérente reste
visible mais devient `partial`; ses valeurs d'excursion ne sont pas projetées comme
preuves fortes.

`holding_time_sec` suit la même règle et vaut exactement
`exit_last_fill_at - entry_first_fill_at` lorsque la quantité est complètement
fermée. `holding_time_source` expose `fill_cost_ledger_v1`. Une sortie antérieure au
premier fill d'entrée ajoute `ledger_fill_chronology_invalid`, masque le PnL net
canonique et interdit donc la certification.

Aucun backfill heuristique n'est autorise. Les anciennes lignes restent
visibles pour l'audit, mais leurs montants enregistres ou estimes ne deviennent
pas canoniques sans evidence ledger complete et lineage #302 canonique.
