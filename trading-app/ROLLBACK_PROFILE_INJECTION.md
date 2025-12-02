# Rollback: Injection automatique du profile MTF + Correction normalisation

## Date: 2025-01-XX
## Fichiers modifiés: 
- `trading-app/src/Controller/RunnerController.php`
- `trading-app/src/Contract/MtfValidator/Dto/MtfRunResponseDto.php`

## Changements effectués

### 1. Injection de dépendance ajoutée
- **Ligne ~18**: Ajout de `TradeEntryModeContext $modeContext` dans le constructeur

### 2. Logique d'injection automatique
- **Lignes ~64-75**: Ajout de la logique pour injecter automatiquement le profile depuis la configuration
- **Lignes ~80-81**: Modification pour utiliser le profile par défaut si non fourni

## Pour rollback

### Étape 1: Retirer l'injection de dépendance
```php
public function __construct(
    private readonly LoggerInterface $logger,
    // SUPPRIMER cette ligne:
    // private readonly TradeEntryModeContext $modeContext,
) {
}
```

### Étape 2: Retirer la logique d'injection automatique
Remplacer les lignes ~64-81 par:
```php
// Construire la requête Runner (le Runner gère tout : résolution, filtrage, switches, TP/SL, post-processing)
$runnerRequest = MtfRunnerRequestDto::fromArray([
    'symbols' => $symbols,
    'dry_run' => $dryRun,
    'force_run' => $forceRun,
    'current_tf' => $currentTf,
    'force_timeframe_check' => $forceTimeframeCheck,
    'skip_context' => (bool)($data['skip_context'] ?? false),
    'lock_per_symbol' => (bool)($data['lock_per_symbol'] ?? false),
    'skip_open_state_filter' => (bool)($data['skip_open_state_filter'] ?? false),
    'user_id' => $data['user_id'] ?? null,
    'ip_address' => $data['ip_address'] ?? null,
    'exchange' => $data['exchange'] ?? $data['cex'] ?? null,
    'market_type' => $data['market_type'] ?? $data['type_contract'] ?? null,
    'workers' => $workers,
    'sync_tables' => filter_var($data['sync_tables'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'process_tp_sl' => filter_var($data['process_tp_sl'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'profile' => $data['profile'] ?? $data['mtf_profile'] ?? null,
    'mtf_profile' => $data['mtf_profile'] ?? null,
    'validation_mode' => $data['validation_mode'] ?? null,
    'context_mode' => $data['context_mode'] ?? null,
    'mode' => $data['mode'] ?? null,
]);
```

### Étape 3: Retirer l'import
Supprimer la ligne:
```php
use App\Config\TradeEntryModeContext;
```

## Comportement avant le changement

- Si aucun `profile` ou `mtf_profile` n'était fourni, la valeur était `null`
- Le service utilisait alors `app.trade_entry_default_mode` comme fallback (défini dans `services.yaml`)

## Comportement après le changement

- Si aucun `profile` ou `mtf_profile` n'est fourni, le mode activé avec la priorité la plus élevée est injecté automatiquement
- Le mode peut toujours être surchargé explicitement dans la requête
- Un log de debug est émis lors de l'injection automatique

---

## Correction du problème de normalisation

### Fichier: `trading-app/src/Contract/MtfValidator/Dto/MtfRunResponseDto.php`

### Changement effectué
- **Lignes ~53-92**: Modification de `toArray()` pour convertir les objets `MtfResultDto` en tableaux avant sérialisation JSON

### Pour rollback
Remplacer la méthode `toArray()` par:
```php
public function toArray(): array
{
    return [
        'run_id' => $this->runId,
        'status' => $this->status,
        'execution_time_seconds' => $this->executionTimeSeconds,
        'symbols_requested' => $this->symbolsRequested,
        'symbols_processed' => $this->symbolsProcessed,
        'symbols_successful' => $this->symbolsSuccessful,
        'symbols_failed' => $this->symbolsFailed,
        'symbols_skipped' => $this->symbolsSkipped,
        'success_rate' => $this->successRate,
        'results' => $this->results,
        'errors' => $this->errors,
        'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
        'message' => $this->message
    ];
}
```

### Problème résolu
- Avant: Erreur "Could not normalize object of type App\Contract\MtfValidator\Dto\MtfResultDto"
- Après: Les objets `MtfResultDto` sont convertis en tableaux via `toArray()` avant sérialisation

---

## Correction supplémentaire: TimeframeDecisionDto

### Fichiers modifiés:
- `trading-app/src/Contract/MtfValidator/Dto/TimeframeDecisionDto.php`
- `trading-app/src/Contract/MtfValidator/Dto/ContextDecisionDto.php`
- `trading-app/src/Contract/MtfValidator/Dto/ExecutionSelectionDto.php`

### Changements effectués
1. **TimeframeDecisionDto**: Ajout de la méthode `toArray()` pour sérialiser l'objet
2. **ContextDecisionDto**: Modification de `toArray()` pour convertir les objets `TimeframeDecisionDto` en tableaux
3. **ExecutionSelectionDto**: Modification de `toArray()` pour convertir les objets `TimeframeDecisionDto` en tableaux

### Pour rollback
- **TimeframeDecisionDto**: Retirer la méthode `toArray()` (retourner à la version sans méthode)
- **ContextDecisionDto**: Remplacer `toArray()` par:
```php
public function toArray(): array
{
    return [
        'valid' => $this->isValid,
        'reason_if_invalid' => $this->reasonIfInvalid,
        'timeframe_decisions' => $this->timeframeDecisions
    ];
}
```
- **ExecutionSelectionDto**: Remplacer `toArray()` par:
```php
public function toArray(): array
{
    return [
        'selected_timeframe' => $this->selectedTimeframe,
        'selected_side' => $this->selectedSide,
        'reason_if_none' => $this->reasonIfNone,
        'timeframe_decisions' => $this->timeframeDecisions
    ];
}
```

### Problème résolu
- Avant: Erreur "Could not normalize object of type App\Contract\MtfValidator\Dto\TimeframeDecisionDto"
- Après: Tous les objets DTO sont correctement convertis en tableaux avant sérialisation JSON

---

## Correction: Fichier de configuration manquant

### Fichier: `trading-app/config/services.yaml`

### Changement effectué
- **Ligne ~246**: Modification du chemin par défaut de `validations.yaml` vers `validations.scalper_micro.yaml`

### Pour rollback
Remplacer la ligne par:
```yaml
App\Config\MtfValidationConfig:
    arguments:
        $path: '%kernel.project_dir%/src/MtfValidator/config/validations.yaml'
```

### Problème résolu
- Avant: Erreur "Configuration file not found: /var/www/html/src/MtfValidator/config/validations.yaml" pour BTCUSDT
- Après: Le fichier `validations.scalper_micro.yaml` (mode activé par défaut) est utilisé comme config par défaut

