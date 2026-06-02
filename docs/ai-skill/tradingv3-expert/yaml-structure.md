# YAML Structure - TradingV3 Expert

## Objectif

Modifier les YAML TradingV3 sans casser la separation entre signal, validation, risk, entry et exchange constraints.

## Types de YAML

Identifier le fichier cible avant modification :

- Validations MTF : conditions, seuils, context filters.
- Trade entry : entry zone, stop, take profit, risk profile.
- MTF contracts : timeframes, orchestration, profils.
- Defaults : valeurs de fallback et limites globales.
- Overrides symboles : exceptions documentees.

## Regles

- Ne pas dupliquer un seuil dans plusieurs blocs sans raison.
- Documenter tout override symbole.
- Garder les noms de condition alignes avec `ConditionRegistry`.
- Preferer des seuils explicites a des fallbacks caches.
- Ajouter une version de profil si le comportement change.
- Inclure une note rollback pour les changements experimentaux.

## Clefs critiques

Surveiller :

- `trade_entry.defaults.max_deviation_pct`
- `trade_entry.defaults.implausible_pct`
- `trade_entry.defaults.zone_max_deviation_pct`
- `trade_entry.entry.entry_zone.from`
- `trade_entry.entry.entry_zone.offset_atr_tf`
- `trade_entry.entry.entry_zone.k_atr`
- `trade_entry.entry.entry_zone.w_min`
- `trade_entry.entry.entry_zone.w_max`
- `trade_entry.entry.entry_zone.max_deviation_pct`
- `trade_entry.entry.entry_zone.asym_bias`
- `trade_entry.entry.entry_zone.ttl_sec`
- `post_validation.entry_zone.vwap_anchor`
- `post_validation.entry_zone.k_atr`
- `post_validation.entry_zone.w_min`
- `post_validation.entry_zone.w_max`
- `post_validation.entry_zone.ttl_sec`
- `pivot_sl_policy`

## Conditions

Avant d'ajouter une condition YAML :

1. Verifier que la condition existe en PHP.
2. Verifier ses parametres et defaults.
3. Verifier les logs debug disponibles.
4. Ajouter un test ou fixture si le comportement est nouveau.

Refuser :

- Condition generique manquante comme `gt` si non enregistree.
- Condition long/short ambigue.
- Condition dependant d'un indicateur absent du snapshot.

## Entry zone

Verifier coherence entre :

- `entry.entry_zone`
- `post_validation.entry_zone`
- Defaults globaux
- Runtime `OrderPlanBuilder`

Toute modification doit indiquer :

- Effet attendu sur largeur zone.
- Effet attendu sur skips out of zone.
- Effet attendu sur fills et slippage.
- Fenetre de validation post-run.

## Risk YAML

Tout changement risk doit inclure :

- Ancienne valeur.
- Nouvelle valeur.
- Justification.
- Backtest/OOS attendu.
- Rollback si drawdown ou winrate degrade.

## Diff attendu

Un bon diff YAML :

- Est petit.
- Change une seule hypothese.
- Ajoute un commentaire utile si experimental.
- Met a jour la version du profil.
- Inclut une commande de validation ou rapport.

## Issue template YAML

```markdown
## YAML Change
- File:
- Profile:
- Old behavior:
- New behavior:

## Hypothesis

## Risk Impact

## Validation
- Backtest:
- Forward:
- Logs:

## Rollback
```
