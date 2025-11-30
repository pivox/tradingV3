<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\MtfRunner\Service\MtfReportingService;
use App\MtfRunner\Service\SymbolInvestigationService;
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
    ) {
    }

    #[Route('/reporting/mtf-report', name: 'reporting_mtf_report')]
    public function mtfReport(Request $request): Response
    {
        $date = $this->resolveDateParam($request->query->get('date'));
        $report = $this->reportingService->getMtfReport($date);

        return $this->render('reporting/mtf_report.html.twig', [
            'date' => $date,
            'report' => $report,
        ]);
    }

    #[Route('/reporting/mtf-symbols', name: 'reporting_mtf_symbols')]
    public function mtfSymbols(Request $request): Response
    {
        $date = $this->resolveDateParam($request->query->get('date'));
        $report = $this->reportingService->getMtfSymbolsReport($date);

        return $this->render('reporting/mtf_symbols_report.html.twig', [
            'date' => $date,
            'report' => $report,
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

    private function resolveDateParam(?string $value): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $nowUtc->format('Y-m-d');
    }
}
