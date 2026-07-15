# Plan d'implémentation de l'observabilité WebSocket privée OKX

> **Statut historique au 13 juillet 2026 :** ce plan a ete execute pour les taches 1 a 8 et pour la validation locale de la tache 9. Une case cochee constate une etape livree ; elle ne prouve aucune readiness d'ecriture. La recette reelle demo n'a pas atteint la readiness : DEMO-005 est termine avec la decision `blocked` et #188 reste ouvert. Les etapes de smoke reussi, PR, merge et mise a jour post-merge restent donc non cochees.

**Objectif :** Ajouter un worker WebSocket privé OKX demo strictement read-only, publier sa readiness éphémère dans Redis et la brancher au contrôle runtime commun sans jamais activer une écriture exchange.

**Architecture :** Une commande Symfony possède la connexion Pawl et authentifie les credentials demo. Après le login, elle envoie les souscriptions, exécute et projette immédiatement le snapshot REST privé, puis n'accorde la readiness qu'une fois le snapshot projeté et les acknowledgements requis reçus. Un store Redis dédié publie un statut versionné avec TTL ; `app:exchange:runtime-check` le lit et le convertit en `ExchangePrivateObservabilityStatus`, tandis que la politique commune reste l'autorité finale.

**Stack technique :** PHP 8.2, Symfony 7.1 Console/DI, ReactPHP, Ratchet Pawl, ext-redis, PHPUnit 11, PHPStan, Docker Compose, MkDocs.

---

## Structure des fichiers

### Protocole et sécurité OKX

- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketEndpointGuard.php` : accepte uniquement l'endpoint privé demo canonique.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketLoginSigner.php` : produit les arguments de login sans exposer le secret.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSession.php` : machine d'état pure login/souscriptions/événements.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketTransportInterface.php` : frontière transport testable.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/PawlOkxPrivateWebSocketTransport.php` : adaptation ReactPHP/Pawl.

### Snapshot, statut et worker

- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateRestSnapshotProbe.php` : baseline REST read-only compte/positions/ordres/fills.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketObservabilityStatus.php` : DTO versionné et borné.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketStatusStoreInterface.php` : contrat de publication/lecture/suppression.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/RedisOkxPrivateWebSocketStatusStore.php` : store Redis TTL 10 secondes.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketObservabilityPolicy.php` : validation fraîcheur/couverture et mapping commun.
- `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketWorker.php` : cycle connexion, snapshot, heartbeat, reconnexion et arrêt.
- `trading-app/src/Command/OkxPrivateWebSocketCommand.php` : commande `app:okx:private-ws`.

### Intégration et exploitation

- `trading-app/src/Command/ExchangeRuntimeCheckCommand.php` : lecture du statut OKX demo.
- `trading-app/src/Exchange/Adapter/OkxExchangeAdapter.php` : annonce la capability, distincte de la readiness.
- `trading-app/config/services.yaml` : wiring, paramètres Redis et constantes sûres.
- `trading-app/config/packages/monolog.yaml` : canal de logs redacted.
- `docker-compose.yml` : worker opt-in, désactivé par défaut.
- `.env.example` : variables documentées avec valeurs d'écriture désactivées.
- `trading-app/src/Exchange/Okx/README.md` : contrat technique.
- `docs/handbook/technical/okx-demo-readiness.md` : matrice de readiness mise à jour.
- `docs/handbook/runbooks/okx-private-ws-observability.md` : démarrage, vérification, incident et rollback.
- `mkdocs.yml` : navigation du runbook.

---

### Tâche 1 : Verrouiller l'endpoint et signer le login

**Fichiers :**
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketEndpointGuard.php`
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketLoginSigner.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketEndpointGuardTest.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketLoginSignerTest.php`

- [x] **Étape 1 : Écrire les tests en échec**

