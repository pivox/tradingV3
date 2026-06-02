# Validation MTF

La validation MTF decide si un symbole est exploitable pour un profil donne.

## Sources de configuration

| Source | Role |
| --- | --- |
| `trading-app/src/MtfValidator/config/validations.regular.yaml` | Regles du profil regular. |
| `trading-app/src/MtfValidator/config/validations.scalper.yaml` | Regles du profil scalper. |
| `trading-app/src/MtfValidator/config/validations.scalper_micro.yaml` | Regles du profil scalper micro. |
| `trading-app/src/MtfValidator/config/validations.crash.yaml` | Regles du profil crash. |
| `trading-app/config/app/mtf_contracts*.yaml` | Selection et contraintes de contrats. |

## Objets principaux

| Objet | Role |
| --- | --- |
| `MtfValidationConfigProvider` | Charge et normalise les YAML MTF. |
| `IndicatorProviderInterface` | Fournit indicateurs et contexte multi-timeframe. |
| `MtfValidatorService` | Facade compatible avec le contrat applicatif. |
| `MtfValidatorCoreService` | Orchestre contexte, execution et decision finale. |
| `ContextValidationService` | Valide les timeframes de contexte. |
| `TimeframeValidationService` | Evalue les conditions sur un timeframe. |
| `ExecutionSelectionService` | Choisit le timeframe executable et le side. |
| `ConditionRegistry` | Mappe les noms YAML vers les conditions PHP. |

## Sorties

| DTO | Contenu |
| --- | --- |
| `MtfRunDto` | Demande normalisee de validation. |
| `ValidationContextDto` | Donnees indicateurs et metadonnees. |
| `TimeframeDecisionDto` | Resultat par timeframe et par cote. |
| `ExecutionSelectionDto` | Timeframe retenu, side et motifs. |
| `MtfResultDto` | Resultat final retourne au runner. |

## Raisons typiques de rejet

- historique kline insuffisant;
- contexte timeframe invalide;
- filtre mandatory echoue;
- conflit long/short;
- spread, volume, ATR ou RSI hors bornes;
- contrat non eligible;
- symbole deja protege par lock ou switch.
