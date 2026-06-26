<?php

declare(strict_types=1);

namespace App\Trading\Reporting;

use App\Trading\Entity\PositionTradeAnalysis;
use App\Trading\Entity\PositionTradeAnalysisV2;

final class PositionTradeAnalysisReportingService
{
    private const DUAL_COMPARISON_LIMIT = 5000;

    public function __construct(
        private readonly PositionTradeAnalysisLegacyReaderInterface $legacyReader,
        private readonly PositionTradeAnalysisCertifiedReaderInterface $certifiedReader,
        private readonly string $configuredSource = PositionTradeAnalysisReportingSource::V2,
    ) {
    }

    /**
     * @param array{symbol?: string|null, from?: string|null, to?: string|null, timeframe?: string|null} $filters
     * @param array{sort?: string, direction?: string} $options
     */
    public function search(array $filters, array $options = [], int $limit = 200, ?string $source = null): PositionTradeAnalysisReportingResult
    {
        $activeSource = PositionTradeAnalysisReportingSource::normalize($source, $this->configuredSource);

        if ($activeSource === PositionTradeAnalysisReportingSource::V1) {
            $legacyRows = $this->fetchLegacy($filters, $options, $limit);
            $rows = array_map([$this, 'fromLegacy'], $legacyRows);

            return new PositionTradeAnalysisReportingResult(
                activeSource: $activeSource,
                primarySource: PositionTradeAnalysisReportingSource::V1,
                dualRead: false,
                rows: $rows,
                summary: $this->summary($activeSource, PositionTradeAnalysisReportingSource::V1, $rows, [
                    'pnl_definition' => 'legacy_recorded_pnl',
                ]),
            );
        }

        if ($activeSource === PositionTradeAnalysisReportingSource::DUAL) {
            $comparisonOptions = ['sort' => 'entryTime', 'direction' => 'DESC'];
            $legacyComparisonRows = $this->fetchLegacy($filters, $comparisonOptions, self::DUAL_COMPARISON_LIMIT);
            $certifiedComparisonRows = $this->fetchCertified($filters, $comparisonOptions, self::DUAL_COMPARISON_LIMIT);
            $certifiedDisplayRows = $this->fetchCertified($filters, $options, $limit);
            $rows = array_map([$this, 'fromCertified'], $certifiedDisplayRows);
            $divergence = $this->compareLegacyAndCertified($legacyComparisonRows, $certifiedComparisonRows);

            return new PositionTradeAnalysisReportingResult(
                activeSource: $activeSource,
                primarySource: PositionTradeAnalysisReportingSource::V2,
                dualRead: true,
                rows: $rows,
                summary: $this->summary($activeSource, PositionTradeAnalysisReportingSource::V2, $rows, [
                    'pnl_definition' => 'certified_net_v1',
                    'display_limit' => $limit,
                    'comparison_limit' => self::DUAL_COMPARISON_LIMIT,
                    'comparison_sort' => 'entryTime DESC',
                    'v1_rows' => count($legacyComparisonRows),
                    'v2_rows' => count($certifiedComparisonRows),
                    'common_rows' => $divergence['common_rows'],
                    'divergent_rows' => $divergence['divergent_rows'],
                    'v1_only_rows' => $divergence['v1_only_rows'],
                    'v2_only_rows' => $divergence['v2_only_rows'],
                ]),
            );
        }

        $certifiedRows = $this->fetchCertified($filters, $options, $limit);
        $rows = array_map([$this, 'fromCertified'], $certifiedRows);

        return new PositionTradeAnalysisReportingResult(
            activeSource: PositionTradeAnalysisReportingSource::V2,
            primarySource: PositionTradeAnalysisReportingSource::V2,
            dualRead: false,
            rows: $rows,
            summary: $this->summary(PositionTradeAnalysisReportingSource::V2, PositionTradeAnalysisReportingSource::V2, $rows, [
                'pnl_definition' => 'certified_net_v1',
            ]),
        );
    }

    /**
     * @param array{symbol?: string|null, from?: string|null, to?: string|null, timeframe?: string|null} $filters
     * @param array{sort?: string, direction?: string} $options
     * @return PositionTradeAnalysis[]
     */
    private function fetchLegacy(array $filters, array $options, int $limit): array
    {
        try {
            return $this->legacyReader->search($filters, $options, $limit);
        } catch (\Throwable $e) {
            throw new PositionTradeAnalysisReportingSourceException(PositionTradeAnalysisReportingSource::V1, $e);
        }
    }

    /**
     * @param array{symbol?: string|null, from?: string|null, to?: string|null, timeframe?: string|null} $filters
     * @param array{sort?: string, direction?: string} $options
     * @return PositionTradeAnalysisV2[]
     */
    private function fetchCertified(array $filters, array $options, int $limit): array
    {
        try {
            return $this->certifiedReader->search($filters, $options, $limit);
        } catch (\Throwable $e) {
            throw new PositionTradeAnalysisReportingSourceException(PositionTradeAnalysisReportingSource::V2, $e);
        }
    }

