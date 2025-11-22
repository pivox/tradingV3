# Étude d'Impact : Changement Context/Execution Timeframes

## Objectif
Changer la configuration scalper pour :
- **Contexte** : `1h` et `15m` (au lieu de `4h` et `1h`)
- **Execution** : `15m` ou `1m` (au lieu de `5m` ou `1m`)

## Configuration Actuelle

```yaml
mtf_validation:
    context_timeframes: ['4h','1h']     # Contexte actuel
    execution_timeframe_default: '5m'
    allow_skip_lower_tf: true
    
    validation:
        start_from_timeframe: '1h'      # Validation commence à 1h
        
    execution_selector:
        stay_on_15m_if:
            - get_false: true           # Ne reste jamais en 15m
        drop_to_5m_if_any:              # Descend en 5m si conditions
        allow_1m_only_for:
            enabled: false               # 1m désactivé
```

## Configuration Proposée

```yaml
mtf_validation:
    context_timeframes: ['1h','15m']   # NOUVEAU : Contexte 1h + 15m
    execution_timeframe_default: '15m'  # NOUVEAU : Par défaut 15m
    allow_skip_lower_tf: true
    
    validation:
        start_from_timeframe: '1h'      # Inchangé
        
    execution_selector:
        stay_on_15m_if:                 # NOUVEAU : Permettre 15m
            - get_true: true            # Condition qui retourne toujours true
        drop_to_1m_if_any:              # NOUVEAU : Descend en 1m si conditions
        forbid_drop_to_1m_if_any:        # NOUVEAU : Empêcher descente 1m si conditions
        allow_1m_only_for:
            enabled: true                # NOUVEAU : Activer 1m
```

## Impact sur la Validation MTF

### 1. Timeframes Validés

