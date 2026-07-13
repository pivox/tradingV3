# Conception de l'observabilité WebSocket privée OKX demo

## Statut et périmètre

Cette conception livre le dernier prérequis technique d'observabilité pour
OKX-010. Elle est strictement read-only : elle n'ajoute aucune opération submit,
amend, cancel, leverage, protection ou autre écriture exchange. Elle n'autorise
pas OKX-010 tant que #188 ou DEMO-005 restent bloqués.

L'implémentation doit :

- se connecter uniquement au WebSocket privé OKX demo ;
- s'authentifier uniquement avec des credentials dédiés `OKX_DEMO_*` ;
- observer les ordres, fills et positions ;
- réconcilier un snapshot REST privé initial avant de devenir prête ;
- publier un statut de readiness redacted à durée de vie courte ;
- rester fail-closed en cas d'absence, d'expiration, de reconnexion ou de
  couverture partielle ;
- conserver tous les flags persistants d'écriture demo désactivés et le kill
  switch actif.

Références :

- [Guide API v5 OKX](https://www.okx.com/docs-v5/en/)
- [Politique d'observabilité privée exchange](../../handbook/technical/exchange-private-observability-policy.md)
- [Readiness OKX demo](../../handbook/technical/okx-demo-readiness.md)

## Décisions

### Worker dédié

Une commande Symfony dédiée, `app:okx:private-ws`, possède une unique connexion
WebSocket privée longue durée. Le futur chemin mutatif ne possède ni n'ouvre la
socket. `app:exchange:runtime-check` lit uniquement le statut du worker.

Cela évite qu'une connexion ouverte à la demande soit confondue avec une
observabilité continue et donne un propriétaire unique aux déconnexions,
reconnexions et heartbeats périmés.

### WebSocket plutôt qu'une exemption polling

La politique commune `ExchangePrivateObservabilityPolicy` reste stricte. Aucune
exception REST-only n'est ajoutée. Le worker satisfait les exigences existantes
de canal privé authentifié, visibilité ordres/fills/positions, snapshot initial et
réconciliation fraîche.

### Statut Redis avec TTL

Le worker publie un statut versionné sous la clé :

```text
tradingv3:okx:demo:private-observability:v1
```

Le TTL du statut est de 10 secondes et le worker sain le rafraîchit au maximum
toutes les 3 secondes. Le store de production utilise un client Redis dédié,
configuré par `REDIS_HOST` et `REDIS_PORT` ; il ne doit pas réutiliser le service
historique `MockRedis`. Les tests utilisent un store en mémoire.

Redis est une coordination transitoire, pas un stockage de preuves. Les preuves
lifecycle continuent d'utiliser les surfaces d'audit et de lineage existantes.
Aucun payload exchange brut n'est stocké dans Redis.

## Gardes endpoint et credentials

Le worker démarre uniquement si toutes les conditions suivantes sont vraies :

- `OKX_ENV=demo` ;
- `OKX_SIMULATED_TRADING=1` ;
- `OKX_LIVE_ENABLED=0` ;
- `OKX_DEMO_API_KEY`, `OKX_DEMO_API_SECRET` et
  `OKX_DEMO_API_PASSPHRASE` sont non vides ;
- l'URI utilise le schéma `wss`, l'hôte `wspap.okx.com`, le port `8443` et le
  chemin `/ws/v5/private` ;
- la query est vide ou exactement égale à `brokerId=9999`.

Les userinfo, fragments, ports alternatifs, suffixes d'hôte, redirections et toute
autre query sont refusés. Le worker ne lit jamais de variables portant des noms de
credentials live/mainnet.

## Authentification et souscriptions

Le signer de login utilise le contrat WebSocket OKX :

```text
timestamp + "GET" + "/users/self/verify"
```

La signature est un HMAC-SHA256 utilisant le secret demo, encodé en Base64. Les
secrets et la signature générée ne sont jamais loggés ni sérialisés dans le statut.

Après un acknowledgement de login réussi, le worker souscrit à :

- `orders` avec `instType=SWAP` ;
- `positions` avec `instType=SWAP` ;
- `balance_and_position` ;
- `fills` lorsque le compte accepte cette souscription.

OKX réserve le canal `fills` aux niveaux de frais éligibles. L'erreur `64003` ne
suffit donc pas, à elle seule, à rendre le worker indisponible. Dans cet unique
cas, la visibilité des fills est couverte par les mises à jour `orders` contenant
les données de fill et par la réconciliation REST bornée des fills. Toute autre
erreur de souscription au canal `fills` reste bloquante.

La readiness des souscriptions repose sur les acknowledgements explicites d'OKX,
jamais uniquement sur les messages envoyés par le client.

## Snapshot initial et réconciliation

Avant que le statut puisse devenir prêt, le worker exécute un snapshot REST
read-only via le provider bundle OKX existant :

- l'endpoint de compte est lisible, y compris pour un compte demo vide valide ;
- les positions ouvertes sont lisibles ;
- les ordres standards ouverts sont lisibles ;
- les ordres algo ouverts sont lisibles ;
- les fills récents sont lisibles.

Un ensemble de données vide est valide. Une erreur de transport, une réponse mal
formée, un provider non supporté ou un parsing ambigu sont bloquants. Le snapshot
établit une baseline ; les messages WebSocket suivants sont normalisés avec les
normalizers lifecycle OKX existants et comparés à cette baseline.

Le worker marque la réconciliation fraîche uniquement lorsqu'aucune application
de snapshot ou de message n'est en cours et que toutes les lectures requises ont
réussi. Une reconnexion invalide toujours l'ancien snapshot et en exige un nouveau.

## Contrat de statut

`OkxPrivateWebSocketObservabilityStatus` contient uniquement :

```text
schema_version
exchange
environment
endpoint_id
connected
authenticated
orders_stream_ready
fills_stream_ready
fills_source
positions_stream_ready
initial_snapshot_loaded
reconciliation_fresh
reconnecting
connected_at
last_heartbeat_at
last_event_at
observed_at
blocking_errors
warnings
```

`endpoint_id` est une constante telle que `okx_demo_private_v1`, pas l'URI
configurée. `fills_source` vaut `fills_channel` ou `orders_plus_rest`. Les listes
d'erreurs et warnings contiennent uniquement des codes canoniques et passent par
les règles existantes de redaction des messages sensibles.

L'enveloppe Redis contient le statut JSON et sa version de schéma. Une version
inconnue, un JSON invalide, des champs manquants, un exchange/environnement
incorrect ou un timestamp futur sont traités comme absents/non prêts.

## Intégration runtime

L'adapter OKX annonce le support du WebSocket privé lorsque le client et le worker
existent. Le support de la capability n'implique pas la readiness runtime.

Le runtime-check lit le statut et le mappe vers
`ExchangePrivateObservabilityStatus`. Il déclare l'observabilité privée prête
uniquement si :

- la clé Redis existe et n'a pas expiré ;
- le statut cible `okx/demo` et l'identifiant d'endpoint est allow-listé ;
- la connexion et l'authentification sont confirmées ;
- la couverture ordres, fills et positions est confirmée ;
- le snapshot initial est chargé ;
- la réconciliation est fraîche ;
- `reconnecting` vaut false ;
- aucune erreur bloquante n'existe ;
- `observed_at` et `last_heartbeat_at` ont au plus 10 secondes.

La politique commune existante reste l'autorité finale. Un statut sain peut
retirer `private_observability_absent_for_dry_run` ; il ne peut ni désactiver le
kill switch, ni activer les écritures demo, ni transformer DEMO-005 en approbation.

## Cycle de vie du worker et gestion des erreurs

Séquence de démarrage :

1. valider la configuration et l'endpoint ;
2. publier un statut `connecting` non prêt ;
3. se connecter et s'authentifier ;
4. envoyer les commandes de souscription ;
5. exécuter, réconcilier et projeter immédiatement le snapshot REST initial, sans
   attendre les acknowledgements de souscription ;
6. publier le statut prêt uniquement lorsque le snapshot est projeté et que tous
   les acknowledgements requis ont été reçus, puis entrer dans la boucle de
   réception.

Le worker gère le ping/pong du protocole et rafraîchit le statut lors d'un
heartbeat ou d'un événement privé. Une déconnexion ou une erreur publie un statut
non prêt avant la reconnexion. Le délai de reconnexion utilise un backoff
exponentiel borné : 1, 2, 4, 8 puis 15 secondes. Chaque tentative reconstruit
l'authentification, les souscriptions et le snapshot REST.

Une panne Redis reste fail-closed. Le worker peut conserver la socket et réessayer
le store, mais les lecteurs runtime ne voient aucun statut valide. Les échecs
d'authentification, de garde endpoint, les messages mal formés, les erreurs de
souscription inattendues et les échecs de snapshot sont représentés par des codes
stables, jamais par les corps de réponse bruts.

SIGTERM et SIGINT publient un statut `worker_stopping` non prêt lorsque Redis est
disponible, ferment la socket puis terminent proprement.

## Sécurité et redaction

- Aucun credential, signature de login, header HTTP, cookie, payload de connexion
  ou réponse exchange brute n'est loggé ni persisté.
- Les logs contiennent uniquement l'identifiant d'endpoint, le nom du canal,
  l'état de connexion, un code d'erreur canonique et des identifiants de
  corrélation générés localement.
- Les messages entrants sont parsés en JSON structuré et transmis à des
  normalizers allow-listés. Les champs inconnus sont ignorés au lieu d'être copiés
  dans le statut.
- Le worker n'expose aucun endpoint HTTP et n'accepte aucune commande d'écriture.
- Les tests recherchent les secrets dans les statuts sérialisés, logs, exceptions
  et fixtures.

## Tests

Les tests unitaires couvrent :

- la signature de login déterministe et le format du timestamp ;
- l'allow-list endpoint, notamment suffixe d'hôte, userinfo, port, chemin, query et
  refus mainnet ;
- les gardes credentials et simulated trading ;
- les succès/échecs de login et la redaction ;
- les acknowledgements des souscriptions requises ;
- le succès du canal `fills` et le fallback exact sur `64003` ;
- le maintien du blocage pour toute autre erreur `fills` ;
- les snapshots REST complet, vide, partiel, mal formé et en échec ;
- la normalisation ordre/fill/position sans persistance du payload brut ;
- la sérialisation Redis, le versioning, TTL, absence, corruption, expiration,
  timestamp futur et mauvaise cible ;
- la déconnexion, reconnexion, backoff, reprise du snapshot et arrêt propre ;
- la readiness runtime avec des statuts sains et non sains ;
- l'absence de tout appel submit, cancel, amend, leverage ou protection.

Les tests d'intégration utilisent des transports WebSocket et REST fake ainsi
qu'un service Redis réel lorsque le comportement TTL doit être vérifié. Un smoke
test OKX demo avec credentials est uniquement opérateur, read-only et exclu de la
CI. Il prouve login, souscriptions, snapshot, statut, déconnexion et rollback sans
placer d'ordre.

La vérification obligatoire comprend PHPUnit ciblé, PHPStan sur les fichiers
touchés, `lint:container --no-debug`, `lint:yaml config`, `mkdocs build --strict`,
les tests d'intégration Redis TTL et `git diff --check`.

## Déploiement et rollback

Le worker est désactivé par défaut et s'exécute dans un service Compose dédié,
uniquement lorsqu'un opérateur le démarre explicitement. Déployer le code ne
démarre aucune socket et ne modifie aucun flag d'écriture.

Séquence d'activation opérateur :

1. confirmer que les flags persistants d'écriture demo restent à `0` et que le
   kill switch reste actif ;
2. démarrer uniquement le worker d'observabilité privée ;
3. exécuter le runtime-check read-only ;
4. observer un statut prêt et frais ;
5. arrêter le worker et confirmer que la readiness devient indisponible en au plus
   10 secondes ;
6. le redémarrer et confirmer un nouveau snapshot complet.

Rollback immédiat :

1. arrêter le worker ;
2. supprimer `tradingv3:okx:demo:private-observability:v1` si la clé existe encore ;
3. conserver `DEMO_TRADING_ENABLED=0` et `OKX_DEMO_TRADING_ENABLED=0` ;
4. conserver `OKX_LIVE_ENABLED=0` et les kill switches exchange/global actifs ;
5. confirmer que l'observabilité privée runtime est indisponible.

Aucun nettoyage de position ou d'ordre n'est requis car cette conception
n'introduit aucune mutation exchange.

## Hors périmètre

- soumission d'ordre, annulation, leverage, SL/TP ou compensation OKX ;
- connectivité ou credentials mainnet ;
- WebSocket public de données de marché ;
- changements stratégie, MTF, EntryZone, risque, leverage ou protection ;
- modification des décisions #188 ou DEMO-005 ;
- preuve d'ordre demo réel.
