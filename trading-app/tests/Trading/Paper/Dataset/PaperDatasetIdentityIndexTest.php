<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Trading\Paper\Dataset\PaperDatasetIdentityIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperDatasetIdentityIndex::class)]
final class PaperDatasetIdentityIndexTest extends TestCase
{
    public function testLargeExactIndexKeepsPhpMemoryBoundedAndFindsOldEntries(): void
    {
        $index = new PaperDatasetIdentityIndex();
        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);

        for ($ordinal = 0; $ordinal < 50_000; ++$ordinal) {
            $index->add(
                hash('sha256', 'event-' . $ordinal),
                hash('sha256', 'payload-' . $ordinal),
                hash('sha256', 'canonical-' . $ordinal),
            );
        }

        self::assertSame(50_000, $index->count());
        self::assertSame([
            'payload_hash' => hash('sha256', 'payload-0'),
            'event_hash' => hash('sha256', 'canonical-0'),
        ], $index->find(hash('sha256', 'event-0')));
        self::assertSame([
            'payload_hash' => hash('sha256', 'payload-49999'),
            'event_hash' => hash('sha256', 'canonical-49999'),
        ], $index->find(hash('sha256', 'event-49999')));
        self::assertLessThanOrEqual(
            4 * 1024 * 1024,
            memory_get_usage(true) - $memoryBefore,
            'The exact identity index must spill to disk instead of growing the PHP heap.',
        );
    }

    public function testConflictingBatchIsAtomic(): void
    {
        $index = new PaperDatasetIdentityIndex();
        $existingId = hash('sha256', 'existing');
        $index->add(
            $existingId,
            hash('sha256', 'existing-payload'),
            hash('sha256', 'existing-event'),
        );

        try {
            $index->addBatch([
                [
                    'event_id' => hash('sha256', 'new'),
                    'payload_hash' => hash('sha256', 'new-payload'),
                    'event_hash' => hash('sha256', 'new-event'),
                ],
                [
                    'event_id' => $existingId,
                    'payload_hash' => hash('sha256', 'conflict-payload'),
                    'event_hash' => hash('sha256', 'conflict-event'),
                ],
            ]);
            self::fail('An identity conflict must reject the complete candidate batch.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_identity_index_conflict', $exception->getMessage());
        }

        self::assertNull($index->find(hash('sha256', 'new')));
        self::assertSame(1, $index->count());
    }
}
