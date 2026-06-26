<?php

declare(strict_types=1);

namespace App\Trading\Reporting;

final readonly class PositionTradeAnalysisReportRow
{
    /**
     * @param list<string> $qualityFlags
     */
    public function __construct(
        public int $entryEventId,
        public string $sourceVersion,
        public string $symbol,
        public ?string $timeframe,
        public \DateTimeImmutable $entryTime,
        public ?\DateTimeImmutable $closeTime,
        public ?float $expectedRMultiple,
        public ?float $pnlR,
        public ?float $pnlUsdt,
        public ?float $recordedPnlUsdt,
        public ?float $estimatedNetPnlUsdt,
        public ?float $mfePct,
        public ?float $maePct,
        public ?float $entryRsi,
        public ?float $entryAtr,
        public ?float $entryMa9,
        public ?float $entryMa21,
        public ?float $entryVwap,
        public string $costCompleteness,
        public array $qualityFlags,
        public string $closeMatchStatus,
        public string $closeMatchedBy,
        public string $analysisStatus,
        public string $pnlDefinition,
        public bool $dataComplete,
        public bool $netCertified,
    ) {
    }
}
