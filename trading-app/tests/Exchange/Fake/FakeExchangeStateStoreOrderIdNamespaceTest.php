<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeExchangeStateStore;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FakeExchangeStateStore::class)]
#[CoversClass(PaperFakeRuntimeFactory::class)]
final class FakeExchangeStateStoreOrderIdNamespaceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/paper_fake_ids_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testTheHistoricalFormatIsKeptWithoutANamespace(): void
    {
        $store = new FakeExchangeStateStore();

        self::assertSame(['fake-000001', 'fake-000002'], [$store->nextOrderId(), $store->nextOrderId()]);
    }

    public function testANamespacePrefixesEveryOrderIdAndCannotBeSwapped(): void
    {
        $store = new FakeExchangeStateStore();
        $store->useOrderIdNamespace('0123456789abcdef');
        $store->useOrderIdNamespace('0123456789abcdef');

        self::assertSame('fake-0123456789abcdef-000001', $store->nextOrderId());
        try {
            $store->useOrderIdNamespace('fedcba9876543210');
            self::fail('A store must not change its order id namespace.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_order_id_namespace_conflict', $exception->getMessage());
        }
        foreach (['', '0123456789ABCDEF', '0123456789abcde', '0123456789abcdef0', '../../../etc/xx'] as $invalid) {
            try {
                (new FakeExchangeStateStore())->useOrderIdNamespace($invalid);
                self::fail('Invalid namespace accepted: ' . $invalid);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('fake_order_id_namespace_invalid', $exception->getMessage());
            }
        }
    }

    public function testPaperCellsNeverShareAnOrderIdAndKeepTheirsAcrossRestarts(): void
    {
        $first = $this->cell('fake-ids-run-1');
        $second = $this->cell('fake-ids-run-2');
        $factory = new PaperFakeRuntimeFactory($this->root, new MockClock('2026-08-01T10:00:00Z'));

        $firstId = $factory->forCell($first)->stateStore->nextOrderId();
        $secondId = $factory->forCell($second)->stateStore->nextOrderId();
        $restarted = (new PaperFakeRuntimeFactory($this->root, new MockClock('2026-08-01T10:00:00Z')))->forCell($first)->stateStore->nextOrderId();

        self::assertSame('fake-' . substr($first->id, 7, 16) . '-000001', $firstId);
        self::assertSame('fake-' . substr($second->id, 7, 16) . '-000001', $secondId);
        self::assertNotSame($firstId, $secondId);
        self::assertSame($firstId, $restarted, 'The namespace is derived from the cell: a restarted runtime issues the same ids from the same state.');
        self::assertLessThanOrEqual(64, \strlen($firstId), 'Fits every order id column (trade_lifecycle_event.order_id is 64).');
    }

    private function cell(string $runId): PaperExecutionCell
    {
        return PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('e', 64),
            'scalper_micro',
            $runId,
        );
    }
}
