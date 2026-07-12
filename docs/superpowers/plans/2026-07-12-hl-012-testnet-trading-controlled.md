# HL-012 Controlled Hyperliquid Testnet Trading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fail-closed Hyperliquid testnet execution port backed by an internal signer sidecar that uses the official SDK, requires grouped entry/SL protection, and compensates partial protection failures.

**Architecture:** Symfony owns readiness, safety, nonce reservation, action construction, reconciliation, compensation, and audit. A dedicated non-public Python sidecar owns only the testnet agent private key, signs allow-listed L1 actions with `hyperliquid-python-sdk==0.24.0`, and broadcasts only to the exact testnet endpoint. The implementation is disabled by default and is tested with strict fake transports while DEMO-005 remains blocked.

**Tech Stack:** PHP 8.2, Symfony 7.1 HttpClient/Console/DI, Doctrine/PostgreSQL nonce storage, PHPUnit 11, PHPStan, Python 3.11, FastAPI, Pydantic 2, official Hyperliquid Python SDK 0.24.0, pytest, Docker Compose.

---

## File Structure

### New signer sidecar

- `hyperliquid-signer/Dockerfile`: non-root image, pinned dependencies, internal healthcheck.
- `hyperliquid-signer/requirements.txt`: runtime dependencies including official SDK 0.24.0.
- `hyperliquid-signer/requirements-dev.txt`: pytest test dependencies.
- `hyperliquid-signer/app/config.py`: strict testnet-only environment parsing.
- `hyperliquid-signer/app/contracts.py`: bounded Pydantic request/response models.
- `hyperliquid-signer/app/signing.py`: official SDK signing and exchange transport boundary.
- `hyperliquid-signer/app/main.py`: authenticated health and exchange endpoints.
- `hyperliquid-signer/tests/test_config.py`: startup/mainnet rejection tests.
- `hyperliquid-signer/tests/test_signing.py`: official signing vector, allow-list, redaction and fake transport tests.
- `hyperliquid-signer/tests/test_api.py`: authentication and API contract tests.

### Symfony execution boundary

- `trading-app/src/Exchange/Hyperliquid/HyperliquidSignedActionClientInterface.php`: sidecar contract.
- `trading-app/src/Exchange/Hyperliquid/HyperliquidSignedActionResult.php`: normalized bounded sidecar response.
- `trading-app/src/Exchange/Hyperliquid/HttpHyperliquidSignedActionClient.php`: internal HTTP client with exact-host guard and redaction.
- `trading-app/src/Exchange/Hyperliquid/HyperliquidActionFactory.php`: grouped `positionTpsl` and emergency close actions.
- `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidMutationReadinessGate.php`: typed readiness and config validation.
- `trading-app/src/Provider/Hyperliquid/HyperliquidMutationReadinessProbeInterface.php`: shared runtime report contract.
- `trading-app/src/Provider/Hyperliquid/HyperliquidMutationReadinessProbe.php`: real read-only readiness probes reused by command and port.
- `trading-app/src/Exchange/Hyperliquid/HyperliquidPollingObservabilityStatus.php`: typed orders/fills/positions polling evidence.
- `trading-app/src/Exchange/Hyperliquid/HyperliquidPollingObservabilityPolicy.php`: testnet-only bounded polling fallback policy.
- `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidCompensationService.php`: cancel, close and identifier-based reconciliation.
- `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidKillSwitchTripInterface.php`: durable trip/query contract.
- `trading-app/src/Entity/HyperliquidTestnetKillSwitchState.php`: persisted fail-closed trip state.
- `trading-app/src/Repository/HyperliquidTestnetKillSwitchStateRepository.php`: atomic trip and query operations.
- `trading-app/migrations/Version20260712120000.php`: additive kill-switch state table.
- `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidTestnetExecutionPort.php`: controlled orchestration of gates, nonce, grouped submit and compensation.
- `trading-app/src/Command/HyperliquidTestnetSmokeCommand.php`: explicit operator-only invocation with confirmation phrase.
- `trading-app/tests/Exchange/Hyperliquid/HyperliquidSignedActionClientTest.php`: strict client boundary tests.
- `trading-app/tests/Exchange/Hyperliquid/HyperliquidActionFactoryTest.php`: grouped action tests.
- `trading-app/tests/TradingCore/Execution/HyperliquidMutationReadinessGateTest.php`: all readiness gates.
- `trading-app/tests/TradingCore/Execution/HyperliquidCompensationServiceTest.php`: cancel/close/resync behavior.
- `trading-app/tests/TradingCore/Execution/HyperliquidTestnetExecutionPortTest.php`: end-to-end port tests with fake sidecar.
- `trading-app/tests/Command/HyperliquidTestnetSmokeCommandTest.php`: confirmation and disabled-default tests.

### Configuration and documentation

