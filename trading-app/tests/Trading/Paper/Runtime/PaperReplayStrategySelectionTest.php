<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Runtime;

use App\Trading\Paper\Runtime\PaperReplayStrategySelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperReplayStrategySelection::class)]
final class PaperReplayStrategySelectionTest extends TestCase
{
    public function testLegacyProfileIsExclusive(): void
    {
        $selection = PaperReplayStrategySelection::fromOptions('regular', null, null, null, null, null);

        self::assertFalse($selection->isModern());
        self::assertSame('regular', $selection->legacyProfile());
    }

    public function testModernIdentityRequiresEveryExactField(): void
    {
        $selection = PaperReplayStrategySelection::fromOptions(
            null,
            'day_trading',
            '1.1.0',
            'day_trading.trend_continuation.long',
            '1.1.0',
            'long',
        );

        self::assertTrue($selection->isModern());
        self::assertSame([
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
        ], $selection->modernIdentity());
    }

    public function testMixedLegacyAndModernSelectionFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_strategy_selection_ambiguous');

        PaperReplayStrategySelection::fromOptions(
            'regular', 'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0', 'long',
        );
    }

    public function testPartialModernSelectionFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_modern_strategy_identity_incomplete');

        PaperReplayStrategySelection::fromOptions(null, 'day_trading', '1.1.0', null, null, null);
    }

    public function testAbsentSelectionFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_strategy_selection_required');

        PaperReplayStrategySelection::fromOptions(null, null, null, null, null, null);
    }
}
