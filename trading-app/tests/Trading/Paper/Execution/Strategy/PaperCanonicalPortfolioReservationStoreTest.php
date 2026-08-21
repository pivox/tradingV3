<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Strategy\PaperCanonicalPortfolioReservationStore;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperCanonicalPortfolioReservationStore::class)]
final class PaperCanonicalPortfolioReservationStoreTest extends TestCase
{
    public function testEachAdmissionUsesTheAuthenticatedSnapshotWithoutPrivateState(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $proof = $effect->admissionProof;
        $store = new PaperCanonicalPortfolioReservationStore();
        $engine = new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00'));
        $first = $store->reserve(new CanonicalPortfolioAdmissionRequest(
            $proof->policy,
            $effect->plan,
            $proof->scope,
            $proof->snapshot,
            'paper-modern-decision-001',
        ), $engine);
        $recoveredSnapshot = new CanonicalPortfolioSnapshot(
            $proof->scope,
            $proof->snapshot->source,
            $proof->snapshot->sourceVersion,
            $proof->snapshot->policyDayStart,
            $proof->snapshot->policyDayEnd,
            $proof->snapshot->observedAt,
            $proof->snapshot->equityQuote,
            $proof->snapshot->realizedNetPnlQuote,
            $proof->snapshot->unrealizedNetPnlQuote,
            $proof->snapshot->openPositions,
            $proof->snapshot->pendingEntries,
            $proof->snapshot->openNotionalQuote,
            $proof->snapshot->pendingNotionalQuote,
            $proof->snapshot->reservedRiskQuote,
            $proof->snapshot->activeDecisionKeys,
            42,
            'sha256:' . str_repeat('9', 64),
        );
        $recovered = $store->reserve(new CanonicalPortfolioAdmissionRequest(
            $proof->policy,
            $effect->plan,
            $proof->scope,
            $recoveredSnapshot,
            'paper-modern-decision-after-recovery',
        ), $engine);

        self::assertSame($proof->snapshot->inputHash, $first->portfolioInputHash);
        self::assertSame('sha256:' . str_repeat('9', 64), $recovered->portfolioInputHash);
        self::assertNotSame($first->admissionHash, $recovered->admissionHash);
        self::assertSame('paper-modern-decision-after-recovery', $recovered->decisionKey);
    }

    public function testLifecycleTransitionsFailClosedBecauseDurablePaperOwnsThem(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('paper_canonical_portfolio_transition_forbidden');
        (new PaperCanonicalPortfolioReservationStore())->close(
            $effect->reservation,
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('c', 64),
        );
    }
}
