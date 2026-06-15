# Runner extraction

## Objectif PR03

PR03 commence l'extraction du runner sans changer le comportement runtime.

Le scope est volontairement limite a deux responsabilites deja presentes dans
`MtfRunnerService` :

- resolution de l'univers de symboles a traiter ;
- filtrage des symboles occupes par positions ou ordres ouverts.

`MtfRunnerService` reste l'orchestrateur principal. Les nouveaux services sont
des delegations mecaniques qui conservent les memes entrees, sorties, logs et
side effects que les blocs extraits.

## Entrypoints conserves

| Entrypoint | Statut PR03 |
| --- | --- |
| `php bin/console mtf:run` | Conserve. La commande construit toujours `MtfRunnerRequestDto` puis appelle `RunMtfCycleUseCase`. |
| `POST /api/mtf/run` | Conserve. `RunnerController` garde le payload et la reponse existants. |
| Temporal -> `/api/mtf/run` | Conserve. Aucun changement de schedule ou payload Temporal. |
| Worker symboles `mtf:run-worker` | Conserve. Le worker continue d'appeler `MtfValidatorInterface` avec `--skip-open-filter` quand le runner a filtre en amont. |

## Responsabilites actuelles du runner

`MtfRunnerService` orchestre encore le cycle complet :

1. creation du contexte exchange/market ;
2. resolution des symboles ;
3. synchronisation positions et ordres depuis l'exchange ;
4. filtrage des symboles ayant une activite ouverte ;
5. gestion des locks ;
6. gestion des switches ;
7. execution MTF sequentielle ou parallele ;
8. projection Messenger des snapshots indicateurs ;
9. recalcul TP/SL cadence ;
10. enrichissement/reporting final.

PR03 ne change pas l'ordre de ces etapes.

## Ce que PR03 extrait

### `App\Application\Runner\SymbolUniverseResolver`

Responsabilite extraite depuis `MtfRunnerService::resolveSymbols()` :

- normaliser les symboles fournis en entree ;
- dedupliquer les symboles ;
- charger les contrats actifs via `ContractRepository::allActiveSymbolNames()` quand aucun symbole n'est fourni ;
- conserver le fallback legacy `BTCUSDT`, `ETHUSDT`, `ADAUSDT`, `SOLUSDT`, `DOTUSDT` ;
- consommer la queue de switches via `MtfSwitchRepository::consumeSymbolsWithFutureExpiration()` ;
- logger les memes avertissements et informations que le bloc historique.

`MtfRunnerService::resolveSymbols()` reste disponible et delegue au nouveau
service afin de preserver les appels existants.

### `App\Application\Runner\OpenActivityFilter`

Responsabilite extraite depuis
`MtfRunnerService::filterSymbolsWithOpenOrdersOrPositions()` :

- recuperer ou reutiliser les positions ouvertes ;
- recuperer ou reutiliser les ordres ouverts ;
- construire la liste des symboles avec activite ouverte ;
- reactiver les switches des symboles redevenus inactifs ;
- exclure les symboles occupes ;
- renseigner `$excludedSymbols` avec les symboles exclus en majuscules ;
- conserver les logs existants.

`MtfRunnerService::filterSymbolsWithOpenOrdersOrPositions()` reste disponible et
delegue au nouveau service.

## Structure utilisee ensuite par MTF

Apres resolution et filtrage, le runner transmet toujours une liste simple de
symboles a `MtfValidatorService` :

- en mode sequentiel, via `MtfRunRequestDto::symbols` ;
- en mode parallele, via une `SplQueue` et la commande `mtf:run-worker`.

Les resultats restent indexes par symbole dans `results`, avec les statuts
`READY` ou `INVALID` derives de `MtfResultDto::isTradable`. PR03 ne modifie pas
la structure de reponse ni les valeurs `READY` / `REJECTED` / `INVALID`.

## Ce qui reste legacy dans PR03

Les responsabilites suivantes restent dans `MtfRunnerService` :

- synchronisation exchange complete ;
- creation et mise a jour des entites `Position` ;
- gestion des locks ;
- extension/desactivation post-run des switches pour symboles exclus ;
- execution parallele par `Process` ;
- dispatch Messenger de projection indicateurs ;
- recalcul TP/SL ;
- reporting final et enrichissement de resultat.

Les entrypoints Symfony, Temporal et worker ne sont pas extraits dans cette PR.

## Hors-scope confirme

PR03 ne branche pas `EffectiveTradingConfigResolver` au runtime, ne modifie pas
les YAML strategie, ne change pas TradeEntry, EntryZone, Risk/Leverage, SL/TP,
Temporal, les schedules, Bitmart, OKX ou Hyperliquid live.

Aucun secret n'est ajoute.

## Tests de non-regression

Tests ajoutes :

- `tests/Application/Runner/SymbolUniverseResolverTest.php`
- `tests/Application/Runner/OpenActivityFilterTest.php`

Ces tests couvrent :

- normalisation et deduplication des symboles fournis ;
- chargement des contrats actifs quand aucun symbole n'est fourni ;
- fallback legacy quand la base et la queue ne retournent rien ;
- consommation de la queue de switches ;
- exclusion des symboles avec positions ou ordres ouverts ;
- side effect de reactivation des switches inactifs.

## Suite PR04

PR04 pourra extraire les responsabilites suivantes, sans les melanger avec PR03 :

- synchronisation exchange/tables ;
- reporting final ;
- assemblage de resultat ;
- dispatch post-run.

La validation MTF, TradeEntry, EntryZone, Risk/Leverage, SL/TP et ExecutionPort
restent des PR separees du plan TradingCore canonique.
