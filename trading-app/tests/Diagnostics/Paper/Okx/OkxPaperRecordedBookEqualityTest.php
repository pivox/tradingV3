<?php

declare(strict_types=1);

namespace App\Tests\Diagnostics\Paper\Okx;

use App\Tests\Trading\Paper\Okx\Live\BookReplay;
use App\Trading\Paper\Okx\Live\OkxPaperOrderBookMaterializer;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperOrderBookMaterializer::class)]
#[CoversClass(OkxMaterializedBookState::class)]
final class OkxPaperRecordedBookEqualityTest extends TestCase
{
    /**
     * Raw OKX books frames recorded from the public websocket (one JSON frame per line),
     * replayed through both materializations: OKX_PAPER_BOOK_REPLAY_FILE=<ndjson>.
     */
    public function testRecordedDeltasMatchTheFullRematerialization(): void
    {
        $file = getenv('OKX_PAPER_BOOK_REPLAY_FILE');
        if (!\is_string($file) || $file === '') {
            self::markTestSkipped('Set OKX_PAPER_BOOK_REPLAY_FILE to a recording of raw OKX books frames.');
        }
        $replays = [];
        $applied = 0;
        $rejected = 0;
        $snapshots = 0;
        foreach (new \SplFileObject($file) as $line) {
            if (!\is_string($line) || trim($line) === '') {
                continue;
            }
            $frame = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (($frame['arg']['channel'] ?? null) !== 'books' || !\is_array($frame['data'] ?? null)) {
                continue;
            }
            $instrument = (string) $frame['arg']['instId'];
            $replays[$instrument] ??= new BookReplay();
            foreach ($frame['data'] as $row) {
                if (($frame['action'] ?? null) === 'snapshot') {
                    $replays[$instrument]->snapshot($row);
                    ++$snapshots;
                } elseif ($replays[$instrument]->hasBook()) {
                    $replays[$instrument]->delta($row, $applied, $rejected);
                }
            }
        }
        self::assertGreaterThan(0, $snapshots);
        self::assertGreaterThan(1_000, $applied);
        self::assertSame(0, $rejected);
    }
}
