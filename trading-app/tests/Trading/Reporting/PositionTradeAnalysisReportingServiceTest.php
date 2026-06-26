<?php

declare(strict_types=1);

namespace App\Tests\Trading\Reporting;

use App\Trading\Entity\PositionTradeAnalysis;
use App\Trading\Entity\PositionTradeAnalysisV2;
use App\Trading\Reporting\PositionTradeAnalysisCertifiedReaderInterface;
use App\Trading\Reporting\PositionTradeAnalysisLegacyReaderInterface;
use App\Trading\Reporting\PositionTradeAnalysisReportRow;
use App\Trading\Reporting\PositionTradeAnalysisReportingResult;
use App\Trading\Reporting\PositionTradeAnalysisReportingService;
use App\Trading\Reporting\PositionTradeAnalysisReportingSource;
use App\Trading\Reporting\PositionTradeAnalysisReportingSourceException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PositionTradeAnalysisReportingService::class)]
#[CoversClass(PositionTradeAnalysisReportingSource::class)]
#[CoversClass(PositionTradeAnalysisReportingResult::class)]
#[CoversClass(PositionTradeAnalysisReportRow::class)]
#[CoversClass(PositionTradeAnalysisReportingSourceException::class)]
final class PositionTradeAnalysisReportingServiceTest extends TestCase
{
    public function testDefaultV2ConsumerUsesCertifiedNetPnlAndExposesQualityMetadata(): void
    {
        $service = new PositionTradeAnalysisReportingService(
            new RecordingLegacyReader([$this->legacyRow(['entryEventId' => 101, 'pnlUsdt' => 12.0])]),
            new RecordingCertifiedReader([$this->v2Row([
                'entryEventId' => 101,
                'recordedPnlUsdt' => 12.0,
                'netPnlUsdt' => 10.5,
                'costCompleteness' => 'complete',
                'closeEventId' => 201,
                'closeMatchStatus' => 'matched',
                'closeMatchedBy' => 'matched_internal_trade_id',
                'analysisStatus' => 'matched_closed',
                'pnlQualityFlags' => ['ledger_complete'],
            ])]),
            'v2',
        );

        $result = $service->search(['symbol' => 'btcusdt'], ['sort' => 'pnlUsdt'], 50);

        self::assertSame('v2', $result->activeSource);
        self::assertSame('certified_net_v1', $result->summary['pnl_definition']);
        self::assertFalse($result->dualRead);
        self::assertCount(1, $result->rows);
        self::assertSame(10.5, $result->rows[0]->pnlUsdt);
        self::assertSame(12.0, $result->rows[0]->recordedPnlUsdt);
        self::assertTrue($result->rows[0]->netCertified);
        self::assertTrue($result->rows[0]->dataComplete);
        self::assertSame(['ledger_complete'], $result->rows[0]->qualityFlags);
    }

    public function testIncompleteV2NeverDisplaysRecordedPnlAsCertifiedNetPnl(): void
    {
        $service = new PositionTradeAnalysisReportingService(
            new RecordingLegacyReader([]),
            new RecordingCertifiedReader([$this->v2Row([
                'recordedPnlUsdt' => 7.25,
                'estimatedNetPnlUsdt' => 6.8,
                'netPnlUsdt' => null,
                'costCompleteness' => 'partial',
                'closeEventId' => 301,
                'closeMatchStatus' => 'matched',
                'closeMatchedBy' => 'matched_internal_trade_id',
                'analysisStatus' => 'matched_closed',
            ])]),
            'v2',
        );

        $result = $service->search([], [], 200);

        self::assertNull($result->rows[0]->pnlUsdt);
        self::assertSame(7.25, $result->rows[0]->recordedPnlUsdt);
        self::assertSame(6.8, $result->rows[0]->estimatedNetPnlUsdt);
        self::assertFalse($result->rows[0]->netCertified);
        self::assertFalse($result->rows[0]->dataComplete);
        self::assertContains('net_pnl_not_certified', $result->rows[0]->qualityFlags);
        self::assertContains('cost_partial', $result->rows[0]->qualityFlags);
    }

