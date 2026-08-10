<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

final readonly class CanonicalPortfolioReservationDecision
{
    private function __construct(
        public CanonicalPortfolioScope $scope,
        public string $decisionKey,
        public string $configHash,
        public string $planHash,
        public string $portfolioInputHash,
        public string $portfolioSource,
        public string $portfolioSourceVersion,
        public int $expectedStateVersion,
        public float $effectiveDailyLossCapQuote,
        public float $consumedDailyLossQuote,
        public float $remainingDailyLossBeforeCandidateQuote,
        public float $reservedRiskQuote,
        public float $reservedNotionalQuote,
        public float $modeExposureCapQuote,
        public float $projectedModeExposureQuote,
        public int $projectedConcurrentPositions,
        public \DateTimeImmutable $createdAt,
        public string $reservationHash,
    ) {
    }

    public static function fromAdmission(
        CanonicalPortfolioAdmissionRequest $request,
        CanonicalPortfolioAdmissionEngine $engine,
    ): self {
        return new self(...$engine->evaluate($request));
    }
}
