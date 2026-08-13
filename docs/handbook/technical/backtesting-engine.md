# Backtesting net deterministe

## Statut

Contrats v1, Dataset Builder deterministe, frontiere d'identite moderne,
projection des indicateurs Paper et pont canonique vers les regles TradingCore
livres pour #191.

Le premier runtime Backtrader executable est livre derriere ces frontieres. Il fixe la frontiere
de contrat entre les futurs composants :

```text
Dataset Builder
  -> Canonical Effective Config snapshot #133/#303
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
- le protocole PHP et son pont subprocess Python dans
  `trading-app/src/TradingCore/Backtesting` et
  `python-orchestrator/app/backtesting/tradingcore_bridge.py` ;
- la projection deterministe des fenetres Paper verifiees dans
  `trading-app/src/TradingCore/Backtesting/Indicator` et son pont Python
  `python-orchestrator/app/backtesting/indicator_bridge.py` ;
- des fixtures golden et des tests unitaires verrouillant les bytes, les checksums,
  la qualite et les conflits de publication ;
- cette page d'architecture.

Les resultats de backtest ne sont jamais presentes comme preuve live.

### Runtime Backtrader canonique v1

`CanonicalBacktestOrderPlanProjection` expose le `CanonicalOrderPlan` PHP
authentifie sans recalcul Python des regles, de la zone, du risque, du levier ou
des couts. Le miroir Pydantic refuse les champs absents/inconnus, les valeurs non
finies, une identite autre que `fake` local/test et toute divergence de
`plan_hash`.

`VerifiedBacktraderFeedAdapter` accepte un seul flux `CandleRecord` continu et
verifie. Les bougies sont livrees a Cerebro a `available_at`, jamais a leur
ouverture ou avant leur cloture. `CanonicalBacktraderRuntime` utilise Backtrader
`1.9.78.123` uniquement comme horloge d'iteration et transmet les barres a une
machine d'etat pure.

La v1 accepte un plan limit, un full fill au prix authentifie, attache le stop
full-size dans le meme evenement, puis ferme integralement au stop ou au premier
target. Si stop et target sont atteignables dans la meme bougie,
`conservative_stop_first` gagne. Une bougie qui chevauche l'expiration est
ambigue et rejetee. Une position encore ouverte en fin de dataset est egalement
rejetee, sans fermeture optimiste.

Pour une sortie au stop ou a un target canonique,
`backtrader_net_outcome.py` selectionne la branche de cout deja authentifiee
dans le plan PHP. Il publie le brut, les fees, spread, slippage, la provision
funding adverse, le net et le R exacts, puis lie le document au dataset,
`plan_hash`, `config_hash` et `cost_input_hash`. Python ne recalcule aucun cout
a partir des taux ou du notionnel. `funding_evidence=canonical_plan_provision`
signifie explicitement qu'il s'agit de la provision conservative du plan, pas
d'un funding historique realise. Une sortie `holding_expired` n'a pas encore
de branche de cout PHP authentifiee et echoue donc fermee au lieu d'inventer un
PnL.

Restent hors de ce lot : partial fills, fallback taker, funding historique
horodate, portefeuille multi-plan, PostgreSQL, metriques et rapports de
certification. Aucun endpoint prive ni execution mainnet n'est ajoute.

## Invariants verrouilles par les contrats v1

### Dataset versionne

Le builder ne lit pas directement les exports PHP Paper. Son entree est une
`DatasetSourceIdentity` dont la source a deja ete authentifiee par son
proprietaire, suivie uniquement de `CandleRecord` normalises. L'adapter PHP
livre par ce lot consomme exclusivement un `VerifiedPaperDatasetSnapshot`
construit pendant la lecture epinglee de `PaperDatasetVerifier` : il ne verifie
pas un fichier puis ne le rouvre jamais. Le snapshot en memoire est borne en
octets, evenements, noeuds et cles afin d'echouer avec un code stable avant un
epuisement memoire, y compris pour un JSON structurellement dense. Le builder
Python ne duplique pas l'authentification de `PaperDatasetVerifier`.

`PaperBacktestDatasetAdapter` revalide le manifeste et ses evenements, recalcule
le checksum NDJSON canonique, ancre les symboles natifs sur les catalogues OKX
et Hyperliquid, puis normalise uniquement les candles confirmees `1m`, `5m`,
`15m` et `1h`. Les autres evenements certifies sont ignores apres validation de
leur enveloppe. Les payloads candle restent specifiques a chaque venue :

- OKX utilise le timestamp exchange comme ouverture et derive la cloture
  exclusive ; le volume normalise est `volume_base` ;
- Hyperliquid exige `start_time`, la cloture inclusive exacte
  `start + duree - 1 ms`, et sa concordance avec le timestamp exchange ; le
  volume normalise est `volume`.

Dans les deux cas, `available_at = max(received_timestamp, close_at)` interdit
le look-ahead sur un historique recu a l'ouverture. `source_record_id` est
l'`event_id`, `market_type` vaut `perpetual`, et l'identite source lie schema,
version recorder, reseau, venue et `sha256` des evenements verifies. L'encodeur
PHP produit du JSON/NDJSON canonique; une fixture byte-for-byte est ensuite
validee par les modeles Python stricts et par `DatasetBuilder`.

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
Le repertoire cible est snapshotte au meme point et ses identite, mode, liens,
taille, `mtime_ns` et `ctime_ns` sont revalides apres tous les artefacts, ce qui
detecte aussi un echange d'entree tardif meme restaure.
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

### Identite moderne et config effective

`BacktestRunRequest` exige une `ModernTradingIdentity` exacte :

- `mode_id` et `mode_version` ;
- `setup_id` et `setup_version` ;
- `exchange` et `environment` ;
- `side`.

Seules les cellules publiees par les contrats #300/#301 sont acceptees. Les
anciens profils `regular`, `scalper` et `scalper_micro`, les variantes de casse,
les versions implicites et tout champ JSON `profile` sont rejetes sans alias ni
fallback.

La config est un `CanonicalEffectiveConfigSnapshot` #133/#303. Elle lie la
requete moderne, la config effective, les six couches ordonnees
`base -> mode -> setup -> exchange -> mode_exchange -> environment`, les
fichiers exacts, la provenance declaree, l'executabilite et les
blockers. Trois empreintes `sha256` distinctes protegent la config et le
catalogue de conditions (`config_hash`), le catalogue lui-meme
(`condition_catalog_hash`) et l'enveloppe complete (`snapshot_hash`). La
canonicalisation Python est verrouillee hash pour hash par une fixture calculee
avec les methodes publiques PHP de `CanonicalEffectiveConfigSnapshot`, y compris
Unicode, slash, float integral et notation scientifique.

La configuration effective et sa provenance sont gelees recursivement : une
structure source mutee apres validation ne peut pas changer silencieusement un
run. Le run echoue ferme si son identite differe de celle du snapshot, si
`executable` n'est pas vrai, si `blockers` n'est pas vide ou si
`execution_capability` n'est pas exactement `backtest`. Cette capacite exige
`exchange=fake`; `private_mainnet` est interdite.

La venue de donnees du dataset est independante de l'exchange simule. Un run
avec `exchange=fake` peut donc rejouer un dataset Paper verifie provenant
d'OKX ou d'Hyperliquid sans pretendre avoir execute un ordre sur cette venue.

### Pont canonique vers les regles TradingCore

Le pont evalue un setup moderne sur des snapshots d'indicateurs deja calcules.
Python ne reimplemente aucune regle : `BacktestTradingCoreBridge` lance, avec un
`argv` fixe et `shell=false`, la commande Symfony suivante :

```text
php trading-app/bin/console app:backtest:rules:evaluate --no-interaction --no-ansi
```

Depuis la racine du depot, une invocation peut etre reproduite sans argument ni
fichier temporaire impose par le protocole :

```bash
php trading-app/bin/console app:backtest:rules:evaluate \
  --no-interaction --no-ansi \
  < /chemin/vers/request.json \
  > /tmp/canonical-rule-result.json \
  2> /tmp/canonical-rule-error.log
