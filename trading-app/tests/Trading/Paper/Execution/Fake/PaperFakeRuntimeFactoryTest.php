<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntime;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperFakeRuntime::class)]
#[CoversClass(PaperFakeRuntimeFactory::class)]
final class PaperFakeRuntimeFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/paper_fake_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root) && !is_link($this->root)) {
            foreach (glob($this->root . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->root);
        } elseif (is_link($this->root)) {
            @unlink($this->root);
        }
    }

    public function testEachCellGetsOneDistinctDurablePrivateState(): void
    {
        $factory = new PaperFakeRuntimeFactory($this->root, new MockClock('2026-08-01T10:00:00Z'));
        $first = $factory->forCell($this->cell('run-1'));
        $same = $factory->forCell($this->cell('run-1'));
        $second = $factory->forCell($this->cell('run-2'));

        self::assertSame($first, $same);
        self::assertNotSame($first->statePath, $second->statePath);
        self::assertSame(0, fileperms($this->root) & 0077);
        self::assertStringStartsWith(realpath($this->root) . '/', $first->statePath);
        self::assertTrue($first->adapter->isBackedByStateStore($first->stateStore));
    }

    public function testPermissiveOrSymlinkedRootIsRejected(): void
    {
        mkdir($this->root, 0755, true);
        chmod($this->root, 0755);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_fake_state_root_not_private');
        (new PaperFakeRuntimeFactory($this->root, new MockClock()))->forCell($this->cell('run-1'));
    }

    public function testRuntimeRejectsAnyNonFakeAdapter(): void
    {
        $clock = new MockClock();
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $adapter = $this->createMock(ExchangeAdapterInterface::class);
        $adapter->method('exchange')->willReturn(Exchange::BITMART);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_execution_exchange_must_be_fake');
        new PaperFakeRuntime($this->cell('run-1'), $this->root . '/state.dat', $state, $book, new FakeExchangeMatchingEngine($state, $book, $clock), $adapter);
    }

    private function cell(string $runId): PaperExecutionCell
    {
        return PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('b', 64),
            'scalper_micro',
            $runId,
        );
    }
}
