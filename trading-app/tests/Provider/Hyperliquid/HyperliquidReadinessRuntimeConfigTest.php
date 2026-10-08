<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Provider\Hyperliquid\EffectiveTradingHyperliquidMutationReadinessConfigSource;
use App\Provider\Hyperliquid\FailClosedHyperliquidReconciliationStatus;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessConfig;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use Symfony\Component\Yaml\Yaml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailClosedHyperliquidReconciliationStatus::class)]
#[CoversClass(EffectiveTradingHyperliquidMutationReadinessConfigSource::class)]
final class HyperliquidReadinessRuntimeConfigTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            is_file($path) && unlink($path);
        }
    }

    public function testProductionReconciliationStatusIsAlwaysFailClosed(): void
    {
        self::assertTrue((new FailClosedHyperliquidReconciliationStatus())->isInFlight());
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function eligibleIdentities(): iterable
    {
        yield 'day_trading long' => ['day_trading', 'day_trading.trend_continuation.long', '1.1.0', '1.1.0', 'long'];
        yield 'micro_scalping long' => ['micro_scalping', 'micro_scalping.momentum_ofi.long', '1.1.0', '1.1.0', 'long'];
        yield 'micro_scalping short' => ['micro_scalping', 'micro_scalping.momentum_ofi.short', '1.1.0', '1.1.0', 'short'];
        yield 'scalping pullback long' => ['scalping', 'scalping.pullback.long', '1.1.0', '1.1.0', 'long'];
        yield 'scalping trend long' => ['scalping', 'scalping.trend_continuation.long', '1.1.0', '1.1.0', 'long'];
        yield 'scalping trend_momentum short' => ['scalping', 'scalping.trend_momentum.short', '1.1.0', '1.1.0', 'short'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eligibleIdentities')]
    public function testVersionedEnvelopeOpensEveryEligibleTestnetIdentity(string $mode, string $setup, string $modeVersion, string $setupVersion, string $side): void
    {
        $config = $this->source()->forIdentity($this->identity($mode, $setup, $side));

        self::assertTrue($config->authorizesTestnetMutation());
        self::assertSame(sprintf('%s@%s/%s@%s/%s', $mode, $modeVersion, $setup, $setupVersion, $side), $config->profile);
        self::assertSame(['BTCUSDT'], $config->allowedSymbols);
        self::assertSame(['perpetual'], $config->allowedMarkets);
        self::assertSame(25.0, $config->maxNotional);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $config->configHash);
    }

    public function testConfigHashBindsTheResolvedSnapshotAndTheEnvelope(): void
    {
        $first = $this->source()->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'));
        $second = $this->source()->forIdentity($this->identity('scalping', 'scalping.trend_continuation.long', 'long'));

        self::assertNotSame($first->configHash, $second->configHash);
        self::assertSame($first->configHash, $this->source()->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'))->configHash);
    }

    public function testIdentityOutsideTheEnvelopeAllowListFailsClosed(): void
    {
        $path = $this->envelopeCopy(static function (array $document): array {
            $document['eligible_identities'] = [$document['eligible_identities'][0]];

            return $document;
        });

        $config = (new EffectiveTradingHyperliquidMutationReadinessConfigSource(new EffectiveTradingConfigResolver(), $path))
            ->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'));

        self::assertEquals(HyperliquidMutationReadinessConfig::failClosed(), $config);
    }

    public function testEnvelopeHashMismatchFailsClosed(): void
    {
        $path = $this->envelopeCopy(static function (array $document): array {
            $document['max_notional'] = 26.0;

            return $document;
        }, rehash: false);

        $config = (new EffectiveTradingHyperliquidMutationReadinessConfigSource(new EffectiveTradingConfigResolver(), $path))
            ->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'));

        self::assertEquals(HyperliquidMutationReadinessConfig::failClosed(), $config);
    }

    public function testRehashedEnvelopeIsAcceptedAndChangesTheBoundConfigHash(): void
    {
        $path = $this->envelopeCopy(static function (array $document): array {
            $document['max_notional'] = 26.0;

            return $document;
        });
        $identity = $this->identity('scalping', 'scalping.pullback.long', 'long');

        $changed = (new EffectiveTradingHyperliquidMutationReadinessConfigSource(new EffectiveTradingConfigResolver(), $path))->forIdentity($identity);

        self::assertTrue($changed->authorizesTestnetMutation());
        self::assertSame(26.0, $changed->maxNotional);
        self::assertNotSame($this->source()->forIdentity($identity)->configHash, $changed->configHash);
    }

    public function testKillSwitchAloneCannotAuthorizeMutationFromTheEnvelopeConfig(): void
    {
        $current = $this->source()->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'));
        $killSwitchOff = new HyperliquidMutationReadinessConfig(
            profile: $current->profile,
            allowedSymbols: $current->allowedSymbols,
            allowedMarkets: $current->allowedMarkets,
            maxNotional: $current->maxNotional,
            dryRun: true,
            liveEnabled: $current->liveEnabled,
            runtimeCheckRequired: $current->runtimeCheckRequired,
            mainnetWriteEnabled: $current->mainnetWriteEnabled,
            demoTestnetWriteEnabled: $current->demoTestnetWriteEnabled,
            killSwitchEnabled: false,
            requireStopLoss: $current->requireStopLoss,
            configHash: $current->configHash,
        );

        self::assertFalse($killSwitchOff->authorizesTestnetMutation());
    }

    public function testRollbackToFailClosedByEnvelopeAloneKeepsMutationUnauthorized(): void
    {
        $path = $this->envelopeCopy(static function (array $document): array {
            $document['execution']['kill_switch_enabled'] = true;
            $document['execution']['demo_testnet_write_enabled'] = false;
            $document['execution']['dry_run'] = true;

            return $document;
        });

        $config = (new EffectiveTradingHyperliquidMutationReadinessConfigSource(new EffectiveTradingConfigResolver(), $path))
            ->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'));

        self::assertFalse($config->authorizesTestnetMutation());
    }

    public function testMissingEnvelopeFailsClosed(): void
    {
        $config = (new EffectiveTradingHyperliquidMutationReadinessConfigSource(new EffectiveTradingConfigResolver(), '/nonexistent/testnet.yaml'))
            ->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'));

        self::assertEquals(HyperliquidMutationReadinessConfig::failClosed(), $config);
    }

    public function testResolverFailureFailsClosed(): void
    {
        $resolver = new class implements EffectiveTradingConfigResolverInterface {
            public function resolve(EffectiveTradingConfigRequest|string $request): EffectiveTradingConfigSnapshot
            {
                throw new TradingConfigException('boom');
            }
        };

        $config = (new EffectiveTradingHyperliquidMutationReadinessConfigSource($resolver, $this->envelopePath()))
            ->forIdentity($this->identity('scalping', 'scalping.pullback.long', 'long'));

        self::assertEquals(HyperliquidMutationReadinessConfig::failClosed(), $config);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonTestnetTargets(): iterable
    {
        yield 'hyperliquid mainnet' => ['hyperliquid', 'mainnet'];
        yield 'okx demo' => ['okx', 'demo'];
        yield 'okx mainnet' => ['okx', 'mainnet'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonTestnetTargets')]
    public function testNonTestnetTargetsFailClosedEvenWithAnOpenEnvelope(string $exchange, string $environment): void
    {
        $identity = new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.pullback.long', '1.1.0', $exchange, $environment, 'long',
            ShadowExecutionCapability::Paper,
        );

        self::assertEquals(HyperliquidMutationReadinessConfig::failClosed(), $this->source()->forIdentity($identity));
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function unsafeAuthorizationOverrides(): iterable
    {
        yield 'dry run' => [['dryRun' => true]];
        yield 'unrestricted live' => [['liveEnabled' => true]];
        yield 'runtime check disabled' => [['runtimeCheckRequired' => false]];
        yield 'mainnet writes' => [['mainnetWriteEnabled' => true]];
        yield 'demo writes disabled' => [['demoTestnetWriteEnabled' => false]];
        yield 'kill switch' => [['killSwitchEnabled' => true]];
        yield 'stop loss optional' => [['requireStopLoss' => false]];
        yield 'missing profile' => [['profile' => null]];
        yield 'missing hash' => [['configHash' => null]];
        yield 'malformed hash' => [['configHash' => 'not-a-hash']];
    }

    /** @param array<string,mixed> $overrides */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeAuthorizationOverrides')]
    public function testExactAuthorizationValuesAreRequired(array $overrides): void
    {
        self::assertFalse($this->safeMutationConfig($overrides)->authorizesTestnetMutation());
    }

    /** @param array<string,mixed> $overrides */
    private function safeMutationConfig(array $overrides = []): \App\Provider\Hyperliquid\HyperliquidMutationReadinessConfig
    {
        $values = array_replace([
            'profile' => 'scalper_micro',
            'allowedSymbols' => ['BTCUSDT'],
            'allowedMarkets' => ['perpetual'],
            'maxNotional' => 10.0,
            'dryRun' => false,
            'liveEnabled' => false,
            'runtimeCheckRequired' => true,
            'mainnetWriteEnabled' => false,
            'demoTestnetWriteEnabled' => true,
            'killSwitchEnabled' => false,
            'requireStopLoss' => true,
            'configHash' => str_repeat('a', 64),
        ], $overrides);

        return new \App\Provider\Hyperliquid\HyperliquidMutationReadinessConfig(...$values);
    }

    private function source(): EffectiveTradingHyperliquidMutationReadinessConfigSource
    {
        return new EffectiveTradingHyperliquidMutationReadinessConfigSource(new EffectiveTradingConfigResolver(), $this->envelopePath());
    }

    private function envelopePath(): string
    {
        return dirname(__DIR__, 3) . '/config/trading/env/testnet.yaml';
    }

    private function identity(string $mode, string $setup, string $side): EffectiveTradingConfigRequest
    {
        return new EffectiveTradingConfigRequest($mode, '1.1.0', $setup, '1.1.0', 'hyperliquid', 'testnet', $side, ShadowExecutionCapability::Paper);
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $mutate */
    private function envelopeCopy(callable $mutate, bool $rehash = true): string
    {
        $document = $mutate(Yaml::parseFile($this->envelopePath()));
        if ($rehash) {
            $document['envelope_hash'] = EffectiveTradingHyperliquidMutationReadinessConfigSource::envelopeHash($document);
        }
        $path = tempnam(sys_get_temp_dir(), 'hl-envelope-');
        self::assertIsString($path);
        file_put_contents($path, Yaml::dump($document, 6, 2));

        return $this->paths[] = $path;
    }
}