```php
#[TestWith(['wss://wseeapap.okx.com:8443/ws/v5/private'])]
public function testAcceptsOnlyCanonicalDemoPrivateUri(string $uri): void
{
    self::assertSame('okx_demo_private_v1', (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri));
}

#[TestWith(['wss://ws.okx.com:8443/ws/v5/private'])]
#[TestWith(['wss://user@wspap.okx.com:8443/ws/v5/private'])]
#[TestWith(['wss://wspap.okx.com.evil.test:8443/ws/v5/private'])]
#[TestWith(['wss://wspap.okx.com/ws/v5/private'])]
#[TestWith(['wss://wspap.okx.com:8443/ws/v5/public'])]
#[TestWith(['wss://wseeapap.okx.com:8443/ws/v5/private?x=1'])]
public function testRejectsEveryNonAllowlistedUri(string $uri): void
{
    $this->expectExceptionMessage('okx_demo_private_ws_endpoint_not_allowed');
    (new OkxPrivateWebSocketEndpointGuard())->assertAllowed($uri);
}

public function testBuildsTheDocumentedLoginSignature(): void
{
    $args = (new OkxPrivateWebSocketLoginSigner(new OkxAuthSigner()))->buildLoginArgs(
        'demo-key', 'demo-secret', 'demo-passphrase', '1538054050',
    );
    self::assertSame('1538054050', $args['timestamp']);
    self::assertSame(
        base64_encode(hash_hmac('sha256', '1538054050GET/users/self/verify', 'demo-secret', true)),
        $args['sign'],
    );
}
```

- [x] **Étape 2 : Vérifier le RED**

Commande :

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketEndpointGuardTest.php tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketLoginSignerTest.php
```

Résultat attendu : échec de chargement, car les deux classes n'existent pas.

- [x] **Étape 3 : Implémenter la garde et le signer minimaux**

```php
final class OkxPrivateWebSocketEndpointGuard
{
    private const ENDPOINT_ID = 'okx_demo_private_v1';

    private const ALLOWED_URIS = [
        'wss://wseeapap.okx.com:8443/ws/v5/private',
    ];

    public function assertAllowed(string $uri): string
    {
        if (!in_array($uri, self::ALLOWED_URIS, true)) {
            throw new \InvalidArgumentException('okx_demo_private_ws_endpoint_not_allowed');
        }

        return self::ENDPOINT_ID;
    }
}

final readonly class OkxPrivateWebSocketLoginSigner
{
    public function __construct(private OkxAuthSigner $authSigner)
    {
    }

    /** @return array{apiKey:string,passphrase:string,timestamp:string,sign:string} */
    public function buildLoginArgs(
        string $apiKey,
        string $secret,
        string $passphrase,
        string $timestamp,
    ): array
    {
        return [
            'apiKey' => $apiKey,
            'passphrase' => $passphrase,
            'timestamp' => $timestamp,
            'sign' => $this->authSigner->sign(
                $timestamp,
                'GET',
                '/users/self/verify',
                '',
                $secret,
            ),
        ];
    }
}
```

- [x] **Étape 4 : Vérifier le GREEN puis committer**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket
git add trading-app/src/Exchange/Okx/PrivateWebSocket trading-app/tests/Exchange/Okx/PrivateWebSocket
git commit -m "feat(okx): guard and sign private ws login"
```

### Tâche 2 : Publier un statut versionné avec TTL Redis

**Fichiers :**
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketObservabilityStatus.php`
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketStatusStoreInterface.php`
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/RedisOkxPrivateWebSocketStatusStore.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/InMemoryOkxPrivateWebSocketStatusStore.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/RedisOkxPrivateWebSocketStatusStoreTest.php`

- [x] **Étape 1 : Tester sérialisation, corruption, TTL et redaction**

```php
public function testSaveUsesTheExactProductionKeyAndTtl(): void
{
    $redis = new SpyOkxPrivateWebSocketRedisClient();
    $store = new RedisOkxPrivateWebSocketStatusStore($redis);

    $store->save(self::healthyStatus());

    self::assertSame('tradingv3:okx:demo:private-observability:v1', $redis->lastKey);
    self::assertSame(10, $redis->lastTtl);
    self::assertNotNull($redis->lastValue);
    self::assertSame(
        self::healthyStatus()->toArray(),
        json_decode($redis->lastValue, true, 512, JSON_THROW_ON_ERROR),
    );
}

