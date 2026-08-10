<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicyCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalRiskPolicyCompiler::class)]
final class CanonicalRiskPolicyCompilerTest extends TestCase
{
    public function testCompilesPercentagePointsExactlyOnceAndPreservesAuthority(): void
    {
        $policy = (new CanonicalRiskPolicyCompiler())->compile($this->snapshot());

        self::assertSame(0.004, $policy->riskRate);
        self::assertSame('micro_scalping', $policy->modeId);
        self::assertSame('1.0.0', $policy->modeVersion);
        self::assertSame('micro_scalping.momentum_ofi.long', $policy->setupId);
        self::assertSame('1.0.0', $policy->setupVersion);
        self::assertSame('fake', $policy->exchange);
        self::assertSame('test', $policy->environment);
        self::assertSame('long', $policy->side);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $policy->configHash);
        self::assertSame(3.0, $policy->modeLeverageCap);
        self::assertSame(0.0002, $policy->makerFeeRate);
        self::assertSame(0.0005, $policy->takerFeeRate);
        self::assertSame(5.0, $policy->exchangeMinNotional);
        self::assertSame(1000.0, $policy->exchangeMaxNotional);
        self::assertSame(250.0, $policy->environmentMaxNotional);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('invalidPolicyProvider')]
    public function testRejectsInvalidOrAmbiguousPolicy(callable $mutate, string $reasonCode): void
    {
        $snapshot = $this->snapshot($mutate($this->payload()));

        try {
            (new CanonicalRiskPolicyCompiler())->compile($snapshot);
            self::fail('Invalid canonical risk policy was accepted.');
        } catch (CanonicalRiskException $exception) {
            self::assertSame($reasonCode, $exception->reasonCode);
            self::assertSame($reasonCode, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalidPolicyProvider(): iterable
    {
        yield 'unresolved trade budget' => [
            static function (array $payload): array {
                $payload['mode']['risk']['trade_budget']['state'] = 'unresolved';
                $payload['mode']['risk']['trade_budget']['value'] = null;
                return $payload;
            },
            'canonical_policy_trade_budget_unresolved',
        ];
        yield 'wrong trade budget unit' => [
            static function (array $payload): array {
                $payload['mode']['risk']['trade_budget']['unit'] = 'quote_notional';
                return $payload;
            },
            'canonical_policy_trade_budget_unit_invalid',
        ];
        yield 'ambiguous 101 percent' => [
            static function (array $payload): array {
                $payload['mode']['risk']['trade_budget']['value'] = 101.0;
                return $payload;
            },
            'canonical_policy_trade_budget_value_invalid',
        ];
        yield 'non finite percentage' => [
            static function (array $payload): array {
                $payload['mode']['risk']['trade_budget']['value'] = INF;
                return $payload;
            },
            'canonical_policy_trade_budget_value_invalid',
        ];
        yield 'legacy duplicate source' => [
            static function (array $payload): array {
                $payload['mode']['risk']['fixed_risk_pct'] = 0.4;
                return $payload;
            },
            'canonical_policy_legacy_risk_source_forbidden',
        ];
        yield 'unresolved leverage' => [
            static function (array $payload): array {
                $payload['mode']['leverage']['state'] = 'unresolved';
                $payload['mode']['leverage']['value'] = null;
                return $payload;
            },
            'canonical_policy_mode_leverage_unresolved',
        ];
        yield 'invalid maker fee' => [
            static function (array $payload): array {
                $payload['exchange']['fees']['maker_rate'] = 1.0;
                return $payload;
            },
            'canonical_policy_fee_rate_invalid',
        ];
        yield 'zero exchange notional cap' => [
            static function (array $payload): array {
                $payload['exchange']['limits']['max_notional'] = 0.0;
                return $payload;
            },
            'canonical_policy_notional_cap_invalid',
        ];
        yield 'unsafe environment write gate' => [
            static function (array $payload): array {
                $payload['environment']['write_enabled'] = true;
                return $payload;
            },
            'canonical_policy_safety_invalid',
        ];
        yield 'identity mismatch' => [
            static function (array $payload): array {
                $payload['setup']['side'] = 'short';
                return $payload;
            },
            'canonical_policy_identity_mismatch',
        ];
    }

    public function testRejectsSnapshotThatIsNotExecutable(): void
    {
        $snapshot = $this->snapshot(executable: false, blockers: ['mode unresolved']);

        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_policy_snapshot_not_executable');
        (new CanonicalRiskPolicyCompiler())->compile($snapshot);
    }

    public function testRejectsInvalidConfigHashAuthority(): void
    {
        $snapshot = $this->snapshot(configHash: 'not-a-hash');

        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_policy_hash_invalid');
        (new CanonicalRiskPolicyCompiler())->compile($snapshot);
    }

    public function testRejectsConfigHashThatDoesNotAuthenticatePayload(): void
    {
        $payload = $this->payload();
        $snapshot = $this->snapshot($payload);
        $payload['mode']['risk']['trade_budget']['value'] = 40.0;
        $tampered = $this->snapshot($payload, configHash: $snapshot->configHash);

        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_policy_hash_mismatch');
        (new CanonicalRiskPolicyCompiler())->compile($tampered);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param list<string> $blockers
     */
    private function snapshot(
        ?array $payload = null,
        bool $executable = true,
        array $blockers = [],
        ?string $configHash = null,
    ): EffectiveTradingConfigSnapshot {
        $request = new EffectiveTradingConfigRequest(
            'micro_scalping',
            '1.0.0',
            'micro_scalping.momentum_ofi.long',
            '1.0.0',
            'fake',
            'test',
            'long',
        );

        $effectivePayload = $payload ?? $this->payload();
        $conditionCatalogHash = 'sha256:' . str_repeat('b', 64);
        if ($configHash === null) {
            try {
                $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($effectivePayload, $conditionCatalogHash);
            } catch (\InvalidArgumentException) {
                $configHash = 'sha256:' . str_repeat('a', 64);
            }
        }

        return new EffectiveTradingConfigSnapshot(
            request: $request,
            payload: $effectivePayload,
            configHash: $configHash,
            conditionCatalogHash: $conditionCatalogHash,
            layers: [],
            provenance: [],
            executable: $executable,
            blockers: $blockers,
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 'effective-trading-config.v2',
            'units' => [
                'percent' => 'percentage_points',
                'duration' => 'iso8601',
                'price' => 'quote_price',
                'notional' => 'quote_notional',
            ],
            'safety' => [
                'mainnet_write_enabled' => false,
                'demo_testnet_write_enabled' => false,
                'require_stop_loss' => true,
                'kill_switch_enabled' => true,
            ],
            'mode' => [
                'mode_id' => 'micro_scalping',
                'mode_version' => '1.0.0',
                'risk' => [
                    'trade_budget' => [
                        'state' => 'defined',
                        'value' => 0.4,
                        'unit' => 'percent_equity_per_trade',
                    ],
                ],
                'leverage' => [
                    'state' => 'defined',
                    'value' => 3.0,
                    'unit' => 'leverage_multiple',
                ],
            ],
            'setup' => [
                'setup_id' => 'micro_scalping.momentum_ofi.long',
                'setup_version' => '1.0.0',
                'side' => 'long',
            ],
            'exchange' => [
                'id' => 'fake',
                'fees' => ['maker_rate' => 0.0002, 'taker_rate' => 0.0005],
                'limits' => ['min_notional' => 5.0, 'max_notional' => 1000.0],
            ],
            'environment' => [
                'id' => 'test',
                'max_notional' => 250.0,
                'write_enabled' => false,
                'kill_switch_enabled' => true,
                'require_stop_loss' => true,
            ],
        ];
    }
}
