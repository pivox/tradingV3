# OKX private WS observability

Ce runbook exploite uniquement le worker d'observabilite privee OKX demo. Il
n'envoie aucun ordre. #188 reste le gate ouvert de preuve runtime representative.
DEMO-005 est termine et livre avec la decision `blocked`; cette decision
distincte maintient la gate mutative fermee.
La recette reelle OKX demo executee pour OKX-010 est restee fail-closed : OKX a
ferme la connexion pendant le login. Aucun ordre exchange n'a ete envoye et ce
resultat reste un gate d'environnement externe, pas une preuve de readiness.

## Preflight

Utiliser exclusivement une cle API OKX demo a permissions read-only. Placer les
variables dans l'environnement du shell ou dans le fichier Compose `.env`. Cette
commande verifie leur presence sans afficher leur valeur :

```bash
bash -c '
required="OKX_DEMO_API_KEY OKX_DEMO_API_SECRET OKX_DEMO_API_PASSPHRASE"
for name in $required; do
  if [ -n "${!name:-}" ] || awk -v key="$name" '\''
    index($0, key "=") == 1 {
      value = substr($0, length(key) + 2)
      if (length(value) > 0) found = 1
    }
    END { exit(found ? 0 : 1) }
  '\'' .env 2>/dev/null; then
    printf "%s: set\n" "$name"
  else
    printf "%s: MISSING\n" "$name"
  fi
done
'
```

Les valeurs non sensibles attendues sont `OKX_ENV=demo`,
`OKX_SIMULATED_TRADING=1`,
`OKX_WS_PRIVATE_URI=wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999`,
`DEMO_TRADING_ENABLED=0`, `OKX_DEMO_TRADING_ENABLED=0` et
`OKX_LIVE_ENABLED=0`. Le service Compose force ces trois gates consommees.

Verifier le profil sans rendre la configuration resolue, qui contiendrait les
valeurs injectees :

```bash
docker compose config --profiles
```

La sortie doit contenir `okx-observability`.

## Demarrage

```bash
docker compose --profile okx-observability up --build -d trading-app-okx-private-ws
docker compose --profile okx-observability ps trading-app-okx-private-ws
docker compose --profile okx-observability logs --tail=100 trading-app-okx-private-ws
```

Les logs applicatifs dedies sont dans
`trading-app/var/log/okx-private-ws-YYYY-MM-DD.log`. Ils ne doivent contenir que
des transitions, phases et codes bornes, jamais de message WS brut.

Le login est borne a 5 secondes. Une fois authentifie, l'ensemble
souscriptions + snapshot REST + reconciliation doit devenir pret en moins de
10 secondes. Chaque lecture REST privee utilise un `timeout` et un
`max_duration` de 2 secondes; le snapshot ne lit que account, positions, ordres
ouverts et fills recents. Une erreur ou un depassement de ces bornes maintient
le statut fail-closed, ferme la connexion courante et programme une reconnexion.

## Verification runtime

Attendre l'authentification, les souscriptions et le snapshot initial, puis :

```bash
docker compose exec trading-app-php php bin/console app:exchange:runtime-check okx perpetual
```

La capability privee doit etre supportee, connectee, authentifiee, complete et
fraiche. Cela peut contribuer a `demo_testnet_candidate`; cela ne doit jamais
annoncer `demo_testnet_enabled`, `live_ready` ou `mainnet_ready`.

Lire uniquement les champs allow-listes du statut Redis :

```bash
docker compose exec -T redis redis-cli --json GET tradingv3:okx:demo:private-observability:v1 \
  | jq 'fromjson | {schema_version,exchange,environment,endpoint_id,connected,authenticated,orders_stream_ready,fills_stream_ready,fills_source,positions_stream_ready,initial_snapshot_loaded,reconciliation_fresh,reconnecting,connected_at,last_heartbeat_at,last_event_at,observed_at,blocking_errors,warnings}'
docker compose exec -T redis redis-cli TTL tradingv3:okx:demo:private-observability:v1
```

Ne jamais afficher le document Redis brut. Le TTL doit rester compris entre 1 et
10 secondes pendant que le worker publie son etat.

Avant d'annoncer `initial_snapshot_loaded=true`, le worker valide et deduplique
les lignes du snapshot, rejette les doublons contradictoires, puis reconcilie et
projette ordres, positions et fills dans les projections locales. Un snapshot
complet ferme explicitement les positions locales OKX perpetual absentes du
snapshot. Les IDs de fills sont derives de `instId + tradeId`, de sorte qu'un
`tradeId` reutilise sur deux instruments ne soit pas fusionne.

Les evenements WS prives appliquent des allowlists positives par type
order/fill/position. Les champs inconnus, sensibles ou imbriques sont retires;
aucun payload provider brut ne doit atteindre Redis, les logs ou les metadata
des projections Doctrine. Une enveloppe ou une ligne supportee mal formee est
rejetee avec `okx_private_ws_message_invalid`, puis la connexion est recyclee.

## Verification fail-closed

```bash
docker compose --profile okx-observability stop trading-app-okx-private-ws
sleep 11
docker compose exec trading-app-php php bin/console app:exchange:runtime-check okx perpetual
```

Apres 11 secondes, le statut Redis a expire et l'observabilite privee doit etre
absente/non prete. La readiness d'ecriture reste fermee.