- `trading-app/config/services.yaml`: sidecar URI/token/broadcast defaults and service wiring.
- `docker-compose.yml`: opt-in internal-only signer profile; private key removed from PHP service topology.
- `.env.example`: names only, safe disabled defaults.
- `docs/handbook/technical/hyperliquid-testnet-readiness.md`: HL-012 capability state.
- `docs/handbook/runbooks/hyperliquid-testnet-controlled-trading.md`: start, smoke, stop, incident and rollback procedures.
- `mkdocs.yml`: runbook navigation.

---

### Task 1: Scaffold a testnet-only signer service

**Files:**
- Create: `hyperliquid-signer/requirements.txt`
- Create: `hyperliquid-signer/requirements-dev.txt`
- Create: `hyperliquid-signer/app/__init__.py`
- Create: `hyperliquid-signer/app/config.py`
- Create: `hyperliquid-signer/app/contracts.py`
- Create: `hyperliquid-signer/tests/test_config.py`
- Create: `hyperliquid-signer/tests/test_contracts.py`

- [ ] **Step 1: Write failing configuration and schema tests**

```python
def test_config_rejects_mainnet_endpoint(monkeypatch):
    monkeypatch.setenv("HYPERLIQUID_ENV", "testnet")
    monkeypatch.setenv("HYPERLIQUID_NETWORK", "testnet")
    monkeypatch.setenv("HYPERLIQUID_API_BASE_URI", "https://api.hyperliquid.xyz")
    with pytest.raises(ValueError, match="testnet_endpoint_required"):
        SignerConfig.from_env()


def test_request_rejects_non_allowlisted_action():
    with pytest.raises(ValidationError):
        ExchangeRequest(
            schema_version="1",
            environment="testnet",
            network="testnet",
            nonce=1_700_000_000_000,
            account_address=ACCOUNT,
            agent_address=AGENT,
            action={"type": "withdraw3"},
            correlation_id="corr-1",
        )
```

- [ ] **Step 2: Run the tests and verify RED**

Run: `cd hyperliquid-signer && python3 -m pytest tests/test_config.py tests/test_contracts.py -q`

Expected: collection fails because `app.config` and `app.contracts` do not exist.

- [ ] **Step 3: Implement strict configuration and bounded contracts**

```python
ALLOWED_ACTIONS = frozenset({"order", "cancel", "cancelByCloid", "updateLeverage"})
TESTNET_URI = "https://api.hyperliquid-testnet.xyz"

@dataclass(frozen=True)
class SignerConfig:
    environment: str
    network: str
    api_base_uri: str
    agent_private_key: SecretStr
    agent_address: str
    auth_token: SecretStr
    broadcast_enabled: bool

    @classmethod
    def from_env(cls) -> "SignerConfig":
        private_key = os.getenv("HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY", "").strip()
        auth_token = os.getenv("HYPERLIQUID_SIGNER_AUTH_TOKEN", "").strip()
        agent_address = os.getenv("HYPERLIQUID_TESTNET_AGENT_ADDRESS", "").strip().lower()
        if not private_key or not auth_token or not agent_address:
            raise ValueError("signer_credentials_required")
        config = cls(
            environment=os.getenv("HYPERLIQUID_ENV", "testnet").strip().lower(),
            network=os.getenv("HYPERLIQUID_NETWORK", "testnet").strip().lower(),
            api_base_uri=os.getenv(
                "HYPERLIQUID_API_BASE_URI",
                TESTNET_URI,
            ).rstrip("/"),
            agent_private_key=SecretStr(private_key),
            agent_address=agent_address,
            auth_token=SecretStr(auth_token),
            broadcast_enabled=os.getenv(
                "HYPERLIQUID_SIGNER_BROADCAST_ENABLED",
                "0",
            ).strip().lower() in {"1", "true", "yes", "on"},
        )
        if config.environment != "testnet" or config.network != "testnet":
            raise ValueError("testnet_environment_required")
        if config.api_base_uri.rstrip("/") != TESTNET_URI:
            raise ValueError("testnet_endpoint_required")
        return config

class ExchangeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    schema_version: Literal["1"]
    environment: Literal["testnet"]
    network: Literal["testnet"]
    nonce: int = Field(ge=1)
    account_address: str = Field(pattern=r"^0x[0-9a-fA-F]{40}$")
    agent_address: str = Field(pattern=r"^0x[0-9a-fA-F]{40}$")
    action: dict[str, Any]
    correlation_id: str = Field(min_length=1, max_length=128)

    @model_validator(mode="after")
    def allow_action(self) -> "ExchangeRequest":
        if self.action.get("type") not in ALLOWED_ACTIONS:
            raise ValueError("action_not_allowed")
        return self
```