    public function testRollbackSourceUsesLegacyRowsWithoutTouchingUnavailableV2(): void
    {
        $legacyReader = new RecordingLegacyReader([$this->legacyRow([
            'entryEventId' => 401,
            'pnlUsdt' => -3.0,
            'closeEventId' => 501,
        ])]);
        $service = new PositionTradeAnalysisReportingService(
            $legacyReader,
            new ThrowingCertifiedReader(),
            'v2',
        );

        $result = $service->search([], [], 200, 'v1');

        self::assertSame('v1', $result->activeSource);
        self::assertSame('legacy_recorded_pnl', $result->summary['pnl_definition']);
        self::assertSame(-3.0, $result->rows[0]->pnlUsdt);
        self::assertSame('v1', $result->rows[0]->sourceVersion);
        self::assertFalse($result->rows[0]->netCertified);
        self::assertFalse($result->rows[0]->dataComplete);
        self::assertSame(['legacy_v1'], $result->rows[0]->qualityFlags);
        self::assertSame(1, $legacyReader->calls);
    }

    public function testV2SourceUnavailableFailsClosedUntilExplicitRollback(): void
    {
        $service = new PositionTradeAnalysisReportingService(
            new RecordingLegacyReader([$this->legacyRow(['pnlUsdt' => 1.0])]),
            new ThrowingCertifiedReader(),
            'v2',
        );

        try {
            $service->search([], [], 200);
            self::fail('v2 indisponible ne doit pas produire un résultat vide ni basculer implicitement.');
        } catch (PositionTradeAnalysisReportingSourceException $exception) {
            self::assertSame('v2', $exception->source);
        }

        self::assertSame('v1', $service->search([], [], 200, 'v1')->activeSource);
    }

    public function testDualReadDetectsDivergenceAndKeepsV2AsPrimarySurface(): void
    {
        $service = new PositionTradeAnalysisReportingService(
            new RecordingLegacyReader([$this->legacyRow(['entryEventId' => 601, 'pnlUsdt' => 12.0])]),
            new RecordingCertifiedReader([$this->v2Row([
                'entryEventId' => 601,
                'recordedPnlUsdt' => 12.0,
                'netPnlUsdt' => 10.75,
                'costCompleteness' => 'complete',
                'closeEventId' => 701,
                'closeMatchStatus' => 'matched',
                'closeMatchedBy' => 'matched_internal_trade_id',
                'analysisStatus' => 'matched_closed',
            ])]),
            'dual',
        );

        $result = $service->search([], [], 200);

        self::assertSame('dual', $result->activeSource);
        self::assertTrue($result->dualRead);
        self::assertSame('v2', $result->primarySource);
        self::assertSame(1, $result->summary['common_rows']);
        self::assertSame(1, $result->summary['divergent_rows']);
        self::assertSame(0, $result->summary['v1_only_rows']);
        self::assertSame(0, $result->summary['v2_only_rows']);
        self::assertSame(10.75, $result->rows[0]->pnlUsdt);
    }

    public function testDualReadComparesBeforeApplyingDisplayLimit(): void
    {
        $service = new PositionTradeAnalysisReportingService(
            new RecordingLegacyReader([
                $this->legacyRow(['entryEventId' => 603, 'pnlUsdt' => 3.0]),
                $this->legacyRow(['entryEventId' => 602, 'pnlUsdt' => 2.0]),
                $this->legacyRow(['entryEventId' => 601, 'pnlUsdt' => 1.0]),
            ]),
            new RecordingCertifiedReader([
                $this->v2Row([
                    'entryEventId' => 602,
                    'recordedPnlUsdt' => 2.0,
                    'netPnlUsdt' => 2.0,
                    'costCompleteness' => 'complete',
                    'closeEventId' => 702,
                    'closeMatchStatus' => 'matched',
                    'closeMatchedBy' => 'matched_internal_trade_id',
                    'analysisStatus' => 'matched_closed',
                ]),
                $this->v2Row([
                    'entryEventId' => 601,
                    'recordedPnlUsdt' => 1.0,
                    'netPnlUsdt' => 1.0,
                    'costCompleteness' => 'complete',
                    'closeEventId' => 701,
                    'closeMatchStatus' => 'matched',
                    'closeMatchedBy' => 'matched_internal_trade_id',
                    'analysisStatus' => 'matched_closed',
                ]),
            ]),
            'dual',
        );

        $result = $service->search([], ['sort' => 'pnlUsdt'], 1);

        self::assertCount(1, $result->rows);
        self::assertSame(2, $result->summary['common_rows']);
        self::assertSame(1, $result->summary['v1_only_rows']);
        self::assertSame(0, $result->summary['v2_only_rows']);
        self::assertSame(1, $result->summary['display_limit']);
        self::assertSame(5000, $result->summary['comparison_limit']);
        self::assertSame('entryTime DESC', $result->summary['comparison_sort']);
    }