## Incidents

### Auth

Symptomes : `okx_private_ws_authentication_failed` ou reconnexions apres login. Verifier
uniquement la presence des trois variables, les permissions read-only de la cle
demo, `OKX_ENV=demo`, `OKX_SIMULATED_TRADING=1` et l'horloge de l'hote. Ne jamais
journaliser la signature, la passphrase ou le message de login. Une absence de
reponse au login sous 5 secondes ou un rejet d'authentification ferme la
connexion et declenche le backoff de reconnexion.

### Subscription

Symptomes : stream orders ou positions non pret. Rechercher les codes
`okx_private_ws_subscription_failed` et le nom de phase dans le canal dedie.
Verifier l'acces aux canaux `orders`, `positions` et `balance_and_position`, puis
redemarrer le worker. Un canal requis manquant reste fail-closed. Un rejet de
souscription ou une readiness incomplete apres 10 secondes recycle la connexion.

### Snapshot et projection

Symptomes : `okx_private_rest_snapshot_failed`, snapshot incomplet ou cycles de
reconnexion apres authentification. Verifier les lectures read-only account,
positions, ordres ouverts et fills recents. Les requetes signees ne sont
autorisees que vers l'origine HTTPS exacte de l'environnement :
`https://eea.okx.com` en demo et `https://www.okx.com` en live. Scheme, port,
userinfo, sous-domaine, chemin, query ou fragment ajoutes a l'origine sont
refuses avant envoi.

La reconciliation est volontairement fail-closed : valeur invalide, doublon
contradictoire, projection Doctrine impossible ou budget global de 10 secondes
depasse entrainent une reconnexion. Ne pas marquer manuellement le snapshot
comme charge et ne pas ignorer une projection partielle.

### Fills VIP

Le rejet du canal VIP `fills` est attendu pour certains comptes. Le statut doit
alors exposer `fills_source=orders_plus_rest` et
`okx_fills_channel_vip_unavailable`. Le stream `orders` et le snapshot REST des
fills doivent tous deux etre operationnels; sinon la couverture fills reste non
prete.

### Redis

Une publication du worker en echec est journalisee avec
`okx_private_ws_status_store_failed`. Le client Redis est cree a la demande; une
erreur d'operation invalide la connexion courante et l'operation suivante tente
une nouvelle connexion. Une panne lors de la publication initiale garde les
timers du worker actifs, ne demarre aucune session WS tant que le store ne
repond pas et programme les tentatives de reprise. Le worker n'est pas tue, mais
aucun statut pret n'est publie et le TTL eventuel expire : le systeme reste
fail-closed.

Une lecture du store indisponible pendant le runtime-check est signalee
separement par `okx_private_observability_status_store_unavailable`. Dans les
deux cas, verifier la sante du service `redis`, le reseau
`trading-app-net` et les variables `REDIS_HOST`/`REDIS_PORT` par leur nom
seulement. Ne pas contourner le store avec le client Redis global ou `MockRedis`.

### Precision des quantites

La migration additive `Version20260713150000` ajoute
`futures_order.quantity_decimal` et
`futures_order.filled_quantity_decimal` en `NUMERIC(36,18)`, avec backfill des
colonnes entieres historiques. Les lecteurs preferent ces valeurs exactes et
conservent un fallback legacy pour les lignes anterieures. Verifier le schema et
la relecture sur PostgreSQL avec :

```bash
docker compose exec -T trading-app-php php vendor/bin/phpunit tests/Repository/FuturesOrderExactQuantityPostgresTest.php
```

Une quantite decimale invalide ou contradictoire doit bloquer la projection;
elle ne doit jamais etre tronquee vers une quantite entiere.

### Stale

Un `observed_at` ou heartbeat age de plus de 10 secondes produit un statut non
pret. Verifier la charge du conteneur, l'horloge UTC, le ping toutes les 5
secondes et le pong sous 4 secondes. Ne pas augmenter TTL ou freshness pour
masquer l'incident.

### Reconnect

Le backoff attendu est `1/2/4/8/15` secondes puis reste plafonne a 15 secondes.
Des cycles continus indiquent une panne endpoint, auth, heartbeat ou reseau.
Conserver `OKX_LIVE_ENABLED=0`; ne jamais basculer vers l'endpoint live pour
resoudre un incident demo.

## Rollback

```bash
docker compose --profile okx-observability stop trading-app-okx-private-ws
docker compose --profile okx-observability rm -f trading-app-okx-private-ws
sleep 11
docker compose exec trading-app-php php bin/console app:exchange:runtime-check okx perpetual
```

Confirmer l'etat fail-closed, puis retirer le deploiement qui active le profil
`okx-observability`. Conserver `OKX_DEMO_TRADING_ENABLED=0`,
`OKX_LIVE_ENABLED=0`, `DEMO_TRADING_ENABLED=0`,
`demo_testnet_write_enabled=false`, `mainnet_write_enabled=false` et
`kill_switch_enabled=true`. Ne pas supprimer Redis, les logs partages ou les
credentials d'autres services dans ce rollback.

Ce rollback n'envoie aucun ordre et ne valide aucune readiness mutative. #188
reste ouvert apres l'operation et la decision DEMO-005 livree reste `blocked`;
les gates d'ecriture restent fermees.