Every required secret rejects blank values, and `broadcast_enabled` defaults to false.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `cd hyperliquid-signer && python3 -m pytest tests/test_config.py tests/test_contracts.py -q`

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add hyperliquid-signer
git commit -m "feat(hl): add strict testnet signer contracts"
```

### Task 2: Sign with the official SDK and expose an authenticated internal API

**Files:**
- Create: `hyperliquid-signer/app/signing.py`
- Create: `hyperliquid-signer/app/main.py`
- Create: `hyperliquid-signer/tests/test_signing.py`
- Create: `hyperliquid-signer/tests/test_api.py`
- Create: `hyperliquid-signer/Dockerfile`
- Modify: `hyperliquid-signer/requirements.txt`
- Modify: `hyperliquid-signer/requirements-dev.txt`

- [ ] **Step 1: Write failing signer and API tests**

```python
def test_signs_l1_action_as_testnet_with_official_sdk():
    signer = HyperliquidTestnetSigner(config(), transport=FakeTransport.ok())
    result = signer.submit(request(action=ORDER_ACTION, nonce=1_700_000_000_001))
    assert result.outcome == "accepted"
    assert result.signature is None
    assert signer.transport.last_url == "https://api.hyperliquid-testnet.xyz/exchange"
    assert signer.transport.last_json["nonce"] == 1_700_000_000_001


def test_exchange_endpoint_requires_constant_time_bearer_auth(client):
    response = client.post("/v1/exchange", json=request_json())
    assert response.status_code == 401
    assert response.json() == {"detail": "unauthorized"}
```

- [ ] **Step 2: Run tests and verify RED**

Run: `cd hyperliquid-signer && python3 -m pytest tests/test_signing.py tests/test_api.py -q`

Expected: failures because signer and API modules do not exist.

- [ ] **Step 3: Implement official SDK signing, bounded transport and redacted API**

```python
wallet = Account.from_key(config.agent_private_key.get_secret_value())
if wallet.address.lower() != config.agent_address.lower():
    raise ValueError("agent_private_key_address_mismatch")
signature = sign_l1_action(
    wallet,
    request.action,
    None,
    request.nonce,
    request.expires_after,
    False,
)
body = {
    "action": request.action,
    "nonce": request.nonce,
    "signature": signature,
    "vaultAddress": None,
}
response = transport.post(TESTNET_URI + "/exchange", json=body, timeout=5.0)
return ExchangeResponse.from_exchange_payload(response.json(), request.correlation_id)
```

Use `hmac.compare_digest()` for the bearer token, cap request bodies at 64 KiB, cap response status rows at 20, use a five-second transport timeout, and log only correlation id, action type, outcome and normalized reason.

- [ ] **Step 4: Add pinned dependencies and non-root image**

```text
fastapi==0.137.1
uvicorn[standard]==0.49.0
pydantic==2.13.4
requests==2.32.5
hyperliquid-python-sdk==0.24.0
```

The Dockerfile must install runtime requirements, create uid 10001, expose only the internal application port, and run `uvicorn app.main:app --host 0.0.0.0 --port 8098` as that user.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `cd hyperliquid-signer && python3 -m pytest -q`

Expected: all sidecar tests pass and no output contains the private key or signature.

- [ ] **Step 6: Commit**

```bash
git add hyperliquid-signer
git commit -m "feat(hl): add official testnet signing sidecar"
```

### Task 3: Add the strict Symfony sidecar client

**Files:**
- Create: `trading-app/src/Exchange/Hyperliquid/HyperliquidSignedActionClientInterface.php`
- Create: `trading-app/src/Exchange/Hyperliquid/HyperliquidSignedActionResult.php`
- Create: `trading-app/src/Exchange/Hyperliquid/HttpHyperliquidSignedActionClient.php`
- Create: `trading-app/tests/Exchange/Hyperliquid/HyperliquidSignedActionClientTest.php`

- [ ] **Step 1: Write failing client tests**

```php
public function testRejectsAnySignerHostOtherThanInternalService(): void
{
    $this->expectExceptionMessage('hyperliquid_signer_endpoint_not_allowed');
    new HttpHyperliquidSignedActionClient($this->http(), 'https://api.hyperliquid.xyz', 'token');
}

public function testMapsAcceptedResponseWithoutExposingSignature(): void
{
    $client = new HttpHyperliquidSignedActionClient(
        new MockHttpClient(new MockResponse(json_encode([
            'schema_version' => '1',
            'outcome' => 'accepted',
            'statuses' => [['kind' => 'resting', 'oid' => '42']],
            'correlation_id' => 'corr-1',
        ], JSON_THROW_ON_ERROR))),
        'http://hyperliquid-signer:8098',
        'token',
    );
    self::assertSame('accepted', $client->submit($this->request())->outcome);
}
```

- [ ] **Step 2: Run test and verify RED**

Run: `cd trading-app && php bin/phpunit tests/Exchange/Hyperliquid/HyperliquidSignedActionClientTest.php`

Expected: class-not-found failure.

- [ ] **Step 3: Implement the typed client and response**

```php
interface HyperliquidSignedActionClientInterface
{
    /** @param array<string,mixed> $action */
    public function submit(array $action, int $nonce, string $correlationId): HyperliquidSignedActionResult;
    public function health(): bool;
}

