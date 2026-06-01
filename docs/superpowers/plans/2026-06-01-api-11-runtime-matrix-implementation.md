# API-11 Runtime Matrix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Implement API-11 so Temporal schedules can explicitly target `exchange x market_type x mtf_profile` with dry-run defaults, live guardrails, Symfony runtime diagnostics, tests, and documentation.

**Architecture:** Keep `/api/mtf/run` compatible while expanding exchange normalization through the existing enum. Add a new importable Python schedule manager with pure helper functions for payload/ID/guardrail logic and thin Temporal side effects. Add a Symfony console diagnostic command that uses existing exchange adapter and provider registries, then update worker documentation to mark older scripts as legacy.

**Tech Stack:** PHP 8.2, Symfony Console 7.1, PHPUnit 11, Python 3, pytest, Temporal Python SDK.

---

## File Structure

- Modify `trading-app/src/MtfRunner/Dto/MtfRunnerRequestDto.php`: replace hardcoded exchange matching with `Exchange::tryFrom()`.
- Create `trading-app/tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php`: DTO normalization tests.
- Create `trading-app/src/Command/ExchangeRuntimeCheckCommand.php`: diagnostic command `app:exchange:runtime-check`.
- Create `trading-app/tests/Command/ExchangeRuntimeCheckCommandTest.php`: command output tests with fake registries.
- Create `cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py`: generic Temporal schedule manager.
- Create `cron_symfony_mtf_workers/tests/test_manage_exchange_profile_schedule.py`: pure helper and guardrail tests.
- Modify `cron_symfony_mtf_workers/README.md`: runtime matrix, new script usage, legacy script notes.

## Task 1: DTO Exchange Normalization

**Files:**
- Modify: `trading-app/src/MtfRunner/Dto/MtfRunnerRequestDto.php`
- Create: `trading-app/tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php`

- [x] **Step 1: Write the failing DTO test**

Create `trading-app/tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerRequestDto::class)]
final class MtfRunnerRequestDtoTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: Exchange}>
     */
    public static function exchangeProvider(): iterable
    {
        yield 'bitmart' => ['bitmart', Exchange::BITMART];
        yield 'binance' => ['binance', Exchange::BINANCE];
        yield 'fake' => ['fake', Exchange::FAKE];
        yield 'okx' => ['okx', Exchange::OKX];
        yield 'hyperliquid' => ['hyperliquid', Exchange::HYPERLIQUID];
        yield 'trimmed uppercase okx' => [' OKX ', Exchange::OKX];
    }

    #[DataProvider('exchangeProvider')]
    public function testFromArrayNormalizesAllExchangeEnumValues(string $input, Exchange $expected): void
    {
        $request = MtfRunnerRequestDto::fromArray(['exchange' => $input]);

        self::assertSame($expected, $request->exchange);
    }

    public function testFromArrayRejectsUnknownExchange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported exchange "unknown"');

        MtfRunnerRequestDto::fromArray(['exchange' => 'unknown']);
    }

    /**
     * @return iterable<string, array{0: string, 1: MarketType}>
     */
    public static function marketTypeProvider(): iterable
    {
        yield 'perpetual' => ['perpetual', MarketType::PERPETUAL];
        yield 'futures alias' => ['futures', MarketType::PERPETUAL];
        yield 'future alias' => ['future', MarketType::PERPETUAL];
        yield 'perp alias' => ['perp', MarketType::PERPETUAL];
        yield 'spot' => ['spot', MarketType::SPOT];
    }

    #[DataProvider('marketTypeProvider')]
    public function testFromArrayNormalizesMarketTypeAliases(string $input, MarketType $expected): void
    {
        $request = MtfRunnerRequestDto::fromArray(['market_type' => $input]);

        self::assertSame($expected, $request->marketType);
    }
}
```

- [x] **Step 2: Run the DTO test and verify red**

Run:

```bash
cd trading-app
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php
```

Expected: FAIL because `fake`, `okx`, or `hyperliquid` are rejected by the current hardcoded match.

- [x] **Step 3: Implement enum-based normalization**

Change `normalizeExchange()` in `trading-app/src/MtfRunner/Dto/MtfRunnerRequestDto.php` to:

```php
private static function normalizeExchange(string $value): Exchange
{
    $normalized = strtolower(trim($value));

    return Exchange::tryFrom($normalized)
        ?? throw new \InvalidArgumentException(sprintf('Unsupported exchange "%s"', $value));
}
```

- [x] **Step 4: Run the DTO test and verify green**

Run:

```bash
cd trading-app
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php
```

Expected: PASS.

## Task 2: Symfony Runtime Diagnostic Command

**Files:**
- Create: `trading-app/src/Command/ExchangeRuntimeCheckCommand.php`
- Create: `trading-app/tests/Command/ExchangeRuntimeCheckCommandTest.php`

- [x] **Step 1: Write failing command tests**

Create tests that instantiate the command with mocked `ExchangeAdapterRegistryInterface` and `ExchangeProviderRegistryInterface`.

The first test uses an OKX adapter mock and an empty provider registry; expected output contains:

```text
Exchange: okx
Market type: perpetual
Adapter: found
Provider bundle: missing
Credentials: missing
REST: unknown
Private WS: unsupported
Live trading: disabled
Recommended dry_run: true
Schedule ready: no
```

The second test uses BitMart adapter and provider mocks; expected output contains:

```text
Exchange: bitmart
Market type: perpetual
Adapter: found
Provider bundle: found
REST: unknown
Recommended dry_run: false
Schedule ready: yes
```

- [x] **Step 2: Run command tests and verify red**

Run:

```bash
cd trading-app
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Command/ExchangeRuntimeCheckCommandTest.php
```

Expected: FAIL because `ExchangeRuntimeCheckCommand` does not exist.

- [x] **Step 3: Implement `ExchangeRuntimeCheckCommand`**

Create `trading-app/src/Command/ExchangeRuntimeCheckCommand.php` with:

```php
#[AsCommand(
    name: 'app:exchange:runtime-check',
    description: 'Diagnose exchange runtime readiness for Temporal MTF schedules'
)]
final class ExchangeRuntimeCheckCommand extends Command
```

The command accepts two required arguments:

```php
$this
    ->addArgument('exchange', InputArgument::REQUIRED, 'Exchange enum value')
    ->addArgument('market_type', InputArgument::REQUIRED, 'Market type enum value');
```

It resolves:

```php
$exchange = Exchange::tryFrom(strtolower(trim((string) $input->getArgument('exchange'))));
$marketType = MarketType::tryFrom(strtolower(trim((string) $input->getArgument('market_type'))));
$context = new ExchangeContext($exchange, $marketType);
```

It checks adapter/provider with registry calls and catches missing-registry exceptions:

```php
$adapter = null;
$adapterStatus = 'missing';
try {
    $adapter = $this->adapters->get($exchange, $marketType);
    $adapterStatus = 'found';
} catch (\Throwable) {
}

$providerStatus = 'missing';
try {
    $this->providers->get($context);
    $providerStatus = 'found';
} catch (\Throwable) {
}
```

It emits stable lines in this order:

```text
Exchange: <value>
Market type: <value>
Adapter: found|missing
Provider bundle: found|missing
Credentials: ok|missing
REST: unknown
Private WS: enabled|disabled|unsupported
Live trading: enabled|disabled
Recommended dry_run: true|false
Schedule ready: yes|no
```

- [x] **Step 4: Run command tests and verify green**

Run:

```bash
cd trading-app
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Command/ExchangeRuntimeCheckCommandTest.php
```

Expected: PASS.

## Task 3: Generic Temporal Schedule Script

**Files:**
- Create: `cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py`
- Create: `cron_symfony_mtf_workers/tests/test_manage_exchange_profile_schedule.py`

- [x] **Step 1: Write failing Python tests**

Create tests for pure helpers:

```python
def test_build_job_okx_scalper_payload_is_explicit():
    job = build_job(exchange="okx", market_type="perpetual", profile="scalper", workers=4, dry_run=True)
    assert job == {
        "url": "http://trading-app-nginx:80/api/mtf/run",
        "workers": 4,
        "dry_run": True,
        "mtf_profile": "scalper",
        "exchange": "okx",
        "market_type": "perpetual",
    }
```

