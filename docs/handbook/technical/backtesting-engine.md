# Backtesting net deterministe

## Statut

Contrats v1 et Dataset Builder deterministe livres pour #191.

Ce lot ne livre pas encore un moteur Backtrader executable. Il fixe la frontiere
de contrat entre les futurs composants :

```text
Dataset Builder
  -> Effective Config snapshot
  -> TradingCore adapter
  -> Backtrader adapter
  -> Execution simulator
  -> Net cost model
  -> Backtest ledger
  -> Metrics and statistical validation
```

Cette separation evite de recopier arbitrairement la strategie dans Python. Les
regles de trading restent portees par TradingCore ou par un adapter explicite et
testable.

## Decision

Le backtesting #191 est implemente en lots atomiques. Les lots livres fixent :

- des contrats Pydantic immuables dans `python-orchestrator/app/backtesting/contracts.py` ;
- un builder et un serializer purs dans `python-orchestrator/app/backtesting/dataset.py` ;
- un publisher prive et atomique dans `python-orchestrator/app/backtesting/dataset_store.py` ;
- des fixtures golden et des tests unitaires verrouillant les bytes, les checksums,
  la qualite et les conflits de publication ;
- cette page d'architecture.

Backtrader sera branche derriere ces contrats dans une PR suivante. Les resultats
de backtest ne seront jamais presentes comme preuve live.

## Invariants verrouilles par les contrats v1

### Dataset versionne

Le builder ne lit pas directement les exports PHP Paper. Son entree est une
`DatasetSourceIdentity` dont la source a deja ete authentifiee par son
proprietaire, suivie uniquement de `CandleRecord` normalises. Un futur adapter
devra verifier explicitement les `manifest.json` et `events.ndjson` Paper avant
de franchir cette frontiere ; le builder Python ne duplique pas
`PaperDatasetVerifier`.

Chaque `CandleRecord` est strict, immuable et refuse les champs inconnus. Il
porte `backtest-candle.v1`, la provenance, le reseau, la venue de market data,
le market type, le symbole, le timeframe, `open_at`, `close_at`,
`available_at`, OHLCV et `complete=true`.

Les timeframes v1 sont exactement `1m`, `5m`, `15m`, `1h` et `4h` :

- timestamps UTC-aware uniquement ;
- `open_at` aligne sur la grille UTC de l'epoch Unix du timeframe ;
- intervalle `[open_at, close_at)` de duree exactement egale au timeframe ;
- `available_at >= close_at` : a l'instant `t`, un consommateur ne peut voir
  que les bougies dont `available_at <= t` ;
- prix strictement positifs, volume positif ou nul et enveloppe OHLC valide ;
- nombres sous forme de chaines decimales canoniques
  `0|[1-9][0-9]*(\.[0-9]*[1-9])?`, sans float, signe, exposant, zero initial
  ou zero final insignifiant.

Un build contient un seul reseau source, une seule venue et un seul market
type. `DatasetBuilder.analyze()` produit toujours un rapport type et ordonne.
`build()` echoue ferme sur entree vide, identite mixte ou incoherente,
duplicate exact ou conflictuel, trou, overlap ou chronologie invalide. Il ne
deduplique pas, ne selectionne pas de gagnant et ne remplit aucun trou.
Dans un groupe d'identite, les repetitions de chaque variante canonique au-dela
de sa premiere occurrence alimentent `exact_duplicate_count`, tandis que les
variantes canoniques distinctes au-dela de la premiere alimentent
`conflicting_duplicate_count`. `A,A,B` produit donc `exact=1` et
`conflicting=1`, quel que soit l'ordre d'entree.

Les records eligibles sont ordonnes par venue, market type, symbole, duree
numerique du timeframe, `open_at`, puis `source_record_id`. Le serializer emet
exactement, en UTF-8 canonique avec une seule newline finale :

```text
candles.ndjson
quality-report.json
manifest.json
```