final readonly class HyperliquidSignedActionResult
{
    /** @param list<array<string,mixed>> $statuses */
    public function __construct(
        public string $outcome,
        public array $statuses,
        public ?string $reason,
        public string $correlationId,
    ) {}
}
```

The HTTP implementation must accept only `http://hyperliquid-signer:8098`, send bearer auth, use five-second timeouts, limit decoded status rows to 20, reject unknown schema versions/outcomes, and map timeouts to `ambiguous` rather than `rejected`.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `cd trading-app && php bin/phpunit tests/Exchange/Hyperliquid/HyperliquidSignedActionClientTest.php`

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Exchange/Hyperliquid trading-app/tests/Exchange/Hyperliquid
git commit -m "feat(hl): add internal signed action client"
```

### Task 4: Build grouped entry and stop-loss actions

**Files:**
- Modify: `trading-app/src/Exchange/Hyperliquid/HyperliquidActionFactory.php`
- Create: `trading-app/tests/Exchange/Hyperliquid/HyperliquidActionFactoryTest.php`

- [ ] **Step 1: Write failing grouped-action tests**

```php
public function testBuildsPositionTpslWithEntryThenReduceOnlyStop(): void
{
    $action = (new HyperliquidActionFactory())->positionTpsl(0, $this->entry(), $this->stop());
    self::assertSame('order', $action['type']);
    self::assertSame('positionTpsl', $action['grouping']);
    self::assertFalse($action['orders'][0]['r']);
    self::assertTrue($action['orders'][1]['r']);
    self::assertSame('sl', $action['orders'][1]['t']['trigger']['tpsl']);
}

public function testRejectsStopOnSameSideAsEntry(): void
{
    $this->expectExceptionMessage('hyperliquid_stop_must_close_entry_side');
    (new HyperliquidActionFactory())->positionTpsl(0, $this->entry(), $this->sameSideStop());
}
```

- [ ] **Step 2: Run test and verify RED**

Run: `cd trading-app && php bin/phpunit tests/Exchange/Hyperliquid/HyperliquidActionFactoryTest.php`

Expected: method-not-found failure.

- [ ] **Step 3: Implement grouped action and emergency close builders**

```php
public function positionTpsl(int $assetId, PlaceOrderRequest $entry, PlaceOrderRequest $stop): array
{
    $entryWire = $this->order($assetId, $entry)['orders'][0];
    $stopWire = $this->order($assetId, $stop)['orders'][0];
    if ($entryWire['b'] === $stopWire['b'] || $stopWire['r'] !== true) {
        throw new \InvalidArgumentException('hyperliquid_stop_must_close_entry_side');
    }
    return ['type' => 'order', 'orders' => [$entryWire, $stopWire], 'grouping' => 'positionTpsl'];
}
```

Add `emergencyClose()` as reduce-only IOC with explicit quantity and slippage-cap price. Do not infer either value from symbol or time.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `cd trading-app && php bin/phpunit tests/Exchange/Hyperliquid/HyperliquidActionFactoryTest.php tests/TradingCore/Execution/HyperliquidDryRunExecutionPortTest.php`

Expected: grouped action tests and existing dry-run regression tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Exchange/Hyperliquid/HyperliquidActionFactory.php trading-app/tests/Exchange/Hyperliquid/HyperliquidActionFactoryTest.php
git commit -m "feat(hl): build grouped entry stop actions"
```

### Task 5: Add a typed mutation readiness gate

**Files:**
- Create: `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidMutationReadinessGate.php`
- Create: `trading-app/src/Provider/Hyperliquid/HyperliquidMutationReadinessProbeInterface.php`
- Create: `trading-app/src/Provider/Hyperliquid/HyperliquidMutationReadinessProbe.php`
- Create: `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidKillSwitchTripInterface.php`
- Create: `trading-app/src/Entity/HyperliquidTestnetKillSwitchState.php`
- Create: `trading-app/src/Repository/HyperliquidTestnetKillSwitchStateRepository.php`
- Create: `trading-app/migrations/Version20260712120000.php`
- Create: `trading-app/src/Exchange/Hyperliquid/HyperliquidPollingObservabilityStatus.php`
- Create: `trading-app/src/Exchange/Hyperliquid/HyperliquidPollingObservabilityPolicy.php`
- Create: `trading-app/tests/TradingCore/Execution/HyperliquidMutationReadinessGateTest.php`
- Create: `trading-app/tests/Provider/Hyperliquid/HyperliquidMutationReadinessProbeTest.php`
- Create: `trading-app/tests/Repository/HyperliquidTestnetKillSwitchStateRepositoryTest.php`
- Create: `trading-app/tests/Exchange/Hyperliquid/HyperliquidPollingObservabilityPolicyTest.php`
- Modify: `trading-app/src/Provider/Hyperliquid/HyperliquidRuntimeCheck.php`
- Modify: `trading-app/src/Command/ExchangeRuntimeCheckCommand.php`
- Modify: `trading-app/tests/Command/ExchangeRuntimeCheckCommandTest.php`

- [ ] **Step 1: Write failing gate tests for every mandatory condition**

