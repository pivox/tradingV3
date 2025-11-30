<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\MtfRunner\Service\MtfReportingService;
use App\MtfRunner\Service\SymbolInvestigationService;
use App\Repository\PositionTradeAnalysisRepository;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReportingController extends AbstractController
{
    public function __construct(
        private readonly MtfReportingService $reportingService,
        private readonly SymbolInvestigationService $symbolInvestigationService,
        private readonly PositionTradeAnalysisRepository $positionTradeAnalysisRepository,
    ) {
    }

    #[Route('/reporting/mtf-report', name: 'reporting_mtf_report')]
    public function mtfReport(Request $request): Response
    {
        $date = $this->resolveDateParam($request->query->get('date'));
        $data = $this->reportingService->getMtfReportData($date);

        return $this->render('reporting/mtf_report.html.twig', [
            'date' => $date,
            'data' => $data,
        ]);
    }

    #[Route('/reporting/mtf-symbols', name: 'reporting_mtf_symbols')]
    public function mtfSymbols(Request $request): Response
    {
        $date = $this->resolveDateParam($request->query->get('date'));
        $data = $this->reportingService->getMtfSymbolsReportData($date);

        return $this->render('reporting/mtf_symbols_report.html.twig', [
            'date' => $date,
            'data' => $data,
        ]);
    }

    #[Route('/reporting/blockers', name: 'reporting_mtf_blockers')]
    public function mtfBlockers(Request $request): Response
    {
        $date = $this->resolveDateParam($request->query->get('date'));
        $timeFilter = $request->query->get('time') ?: null;
        $report = $this->reportingService->getMtfBlockersReport($date, $timeFilter);

        return $this->render('reporting/mtf_blockers_report.html.twig', [
            'date' => $date,
            'timeFilter' => $timeFilter,
            'report' => $report,
        ]);
    }

    #[Route('/reporting/symbol-investigation', name: 'reporting_symbol_investigation')]
    public function symbolInvestigation(Request $request): Response
    {
        $symbol = strtoupper($request->query->get('symbol', 'AIXBTUSDT'));
        $datetimeParam = $request->query->get('datetime');

        try {
            $datetime = $datetimeParam
                ? new DateTimeImmutable($datetimeParam, new DateTimeZone('UTC'))
                : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            $datetime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $result = null;
        $error = null;
        try {
            $result = $this->symbolInvestigationService->investigate($symbol, $datetime);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->render('reporting/symbol_investigation.html.twig', [
            'symbol' => $symbol,
            'datetime_value' => $datetime->format('Y-m-d\TH:i'),
            'result' => $result,
            'error' => $error,
        ]);
    }

    #[Route('/reporting/position-trade-analysis', name: 'reporting_position_trade_analysis')]
    public function positionTradeAnalysis(Request $request): Response
    {
        $symbol = strtoupper($request->query->get('symbol', ''));
        $timeframe = $request->query->get('timeframe', '');
        $from = $request->query->get('from', '');
        $to = $request->query->get('to', '');
        $sort = $request->query->get('sort', 'entryTime');
        $direction = $request->query->get('direction', 'DESC');

        $filters = [
            'symbol' => $symbol ?: null,
            'timeframe' => $timeframe ?: null,
            'from' => $from ?: null,
            'to' => $to ?: null,
        ];

        $trades = $this->positionTradeAnalysisRepository->search($filters, [
            'sort' => $sort,
            'direction' => $direction,
        ]);

        return $this->render('reporting/position_trade_analysis.html.twig', [
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'trades' => $trades,
        ]);
    }

    private function resolveDateParam(?string $value): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $nowUtc->format('Y-m-d');
    }
}