Le checksum de `candles.ndjson` est calcule en premier, puis celui du rapport.
Le manifeste lie schemas, build version, identite et checksum source,
couverture derivee, nombre de records et checksums des deux artefacts. Le
`dataset_checksum` est le SHA-256 du core canonique du manifeste et des deux
checksums d'artefacts ; `dataset_id` en derive. `DatasetDescriptor` reconstruit
et revalide ces faits : un artefact, une ligne, un ordre ou un champ falsifie
est rejete.

Le publisher accepte uniquement des `DatasetArtifacts` deja cross-verifies. Il
utilise un staging sibling prive `0700`, trois fichiers `0600`, `fsync` des
fichiers et repertoires, puis un rename atomique sans remplacement :
`renameatx_np(RENAME_EXCL)` sur macOS et
`renameat2(RENAME_NOREPLACE)` sur Linux. Une plateforme sans primitive sure
echoue fermee. Toutes les operations sont ancrees sur un `dirfd`
`O_DIRECTORY|O_NOFOLLOW` depuis `/`. Chaque composant du chemin reste ouvert et
son identite device/inode est revalidee avant tout succes. Un composant manquant
est d'abord cree sous un nom prive aleatoire `.dataset-root-*`, ouvert et
`fsync`, puis publie par rename atomique sans remplacement et `fsync` du parent.
Un target existant est idempotent seulement si les trois noms, bytes, modes et
single-links sont exacts ; les trois fd d'artefacts restent ouverts jusqu'a une
validation collective finale de leurs identites et de celle du repertoire.
Cette passe compare aussi taille, `mtime_ns` et `ctime_ns` avant/apres la
seconde lecture afin de detecter les ecritures in-place pendant la validation.
Chaque adoption `ALREADY_PUBLISHED` effectue ensuite un `fsync` du repertoire
racine avant la derniere verification du chemin. De meme, un composant racine
cree par un concurrent n'est adopte qu'apres `fsync` de son parent.
Fichier change/manquant/supplementaire, symlink, hardlink ou course concurrente
conflictuelle est preserve et rejete.
Cette garantie reste un snapshot fini : aucun mecanisme POSIX portable ne peut
interdire a un acteur hostile du meme UID d'ecrire apres la derniere observation.
L'immuabilite au-dela du retour exige donc une propriete/coordination de stockage
qui exclut ces ecritures concurrentes.
Le fd du staging est lie a son nom par device/inode juste avant le rename et la
cible est reverifiee integralement apres celui-ci. Une substitution dans cette
ultime fenetre ne peut donc jamais produire un faux statut de succes ; elle
echoue fermee sans supprimer le repertoire inconnu ni le staging original
deplace par un acteur concurrent.
Le cleanup d'echec ne supprime aucun objet du filesystem : ni repertoire par
nom, ni fichier via fd. Meme une suppression relative a un fd pourrait courir
avec le remplacement de l'entree interne visee. Le publisher ferme seulement
ses descripteurs et preserve donc le staging prive `0700`, vide, partiel ou
complet selon le point d'echec. Un janitor peut le retirer ulterieurement, hors
publication concurrente et selon une politique explicite. Les repertoires
prives `.dataset-root-*` perdants ou interrompus suivent la meme politique et
ne sont jamais supprimes par le publisher. Une publication
`PUBLISHED` ne fuit pas de staging : le repertoire est lui-meme renomme
atomiquement en cible. En revanche, un publisher concurrent perdant qui retourne
`ALREADY_PUBLISHED` preserve son staging complet pour ce meme janitor.

`DatasetDescriptor` identifie le jeu de donnees derive par :

- `dataset_id` ;
- schema record/rapport/manifeste et build version ;
- source, version, checksum, reseau et venue de market data ;
- market type ;
- couverture exacte et bornee de chaque flux
  `(venue, market_type, symbole, timeframe)` avec son nombre de records ;
- symboles ;
- timeframes ;
- periode UTC ;
- nombre de records ;
- flags qualite ;
- checksums bougies, rapport et dataset.

Les listes agregees de symboles/timeframes, la periode et le nombre de records
sont derives de ces flux et ne peuvent pas les contredire. La periode doit etre
bornee (`end_at > start_at`). Chaque debut de flux est aligne sur la grille UTC
de son timeframe et sa couverture sans gap verifie exactement
`last_close - first_open = record_count * duree_timeframe`. Un run exige chaque combinaison cartesienne
`symbole x timeframe` qu'il demande dans le catalogue de flux et sa periode
doit tenir dans les bornes propres de chacun ; la presence separee d'un symbole
et d'un timeframe ne forge donc jamais une combinaison absente.