Add tests for:

```text
dry_run defaults to true
cron */1 * * * * generates schedule_id cron-mtf-okx-scalper-1m
cron */5 * * * * generates workflow_id mtf-bitmart-regular-runner
parse_runtime_check_output reads Schedule ready: no
validate_live_guardrails allows dry_run=true with unready runtime and returns a warning
validate_live_guardrails rejects dry_run=false with unready runtime
```

- [x] **Step 2: Run Python tests and verify red**

Run:

```bash
cd cron_symfony_mtf_workers
PYTHONPATH=$PWD pytest tests/test_manage_exchange_profile_schedule.py
```

Expected: FAIL because `scripts.manage_exchange_profile_schedule` does not exist.

- [x] **Step 3: Implement importable helper functions**

Create `cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py` with pure helpers:

```python
SUPPORTED_EXCHANGES = {"bitmart", "binance", "okx", "hyperliquid", "fake"}
SUPPORTED_MARKET_TYPES = {"perpetual", "spot"}
SUPPORTED_PROFILES = {"regular", "scalper", "scalper_micro"}


def parse_bool(value: Any, default: bool = True) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    lowered = str(value).strip().lower()
    if lowered in {"1", "true", "yes", "on"}:
        return True
    if lowered in {"0", "false", "no", "off"}:
        return False
    return default


def cadence_suffix(cron_expression: str) -> str:
    aliases = {"*/1 * * * *": "1m", "*/5 * * * *": "5m", "*/15 * * * *": "15m", "0 * * * *": "1h"}
    if cron_expression in aliases:
        return aliases[cron_expression]
    return "cron-" + re.sub(r"[^a-zA-Z0-9]+", "-", cron_expression.strip()).strip("-").lower()


def generate_schedule_id(exchange: str, profile: str, cron_expression: str) -> str:
    return f"cron-mtf-{exchange}-{profile.replace('_', '-')}-{cadence_suffix(cron_expression)}"


def generate_workflow_id(exchange: str, profile: str) -> str:
    return f"mtf-{exchange}-{profile.replace('_', '-')}-runner"


def build_job(exchange: str, market_type: str, profile: str, workers: int, dry_run: bool, url: str = DEFAULT_URL) -> Dict[str, Any]:
    return {
        "url": url,
        "workers": max(1, workers),
        "dry_run": dry_run,
        "mtf_profile": profile,
        "exchange": exchange,
        "market_type": market_type,
    }


def parse_runtime_check_output(output: str) -> Dict[str, str]:
    result: Dict[str, str] = {}
    for line in output.splitlines():
        if ":" in line:
            key, value = line.split(":", 1)
            result[key.strip().lower().replace(" ", "_")] = value.strip().lower()
    return result


def validate_live_guardrails(dry_run: bool, runtime_check: Dict[str, str]) -> List[str]:
    if dry_run:
        if runtime_check.get("schedule_ready") == "no":
            return ["Runtime check reports Schedule ready: no; schedule creation is allowed because dry_run=true."]
        return []
    failures = []
    for key, expected in {"schedule_ready": "yes", "credentials": "ok", "live_trading": "enabled"}.items():
        if runtime_check.get(key) != expected:
            failures.append(f"{key} must be {expected} for dry_run=false")
    return failures
```

Implement Temporal side-effect functions following the style of `manage_mtf_workers_schedule.py`.

- [x] **Step 4: Run Python tests and verify green**

Run:

```bash
cd cron_symfony_mtf_workers
PYTHONPATH=$PWD pytest tests/test_manage_exchange_profile_schedule.py
```

Expected: PASS.

## Task 4: CLI Integration And Temporal Commands

**Files:**
- Modify: `cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py`
- Modify: `cron_symfony_mtf_workers/tests/test_manage_exchange_profile_schedule.py`

- [x] **Step 1: Add tests for CLI defaults**

Test that parser defaults produce:

```text
dry_run=True
cron=*/1 * * * *
schedule_id=cron-mtf-okx-scalper-1m when exchange=okx profile=scalper
workflow_id=mtf-okx-scalper-runner
```

- [x] **Step 2: Run tests and verify red**

Run:

```bash
cd cron_symfony_mtf_workers
PYTHONPATH=$PWD pytest tests/test_manage_exchange_profile_schedule.py
```

Expected: FAIL until parser/default resolution is implemented.

- [x] **Step 3: Complete CLI implementation**

Parser requirements:

```python
parser.add_argument("--exchange", required=True, choices=sorted(SUPPORTED_EXCHANGES))
parser.add_argument("--market-type", default="perpetual", choices=sorted(SUPPORTED_MARKET_TYPES))
parser.add_argument("--profile", required=True, choices=sorted(SUPPORTED_PROFILES))
parser.add_argument("--workers", type=int, default=4)
parser.add_argument("--dry-run", default="true")
parser.add_argument("--cron", default=os.getenv("MTF_WORKERS_CRON", "*/1 * * * *"))
parser.add_argument("--schedule-id")
parser.add_argument("--workflow-id")
parser.add_argument("--dry-run-schedule", action="store_true")
parser.add_argument("--skip-runtime-check", action="store_true")
```

The `create` command calls Docker Compose runtime check unless `--skip-runtime-check` is used.

The `status`, `pause`, `resume`, and `delete` commands operate on the resolved `schedule_id`.

- [x] **Step 4: Run Python tests and verify green**

Run:

```bash
cd cron_symfony_mtf_workers
PYTHONPATH=$PWD pytest tests/test_manage_exchange_profile_schedule.py
```

Expected: PASS.

## Task 5: Documentation Runtime Matrix

**Files:**
- Modify: `cron_symfony_mtf_workers/README.md`

- [x] **Step 1: Update README**

Add a section documenting:

```text
manage_exchange_profile_schedule.py is the recommended script for new multi-exchange schedules.
manage_mtf_workers_schedule.py and manage_scalper_micro_schedule.py are legacy.
dry_run=true is the default for all exchanges.
dry_run=false requires app:exchange:runtime-check through Docker Compose.
```

Add the matrix:

```text
cron-mtf-bitmart-scalper-1m
cron-mtf-bitmart-scalper-micro-1m
cron-mtf-bitmart-regular-5m
cron-mtf-okx-scalper-1m
cron-mtf-okx-scalper-micro-1m
cron-mtf-okx-regular-5m
cron-mtf-hyperliquid-scalper-1m
cron-mtf-hyperliquid-scalper-micro-1m
cron-mtf-hyperliquid-regular-5m
```

- [x] **Step 2: Check docs diff**

Run:

```bash
git diff -- cron_symfony_mtf_workers/README.md
```

Expected: README includes the new script, matrix, command examples, and legacy note.

## Task 6: Full Verification And Commit

**Files:**
- All files touched above.

- [x] **Step 1: Run PHP DTO tests**

```bash
cd trading-app
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php
```

Expected: PASS.

- [x] **Step 2: Run PHP command tests**

```bash
cd trading-app
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Command/ExchangeRuntimeCheckCommandTest.php
```

Expected: PASS.

- [x] **Step 3: Run Python tests**

```bash
cd cron_symfony_mtf_workers
PYTHONPATH=$PWD pytest tests/test_manage_exchange_profile_schedule.py
```

Expected: PASS.

- [x] **Step 4: Run worker test suite**

```bash
cd cron_symfony_mtf_workers
PYTHONPATH=$PWD pytest
```

Expected: PASS.

- [x] **Step 5: Run whitespace check**

```bash
git diff --check
```

Expected: no output and exit code 0.

- [x] **Step 6: Commit implementation**

```bash
git add trading-app/src/MtfRunner/Dto/MtfRunnerRequestDto.php trading-app/tests/MtfRunner/Dto/MtfRunnerRequestDtoTest.php trading-app/src/Command/ExchangeRuntimeCheckCommand.php trading-app/tests/Command/ExchangeRuntimeCheckCommandTest.php cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py cron_symfony_mtf_workers/tests/test_manage_exchange_profile_schedule.py cron_symfony_mtf_workers/README.md docs/superpowers/plans/2026-06-01-api-11-runtime-matrix-implementation.md
git commit -m "feat: add exchange profile runtime schedules"
```

Expected: commit contains only API-11 implementation files plus the implementation plan.