```php
#[DataProvider('blockedReports')]
public function testRejectsReportThatIsNotMutationReady(ExchangeReadinessReport $report, string $reason): void
{
    self::assertSame([$reason], (new HyperliquidMutationReadinessGate())->blockingReasons($report, $this->config()));
}

public static function blockedReports(): iterable
{
    yield 'candidate required' => [self::report(level: ExchangeReadinessLevel::LocalDryRunReady), 'demo_testnet_candidate_required'];
    yield 'trade permission required' => [self::report(permissionsTrade: false), 'trade_permission_not_proven'];
    yield 'observability required' => [self::report(privateObservability: false), 'private_observability_not_ready'];
    yield 'kill switch off' => [self::report(killSwitch: true), 'kill_switch_enabled'];
}

public function testPollingFallbackRequiresFreshOrdersFillsAndPositions(): void
{
    $status = new HyperliquidPollingObservabilityStatus(
        environment: 'testnet',
        endpoint: 'https://api.hyperliquid-testnet.xyz',
        initialSnapshotLoaded: true,
        ordersReady: true,
        fillsReady: true,
        positionsReady: false,
        observedAt: new \DateTimeImmutable('2026-07-12T12:00:00Z'),
    );
    self::assertSame(
        ['hyperliquid_positions_poll_not_ready'],
        $this->policy(now: '2026-07-12T12:00:01Z')->blockingReasons($status),
    );
}

public function testProbeDoesNotProveTradePermissionWhenAgentIsAbsent(): void
{
    $report = $this->probe(extraAgents: [
        ['name' => 'another-agent', 'address' => self::OTHER_AGENT, 'validUntil' => 1_900_000_000_000],
    ])->current();
    self::assertFalse($report->permissionsTrade);
    self::assertContains('hyperliquid_agent_wallet_trade_permission_not_proven', $report->warnings);
}

public function testKillSwitchTripSurvivesRepositoryRestart(): void
{
    $this->repository->trip('hyperliquid_compensation_unconfirmed', ['correlation_id' => 'corr-1']);
    $this->entityManager->clear();
    self::assertTrue($this->newRepository()->isTripped());
    self::assertSame('hyperliquid_compensation_unconfirmed', $this->newRepository()->currentReason());
}
```

- [ ] **Step 2: Run tests and verify RED**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Execution/HyperliquidMutationReadinessGateTest.php tests/Provider/Hyperliquid/HyperliquidMutationReadinessProbeTest.php tests/Repository/HyperliquidTestnetKillSwitchStateRepositoryTest.php tests/Exchange/Hyperliquid/HyperliquidPollingObservabilityPolicyTest.php`

Expected: class-not-found failure.

- [ ] **Step 3: Implement gate checks and remove hard-coded dry-run assertions only when proven**

```php
$reasons = [];
$report->exchange === Exchange::HYPERLIQUID || $reasons[] = 'hyperliquid_exchange_required';
$report->environment === 'testnet' || $reasons[] = 'testnet_environment_required';
$report->readyLevel === ExchangeReadinessLevel::DemoTestnetCandidate || $reasons[] = 'demo_testnet_candidate_required';
$report->permissionsTrade || $reasons[] = 'trade_permission_not_proven';
$report->privateObservability || $reasons[] = 'private_observability_not_ready';
$report->demoTestnetWriteGuard || $reasons[] = 'demo_testnet_write_guard_not_ready';
$report->stopLossCapability || $reasons[] = 'stop_loss_capability_not_ready';
!$report->killSwitch || $reasons[] = 'kill_switch_enabled';
return array_values(array_unique($reasons));
```

`ExchangeRuntimeCheckCommand` must query read-only `POST /info` with
`{"type":"extraAgents","user":accountAddress}` and prove that the configured
agent address is present and its `validUntil` has not expired. It must also
prove sidecar health and derived signer-address equality. Only then may
`HyperliquidRuntimeCheck` set `permissionsTrade=true`; it preserves
`dryRun=false` only when account/agent relation, nonce store, private
observability, testnet endpoint, feature flags, stop capability, allow-list,
notional cap, and disabled durable/environment kill switches are all proven.
Otherwise its current fail-closed behavior remains unchanged.

The dedicated polling fallback must require the exact Hyperliquid testnet host,
an initial account snapshot, successful bounded reads for orders, fills and
positions, no in-flight reconciliation, and `observedAt` no older than two
seconds. It is valid only for `exchange=hyperliquid`, `environment=testnet` and
never changes `ExchangePrivateObservabilityPolicy`. The runtime command builds
this typed status from real read-only probes and passes the resulting boolean
and reason codes into `HyperliquidRuntimeCheck`; no request metadata can forge
it.

`HyperliquidMutationReadinessProbeInterface::current(): ExchangeReadinessReport`
is the single source used by both `ExchangeRuntimeCheckCommand` and
`HyperliquidTestnetExecutionPort`. The concrete probe performs the existing
public/private reads, `extraAgents`, sidecar health, nonce readiness, durable
kill-switch query and polling observability checks. This removes the duplicate
or forgeable readiness path from execution.

Add the durable kill-switch singleton in this task so the readiness probe can
read it. `HyperliquidKillSwitchTripInterface` exposes `isTripped(): bool` and
`trip(string $reason, array $auditContext): void`. The Doctrine repository uses
one row (`scope=hyperliquid_testnet`), atomic upsert, UTC timestamps, and
redacted reason/audit fields. A trip survives worker and container restarts and
has no automatic reset.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Execution/HyperliquidMutationReadinessGateTest.php tests/Provider/Hyperliquid/HyperliquidMutationReadinessProbeTest.php tests/Repository/HyperliquidTestnetKillSwitchStateRepositoryTest.php tests/Exchange/Hyperliquid/HyperliquidPollingObservabilityPolicyTest.php tests/Command/ExchangeRuntimeCheckCommandTest.php`

