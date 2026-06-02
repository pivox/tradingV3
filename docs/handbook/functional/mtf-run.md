# Flux MTF Run

## Entry points

| Entrypoint | Fichier | Usage |
| --- | --- | --- |
| `POST /api/mtf/run` | `trading-app/src/Controller/RunnerController.php` | Declenchement HTTP principal. |
| `bin/console mtf:run` | `trading-app/src/MtfValidator/Command/MtfRunCommand.php` | Declenchement CLI. |
| Temporal schedule | `cron_symfony_mtf_workers/workflows/mtf_workers.py` | Declenchement periodique. |
| Worker symboles | `trading-app/src/MtfValidator/Command/MtfRunWorkerCommand.php` | Execution parallele par sous-lot. |

## Payload HTTP courant

```json
{
  "dry_run": false,
  "force_run": false,
  "force_timeframe_check": false,
  "workers": 8,
  "mtf_profile": "scalper_micro",
  "exchange": "bitmart",
  "market_type": "perpetual"
}
```

## Responsabilites du runner

| Etape | Objet principal | Resultat |
| --- | --- | --- |
| Normalisation | `MtfRunnerRequestDto` | Profil, exchange, flags et workers stabilises. |
| Resolution symboles | `MtfRunnerService` | Liste de contrats candidats. |
| Synchronisation | `FuturesOrderSyncService` | Positions et ordres locaux rapproches avec l'exchange. |
| Filtrage | locks, switches, positions | Symboles occupes exclus. |
| Execution | `MtfValidatorService` | Resultat par symbole. |
| Projection | `IndicatorSnapshotPersistRequestMessage` | Persistance indicateurs asynchrone. |
| Enrichissement | `MtfReportingService` | Reponse exploitable par API, logs et Temporal. |

## Reponse attendue

La reponse agrege:

- `run_id`;
- nombre de symboles traites;
- timings d'execution;
- resultat par symbole;
- timeframes d'execution retenues;
- raisons de rejet;
- ordres places ou ignores selon `dry_run`.

## Points d'exploitation

- Les workers Messenger actifs minimum sont `mtf_decision`, `mtf_projection` et `order_timeout`.
- Les logs `[MTF Messenger]`, `mtf-*` et `order-journey*` permettent de suivre le trajet complet.
- En cas de latence, verifier les 429 provider, les backoffs et le nombre de workers.
