# Validation MTF — profils, moteur, décisions

## Entrées / sorties fonctionnelles

Entrée principale : `MtfRunDto` (symbol, profile/mode, now, dry_run, options).  
Sortie principale : `MtfResultDto` (isTradable, side, executionTimeframe, raisons, détails par timeframe).

Moteur : `trading-app/src/MtfValidator/Service/MtfValidatorCoreService.php`

## Résolution des timeframes

Le profil (YAML) peut définir :

- `context_timeframes` : TF utilisés pour valider le contexte
- `execution_timeframes` : TF candidats pour l’exécution

Sinon fallback :

- contexte : clés de `validation.timeframe` ou `['4h','1h']`
- exécution : `validation.timeframe` moins contexte, ou `['15m','5m','1m']`

Source : `MtfValidatorCoreService::resolveContextTimeframes()` et `resolveExecutionTimeframes()`

## Construction des indicateurs par timeframe

Avant validation, le core demande les indicateurs pour la liste complète des TF nécessaires :

- `IndicatorProviderInterface::getIndicatorsForSymbolAndTimeframes(symbol, timeframes, now)`

Source : `trading-app/src/Indicator/Provider/IndicatorProviderService.php`

Spécificité : si pas assez de klines, `NotEnoughKlinesException` bloque le run MTF.

## Validation d’un timeframe (context/execution)

Service : `trading-app/src/MtfValidator/Service/TimeframeValidationService.php`

Séquence :

1. Si pas de bloc `validation.timeframe.<tf>` → invalid (`NO_CONFIG_FOR_TF`)
2. Moteur prioritaire : “ConditionRegistry” si disponible (sinon fallback YAML)
3. Évalue LONG et SHORT (validations du TF)
4. Si long=false et short=false → invalid (`NO_LONG_NO_SHORT`)
5. Si long=true et short=true → invalid (`LONG_AND_SHORT`)
6. Applique `filters_mandatory` (veto global) :
   - si un filtre mandatory échoue → invalid (`FILTERS_MANDATORY_FAILED`)

## Profils disponibles (fichiers)

Les profils MTF sont chargés depuis :

- `trading-app/src/MtfValidator/config/validations.<mode>.yaml`

Résolution de fichier : `trading-app/src/Config/MtfValidationConfigProvider.php`

Profils présents (repo) :

- `validations.scalper_micro.yaml`
- `validations.scalper.yaml`
- `validations.regular.yaml`
- `validations.crash.yaml`

Le mode “primaire” activé (par défaut dans ce repo) est paramétré ici :

- `trading-app/config/services.yaml` → param `mode` (enabled + priority)

## Détail des règles d’un profil

Les conditions utilisées par les profils (et leurs formules précises) sont documentées dans :

- `docs/trading-app/07-conditions-reference.md`

La spécification exhaustive des profils (valeurs, scénarios, overrides) est reproduite dans :

- `docs/trading-app/08-profils-validations.md`

Remarques importantes (comportement “as‑is”) :

- L’engine ConditionRegistry est prioritaire, mais ne supporte pas `{lt: ...}` / `{gt: ...}` pour les règles “rules‑only”.
- En ConditionRegistry, `filters_mandatory` est évalué sans appliquer les overrides YAML (`- name: { ... }`), contrairement à l’engine YAML.
