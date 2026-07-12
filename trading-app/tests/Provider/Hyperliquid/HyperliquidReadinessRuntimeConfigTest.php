<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Provider\Hyperliquid\EffectiveTradingHyperliquidMutationReadinessConfigSource;
use App\Provider\Hyperliquid\FailClosedHyperliquidReconciliationStatus;
use App\TradingCore\Config\EffectiveTradingConfigReadService;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\TradingConfigLayerLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailClosedHyperliquidReconciliationStatus::class)]
#[CoversClass(EffectiveTradingHyperliquidMutationReadinessConfigSource::class)]
final class HyperliquidReadinessRuntimeConfigTest extends TestCase
{
    public function testProductionReconciliationStatusIsAlwaysFailClosed(): void
    {
        self::assertTrue((new FailClosedHyperliquidReconciliationStatus())->isInFlight());
    }

    public function testReadsSafetyInputsFromDeterministicEffectiveTradingProfile(): void
    {
        $config = $this->source('scalper_micro')->current();

        self::assertSame(['BTCUSDT'], $config->allowedSymbols);
        self::assertSame(['perpetual'], $config->allowedMarkets);
        self::assertSame(10.0, $config->maxNotional);
        self::assertSame('scalper_micro', $config->profile);
        self::assertTrue($config->dryRun);
        self::assertFalse($config->liveEnabled);
        self::assertTrue($config->runtimeCheckRequired);
        self::assertFalse($config->mainnetWriteEnabled);
        self::assertFalse($config->demoTestnetWriteEnabled);
        self::assertTrue($config->killSwitchEnabled);
        self::assertTrue($config->requireStopLoss);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $config->configHash);
        self::assertFalse($config->authorizesTestnetMutation());
    }

    public function testMissingDeterministicProfileFailsClosed(): void
    {
        $config = $this->source('')->current();

        self::assertSame([], $config->allowedSymbols);
        self::assertSame([], $config->allowedMarkets);
        self::assertNull($config->maxNotional);
        self::assertNull($config->profile);
        self::assertTrue($config->killSwitchEnabled);
        self::assertNull($config->configHash);
        self::assertFalse($config->authorizesTestnetMutation());
    }

    public function testChangingOnlyCurrentProfileKillSwitchCannotAuthorizeMutation(): void
    {
        $current = $this->source('scalper_micro')->current();
        $killSwitchOnly = new \App\Provider\Hyperliquid\HyperliquidMutationReadinessConfig(
            profile: $current->profile,
            allowedSymbols: $current->allowedSymbols,
            allowedMarkets: $current->allowedMarkets,
            maxNotional: $current->maxNotional,
            dryRun: $current->dryRun,
            liveEnabled: $current->liveEnabled,
            runtimeCheckRequired: $current->runtimeCheckRequired,
            mainnetWriteEnabled: $current->mainnetWriteEnabled,
            demoTestnetWriteEnabled: $current->demoTestnetWriteEnabled,
            killSwitchEnabled: false,
            requireStopLoss: $current->requireStopLoss,
            configHash: $current->configHash,
        );

        self::assertFalse($killSwitchOnly->authorizesTestnetMutation());
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

    private function source(string $profile): EffectiveTradingHyperliquidMutationReadinessConfigSource
    {
        $root = dirname(__DIR__, 3) . '/config/trading';

        return new EffectiveTradingHyperliquidMutationReadinessConfigSource(
            new EffectiveTradingConfigReadService(
                new EffectiveTradingConfigResolver(new TradingConfigLayerLoader($root)),
            ),
            $profile,
        );
    }
}
