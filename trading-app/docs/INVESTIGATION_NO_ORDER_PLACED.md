# Investigation : Pourquoi aucun ordre n'est placé

**Date** : 2025-11-22  
**Problème** : Aucun ordre n'est placé malgré l'exécution du cycle MTF

## Résumé exécutif

**Cause racine** : Les timeframes de contexte (`1h`, `15m`) ne sont pas `VALID`, ce qui empêche la détermination du `context_side` et donc du `execution_tf`. Sans `execution_tf`, le statut reste `INVALID` au lieu de `READY`, et `TradingDecisionHandler` ne traite pas ces symboles.

## Chaîne de blocage

```
1. Timeframes de contexte (1h, 15m) → FAILED
   ↓
2. ContextDecisionService → NO_CONTEXT_SIDE
   ↓
3. MtfService → status: INVALID, execution_tf: null
   ↓
4. SymbolResultDto → status: INVALID (pas READY)
   ↓
5. TradingDecisionHandler → skip (ligne 53: status !== 'READY')
   ↓
6. Aucun ordre placé
```

## Détails techniques

### 1. Échec des timeframes de contexte

**Timeframe `1h`** :
- **LONG** : Échec sur :
  - `ema_above_200_with_tolerance_moderate` : Prix trop en dessous de l'EMA200 (-8.2%)
  - `ema_50_gt_200` : EMA50 < EMA200 (structure baissière)
  - `ema200_slope_pos` : EMA200 en pente négative
  - `price_regime_ok_long` : Prix pas au-dessus des EMAs
- **SHORT** : Conditions EMA réussies mais échec sur :
  - `macd_hist_decreasing_n` : MACD hist en hausse (pas en baisse)
  - `macd_hist_slope_neg` : Pente positive (pas négative)

**Timeframe `15m`** :
- **LONG** : Échec sur :
  - `ema20_over_50_with_tolerance_moderate` : EMA20 < EMA50 avec écart trop important
- **SHORT** : Conditions EMA réussies mais échec sur :
  - `macd_hist_decreasing_n` : MACD hist en hausse
  - `macd_hist_slope_neg` : Pente positive
  - `macd_line_below_signal` : MACD ligne au-dessus du signal

### 2. Impact sur le flux MTF

**Fichier** : `trading-app/src/MtfValidator/Service/MtfService.php`

```php
// Ligne 1261-1277
if (!$skipContextValidation && !$contextDecision->isOk()) {
    $reason = $contextDecision->getReason() ?? 'CONTEXT_NOT_OK';
    // ...
    return [
        'status' => 'INVALID',
        'signal_side' => 'NONE',
        'reason' => $reason,  // NO_CONTEXT_SIDE
        'context_side' => null,
        'execution_tf' => null,  // ← Blocage ici
    ];
}
```

**Conséquence** : Sans `execution_tf`, le statut ne peut pas être `READY` (ligne 1373).

### 3. Blocage dans TradingDecisionHandler

**Fichier** : `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`

```php
// Ligne 53-55
if (strtoupper($symbolResult->status) !== 'READY') {
    return $symbolResult;  // ← Skip si pas READY
}
```

**Conséquence** : Les symboles avec `status: INVALID` ne sont jamais traités par `TradingDecisionHandler`, donc aucun ordre n'est placé.

## Logs observés

```
[2025-11-22 01:31:10.088] mtf.INFO symbol=BTCUSDT msg="[MTF] Context decision failed" reason=NO_CONTEXT_SIDE
[2025-11-22 01:31:10.089] mtf.DEBUG symbol=BTCUSDT msg="[MTF] Mode failed for symbol, trying next" mode=scalper status=INVALID reason=NO_CONTEXT_SIDE
```

**Tous les symboles** retournent `NO_CONTEXT_SIDE` car aucun timeframe de contexte n'est `VALID`.

## Solutions possibles

### Solution 1 : Ajuster les conditions de validation (recommandé)

**Problème** : Conflit entre structure (EMA) et momentum (MACD)
- Structure baissière (EMA) mais momentum haussier (MACD)
- Structure haussière (EMA) mais momentum baissier (MACD)

**Actions** :
1. **Assouplir les conditions MACD** pour les timeframes de contexte :
   - Accepter `macd_hist_gt_eps` même si la pente n'est pas négative pour SHORT
   - Accepter `macd_hist_slope_pos` même si `macd_hist_decreasing_n` échoue
2. **Ajuster les tolérances EMA** :
   - Augmenter `tolerance` dans `ema_above_200_with_tolerance_moderate` (actuellement -0.0025)
   - Augmenter `tolerance` dans `ema20_over_50_with_tolerance_moderate` (actuellement -0.0012)

**Fichiers à modifier** :
- `trading-app/src/MtfValidator/config/validations.scalper.yaml`

### Solution 2 : Utiliser `skip_context_validation` (temporaire)

**Action** : Passer `skip_context_validation: true` dans la requête API

```json
{
  "dry_run": false,
  "skip_context_validation": true,
  "workers": 4
}
```

**Note** : Cette solution contourne le problème mais ne le résout pas. À utiliser uniquement pour tester.

### Solution 3 : Ajuster la configuration des timeframes de contexte

**Action** : Modifier `context_timeframes` pour utiliser des timeframes plus stables

**Fichier** : `trading-app/src/MtfValidator/config/validations.scalper.yaml`

```yaml
mtf_validation:
    context_timeframes: ['4h', '1h']  # Au lieu de ['1h', '15m']
```

**Note** : Nécessite de modifier `start_from_timeframe` également.

## Vérification

Pour vérifier si les solutions fonctionnent :

```bash
# 1. Vérifier les logs de contexte
tail -f trading-app/var/log/mtf-2025-11-22.log | grep -E "(Context decision|Context TF validation result)"

# 2. Tester avec un symbole
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{"dry_run": true, "symbols": ["BTCUSDT"], "workers": 1}' | jq '.data.symbols.BTCUSDT | {status, execution_tf, signal_side, reason}'

# 3. Vérifier si READY
# Le statut doit être "READY" avec execution_tf et signal_side non null
```

## Prochaines étapes

1. ✅ **Identifié** : Cause racine (timeframes de contexte non VALID)
2. ⏳ **À faire** : Ajuster les conditions de validation dans `validations.scalper.yaml`
3. ⏳ **À faire** : Tester avec les nouvelles conditions
4. ⏳ **À faire** : Vérifier que des ordres sont placés après correction

## Références

- `trading-app/src/MtfValidator/Service/ContextDecisionService.php` : Décision de contexte
- `trading-app/src/MtfValidator/Service/MtfService.php` : Lignes 1261-1373 (construction résultat)
- `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php` : Ligne 53 (garde READY)
- `trading-app/src/MtfValidator/config/validations.scalper.yaml` : Configuration active