status=$?
```

Le document JSON UTF-8 complet est lu sur `stdin`. Le schema d'entree exact est
`canonical-backtest-rule-request.v1` et contient uniquement :

- `schema_version` et `request_id` ;
- le `effective_config_snapshot` canonique complet #133/#303 ;
- `symbol`, `market_type` et `evaluated_at` en UTC termine par `Z` ;
- `indicators_by_timeframe`, mapping non vide indexe uniquement par `1m`, `5m`,
  `15m`, `1h` ou `4h`.

Chaque snapshot d'indicateurs contient au minimum `kline_time` et
`snapshot_identity`. Cette identite a exactement les champs `timeframe`,
`symbol`, `exchange`, `environment` et `market_type`; elle doit correspondre a
la requete, avec `exchange=fake` et `environment=local|test`. Les champs
d'indicateurs propres aux conditions restent controles par le catalogue et le
runtime PHP. Un champ critique absent, non fini ou stale produit un resultat
fail-closed, jamais une valeur optimiste par defaut.

Le schema de sortie exact est `canonical-backtest-rule-result.v1`. Il lie
`request_id`, l'identite moderne complete (`mode_id`, `mode_version`, `setup_id`,
`setup_version`, `side`, `exchange`, `environment`), `market_type`, `symbol`, les
trois hashes de config, `evaluated_at`, puis `passed`, `reason_code`, `trace`,
`input_hash` et `result_hash`.

#### Sorties et codes de retour

Un setup qui passe et un setup qui ne produit aucun trade sont tous deux des
succes de protocole : la commande sort avec le code `0`, ecrit exactement un
objet JSON compact suivi d'une newline sur `stdout` et n'ecrit rien sur
`stderr`. Le cas no-trade est porte par `passed=false` et un `reason_code`
explicite ; il ne doit pas etre confondu avec une panne du pont.

Une entree ou une evaluation invalide sort avec le code Symfony
`Command::INVALID`, donc `2`, n'ecrit rien sur `stdout` et ecrit une seule ligne
stable sur `stderr` :

```text
canonical_backtest_rule_command_invalid:<reason>
```

Les raisons de cadrage CLI incluent `input_read_failed`, `input_too_large`,
`input_blank`, `duplicate_object_key`, `json_depth_exceeded`,
`json_structure_too_large`, `json_invalid` et `root_object_required`. Les
rejets de l'evaluateur utilisent exclusivement un code filtre
`canonical_*`; une exception non sure devient `evaluation_failed`. Aucun
payload ni diagnostic interne du processus enfant n'est recopie par le pont
Python.

Cote Python, executable absent, I/O, timeout, depassement de sortie, code non
nul, JSON de sortie invalide et derive d'identite/hashes deviennent
respectivement des `TradingCoreBridgeError` stables. En particulier, tout code
non nul, y compris `2`, devient `tradingcore_bridge_process_failed` : le detail
reste sur le canal operateur `stderr`, pas dans un resultat de backtest.

#### Bornes et isolation

Les gardes sont cumulatives et fail-closed :

- entree canonique et `stdin` limites a 8 MiB (`8 388 608` octets) ;
- JSON PHP limite a une profondeur de 128 et a 20 000 tokens structurels
  (ouvertures de conteneurs et virgules), avec rejet des cles dupliquees ;
- timeout subprocess Python de 15 secondes par defaut ;
- `stdout` et `stderr` lus concurremment et limites chacun a 8 MiB par defaut ;
  la limite configurable doit rester entre 1 octet et 8 MiB ;
- processus tue sur timeout, depassement ou erreur I/O.

Le snapshot est re-resolu en PHP puis compare byte-semantiquement a la config
soumise avant d'appeler `CanonicalSetupRuleRuntime`. Seules les cellules
`exchange=fake`, `environment=local|test`, `execution_capability=backtest`,
`executable=true` et sans blocker sont admises. Le pont ne fait aucun retry,
aucun fallback et n'accede ni a une base de donnees, ni a une API exchange, ni
a un chemin d'ordres, de portefeuille, de Paper execution ou de mainnet.

#### Hashes et determinisme

`input_hash` est le SHA-256 prefixe `sha256:` du document d'entree canonique :
objets tries par cle, scalaires compatibles PHP/Python et valeurs finies. Le
resultat reprend les trois preuves du snapshot (`config_hash`,
`condition_catalog_hash`, `snapshot_hash`). `result_hash` couvre de la meme
facon tout le resultat sauf lui-meme. Le pont Python recalcule les hashes et
reverifie tous les champs lies a la requete.

La lineage replay est derivee de `input_hash`; le champ runtime non deterministe
`plan_cache_hit` est retire de la trace et son eventuel `evaluated_at` est
remplace par l'instant fourni. A requete, code et catalogues identiques, deux
invocations produisent donc les memes bytes sur `stdout`, y compris pour un
no-trade.

### Projection livre : bougies Paper vers indicateurs PHP

Le `VerifiedIndicatorWindowBuilder` execute d'abord
`DatasetSerializer.verify()` sur les artefacts complets, puis seulement
selectionne le suffixe admissible le plus recent a `evaluated_at`. Il ne peut
donc ni lire ni slicer un dataset non verifie. Chaque timeframe natif demande
exactement 250 bougies `backtest-candle.v1` ; `4h` demande exactement 1 000
bougies `1h`, agregees en 250 bougies `4h` alignees UTC. Si `1h` et `4h` sont
demandees ensemble, elles coexistent dans la meme requete : les 1 000 bougies
horaires servent au `4h` et leur suffixe de 250 bougies sert au snapshot `1h`.
Il n'existe ni source native `4h`, ni backfill, ni substitution de timeframe,
ni calcul sur bougie ouverte.

La projection PHP valide forme, provenance, chronologie, disponibilite et
valeurs finies avant de calculer exactement 250 barres par snapshot. Le
calculateur est exclusivement `php_fallback_v1` : il ne branche pas sur
`php-trader`, afin qu'une meme preuve Paper produise les memes indicateurs sur
tous les hotes. Les sorties contiennent la forme canonique attendue par les
regles, `kline_time`, `window_hash`, `input_hash` et `result_hash`; les deux
derniers lient respectivement la requete normalisee et le resultat complet hors
son propre hash.

La provenance de marche (`source_network`, `market_data_venue`, checksums,
`dataset_id` et `market_type=perpetual`) reste dans `dataset_binding`. En
revanche, chaque `snapshot_identity` porte intentionnellement `exchange=fake`:
une source OKX ou Hyperliquid est une provenance de donnees, jamais une identite
d'execution ou un ordre sur cette venue.

Le sous-processus Python est gele apres construction : argv sans shell,
environnement minimal deterministe, timeout de 15 secondes et lecture bornee de
`stdout`/`stderr`. Entree et sortie ont une limite de 8 MiB, le decodeur PHP
refuse plus de 128 niveaux, plus de 20 000 tokens structurels, les cles JSON
dupliquees et tout contenu invalide. Les erreurs de taille, profondeur, tokens,
sortie, I/O ou timeout sont stables et fail-closed; aucun resultat partiel,
retry ou fallback n'est produit.

La reproduction operateur du protocole est la commande Symfony suivante; elle
lit un unique objet JSON sur stdin et ecrit un unique resultat canonique sur
stdout :

```bash
cd trading-app
php bin/console app:backtest:indicators:project --no-interaction --no-ansi < request.json
```

Le controle Python de retour recalcule, a titre de preuve uniquement, les
`window_hash` des fenetres natives et derivees et les compare a la reponse PHP.
Il derive en memoire la seule representation `4h` necessaire a cette preuve,
sans generer de bougie source et sans calculer d'indicateur. PHP reste la seule
autorite de calcul; son projecteur demeure l'autorite canonique d'agregation
`4h`.

### Reproductibilite

`BacktestRunRequest` porte :

- dataset ;
- identite moderne exacte ;
- snapshot canonique de config ;
- symboles et timeframes inclus dans le dataset ;
- periode ;
- commit Git ;
- version moteur ;
- seed ;
- version du modele de cout ;
- politique intra-bougie.

Le fingerprint de reproductibilite est un hash canonique de tous ces inputs. Il
lie notamment les sept champs d'identite, les trois hashes du snapshot, le
dataset et ses checksums, la periode, le commit Git, la seed, le modele de cout
et la politique intra-bougie. A inputs identiques, le fingerprint reste
identique ; toute falsification semantique du snapshot est rejetee avant son
calcul.

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
- le commit Git et le dataset ;
- les sept champs d'identite moderne et la direction coherente avec `side` ;
- `config_hash`, `condition_catalog_hash` et `snapshot_hash`.

Un trade simule sans SL est invalide. Les signaux non executes seront modelises
dans un contrat separe lors du lot execution simulator.

## Relation avec #132

#132 reste ouverte tant que le vrai jeu de donnees n'a pas produit de baseline
quantifiee exploitable. Cela ne bloque pas ce lot de contrats #191 : le moteur est
prepare maintenant, puis les couts et la calibration seront compares a la baseline
reelle lorsque les donnees seront disponibles.

## Hors scope restant

- publication filesystem et commande operateur de l'adapter Paper ;
- simulation maker/taker ;
- partial fills ;
- funding historique ;
- couts arbitraires hors branches stop/targets authentifiees, borrow et liquidation ;
- ledger de trades et resultats de backtest ;
- rapports de metrics ;
- simulation 100 trades ;
- validation statistique.

Ces elements restent dans #191 et doivent etre livres par PRs suivantes avec
tests golden dedies.

Le Dataset Builder reste independant de toute strategie et n'expose aucun champ
`profile`, mode, setup ou alias. La frontiere de run utilise desormais les
identites exactes, le snapshot immuable #133/#303 et la projection
d'indicateurs PHP. Elle ne rend pas encore un mode moderne executable de bout
en bout : les partial fills, le funding historique et le ledger durable restent
differes.

Aucune execution reelle mainnet n'est autorisee par ce chantier. Un resultat de
backtest porte toujours `result_is_live_proof=false` et n'ouvre aucun canal
d'ecriture prive vers une venue.

## Validation locale

```bash
cd python-orchestrator
python3 -m pytest -q \
  tests/test_backtesting_modern_identity.py \
  tests/test_backtesting_contracts.py \
  tests/test_backtesting_tradingcore_bridge.py \
  tests/test_backtesting_indicator_bridge.py \
  tests/test_schemas.py \
  tests/test_symfony_client.py
PYTHONHASHSEED=1 python3 -m pytest \
  tests/test_backtesting_contracts.py \
  tests/test_backtesting_dataset.py \
  tests/test_backtesting_dataset_store.py -q
PYTHONHASHSEED=987654 python3 -m pytest \
  tests/test_backtesting_contracts.py \
  tests/test_backtesting_dataset.py \
  tests/test_backtesting_dataset_store.py -q
python3 -m pytest -q
python3 -m compileall -q app tests
cd ..
cd trading-app
php bin/phpunit \
  tests/TradingCore/Backtesting/Indicator \
  tests/TradingCore/Backtesting/Json \
  tests/Command/BacktestProjectCanonicalIndicatorsCommandTest.php \
  tests/Command/BacktestEvaluateCanonicalRulesCommandTest.php \
  tests/MtfValidator/Policy/CanonicalSetupRuleRuntimeTest.php
cd ..
python3 -m mkdocs build --strict
git diff --check
```
