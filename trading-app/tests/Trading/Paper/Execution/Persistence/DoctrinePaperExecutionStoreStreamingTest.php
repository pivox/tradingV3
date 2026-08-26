<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\DoctrinePaperExecutionStore;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrinePaperExecutionStore::class)]
final class DoctrinePaperExecutionStoreStreamingTest extends TestCase
{
    public function testCheckpointVerificationStreamsTheDurableJournal(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn($this->emptyCheckpoint());
        $connection->expects(self::once())
            ->method('iterateAssociative')
            ->willReturn((static function (): \Generator {
                yield from [];
            })());

        $checkpoint = (new DoctrinePaperExecutionStore($connection))->checkpoint($this->cell());

        self::assertSame(0, $checkpoint->nextSourcePosition);
    }

    public function testAcknowledgedSourceRestorationStreamsDatabasePayloads(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('iterateColumn')
            ->willReturn((static function (): \Generator {
                yield from [];
            })());

        self::assertSame(
            [],
            (new DoctrinePaperExecutionStore($connection))->acknowledgedSources($this->cell()),
        );
    }

    private function cell(): PaperExecutionCell
    {
        return PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('a', 64),
            'scalper_micro',
            'streaming-test-run',
        );
    }

    /** @return array<string, bool|int|string> */
    private function emptyCheckpoint(): array
    {
        return [
            'cell_id' => $this->cell()->id,
            'next_source_position' => 0,
            'journal_ordinal' => 0,
            'journal_checksum' => str_repeat('0', 64),
            'fake_event_cursor' => 0,
            'killed' => false,
            'lock_version' => 0,
        ];
    }
}