public function testLoadReturnsNullForAbsenceCorruptionAndEverySchemaViolation(): void
{
    $valid = self::healthyStatus()->toArray();
    $invalidPayloads = [
        false,
        '{invalid',
        json_encode(array_diff_key($valid, ['exchange' => true]), JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'unexpected' => true], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'schema_version' => 2], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'exchange' => 'bitmart'], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'environment' => 'live'], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'endpoint_id' => 'mainnet'], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'connected' => 1], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'fills_source' => 'raw_fills'], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'last_heartbeat_at' => null], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'observed_at' => 'not-a-timestamp'], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'blocking_errors' => ['api_secret=leaked']], JSON_THROW_ON_ERROR),
        json_encode([...$valid, 'warnings' => ['okx_fills_channel_vip_unavailable', 'okx_fills_channel_vip_unavailable']], JSON_THROW_ON_ERROR),
    ];

    foreach ($invalidPayloads as $payload) {
        $redis = new SpyOkxPrivateWebSocketRedisClient();
        $redis->value = $payload;

        self::assertNull((new RedisOkxPrivateWebSocketStatusStore($redis))->load());
    }
}
```

- [x] **Étape 2 : Lancer les tests et constater le RED**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket/RedisOkxPrivateWebSocketStatusStoreTest.php
```

Résultat attendu : classes absentes.

- [x] **Étape 3 : Implémenter le DTO fermé et le store**

Le DTO doit exposer exactement les champs de la spécification, `SCHEMA_VERSION = 1`, `ENDPOINT_ID = 'okx_demo_private_v1'`, et rejeter les champs manquants, types ou timestamps invalides et codes d'erreur non canoniques. `OkxPrivateWebSocketObservabilityStatus::fromArray()` valide exclusivement la cible sérialisée `okx/demo/okx_demo_private_v1` ainsi que la version et la fermeture du schéma ; la matrice de désérialisation couvre notamment les mauvaises valeurs d'exchange, d'environnement et d'endpoint. Le round-trip `fromArray($status->toArray())` vérifie l'identité de désérialisation. La fraîcheur et le mapping vers `ExchangePrivateObservabilityStatus` relèvent de `OkxPrivateWebSocketObservabilityPolicy::evaluate()`. Le store doit utiliser `setex(KEY, 10, $json)`, `get(KEY)` et `del(KEY)` sur un client ext-redis dédié ; aucune exception Redis ne doit être transformée en readiness positive.

```php
interface OkxPrivateWebSocketStatusStoreInterface
{
    public function save(OkxPrivateWebSocketObservabilityStatus $status): void;
    public function load(): ?OkxPrivateWebSocketObservabilityStatus;
    public function clear(): void;
}
```

- [x] **Étape 4 : Ajouter un test d'intégration contre Redis réel**

Le test écrit le statut, vérifie `TTL` compris entre 1 et 10, attend l'expiration avec un TTL injecté à 1 seconde, puis vérifie `load() === null`. Le TTL reste paramétrable uniquement dans le constructeur de test ; le service de production impose 10 secondes.

- [x] **Étape 5 : Vérifier et committer**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket/RedisOkxPrivateWebSocketStatusStoreTest.php
git add trading-app/src/Exchange/Okx/PrivateWebSocket trading-app/tests/Exchange/Okx/PrivateWebSocket
git commit -m "feat(okx): persist private ws readiness with ttl"
```

### Tâche 3 : Évaluer la fraîcheur et mapper le statut commun

**Fichiers :**
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketObservabilityPolicy.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketObservabilityPolicyTest.php`

- [x] **Étape 1 : Écrire la matrice de tests en échec**

La matrice de policy doit attendre un statut non prêt et un code stable distinct pour : statut absent, heartbeat âgé de plus de 10 secondes, timestamp futur, non connecté, non authentifié, ordre/fill/position absent, snapshot absent, réconciliation non fraîche, reconnexion active et erreur bloquante. La mauvaise cible appartient à la matrice du statut et de sa désérialisation de la tâche précédente. Un statut complet avec `fills_source=orders_plus_rest` doit être prêt.

```php
#[DataProvider('healthyFillsSources')]
public function testFreshCompleteStatusIsAllowedByCommonPolicy(string $fillsSource): void
{
    $status = self::evaluate(self::healthyStatus(fillsSource: $fillsSource));
    $decision = self::commonDecision($status);

    self::assertTrue($decision->allowed);
    self::assertSame([], $decision->blockingErrors);
    self::assertSame(Exchange::OKX, $status->exchange);
    self::assertSame('demo', $status->environment);
    self::assertTrue($status->privateWsSupported);
    self::assertTrue($status->privateWsConnected);
    self::assertTrue($status->privateWsAuthenticated);
    self::assertTrue($status->ordersStreamReady);
    self::assertTrue($status->fillsStreamReady);
    self::assertTrue($status->positionsStreamReady);
    self::assertTrue($status->initialSnapshotLoaded);
    self::assertTrue($status->reconciliationFresh);
    self::assertFalse($status->reconnecting);
    self::assertSame('2026-07-13T10:00:08+00:00', $status->lastEventAt?->format(DATE_ATOM));
}
```

