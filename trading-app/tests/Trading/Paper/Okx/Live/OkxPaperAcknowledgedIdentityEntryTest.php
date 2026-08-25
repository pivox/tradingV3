<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Live\OkxPaperAcknowledgedIdentityEntry;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperAcknowledgedIdentityEntry::class)]
final class OkxPaperAcknowledgedIdentityEntryTest extends TestCase
{
    public function testCompactRoundTripPreservesSingleOriginEntry(): void
    {
        $entry = [
            hash('sha256', 'identity'),
            hash('sha256', 'overlap'),
            hash('sha256', 'rest'),
            OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST,
        ];

        $compact = OkxPaperAcknowledgedIdentityEntry::compact($entry);

        self::assertLessThan(190, \strlen($compact));
        self::assertSame($entry, OkxPaperAcknowledgedIdentityEntry::expand($compact));
    }

    public function testCompactRoundTripPreservesCrossOriginEntry(): void
    {
        $entry = [
            hash('sha256', 'identity'),
            hash('sha256', 'overlap'),
            hash('sha256', 'rest'),
            hash('sha256', 'websocket'),
        ];

        $compact = OkxPaperAcknowledgedIdentityEntry::compact($entry);

        self::assertLessThan(190, \strlen($compact));
        self::assertSame($entry, OkxPaperAcknowledgedIdentityEntry::expand($compact));
    }

    public function testMalformedCompactEntryFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_acknowledged_identity_entry_invalid');

        OkxPaperAcknowledgedIdentityEntry::expand('v1:r:not-base64');
    }
}