Expected: all readiness tests pass, including default `Schedule ready: no`.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/TradingCore/Execution/Hyperliquid trading-app/src/Exchange/Hyperliquid/HyperliquidPollingObservabilityStatus.php trading-app/src/Exchange/Hyperliquid/HyperliquidPollingObservabilityPolicy.php trading-app/src/Provider/Hyperliquid/HyperliquidMutationReadinessProbeInterface.php trading-app/src/Provider/Hyperliquid/HyperliquidMutationReadinessProbe.php trading-app/src/Provider/Hyperliquid/HyperliquidRuntimeCheck.php trading-app/src/Command/ExchangeRuntimeCheckCommand.php trading-app/src/Entity/HyperliquidTestnetKillSwitchState.php trading-app/src/Repository/HyperliquidTestnetKillSwitchStateRepository.php trading-app/migrations/Version20260712120000.php trading-app/tests/TradingCore/Execution/HyperliquidMutationReadinessGateTest.php trading-app/tests/Provider/Hyperliquid/HyperliquidMutationReadinessProbeTest.php trading-app/tests/Repository/HyperliquidTestnetKillSwitchStateRepositoryTest.php trading-app/tests/Exchange/Hyperliquid/HyperliquidPollingObservabilityPolicyTest.php trading-app/tests/Command/ExchangeRuntimeCheckCommandTest.php
git commit -m "feat(hl): require mutation readiness evidence"
```

### Task 6: Implement identifier-based compensation

**Files:**
- Create: `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidCompensationService.php`
- Create: `trading-app/tests/TradingCore/Execution/HyperliquidCompensationServiceTest.php`

- [ ] **Step 1: Write failing compensation tests**

```php
public function testCancelsRestingEntryByCloidWhenStopIsRejected(): void
{
    $result = $this->service(openOrders: [$this->restingEntry()])->compensate($this->context());
    self::assertSame('entry_canceled', $result->outcome);
    self::assertSame('cancelByCloid', $this->client->actions[0]['type']);
}

public function testClosesFilledPositionAndTripsSwitchWhenConfirmationFails(): void
{
    $result = $this->service(position: $this->openPosition(), reconciliationNeverClears: true)->compensate($this->context());
    self::assertSame('unknown_requires_resync', $result->outcome);
    self::assertSame(['hyperliquid_compensation_unconfirmed'], $this->trip->reasons);
    self::assertTrue($this->trip->isTripped());
}
```

- [ ] **Step 2: Run test and verify RED**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Execution/HyperliquidCompensationServiceTest.php`

Expected: class-not-found failure.

- [ ] **Step 3: Implement bounded reconciliation, cancel, close and trip behavior**

```php
for ($attempt = 0; $attempt < 3; ++$attempt) {
    $order = $this->orders->getOrder($context->symbol, $context->clientOrderId);
    if ($order !== null && $this->isResting($order)) {
        return $this->cancelByCloid($context);
    }
    $position = $this->positions->findBySymbol($context->symbol);
    if ($position !== null) {
        return $this->closeReduceOnly($context, $position);
    }
    $this->clock->sleep(250);
}
$this->trip->trip('hyperliquid_compensation_unconfirmed', $context->auditContext);
return HyperliquidCompensationResult::unknownRequiresResync();
```

Use only client order id, exchange order id, and explicit account position state. Never use a symbol-only match to associate an order, and never use a temporal window. Symbol is permitted only after the position is already scoped to the configured account and the original execution context.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Execution/HyperliquidCompensationServiceTest.php`

Expected: all compensation branches pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidCompensationService.php trading-app/tests/TradingCore/Execution/HyperliquidCompensationServiceTest.php
git commit -m "feat(hl): compensate unprotected testnet entries"
```

### Task 7: Implement the controlled testnet execution port

**Files:**
- Create: `trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidTestnetExecutionPort.php`
- Create: `trading-app/tests/TradingCore/Execution/HyperliquidTestnetExecutionPortTest.php`

- [ ] **Step 1: Write failing port tests for blocked, accepted, partial and ambiguous flows**