- [x] **Étape 2 : Vérifier le RED puis implémenter**

La méthode `evaluate(?OkxPrivateWebSocketObservabilityStatus $status, DateTimeImmutable $now): ExchangePrivateObservabilityStatus` doit uniquement calculer la fraîcheur et mapper les booléens vers le statut commun. Elle ne reçoit aucun champ de cible : exchange, environnement et endpoint ont déjà été validés par `OkxPrivateWebSocketObservabilityStatus::fromArray()`. Elle ne contourne jamais `ExchangePrivateObservabilityPolicy`.

- [x] **Étape 3 : Vérifier et committer**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketObservabilityPolicyTest.php tests/Exchange/Readiness/ExchangePrivateObservabilityPolicyTest.php
git add trading-app/src/Exchange/Okx/PrivateWebSocket trading-app/tests/Exchange/Okx/PrivateWebSocket
git commit -m "feat(okx): evaluate private ws observability"
```

### Tâche 4 : Charger le snapshot REST privé initial

**Fichiers :**
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateRestSnapshot.php`
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateRestSnapshotProbe.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/OkxPrivateRestSnapshotProbeTest.php`

- [x] **Étape 1 : Tester les lectures requises et l'absence d'écriture**

```php
public function testAcceptsAnEmptyButCompleteSnapshot(): void
{
    $source = $this->createStub(OkxPrivateRestSnapshotSourceInterface::class);
    $source->method('accountReadable')->willReturn(true);
    $source->method('positions')->willReturn([]);
    $source->method('openOrders')->willReturn([]);
    $source->method('fills')->willReturn([]);

    $snapshot = (new OkxPrivateRestSnapshotProbe($source))->probe(
        new DateTimeImmutable('2026-07-13T10:00:00+00:00'),
    );

    self::assertTrue($snapshot->complete);
    self::assertSame([], $snapshot->blockingErrors);
}
```

Ajouter un test par échec de lecture : compte, positions, ordres standards/algo et fills. Les corps d'erreur et credentials injectés doivent être absents des exceptions, logs et DTO.

- [x] **Étape 2 : Vérifier le RED puis implémenter le probe**

Réutiliser `OkxAccountGateway::healthCheck()`, `getOpenPositionsOrFail()`, `OkxOrderGateway::getOpenOrdersOrFail()` et `getTrades(...)`. Le résultat contient uniquement les objets normalisés nécessaires à la baseline, `observedAt`, `complete`, `blockingErrors`; un tableau vide est valide.

- [x] **Étape 3 : Vérifier et committer**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket/OkxPrivateRestSnapshotProbeTest.php tests/Provider/Okx/OkxPrivateReadProviderTest.php
git add trading-app/src/Exchange/Okx/PrivateWebSocket trading-app/tests/Exchange/Okx/PrivateWebSocket
git commit -m "feat(okx): probe private rest snapshot"
```

### Tâche 5 : Modéliser la session WebSocket privée

