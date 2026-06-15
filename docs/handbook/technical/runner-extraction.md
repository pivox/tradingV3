# Runner extraction

## Objectif

Les PR Runner amincissent progressivement `MtfRunnerService` sans changer le
comportement runtime.

Le runner reste l'orchestrateur principal des entrypoints existants. Chaque PR
extrait uniquement des blocs deja presents, avec les memes entrees, sorties,
logs et side effects.

## PR03 - extraction symboles et activite ouverte

PR03 a extrait mecaniquement deux responsabilites :

- resolution de l'univers de symboles a traiter ;
- filtrage des symboles occupes par positions ou ordres ouverts.

Services ajoutes :

- `App\Application\Runner\SymbolUniverseResolver` ;
- `App\Application\Runner\OpenActivityFilter`.

## Entrypoints conserves

| Entrypoint | Statut |
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

Les PR03 et PR04 ne changent pas l'ordre de ces etapes.

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

## PR04 - sync exchange, projection post-run et resultat

PR04 poursuit l'extraction sans changer le comportement runtime ni la structure
JSON retournee par `/api/mtf/run`.

Services ajoutes :

- `App\Application\Runner\ExchangeStateSynchronizer` ;
- `App\Application\Runner\PostRunProjectionDispatcher` ;
- `App\Application\Runner\RunResultAssembler`.

### `App\Application\Runner\ExchangeStateSynchronizer`

Responsabilite extraite depuis `MtfRunnerService::syncTables()` :

- resoudre les providers via `MainProviderInterface::forContext()` ;
- conserver le guard legacy quand les providers account et order sont absents ;
- synchroniser les positions ouvertes dans `positions` via
  `PositionRepository::findOneBySymbolSide()` puis `upsert()` ;
- reutiliser `OrderProviderInterface::getOpenOrders()`, qui conserve le chemin
  existant de synchronisation des ordres cote provider/FuturesOrderSyncService ;
- retourner le meme tableau `open_positions` / `open_orders` ;
- conserver les logs et catches existants.

`MtfRunnerService::syncTables()` reste disponible et delegue au nouveau service.

### `App\Application\Runner\PostRunProjectionDispatcher`

Responsabilite extraite autour du dispatch post-run :

- resoudre les timeframes depuis `current_tf` ou `MtfValidatorInterface::getListTimeframe()` ;
- filtrer les resultats de symboles en ignorant l'entree synthetique `FINAL` ;
- publier le meme `IndicatorSnapshotPersistRequestMessage` ;
- conserver `run_id`, profil, timestamp UTC, exchange et market type ;
- conserver les conditions de non-dispatch quand il n'y a pas de timeframe ou de symbole.

Aucun transport Messenger, queue ou handler n'est modifie.

### `App\Application\Runner\RunResultAssembler`

Responsabilite extraite autour de l'enrichissement et de la reponse finale :

- deleguer l'enrichissement existant a `MtfRunResultEnricher` ;
- assembler la reponse avec les memes clefs :
  `summary`, `results`, `errors`, `summary_by_tf`, `rejected_by`,
  `last_validated`, `orders_placed`, `performance` ;
- conserver les compteurs, timings, resultats par symbole, raisons de rejet et
  ordres places/ignores.

La structure de reponse `/api/mtf/run` reste inchangee.

## Structure utilisee ensuite par MTF

Apres resolution et filtrage, le runner transmet toujours une liste simple de
symboles a `MtfValidatorService` :

- en mode sequentiel, via `MtfRunRequestDto::symbols` ;
- en mode parallele, via une `SplQueue` et la commande `mtf:run-worker`.

Les resultats restent indexes par symbole dans `results`, avec les statuts
`READY` ou `INVALID` derives de `MtfResultDto::isTradable`. PR03 ne modifie pas
la structure de reponse ni les valeurs `READY` / `REJECTED` / `INVALID`.

PR04 ne change pas cette structure.

## Ce qui reste dans `MtfRunnerService`

Les responsabilites suivantes restent dans `MtfRunnerService` :

- gestion des locks ;
- extension/desactivation post-run des switches pour symboles exclus ;
- execution parallele par `Process` ;
- recalcul TP/SL ;
- construction du contexte exchange/market ;
- construction de `MtfRunRequestDto` pour l'execution sequentielle ;
- aggregation des workers paralleles ;
- creation de la commande `mtf:run-worker`.

Les entrypoints Symfony, Temporal et worker ne sont pas extraits dans cette PR.

## Hors-scope confirme

PR03 et PR04 ne branchent pas `EffectiveTradingConfigResolver` au runtime, ne
modifient pas les YAML strategie, ne changent pas TradeEntry, EntryZone,
Risk/Leverage, SL/TP, Temporal, les schedules, Bitmart, OKX ou Hyperliquid
live.

Aucun secret n'est ajoute.

## Tests de non-regression

Tests PR03 :

- `tests/Application/Runner/SymbolUniverseResolverTest.php`
- `tests/Application/Runner/OpenActivityFilterTest.php`

Ils couvrent :

- normalisation et deduplication des symboles fournis ;
- chargement des contrats actifs quand aucun symbole n'est fourni ;
- fallback legacy quand la base et la queue ne retournent rien ;
- consommation de la queue de switches ;
- exclusion des symboles avec positions ou ordres ouverts ;
- side effect de reactivation des switches inactifs.

Tests PR04 :

- `tests/Application/Runner/ExchangeStateSynchronizerTest.php`
- `tests/Application/Runner/PostRunProjectionDispatcherTest.php`
- `tests/Application/Runner/RunResultAssemblerTest.php`

Ils couvrent :

- absence de providers account/order sans sync ni erreur ;
- synchronisation positions et retour des ordres ouverts ;
- payload `IndicatorSnapshotPersistRequestMessage` ;
- absence de dispatch quand aucun symbole reel n'est present ;
- assemblage final avec la structure de reponse existante.

## Suite PR05

PR05 doit traiter les DTOs MTF / TradeCandidate sans melanger cette etape avec
la validation strategique ou l'execution :

- stabiliser un DTO explicite entre MTF et TradeEntry ;
- rendre visibles profil, instrument, side, execution timeframe, raisons de
  rejet et metadata ;
- conserver les valeurs READY / REJECTED et le comportement de validation ;
- ne pas modifier TradeEntry, EntryZone, Risk/Leverage, SL/TP ou ExecutionPort
  dans la meme PR.

La validation MTF, TradeEntry, EntryZone, Risk/Leverage, SL/TP et ExecutionPort
restent des PR separees du plan TradingCore canonique.

## Garantie runtime

PR04 reste une extraction mecanique :

- aucun entrypoint n'est modifie ;
- aucun schedule Temporal n'est modifie ;
- aucune strategie YAML n'est modifiee ;
- aucun exchange live OKX/Hyperliquid n'est active ;
- Bitmart reste legacy runtime ;
- la reponse `/api/mtf/run` conserve les memes clefs et payloads.