```php
public function testDoesNotReserveNonceWhenKillSwitchBlocks(): void
{
    $result = $this->port(killSwitch: true)->execute($this->request());
    self::assertSame(ExecutionStatus::Rejected, $result->status);
    self::assertSame(0, $this->nonces->reservations);
    self::assertSame([], $this->signedClient->actions);
}

public function testAcceptsOnlyWhenEntryAndStopAreAccepted(): void
{
    $result = $this->port(sidecar: $this->entryAndStopAccepted())->execute($this->request());
    self::assertSame(ExecutionStatus::Accepted, $result->status);
    self::assertSame('42', $result->exchangeOrderId);
    self::assertTrue($result->metadata['protection_confirmed']);
}

public function testCompensatesAcceptedEntryWithRejectedStop(): void
{
    $result = $this->port(sidecar: $this->entryAcceptedStopRejected())->execute($this->request());
    self::assertSame(ExecutionStatus::Failed, $result->status);
    self::assertSame('entry_canceled', $result->metadata['compensation_outcome']);
}
```

- [ ] **Step 2: Run test and verify RED**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Execution/HyperliquidTestnetExecutionPortTest.php`

Expected: class-not-found failure.

- [ ] **Step 3: Implement fail-closed execution orchestration**

```php
$decision = $this->killSwitch->evaluate($this->mutationAttempt($request));
if ($this->trip->isTripped() || !$decision->allowed) {
    return $this->rejected($plan, 'demo_trading_safety_blocked', $decision->reasons);
}
$reasons = $this->readinessGate->blockingReasons($this->readiness->current(), $this->config);
if ($reasons !== []) {
    return $this->rejected($plan, 'hyperliquid_mutation_not_ready', $reasons);
}
$nonce = $this->nonces->nextNonce($this->nonceScope());
$action = $this->actions->positionTpsl($assetId, $entry, $stop);
$submission = $this->signedClient->submit($action, $nonce, $correlationId);
return $this->mapOrCompensate($submission, $request);
```

Evaluate every gate before nonce reservation. Submit leverage with its own nonce only when the observed leverage differs. Redact raw and metadata recursively. Treat timeout/unknown status as ambiguous and reconcile before choosing a result.

- [ ] **Step 4: Run tests and verify GREEN**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Execution/HyperliquidTestnetExecutionPortTest.php tests/TradingCore/Execution/HyperliquidCompensationServiceTest.php tests/TradingCore/Execution/Safety/DemoTradingKillSwitchServiceTest.php`

Expected: all controlled execution and existing safety tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/TradingCore/Execution/Hyperliquid/HyperliquidTestnetExecutionPort.php trading-app/tests/TradingCore/Execution/HyperliquidTestnetExecutionPortTest.php
git commit -m "feat(hl): add controlled testnet execution port"
```

### Task 8: Add explicit operator command and disabled-by-default wiring

**Files:**
- Create: `trading-app/src/Command/HyperliquidTestnetSmokeCommand.php`
- Create: `trading-app/tests/Command/HyperliquidTestnetSmokeCommandTest.php`
- Modify: `trading-app/config/services.yaml`
- Modify: `docker-compose.yml`
- Modify: `.env.example`

- [ ] **Step 1: Write failing command and container tests**

```php
public function testCommandRequiresExactConfirmation(): void
{
    $tester = new CommandTester($this->command());
    self::assertSame(Command::FAILURE, $tester->execute(['--confirm' => 'yes']));
    self::assertStringContainsString('CONFIRM_HYPERLIQUID_TESTNET_ONLY', $tester->getDisplay());
    self::assertSame(0, $this->port->calls);
}

public function testCommandRefusesWhileReadinessDecisionIsBlocked(): void
{
    $tester = new CommandTester($this->command(decision: 'blocked'));
    self::assertSame(Command::FAILURE, $tester->execute(['--confirm' => 'CONFIRM_HYPERLIQUID_TESTNET_ONLY']));
    self::assertSame(0, $this->port->calls);
}
```

- [ ] **Step 2: Run tests and verify RED**

Run: `cd trading-app && php bin/phpunit tests/Command/HyperliquidTestnetSmokeCommandTest.php`

Expected: class-not-found failure.

- [ ] **Step 3: Implement the operator-only command**

The command accepts a versioned JSON order-plan file, `--confirm CONFIRM_HYPERLIQUID_TESTNET_ONLY`, and `--readiness-decision ready_for_demo_testnet_trading_attempt`. It rejects all other decision values, checks runtime readiness immediately before execution, prints only redacted identifiers/outcomes, and has no HTTP controller route.

- [ ] **Step 4: Wire disabled defaults and internal-only Compose profile**

```yaml
  hyperliquid-signer:
    build: ./hyperliquid-signer
    profiles: ["hyperliquid-testnet"]
    expose: ["8098"]
    environment:
      HYPERLIQUID_ENV: testnet
      HYPERLIQUID_NETWORK: testnet
      HYPERLIQUID_API_BASE_URI: https://api.hyperliquid-testnet.xyz
      HYPERLIQUID_SIGNER_BROADCAST_ENABLED: "${HYPERLIQUID_SIGNER_BROADCAST_ENABLED:-0}"
      HYPERLIQUID_TESTNET_AGENT_ADDRESS: "${HYPERLIQUID_TESTNET_AGENT_ADDRESS:-}"
      HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY: "${HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY:-}"
      HYPERLIQUID_SIGNER_AUTH_TOKEN: "${HYPERLIQUID_SIGNER_AUTH_TOKEN:-}"
    networks: [trading-app-net]