**Fichiers :**
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSession.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSessionTest.php`
- Modifier : `trading-app/src/Exchange/Okx/OkxExchangeEventNormalizer.php`
- Modifier : `trading-app/tests/Exchange/Okx/OkxExchangeEventNormalizerTest.php`

- [x] **Étape 1 : Tester la séquence protocolaire pure**

Les tests de session doivent prouver : connexion vers login ; ack login vers quatre souscriptions ; streams prêts uniquement après ack `orders`, `positions`, `balance_and_position` et `fills` ; fallback exact de `fills` sur code `64003` ; toute autre erreur bloquante ; JSON mal formé non prêt ; messages `orders`, `fills`, `positions` normalisés ; aucun payload brut conservé. L'ordre entre souscriptions, snapshot et readiness relève des tests du worker de la tâche suivante.

```php
public function testVipFillRejectionUsesOrdersPlusRestFallback(): void
{
    $now = new DateTimeImmutable('2026-07-13T10:00:00+00:00');
    $clock = $this->createStub(ClockInterface::class);
    $clock->method('now')->willReturn($now);
    $session = new OkxPrivateWebSocketSession(
        new OkxExchangeEventNormalizer(new OkxInstrumentResolver(), $clock),
        $now,
    );
    $session->onConnected([[
        'apiKey' => 'demo-key',
        'passphrase' => 'demo-passphrase',
        'timestamp' => '1783936800',
        'sign' => 'fixture-signature',
    ]], $now);
    $session->onMessage(
        ['event' => 'login', 'code' => '0'],
        $now->modify('+1 second'),
    );
    $session->onMessage([
        'event' => 'error',
        'code' => '64003',
        'arg' => ['channel' => 'fills'],
    ], $now->modify('+2 seconds'));

    self::assertSame('orders_plus_rest', $session->status()->fillsSource);
    self::assertTrue($session->status()->fillsStreamReady);
    self::assertContains('okx_fills_channel_vip_unavailable', $session->status()->warnings);
}
```

- [x] **Étape 2 : Vérifier le RED puis implémenter la machine d'état**

La session reçoit des tableaux JSON décodés et un `DateTimeImmutable` pour chaque transition, puis retourne une liste de commandes sortantes structurées ; elle ne possède ni socket, ni Redis, ni horloge système. Son constructeur reçoit `OkxExchangeEventNormalizer` et un `DateTimeImmutable` initial. Une reconnexion appelle `reset()` avec un `DateTimeImmutable` et invalide authentification, acks et snapshot.

- [x] **Étape 3 : Vérifier et committer**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSessionTest.php tests/Exchange/Okx/OkxExchangeEventNormalizerTest.php
git add trading-app/src/Exchange/Okx trading-app/tests/Exchange/Okx
git commit -m "feat(okx): model private ws session"
```

### Tâche 6 : Exécuter le transport Pawl et le worker résilient

**Fichiers :**
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketTransportInterface.php`
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/PawlOkxPrivateWebSocketTransport.php`
- Créer : `trading-app/src/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketWorker.php`
- Créer : `trading-app/src/Command/OkxPrivateWebSocketCommand.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/FakeOkxPrivateWebSocketTransport.php`
- Créer : `trading-app/tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketWorkerTest.php`
- Créer : `trading-app/tests/Command/OkxPrivateWebSocketCommandTest.php`

- [x] **Étape 1 : Tester démarrage, heartbeat, reconnexion et arrêt**

Tester l'envoi des souscriptions avant le lancement et la projection immédiats du snapshot, sans attente de leurs acknowledgements, puis la readiness uniquement après projection du snapshot et réception des acknowledgements requis. Tester aussi les délais exacts `1, 2, 4, 8, 15, 15`, le snapshot repris après chaque reconnexion, le statut non prêt publié avant retry, le refresh au plus toutes les 3 secondes, l'échec Redis fail-closed, et SIGTERM/SIGINT publiant `worker_stopping`. La commande doit refuser tout environnement autre que `demo`, `OKX_SIMULATED_TRADING != 1`, `OKX_LIVE_ENABLED != 0` ou credentials demo vides.

```php
public function testSnapshotIsReloadedAfterEveryAuthenticatedReconnection(): void
{
    $transport = new FakeOkxPrivateWebSocketTransport();
    $loop = new DeterministicLoop();
    $source = new CountingSnapshotSource();
    $worker = $this->worker($transport, $loop, new RecordingStatusStore(), $source);

    $worker->start();
    $transport->open();
    $transport->message(['event' => 'login', 'code' => '0']);
    self::assertSame(1, $source->calls);

    $transport->disconnect(1006);
    self::assertSame(1.0, $loop->fireNextTimer());
    $transport->open();
    $transport->message(['event' => 'login', 'code' => '0']);

    self::assertSame(2, $source->calls);
}
```

- [x] **Étape 2 : Vérifier le RED puis implémenter les frontières**

```php
interface OkxPrivateWebSocketTransportInterface
{
    public function connect(string $uri, callable $onOpen, callable $onMessage, callable $onClose, callable $onError): void;
    public function send(array $message): void;
    public function close(): void;
}
```