    private function fromLegacy(PositionTradeAnalysis $row): PositionTradeAnalysisReportRow
    {
        return new PositionTradeAnalysisReportRow(
            entryEventId: $row->getEntryEventId(),
            sourceVersion: PositionTradeAnalysisReportingSource::V1,
            symbol: $row->getSymbol(),
            timeframe: $row->getTimeframe(),
            entryTime: $row->getEntryTime(),
            closeTime: $row->getCloseTime(),
            expectedRMultiple: $row->getExpectedRMultiple(),
            pnlR: $row->getPnlR(),
            pnlUsdt: $row->getPnlUsdt(),
            recordedPnlUsdt: $row->getPnlUsdt(),
            estimatedNetPnlUsdt: null,
            mfePct: $row->getMfePct(),
            maePct: $row->getMaePct(),
            entryRsi: $row->getEntryRsi(),
            entryAtr: $row->getEntryAtr(),
            entryMa9: $row->getEntryMa9(),
            entryMa21: $row->getEntryMa21(),
            entryVwap: $row->getEntryVwap(),
            costCompleteness: 'legacy',
            qualityFlags: ['legacy_v1'],
            closeMatchStatus: $row->getCloseEventId() === null ? 'unmatched' : 'legacy',
            closeMatchedBy: 'legacy_v1',
            analysisStatus: $row->getCloseEventId() === null ? 'legacy_unmatched' : 'legacy_closed',
            pnlDefinition: 'legacy_recorded_pnl',
            dataComplete: false,
            netCertified: false,
        );
    }

    private function fromCertified(PositionTradeAnalysisV2 $row): PositionTradeAnalysisReportRow
    {
        $certified = $row->hasCertifiedNetPnl();
        $flags = $row->getPnlQualityFlags();
        if (!$certified) {
            $flags[] = 'net_pnl_not_certified';
        }
        if (!$row->isMatchedClosed()) {
            $flags[] = 'close_unmatched';
        }
        if (!$row->isCostComplete()) {
            $flags[] = 'cost_' . $row->getCostCompleteness();
        }
        $flags = array_values(array_unique($flags));

        return new PositionTradeAnalysisReportRow(
            entryEventId: $row->getEntryEventId(),
            sourceVersion: PositionTradeAnalysisReportingSource::V2,
            symbol: $row->getSymbol(),
            timeframe: $row->getTimeframe(),
            entryTime: $row->getEntryTime(),
            closeTime: $row->getCloseTime(),
            expectedRMultiple: $row->getExpectedRMultiple(),
            pnlR: $row->getRealizedNetPnlR(),
            pnlUsdt: $certified ? $row->getNetPnlUsdt() : null,
            recordedPnlUsdt: $row->getRecordedPnlUsdt(),
            estimatedNetPnlUsdt: $row->getEstimatedNetPnlUsdt(),
            mfePct: $row->getMfePct(),
            maePct: $row->getMaePct(),
            entryRsi: $row->getEntryRsi(),
            entryAtr: $row->getEntryAtr(),
            entryMa9: $row->getEntryMa9(),
            entryMa21: $row->getEntryMa21(),
            entryVwap: $row->getEntryVwap(),
            costCompleteness: $row->getCostCompleteness(),
            qualityFlags: $flags,
            closeMatchStatus: $row->getCloseMatchStatus(),
            closeMatchedBy: $row->getCloseMatchedBy(),
            analysisStatus: $row->getAnalysisStatus(),
            pnlDefinition: 'certified_net_v1',
            dataComplete: $certified,
            netCertified: $certified,
        );
    }

    /**
     * @param list<PositionTradeAnalysisReportRow> $rows
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function summary(string $activeSource, string $primarySource, array $rows, array $extra): array
    {
        $dataComplete = true;
        foreach ($rows as $row) {
            if (!$row->dataComplete) {
                $dataComplete = false;
                break;
            }
        }

        return $extra + [
            'active_source' => $activeSource,
            'primary_source' => $primarySource,
            'rollback_source' => PositionTradeAnalysisReportingSource::V1,
            'row_count' => count($rows),
            'data_complete' => $dataComplete,
        ];
    }

    /**
     * @param PositionTradeAnalysis[] $legacyRows
     * @param PositionTradeAnalysisV2[] $certifiedRows
     * @return array{common_rows:int, divergent_rows:int, v1_only_rows:int, v2_only_rows:int}
     */
    private function compareLegacyAndCertified(array $legacyRows, array $certifiedRows): array
    {
        $legacyById = [];
        foreach ($legacyRows as $row) {
            $legacyById[$row->getEntryEventId()] = $row;
        }

        $certifiedById = [];
        foreach ($certifiedRows as $row) {
            $certifiedById[$row->getEntryEventId()] = $row;
        }

        $commonIds = array_intersect(array_keys($legacyById), array_keys($certifiedById));
        $divergent = 0;
        foreach ($commonIds as $id) {
            $legacyPnl = $legacyById[$id]->getPnlUsdt();
            $v2Pnl = $certifiedById[$id]->hasCertifiedNetPnl()
                ? $certifiedById[$id]->getNetPnlUsdt()
                : $certifiedById[$id]->getRecordedPnlUsdt();

            if ($legacyPnl === null || $v2Pnl === null) {
                if ($legacyPnl !== $v2Pnl) {
                    ++$divergent;
                }
                continue;
            }

            if (abs($legacyPnl - $v2Pnl) > 0.000001) {
                ++$divergent;
            }
        }

        return [
            'common_rows' => count($commonIds),
            'divergent_rows' => $divergent,
            'v1_only_rows' => count(array_diff(array_keys($legacyById), array_keys($certifiedById))),
            'v2_only_rows' => count(array_diff(array_keys($certifiedById), array_keys($legacyById))),
        ];
    }
}