```

Do not add `ports`. Pass only signer URI/token, account address and agent address to PHP. Do not pass the agent private key to PHP in this profile.

- [ ] **Step 5: Run command/container validation and verify GREEN**

Run:

```bash
cd trading-app && php bin/phpunit tests/Command/HyperliquidTestnetSmokeCommandTest.php
php bin/console lint:container --no-debug
php bin/console lint:yaml config
cd .. && docker compose config --quiet
```

Expected: tests and all lint/config checks pass; default rendered config keeps both trading and signer broadcast disabled.

- [ ] **Step 6: Commit**

```bash
git add trading-app/src/Command/HyperliquidTestnetSmokeCommand.php trading-app/tests/Command/HyperliquidTestnetSmokeCommandTest.php trading-app/config/services.yaml docker-compose.yml .env.example
git commit -m "feat(hl): wire opt-in testnet smoke execution"
```

### Task 9: Document operations, run full verification, and prepare PR

**Files:**
- Create: `docs/handbook/runbooks/hyperliquid-testnet-controlled-trading.md`
- Modify: `docs/handbook/technical/hyperliquid-testnet-readiness.md`
- Modify: `docs/roadmap/01-okx-hyperliquid-demo-testnet.md`
- Modify: `mkdocs.yml`

- [ ] **Step 1: Write the runbook and update readiness status**

Document prerequisites, dedicated agent creation, account versus agent address, testnet funding, sidecar startup, dry-run checks, exact smoke confirmation, observation, cancel/close, incident handling, secret redaction, and immediate rollback. State that real execution remains prohibited while DEMO-005 is `blocked`.

- [ ] **Step 2: Run focused and broad tests**

```bash
cd hyperliquid-signer
python3 -m pytest -q
cd ../trading-app
php bin/phpunit tests/Exchange/Hyperliquid tests/Provider/Hyperliquid tests/TradingCore/Execution/Hyperliquid* tests/Command/HyperliquidTestnetSmokeCommandTest.php tests/Command/ExchangeRuntimeCheckCommandTest.php
vendor/bin/phpstan analyse \
  src/Exchange/Hyperliquid \
  src/TradingCore/Execution/Hyperliquid \
  src/Command/HyperliquidTestnetSmokeCommand.php \
  tests/Exchange/Hyperliquid \
  tests/TradingCore/Execution/HyperliquidMutationReadinessGateTest.php \
  tests/TradingCore/Execution/HyperliquidCompensationServiceTest.php \
  tests/TradingCore/Execution/HyperliquidTestnetExecutionPortTest.php \
  tests/Command/HyperliquidTestnetSmokeCommandTest.php
php bin/console lint:container --no-debug
php bin/console lint:yaml config
cd ..
docker compose config --quiet
python3 -m mkdocs build --strict
git diff --check
```

Expected: all commands exit zero.

- [ ] **Step 3: Run PostgreSQL nonce integration and secret scans**

Run the existing PostgreSQL-backed `HyperliquidNonceManagerTest` and the new durable kill-switch repository test, then scan the diff for private-key-looking hex strings, bearer values, `signature`, and raw payload logging. Confirm no secret value appears in Git, test fixtures, docs, screenshots or command output.

- [ ] **Step 4: Review the complete diff**

Verify no mainnet endpoint is accepted, no strategy/MTF/risk business code changed, no port is published for the sidecar, defaults remain disabled, ambiguous states never become accepted, and rollback is executable without code deployment.

- [ ] **Step 5: Commit documentation**

```bash
git add docs/handbook/runbooks/hyperliquid-testnet-controlled-trading.md docs/handbook/technical/hyperliquid-testnet-readiness.md docs/roadmap/01-okx-hyperliquid-demo-testnet.md mkdocs.yml
git commit -m "docs(hl): add controlled testnet operations runbook"
```

- [ ] **Step 6: Push and open the PR without claiming execution validation**

The PR body must include:

```text
Part of #198
Related to #188
Related to #196
Related to #219
Related to #220

HL-012 implementation is disabled by default. DEMO-005 remains blocked, so no
real Hyperliquid testnet order was sent by this PR. Strict fake-transport and
PostgreSQL nonce evidence are included. Mainnet write remains impossible.
```

Request Codex review after CI starts, process every thread, and merge only after Codex approval, green CI, no unresolved blocking thread, and operator approval under the standing instruction.