    public function testFiltersSortAndLimitAreForwardedToSelectedReader(): void
    {
        $legacyReader = new RecordingLegacyReader([]);
        $certifiedReader = new RecordingCertifiedReader([]);
        $service = new PositionTradeAnalysisReportingService($legacyReader, $certifiedReader, 'v2');

        $service->search(['symbol' => 'ETHUSDT'], ['sort' => 'entryTime', 'direction' => 'ASC'], 12);

        self::assertSame(0, $legacyReader->calls);
        self::assertSame(1, $certifiedReader->calls);
        self::assertSame(['symbol' => 'ETHUSDT'], $certifiedReader->lastFilters);
        self::assertSame(['sort' => 'entryTime', 'direction' => 'ASC'], $certifiedReader->lastOptions);
        self::assertSame(12, $certifiedReader->lastLimit);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function legacyRow(array $overrides = []): PositionTradeAnalysis
    {
        $defaults = [
            'entryEventId' => 1,
            'symbol' => 'BTCUSDT',
            'timeframe' => '5m',
            'entryTime' => new \DateTimeImmutable('2026-06-17T08:30:00+00:00'),
            'pnlUsdt' => 1.0,
        ];

        return $this->hydrate(PositionTradeAnalysis::class, array_merge($defaults, $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function v2Row(array $overrides = []): PositionTradeAnalysisV2
    {
        $defaults = [
            'entryEventId' => 1,
            'symbol' => 'BTCUSDT',
            'timeframe' => '5m',
            'entryTime' => new \DateTimeImmutable('2026-06-17T08:30:00+00:00'),
            'closeMatchStatus' => 'unmatched',
            'closeMatchedBy' => 'unmatched',
            'analysisStatus' => 'unmatched',
            'costCompleteness' => 'not_applicable',
        ];

        return $this->hydrate(PositionTradeAnalysisV2::class, array_merge($defaults, $overrides));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string,mixed> $data
     * @return T
     */
    private function hydrate(string $class, array $data): object
    {
        $entity = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionObject($entity);
        foreach ($data as $property => $value) {
            if (!$ref->hasProperty($property)) {
                continue;
            }

            $p = $ref->getProperty($property);
            $p->setAccessible(true);
            $p->setValue($entity, $value);
        }

        return $entity;
    }
}

final class RecordingLegacyReader implements PositionTradeAnalysisLegacyReaderInterface
{
    public int $calls = 0;

    /** @var array<string,mixed>|null */
    public ?array $lastFilters = null;

    /** @var array<string,mixed>|null */
    public ?array $lastOptions = null;

    public ?int $lastLimit = null;

    /** @param list<PositionTradeAnalysis> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function search(array $filters, array $options = [], int $limit = 200): array
    {
        ++$this->calls;
        $this->lastFilters = $filters;
        $this->lastOptions = $options;
        $this->lastLimit = $limit;

        return array_slice($this->rows, 0, $limit);
    }
}

final class RecordingCertifiedReader implements PositionTradeAnalysisCertifiedReaderInterface
{
    public int $calls = 0;

    /** @var array<string,mixed>|null */
    public ?array $lastFilters = null;

    /** @var array<string,mixed>|null */
    public ?array $lastOptions = null;

    public ?int $lastLimit = null;

    /** @param list<PositionTradeAnalysisV2> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function search(array $filters, array $options = [], int $limit = 200): array
    {
        ++$this->calls;
        $this->lastFilters = $filters;
        $this->lastOptions = $options;
        $this->lastLimit = $limit;

        return array_slice($this->rows, 0, $limit);
    }
}

final class ThrowingCertifiedReader implements PositionTradeAnalysisCertifiedReaderInterface
{
    public function search(array $filters, array $options = [], int $limit = 200): array
    {
        throw new \RuntimeException('position_trade_analysis_v2 missing');
    }
}
