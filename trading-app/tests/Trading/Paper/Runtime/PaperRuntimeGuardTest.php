<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Runtime;

use App\Common\Enum\Exchange;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Runtime\PaperRuntimeContext;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperRuntimeGuard::class)]
#[CoversClass(PaperRuntimeContext::class)]
final class PaperRuntimeGuardTest extends TestCase
{
    public function testSafePaperRuntimeIsAccepted(): void
    {
        $context = $this->safeContext(symbols: ['BTCUSDT', 'ETHUSDT']);

        (new PaperRuntimeGuard())->assertSafe($context);

        self::addToAssertionCount(1);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('unsafeContextProvider')]
    public function testUnsafePaperRuntimeFailsWithStableReason(array $overrides, string $expectedReason): void
    {
        $guard = new PaperRuntimeGuard();

        try {
            $guard->assertSafe($this->safeContext(...$overrides));
            self::fail('Unsafe Paper runtime must be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame($expectedReason, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unsafeContextProvider(): iterable
    {
        yield 'dry-run mode' => [
            ['executionMode' => 'dry_run'],
            'paper_execution_mode_required',
        ];
        yield 'OKX exchange' => [
            ['executionExchange' => Exchange::OKX],
            'paper_execution_exchange_must_be_fake',
        ];
        yield 'Hyperliquid exchange' => [
            ['executionExchange' => Exchange::HYPERLIQUID],
            'paper_execution_exchange_must_be_fake',
        ];
        yield 'Bitmart exchange' => [
            ['executionExchange' => Exchange::BITMART],
            'paper_execution_exchange_must_be_fake',
        ];
        yield 'Binance exchange' => [
            ['executionExchange' => Exchange::BINANCE],
            'paper_execution_exchange_must_be_fake',
        ];
        yield 'Paper disabled' => [
            ['paperExecutionEnabled' => false],
            'paper_execution_disabled',
        ];
        yield 'mainnet writes enabled' => [
            ['mainnetWriteEnabled' => true],
            'paper_exchange_writes_must_be_disabled',
        ];
        yield 'demo or testnet writes enabled' => [
            ['demoTestnetWriteEnabled' => true],
            'paper_exchange_writes_must_be_disabled',
        ];
        yield 'empty symbol set' => [
            ['symbols' => []],
            'paper_symbol_not_allowed',
        ];
        yield 'unallowlisted symbol' => [
            ['symbols' => ['BTCUSDT', 'SOLUSDT']],
            'paper_symbol_not_allowed',
        ];
    }

    public function testFailureDoesNotExposeEnvironmentValuesOrCredentials(): void
    {
        $credential = 'postgresql://paper_user:super-secret@database/trading_app';

        try {
            (new PaperRuntimeGuard())->assertSafe($this->safeContext(executionMode: $credential));
            self::fail('Unsafe Paper runtime must be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_execution_mode_required', $exception->getMessage());
            self::assertStringNotContainsString($credential, $exception->getMessage());
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
        }
    }

    public function testEventWithExactCellProvenanceIsAccepted(): void
    {
        $context = $this->safeContext();

        (new PaperRuntimeGuard())->assertEventProvenance(
            $context,
            $this->event(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID),
        );

        self::addToAssertionCount(1);
    }

    public function testCrossNetworkEventIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_network_mismatch');

        (new PaperRuntimeGuard())->assertEventProvenance(
            $this->safeContext(),
            $this->event(PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::HYPERLIQUID),
        );
    }

    public function testVenueFallbackEventIsRejected(): void
    {
        $context = $this->safeContext(cell: PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            'regular',
            'run-okx',
        ));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_market_data_venue_mismatch');

        (new PaperRuntimeGuard())->assertEventProvenance(
            $context,
            $this->event(PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::HYPERLIQUID),
        );
    }

    /** @param list<string> $symbols */
    private function safeContext(
        string $executionMode = 'paper',
        Exchange $executionExchange = Exchange::FAKE,
        bool $paperExecutionEnabled = true,
        bool $mainnetWriteEnabled = false,
        bool $demoTestnetWriteEnabled = false,
        array $symbols = ['BTCUSDT'],
        ?PaperExecutionCell $cell = null,
    ): PaperRuntimeContext {
        return new PaperRuntimeContext(
            executionMode: $executionMode,
            executionExchange: $executionExchange,
            paperExecutionEnabled: $paperExecutionEnabled,
            mainnetWriteEnabled: $mainnetWriteEnabled,
            demoTestnetWriteEnabled: $demoTestnetWriteEnabled,
            symbols: $symbols,
            cell: $cell ?? PaperExecutionCell::create(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'sha256:' . str_repeat('a', 64),
                'scalper_micro',
                'run-001',
            ),
        );
    }

    private function event(PaperMarketDataNetwork $network, PaperMarketDataVenue $venue): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            $network,
            $venue,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            '1',
            ['bid' => '999', 'ask' => '1001'],
        );
    }
}
