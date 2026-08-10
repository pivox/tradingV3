<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical;

use App\TradingCore\Risk\Canonical\CanonicalCostSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalCostSnapshot::class)]
#[CoversClass(CanonicalRiskCalculationRequest::class)]
final class CanonicalRiskContractTest extends TestCase
{
    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('unknownCostProvider')]
    public function testRejectsEveryUnknownCost(callable $mutate): void
    {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_market_cost_unknown');
        new CanonicalCostSnapshot(...$mutate($this->costArguments()));
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function unknownCostProvider(): iterable
    {
        foreach (['entryFeeRate', 'stopExitFeeRate', 'spreadRate', 'slippageRate', 'fundingRate', 'fundingIntervals'] as $field) {
            yield $field => [static function (array $arguments) use ($field): array {
                $arguments[$field] = null;
                return $arguments;
            }];
        }
    }

    public function testAcceptsExplicitZeroFundingAndZeroIntervals(): void
    {
        $costs = new CanonicalCostSnapshot(...$this->costArguments());

        self::assertSame(0.0, $costs->fundingRate);
        self::assertSame(0, $costs->fundingIntervals);
    }

    #[DataProvider('invalidRateProvider')]
    public function testRejectsInvalidCostRates(string $field, int|float $value): void
    {
        $arguments = $this->costArguments();
        $arguments[$field] = $value;

        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_market_cost_rate_invalid');
        new CanonicalCostSnapshot(...$arguments);
    }

    /** @return iterable<string, array{string, int|float}> */
    public static function invalidRateProvider(): iterable
    {
        yield 'negative fee' => ['entryFeeRate', -0.001];
        yield 'fee at one' => ['stopExitFeeRate', 1.0];
        yield 'infinite spread' => ['spreadRate', INF];
        yield 'negative slippage' => ['slippageRate', -0.001];
        yield 'funding at one' => ['fundingRate', 1.0];
        yield 'funding at minus one' => ['fundingRate', -1.0];
    }

    public function testRejectsNegativeFundingIntervals(): void
    {
        $arguments = $this->costArguments();
        $arguments['fundingIntervals'] = -1;

        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_market_funding_intervals_invalid');
        new CanonicalCostSnapshot(...$arguments);
    }

    #[DataProvider('invalidRequestProvider')]
    public function testRejectsInvalidRiskRequest(string $reasonCode, string $side, array $overrides): void
    {
        $arguments = $this->requestArguments($side);
        foreach ($overrides as $field => $value) {
            $arguments[$field] = $value;
        }

        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage($reasonCode);
        new CanonicalRiskCalculationRequest(...$arguments);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function invalidRequestProvider(): iterable
    {
        yield 'empty symbol' => ['canonical_risk_symbol_invalid', 'long', ['symbol' => '']];
        yield 'zero equity' => ['canonical_risk_equity_invalid', 'long', ['equityQuote' => 0.0]];
        yield 'negative balance' => ['canonical_risk_available_balance_invalid', 'long', ['availableBalanceQuote' => -1.0]];
        yield 'zero entry' => ['canonical_risk_price_invalid', 'long', ['entryPrice' => 0.0]];
        yield 'zero stop' => ['canonical_risk_price_invalid', 'long', ['stopPrice' => 0.0]];
        yield 'long stop above entry' => ['canonical_risk_stop_side_invalid', 'long', ['stopPrice' => 101.0]];
        yield 'short stop below entry' => ['canonical_risk_stop_side_invalid', 'short', ['stopPrice' => 99.0]];
        yield 'zero contract' => ['canonical_risk_contract_size_invalid', 'long', ['contractSize' => 0.0]];
        yield 'zero step' => ['canonical_risk_quantity_step_invalid', 'long', ['quantityStep' => 0.0]];
        yield 'minimum below step' => ['canonical_risk_quantity_bounds_invalid', 'long', ['minQuantity' => 0.0005]];
        yield 'maximum below minimum' => ['canonical_risk_quantity_bounds_invalid', 'long', ['maxQuantity' => 0.0005]];
        yield 'market maximum below minimum' => ['canonical_risk_quantity_bounds_invalid', 'long', ['marketMaxQuantity' => 0.0005]];
        yield 'exchange leverage below one' => ['canonical_leverage_cap_invalid', 'long', ['exchangeLeverageCap' => 0.5]];
        yield 'symbol leverage below one' => ['canonical_leverage_cap_invalid', 'long', ['symbolLeverageCap' => 0.5]];
    }

    public function testRejectsSideThatDoesNotMatchPolicy(): void
    {
        $arguments = $this->requestArguments('long');
        $arguments['side'] = 'short';
        $arguments['stopPrice'] = 102.0;

        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_policy_identity_mismatch');
        new CanonicalRiskCalculationRequest(...$arguments);
    }

    public function testAcceptsExplicitFiniteLongAndShortSnapshots(): void
    {
        $long = new CanonicalRiskCalculationRequest(...$this->requestArguments('long'));
        $short = new CanonicalRiskCalculationRequest(...$this->requestArguments('short'));

        self::assertSame(98.0, $long->stopPrice);
        self::assertSame(102.0, $short->stopPrice);
        self::assertSame('long', $long->policy->side);
        self::assertSame('short', $short->policy->side);
    }

    /** @return array<string, int|float|null> */
    private function costArguments(): array
    {
        return [
            'entryFeeRate' => 0.001,
            'stopExitFeeRate' => 0.001,
            'spreadRate' => 0.0002,
            'slippageRate' => 0.0005,
            'fundingRate' => 0.0,
            'fundingIntervals' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function requestArguments(string $side): array
    {
        return [
            'policy' => $this->policy($side),
            'symbol' => 'BTCUSDT',
            'side' => $side,
            'equityQuote' => 1000.0,
            'availableBalanceQuote' => 100.0,
            'entryPrice' => 100.0,
            'stopPrice' => $side === 'long' ? 98.0 : 102.0,
            'contractSize' => 1.0,
            'quantityStep' => 0.001,
            'minQuantity' => 0.001,
            'maxQuantity' => 100.0,
            'marketMaxQuantity' => 50.0,
            'exchangeLeverageCap' => 20.0,
            'symbolLeverageCap' => 10.0,
            'costs' => new CanonicalCostSnapshot(...$this->costArguments()),
        ];
    }

    private function policy(string $side): CanonicalRiskPolicy
    {
        return new CanonicalRiskPolicy(
            modeId: 'day_trading',
            modeVersion: '1.0.0',
            setupId: 'day_trading.trend_continuation.' . $side,
            setupVersion: '1.0.0',
            exchange: 'fake',
            environment: 'test',
            side: $side,
            configHash: 'sha256:' . str_repeat('a', 64),
            riskRate: 0.01,
            modeLeverageCap: 5.0,
            makerFeeRate: 0.0,
            takerFeeRate: 0.0,
            exchangeMaxNotional: 1000.0,
            environmentMaxNotional: 500.0,
        );
    }
}
