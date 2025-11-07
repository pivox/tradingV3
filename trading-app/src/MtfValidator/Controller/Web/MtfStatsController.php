<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller\Web;

use App\MtfValidator\Repository\MtfAuditStatsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MtfStatsController extends AbstractController
{
    public function __construct(
        private readonly MtfAuditStatsRepository $statsRepository,
    ) {
    }

    #[Route('/mtf/stats', name: 'mtf_stats_index')]
    public function index(Request $request): Response
    {
        // KPIs globaux basés sur la vue matérialisée
        $kpis = $this->statsRepository->getGlobalKpis();

        return $this->render('MtfValidator/stats/index.html.twig', [
            'kpis' => $kpis,
        ]);
    }

    #[Route('/mtf/stats/data', name: 'mtf_stats_data', methods: ['GET'])]
    public function data(Request $request): Response
    {
        $draw = (int)($request->query->get('draw') ?? 0);
        $start = (int)($request->query->get('start') ?? 0);
        $length = (int)($request->query->get('length') ?? 25);
        $searchValue = (string)($request->query->all('search')['value'] ?? '');
        $order = $request->query->all('order');

        $orderCol = 2; // default total desc
        $orderDir = 'desc';
        if (is_array($order) && isset($order[0])) {
            $orderCol = (int)($order[0]['column'] ?? 2);
            $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        }

        $symbol = $request->query->get('symbol');
        $tf = $request->query->get('tf');

        $result = $this->statsRepository->getSummaryPaged(
            is_string($symbol) && $symbol !== '' ? $symbol : null,
            is_string($tf) && $tf !== '' ? $tf : null,
            $searchValue,
            $orderCol,
            $orderDir,
            $start,
            $length
        );

        return new Response(json_encode([
            'draw' => $draw,
            'recordsTotal' => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data' => $result['rows'],
        ]), 200, ['Content-Type' => 'application/json']);
    }

    #[Route('/mtf/stats/top10', name: 'mtf_stats_top10', methods: ['GET'])]
    public function top10(Request $request): Response
    {
        $symbol = $request->query->get('symbol');
        $tf = $request->query->get('tf');
        $top = $this->statsRepository->getTopByTotal(
            is_string($symbol) && $symbol !== '' ? $symbol : null,
            is_string($tf) && $tf !== '' ? $tf : null,
            10
        );

        return new Response(json_encode(['data' => $top]), 200, ['Content-Type' => 'application/json']);
    }
}
