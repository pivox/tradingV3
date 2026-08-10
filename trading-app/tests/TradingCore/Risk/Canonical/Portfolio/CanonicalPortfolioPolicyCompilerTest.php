<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicyCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalPortfolioPolicyCompiler::class)]
final class CanonicalPortfolioPolicyCompilerTest extends TestCase
{
    public function testCompilesExplicitPortfolioSemanticsAndPercentagePointsExactlyOnce(): void
    {
        $policy = (new CanonicalPortfolioPolicyCompiler())->compile(CanonicalPortfolioFixture::snapshot());

        self::assertSame(0.06, $policy->dailyLossRate);
        self::assertSame(30.0, $policy->dailyLossAbsoluteQuote);
        self::assertSame('USDT', $policy->quoteCurrency);
        self::assertSame('UTC', $policy->dayTimezone);
        self::assertSame('00:00:00', $policy->dayBoundaryLocal);
        self::assertTrue($policy->includeUnrealizedLoss);
        self::assertSame(4, $policy->maxConcurrentPositions);
        self::assertTrue($policy->includePendingEntries);
        self::assertSame(1.0, $policy->modeExposureRate);
        self::assertSame('day_trading', $policy->modeId);
        self::assertSame('fake', $policy->exchange);
        self::assertSame('test', $policy->environment);
    }

    public function testCompiledPolicyCannotCrossPhpSerializationBoundary(): void
    {
        $policy = (new CanonicalPortfolioPolicyCompiler())->compile(CanonicalPortfolioFixture::snapshot());

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_policy_serialization_forbidden');
        serialize($policy);
    }

    public function testForgedSerializedPolicyCannotBeHydrated(): void
    {
        $class = \App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy::class;
        $payload = sprintf('O:%d:"%s":0:{}', strlen($class), $class);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_policy_serialization_forbidden');
        unserialize($payload);
    }

    /** @param array<string, mixed> $replacement */
    #[DataProvider('invalidPolicyProvider')]
    public function testRejectsUnresolvedOrAmbiguousPortfolioPolicy(array $replacement, string $reasonCode): void
    {
        $snapshot = CanonicalPortfolioFixture::snapshot($replacement);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage($reasonCode);
        (new CanonicalPortfolioPolicyCompiler())->compile($snapshot);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPolicyProvider(): iterable
    {
        yield 'legacy daily cap without day semantics' => [[
            'daily_loss_cap' => [
                'state' => 'defined',
                'value' => ['percent_equity' => 6.0, 'absolute_quote' => 30.0, 'quote_currency' => 'USDT'],
                'unit' => 'compound_percent_equity_and_quote_per_day',
            ],
        ], 'canonical_portfolio_daily_policy_invalid'];
        yield 'scalar concurrency without pending semantics' => [[
            'max_concurrent_positions' => ['state' => 'defined', 'value' => 4, 'unit' => 'positions'],
        ], 'canonical_portfolio_concurrency_policy_invalid'];
        yield 'unresolved exposure' => [[
            'mode_exposure_cap' => ['state' => 'unresolved', 'value' => null, 'unit' => 'percent_equity_notional'],
        ], 'canonical_portfolio_exposure_policy_unresolved'];
        yield 'invalid timezone' => [[
            'daily_loss_cap' => [
                'state' => 'defined',
                'value' => [
                    'percent_equity' => 6.0,
                    'absolute_quote' => 30.0,
                    'quote_currency' => 'USDT',
                    'day_timezone' => 'not/a-zone',
                    'day_boundary_local' => '00:00:00',
                    'include_unrealized_loss' => true,
                ],
                'unit' => 'compound_percent_equity_and_quote_per_day',
            ],
        ], 'canonical_portfolio_daily_policy_invalid'];
    }
}
