<?php

namespace App\Controller;

use App\Repository\KlineRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KlinesController
{
    public function __construct(private HttpClientInterface $httpClient) {}

    #[Route('/api/klines', name: 'api_klines', methods: ['GET'])]
    public function __invoke(Request $request, KlineRepository $klineRepository): JsonResponse
    {
        $symbol = $request->query->get('symbol');
        $start = $request->query->get('start');
        $end = $request->query->get('end');
        $step = (int) $request->query->get('step', 1);
        $allowedSteps = [1,3,5,15,30,60,120,240,360,720,1440,4320,10080];
        if (!in_array($step, $allowedSteps, true)) {
            return new JsonResponse(['error' => 'Invalid step value'], 400);
        }

        if (!$symbol || !$start || !$end) {
            return new JsonResponse(['error' => 'Missing required parameters'], 400);
        }

        try {
            $startTime = new \DateTimeImmutable($start, new \DateTimeImmutable('UTC'));
            $endTime = new \DateTimeImmutable($end, new \DateTimeImmutable('UTC'));
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid date format'], 400);
        }

        try {
            $response = $this->httpClient->request('GET', 'http://localhost:8080/api/klines', [
                'query' => [
                    'symbol' => $symbol,
                    'start'  => $start,
                    'end'    => $end,
                    'step'   => $step,
                ],
            ]);

            $klines = $response->toArray();
        } catch (ExceptionInterface $exception) {
            return new JsonResponse([
                'error' => 'Unable to fetch klines from upstream service',
                'message' => $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse($klines);
    }
}