Le transport Pawl sérialise uniquement les commandes structurées. Le worker orchestre garde, login, envoi des souscriptions, exécution et projection immédiates du snapshot, acknowledgements requis, publication de la readiness et backoff. Les logs ne contiennent que `endpoint_id`, canal, état et code canonique.

- [x] **Étape 3 : Vérifier et committer**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketWorkerTest.php tests/Command/OkxPrivateWebSocketCommandTest.php
git add trading-app/src/Exchange/Okx/PrivateWebSocket trading-app/src/Command/OkxPrivateWebSocketCommand.php trading-app/tests/Exchange/Okx/PrivateWebSocket trading-app/tests/Command/OkxPrivateWebSocketCommandTest.php
git commit -m "feat(okx): run private ws observer"
```

### Tâche 7 : Brancher la readiness au runtime OKX

**Fichiers :**
- Modifier : `trading-app/src/Command/ExchangeRuntimeCheckCommand.php`
- Modifier : `trading-app/src/Exchange/Adapter/OkxExchangeAdapter.php`
- Modifier : `trading-app/tests/Command/ExchangeRuntimeCheckCommandTest.php`
- Modifier : `trading-app/tests/Exchange/Adapter/OkxExchangeAdapterTest.php`
- Modifier : `trading-app/tests/Exchange/Contract/OkxExchangeAdapterContractTest.php`

- [x] **Étape 1 : Écrire les tests runtime en échec**

Un statut sain doit alimenter `privateObservabilityStatus` et afficher Private WS prêt, sans modifier kill switch ni flags d'écriture. Statut absent, expiré, reconnecting ou store indisponible reste non prêt. La capability adapter devient `supportsWebSocketPrivate: true`, mais un test prouve qu'elle seule n'accorde pas la readiness.

- [x] **Étape 2 : Vérifier le RED puis injecter le store et la policy**

Dans le chemin OKX demo, charger le statut, appeler `OkxPrivateWebSocketObservabilityPolicy::evaluate()` et transmettre le résultat à `OkxRuntimeCheck`. En local dry-run ou pour une autre venue, conserver le comportement existant. Toute exception du store produit `ExchangePrivateObservabilityStatus::absent(Exchange::OKX, 'demo')` avec un code redacted.

- [x] **Étape 3 : Vérifier les régressions et committer**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Command/ExchangeRuntimeCheckCommandTest.php tests/Exchange/Adapter/OkxExchangeAdapterTest.php tests/Exchange/Contract/OkxExchangeAdapterContractTest.php tests/Exchange/Readiness
git add trading-app/src/Command/ExchangeRuntimeCheckCommand.php trading-app/src/Exchange/Adapter/OkxExchangeAdapter.php trading-app/tests/Command/ExchangeRuntimeCheckCommandTest.php trading-app/tests/Exchange
git commit -m "feat(okx): expose private ws runtime readiness"
```

### Tâche 8 : Configurer l'exploitation désactivée par défaut

**Fichiers :**
- Modifier : `trading-app/config/services.yaml`
- Modifier : `trading-app/config/packages/monolog.yaml`
- Modifier : `docker-compose.yml`
- Modifier : `.env.example`
- Modifier : `trading-app/src/Exchange/Okx/README.md`
- Modifier : `docs/handbook/technical/okx-demo-readiness.md`
- Créer : `docs/handbook/runbooks/okx-private-ws-observability.md`
- Modifier : `mkdocs.yml`

- [x] **Étape 1 : Ajouter le wiring et le service Compose opt-in**

Déclarer un client Redis dédié au store avec `REDIS_HOST`/`REDIS_PORT`, sans alias vers `MockRedis`. Le service `trading-app-okx-private-ws` utilise un profil `okx-observability`, lance `php bin/console app:okx:private-ws`, force `DEMO_TRADING_ENABLED=0`, `OKX_DEMO_TRADING_ENABLED=0` et `OKX_LIVE_ENABLED=0`, et ne démarre jamais avec le Compose par défaut.

- [x] **Étape 2 : Documenter les opérations exactes**

Le runbook doit contenir : préflight des variables sans afficher leurs valeurs, démarrage du profil, commande runtime-check, lecture des seuls champs allow-listés du statut, arrêt, attente de 11 secondes, vérification fail-closed, incidents auth/subscription/Redis et rollback. Il doit rappeler qu'aucun ordre ne doit être envoyé, que #188 reste ouvert et que DEMO-005 est terminé et livré avec la décision `blocked`, laquelle maintient les gates d'écriture fermées.