### Config effective

`EffectiveConfigSnapshot` capture :

- profil (`regular`, `scalper`, `scalper_micro`) ;
- hash `sha256` ;
- version de contrat ;
- couches chargees ;
- configuration effective serialisee.

Un run backtest ne peut utiliser qu'une config dont le profil correspond au
profil du run.

La configuration effective est gelee recursivement a la creation du snapshot :
un dictionnaire source mute apres coup ne peut pas modifier les parametres du
run ni invalider silencieusement `config_hash`.

### Reproductibilite

`BacktestRunRequest` porte :

- dataset ;
- config ;
- profil execute ;
- symboles et timeframes inclus dans le dataset ;
- periode ;
- commit Git ;
- version moteur ;
- seed ;
- version du modele de cout ;
- politique intra-bougie.

Le fingerprint de reproductibilite est un hash canonique des inputs. A inputs
identiques, le fingerprint doit rester identique.

Tous les timestamps des contrats sont UTC-aware. Une date naive ou dans un autre
offset est rejetee par validation Pydantic avant toute comparaison de bornes.

### Politique intra-bougie

La politique par defaut est conservatrice :

```text
conservative_stop_first
```

Les autres modes admissibles sont :

```text
path_from_lower_timeframe
reject_ambiguous_trade
```

Le mode optimiste `tp_first` n'existe pas dans le contrat v1.

### Ledger simule

`BacktestTradeLedgerEntry` represente un trade simule execute. Il exige :

- un `initial_stop` positif ;
- un stop long sous l'entree ;
- un stop short au-dessus de l'entree ;
- des couts nets explicites (`fee_usdt`, `spread_cost_usdt`, `slippage_cost_usdt`,
  `funding_usdt`, borrow/liquidation si applicables) ;
- des valeurs numeriques finies uniquement, avec `net_pnl_usdt = gross_pnl_usdt - total_known_cost_usdt` ;
- le commit Git, le dataset et le hash de config.

Un trade simule sans SL est invalide. Les signaux non executes seront modelises
dans un contrat separe lors du lot execution simulator.

## Relation avec #132

#132 reste ouverte tant que le vrai jeu de donnees n'a pas produit de baseline
quantifiee exploitable. Cela ne bloque pas ce lot de contrats #191 : le moteur est
prepare maintenant, puis les couts et la calibration seront compares a la baseline
reelle lorsque les donnees seront disponibles.

## Hors scope de ce lot

- execution Backtrader ;
- adapter verifie pour les fichiers PHP Paper bruts ;
- adapter TradingCore ;
- simulation maker/taker ;
- partial fills ;
- funding historique ;
- rapports de metrics ;
- simulation 100 trades ;
- validation statistique.

Ces elements restent dans #191 et doivent etre livres par PRs suivantes avec
tests golden dedies.

Le Dataset Builder est independant de toute strategie et n'expose aucun champ
`profile`, mode, setup ou alias. Il ne rend donc aucun mode moderne executable.
Avant cela, la PR3 de #191 doit remplacer la frontiere runtime legacy `Profile`
par les identites exactes `mode_id`, `mode_version`, `setup_id`,
`setup_version`, `side` et le snapshot immuable #133/#303.

## Validation locale

```bash
cd python-orchestrator
PYTHONHASHSEED=1 python3 -m pytest \
  tests/test_backtesting_contracts.py \
  tests/test_backtesting_dataset.py \
  tests/test_backtesting_dataset_store.py -q
PYTHONHASHSEED=987654 python3 -m pytest \
  tests/test_backtesting_contracts.py \
  tests/test_backtesting_dataset.py \
  tests/test_backtesting_dataset_store.py -q
python3 -m pytest -q
python3 -m py_compile \
  app/backtesting/contracts.py \
  app/backtesting/dataset.py \
  app/backtesting/dataset_store.py
cd ..
git diff --check
```