**Actuel** :
- `4h` : context-only (validation pour contexte, pas d'exécution)
- `1h` : inclus (validation + contexte)
- `15m` : inclus (validation + contexte)
- `5m` : inclus (validation + exécution possible)
- `1m` : inclus (validation + exécution possible)

**Proposé** :
- `4h` : **NON validé** (retiré du contexte)
- `1h` : inclus (validation + contexte)
- `15m` : inclus (validation + contexte + **exécution possible**)
- `5m` : **NON validé** (retiré de la chaîne)
- `1m` : inclus (validation + exécution possible)

### 2. Logique de Validation (MtfService.php)

**Ligne 610-624** : Calcul des flags
```php
$startFrom = '1h';  // Inchangé
$contextTimeframes = ['1h', '15m'];  // NOUVEAU
$tfOrder = ['4h','1h','15m','5m','1m'];

// Calcul des flags :
// - includeFlags : timeframes >= startFrom (1h)
// - contextOnlyFlags : timeframes < startFrom ET dans contextTimeframes
// - shouldRunFlags : includeFlags OU contextOnlyFlags
```

**Résultat** :
- `4h` : `include=false`, `contextOnly=false` → **NON validé** ✅
- `1h` : `include=true`, `contextOnly=false` → **Validé** ✅
- `15m` : `include=true`, `contextOnly=false` → **Validé** ✅
- `5m` : `include=true`, `contextOnly=false` → **Validé** (mais on veut le retirer)
- `1m` : `include=true`, `contextOnly=false` → **Validé** ✅

**PROBLÈME** : `5m` sera toujours validé car `start_from_timeframe='1h'` inclut tous les TF >= 1h.

**SOLUTION** : Changer `start_from_timeframe` à `'15m'` pour exclure `5m` de la validation.

### 3. Impact sur `allow_skip_lower_tf`

**Actuel** (ligne 896-900 MtfService.php) :
- Si `15m` invalide ET `allow_skip_lower_tf=true` ET `include5m=true` → Continue vers `5m`
- Si `5m` invalide ET `allow_skip_lower_tf=true` ET `include1m=true` → Continue vers `1m`

**Proposé** :
- Si `15m` invalide ET `allow_skip_lower_tf=true` ET `include1m=true` → Continue vers `1m`
- Plus de skip vers `5m` (car `5m` ne sera plus validé)

## Impact sur ExecutionSelector

### 1. Logique Actuelle

**Ordre de décision** :
1. `stay_on_15m_if` → Si passe → `execution_tf = '15m'`
2. `drop_to_5m_if_any` → Si passe ET pas `forbid_drop_to_5m_if_any` → `execution_tf = '5m'`
3. `allow_1m_only_for` → Si passe → `execution_tf = '1m'`
4. Fallback → `execution_tf = '15m'`

### 2. Logique Proposée

**Ordre de décision** :
1. `stay_on_15m_if` → Si passe → `execution_tf = '15m'` ✅
2. `drop_to_1m_if_any` → Si passe ET pas `forbid_drop_to_1m_if_any` → `execution_tf = '1m'` ✅
3. `allow_1m_only_for` → Si passe → `execution_tf = '1m'` ✅
4. Fallback → `execution_tf = '15m'` ✅

**CHANGEMENTS NÉCESSAIRES** :
- Créer `GetTrueCondition` (ou utiliser une condition existante qui retourne toujours `true`)
- Remplacer `drop_to_5m_if_any` par `drop_to_1m_if_any`
- Remplacer `forbid_drop_to_5m_if_any` par `forbid_drop_to_1m_if_any`
- Adapter les conditions dans `drop_to_1m_if_any` (actuellement basées sur 5m)

## Impact sur summary_by_tf

### Actuel
- `summary_by_tf.15m` : **Toujours vide** (car `get_false` empêche de rester en 15m)
- `summary_by_tf.5m` : Symboles avec `execution_tf='5m'`
- `summary_by_tf.1m` : Symboles avec `execution_tf='1m'`

### Proposé
- `summary_by_tf.15m` : **Aura des symboles** (car `stay_on_15m_if` peut passer) ✅
- `summary_by_tf.5m` : **Vide** (car 5m retiré)
- `summary_by_tf.1m` : Symboles avec `execution_tf='1m'`

## Points d'Attention

### 1. Validation 5m Retirée
- **Impact** : Plus de validation 5m, donc plus d'alignement 5m↔15m
- **Risque** : Perte de granularité intermédiaire
- **Mitigation** : S'assurer que les conditions de `drop_to_1m_if_any` sont suffisantes

### 2. Contexte 4h Retiré
- **Impact** : Plus de validation 4h pour le contexte
- **Risque** : Perte de vue long terme
- **Mitigation** : Le contexte 1h devrait suffire pour le scalping

### 3. Conditions drop_to_1m_if_any
- **Actuel** : `drop_to_5m_if_any` utilise des conditions basées sur 5m (ADX_5m, etc.)
- **Proposé** : `drop_to_1m_if_any` doit utiliser des conditions adaptées à 1m
- **Action** : Créer/adapter les conditions pour 1m

### 4. Alignement Timeframes
- **Actuel** : Vérifie alignement 15m↔5m et 5m↔1m
- **Proposé** : Vérifier seulement alignement 15m↔1m
- **Code** : Ligne 1021-1030 MtfService.php (alignement 5m↔15m) → À adapter pour 15m↔1m

## Modifications de Code Nécessaires

### 1. Configuration YAML
- [ ] Changer `context_timeframes: ['1h','15m']`
- [ ] Changer `start_from_timeframe: '15m'` (pour exclure 5m)
- [ ] Changer `execution_timeframe_default: '15m'`
- [ ] Modifier `execution_selector` :
  - [ ] `stay_on_15m_if: [get_true: true]` (ou condition équivalente)
  - [ ] `drop_to_1m_if_any: [...]` (adapter conditions)
  - [ ] `forbid_drop_to_1m_if_any: [...]` (adapter conditions)
  - [ ] `allow_1m_only_for: {enabled: true, ...}`

### 2. Code PHP
- [ ] Créer `GetTrueCondition` (ou utiliser condition existante)
- [ ] Adapter logique d'alignement dans `MtfService.php` (ligne 1021-1030)
- [ ] Vérifier que `allow_skip_lower_tf` fonctionne correctement avec 15m→1m

### 3. Tests
- [ ] Tester validation avec nouveau contexte
- [ ] Tester `execution_selector` avec 15m et 1m
- [ ] Vérifier `summary_by_tf.15m` contient des symboles
- [ ] Vérifier qu'aucun symbole n'a `execution_tf='5m'`

## Recommandations

1. **Créer `GetTrueCondition`** : Condition simple qui retourne toujours `true`
2. **Adapter les conditions** : S'assurer que `drop_to_1m_if_any` utilise des conditions pertinentes pour 1m
3. **Tester progressivement** : Commencer avec `start_from_timeframe='15m'` et vérifier que 5m n'est plus validé
4. **Monitoring** : Surveiller les logs pour vérifier que les décisions d'exécution sont correctes