- [x] **Étape 3 : Vérifier configuration et documentation**

```bash
docker compose config --profiles
docker compose exec trading-app-php php bin/console lint:container --no-debug
docker compose exec trading-app-php php bin/console lint:yaml config
docker compose exec trading-app-php php bin/console app:okx:private-ws --help
docker run --rm -v "$PWD:/docs" squidfunk/mkdocs-material build --strict
```

Résultats attendus : profil opt-in visible, container et YAML valides, commande enregistrée, documentation construite sans warning.

- [x] **Étape 4 : Committer**

```bash
git add trading-app/config docker-compose.yml .env.example trading-app/src/Exchange/Okx/README.md docs/handbook mkdocs.yml
git commit -m "docs(okx): operate private ws observer"
```

### Tâche 9 : Valider, livrer et surveiller la PR

**Fichiers :**
- Modifier : `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md` seulement après merge, en conservant ses changements locaux séparés.
- Modifier : issue OKX-010/#197 sur GitHub après validation factuelle.

- [x] **Étape 1 : Exécuter la validation locale complète**

```bash
docker compose exec trading-app-php php bin/phpunit tests/Exchange/Okx tests/Provider/Okx tests/Command/ExchangeRuntimeCheckCommandTest.php tests/Command/OkxPrivateWebSocketCommandTest.php tests/Exchange/Readiness
docker compose exec trading-app-php vendor/bin/phpstan analyse --no-progress src/Exchange/Okx src/Command/OkxPrivateWebSocketCommand.php src/Command/ExchangeRuntimeCheckCommand.php tests/Exchange/Okx tests/Command/OkxPrivateWebSocketCommandTest.php tests/Command/ExchangeRuntimeCheckCommandTest.php
docker compose exec trading-app-php php bin/console lint:container --no-debug
docker compose exec trading-app-php php bin/console lint:yaml config
docker run --rm -v "$PWD:/docs" squidfunk/mkdocs-material build --strict
git diff --check
```

- [ ] **Étape 2 : Exécuter le smoke demo strictement read-only — critere non satisfait**

Sans afficher les credentials : démarrer le profil, attendre un statut prêt, lancer `app:exchange:runtime-check okx` dans `trading-app-okx-private-ws`, arrêter le worker, attendre 11 secondes, puis relancer le check avec `docker compose --env-file <fichier> run --rm --no-deps trading-app-okx-private-ws php bin/console ...`. Rechercher dans les logs et statuts l'absence des valeurs de `OKX_DEMO_API_KEY`, `OKX_DEMO_API_SECRET`, `OKX_DEMO_API_PASSPHRASE`. Vérifier dans les audits qu'aucun submit/cancel/amend/leverage/protection n'a été appelé. La tentative reelle a ete executee mais OKX a ferme la connexion pendant le login : DEMO-005 est termine avec la decision `blocked`, #188 reste ouvert et le critere de readiness demeure non satisfait.

- [ ] **Étape 3 : Effectuer la revue locale et ouvrir la PR — PR non ouverte**

La PR doit référencer #197, #188, DEMO-005 et la PR précédente réellement mergée, sans supposer son numéro et sans `Closes`. Attendre d'abord les éventuelles doubles revues automatiques ; commenter `@codex review` une seule fois uniquement si aucune revue ne démarre. Répondre factuellement à chaque thread, corriger les remarques applicables, résoudre les threads, relancer les tests et pousser.

- [ ] **Étape 4 : Merger seulement lorsque les gates de revue sont vertes — non merge**

Le merge est permis après CI verte, aucun thread bloquant et soit une réaction pouce levé de Codex, soit un verdict explicite équivalent à « Didn't find any major issues ». Une validation utilisateur explicite autorise également le merge. Après merge, mettre à jour `main` local puis commenter #197 avec ce qui est livré, #188 qui reste ouvert et la décision `blocked` de DEMO-005 qui maintient la gate mutative fermée ; ne pas fermer OKX-010.

- [ ] **Étape 5 : Mettre à jour le prompt canonique local — post-merge non execute**

Supprimer uniquement le prompt terminé, corriger les numéros de PR d'après GitHub, conserver les instructions additionnelles de surveillance/quota/merge et ne pas inclure dans la PR les rapports ou environnements locaux non suivis.
