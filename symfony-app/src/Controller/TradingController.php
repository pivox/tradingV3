<?php
// src/Controller/TradingController.php
namespace App\Controller;

use App\Entity\Kline;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Indicator\AtrCalculator;
use App\Service\Risk\PositionSizer;
use App\Service\Signals\Timeframe\Signal15mService;
use App\Service\Signals\Timeframe\Signal1hService;
use App\Service\Signals\Timeframe\Signal1mService;
use App\Service\Signals\Timeframe\Signal4hService;
use App\Service\Signals\Timeframe\Signal5mService;
use App\Service\Signals\Timeframe\SignalService;
use App\Service\Trading\TradingService;
use App\Util\TimeframeHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Yaml\Yaml;

final class TradingController extends AbstractController
{
    public function __construct(
        private TradingService $tradingService,
        private AtrCalculator $atrCalculator,
        private TradingParameters $tradingParameters,
        private KlineRepository $klineRepository,
        private ContractRepository $contractRepository,
        private PositionSizer $positionSizer,
    ) {
        set_time_limit(120);
    }

    /**
     * ⚙️ VALIDATE ROUTES (sans persistance) — on renvoie uniquement le résultat du TF courant.
     */

    #[Route('/api/validate/4h', name: 'api_4h_validate', methods: ['GET'])]
    public function validate4h(
        Request $request,
        Signal4hService $signal4h,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
    ): JsonResponse {
        $symbol  = $request->query->get('symbol');
        $addNoneSignal = (bool) $request->query->get('signal');
        $symbols = $symbol ? [$symbol] : array_column($contractRepository->allActiveSymbols(), 'symbol');

        $out = [
            'timeframe' => '4h',
            'results'   => [],
        ];

        foreach ($symbols as $sym) {
            $klines = $klineRepository->findRecentBySymbolAndTimeframe($sym, '4h', 261);

            $sig4h = $signal4h->evaluate($klines);
            $norm  = $this->extractSignal($sig4h);
            if (!$addNoneSignal && $norm == 'NONE') {
                continue;
            }

            $out['results'][] = [
                'symbol'   => $sym,
                'signal'   => $norm,
                'raw'      => $sig4h,
                'count'    => count($klines),
            ];
        }
        $out['count']    = count($out['results']);

        return new JsonResponse($out);
    }

    #[Route('/api/validate/1h', name: 'api_1h_validate', methods: ['GET'])]
    public function validate1h(
        Request $request,
        Signal1hService $signal1hService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
    ): JsonResponse {
        $symbol  = $request->query->get('symbol');
        $addNoneSignal = (bool) $request->query->get('signal');
        $symbols = $symbol ? [$symbol] : array_column($contractRepository->allActiveSymbols(), 'symbol');

        $out = [
            'timeframe' => '1h',
            'results'   => [],
        ];

        foreach ($symbols as $sym) {
            $klines = $klineRepository->findRecentBySymbolAndTimeframe($sym, '1h', 221);

            $sig1h = $signal1hService->evaluate($klines, '1h');
            $norm  = $this->extractSignal($sig1h);

            if (!$addNoneSignal && $norm == 'NONE') {
                continue;
            }

            $out['results'][] = [
                'symbol' => $sym,
                'signal' => $norm,
                'raw'    => $sig1h,
                'count'  => count($klines),
            ];
        }
        $out['count']    = count($out['results']);

        return new JsonResponse($out);
    }

    #[Route('/api/validate/15m', name: 'api_15m_validate', methods: ['GET'])]
    public function validate15m(
        Request $request,
        Signal15mService $signal15mService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
    ): JsonResponse {
        $symbol  = $request->query->get('symbol');
        $addNoneSignal = (bool) $request->query->get('signal');
        $symbols = $symbol ? [$symbol] : array_column($contractRepository->allActiveSymbols(), 'symbol');

        $out = [
            'timeframe' => '15m',
            'results'   => [],
        ];

        foreach ($symbols as $sym) {
            $klines = $klineRepository->findRecentBySymbolAndTimeframe($sym, '15m', 300);

            $sig15m = $signal15mService->evaluate($klines);
            $norm   = $this->extractSignal($sig15m);

            if (!$addNoneSignal && $norm == 'NONE') {
                continue;
            }
            $out['results'][] = [
                'symbol' => $sym,
                'signal' => $norm,
                'raw'    => $sig15m,
                'count'  => count($klines),
            ];
        }
        $out['count']    = count($out['results']);

        return new JsonResponse($out);
    }

    #[Route('/api/validate/5m', name: 'api_5m_validate', methods: ['GET'])]
    public function validate5m(
        Request $request,
        Signal5mService $signal5mService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
    ): JsonResponse {
        $symbol  = $request->query->get('symbol');
        $addNoneSignal = (bool) $request->query->get('signal');
        $symbols = $symbol ? [$symbol] : array_column($contractRepository->allActiveSymbols(), 'symbol');

        $out = [
            'timeframe' => '5m',
            'results'   => [],
        ];

        foreach ($symbols as $sym) {
            $klines = $klineRepository->findRecentBySymbolAndTimeframe($sym, '5m', 300);

            $sig5m = $signal5mService->evaluate($klines);
            $norm  = $this->extractSignal($sig5m);

            if (!$addNoneSignal && $norm == 'NONE') {
                continue;
            }

            $out['results'][] = [
                'symbol' => $sym,
                'signal' => $norm,
                'raw'    => $sig5m,
                'count'  => count($klines),
            ];
        }
        $out['count']    = count($out['results']);

        return new JsonResponse($out);
    }

    /**
     * Charge config/trading.scalping.yml
     */
    private function loadScalpingConfig(): array
    {
        $path = $this->getParameter('kernel.project_dir') . '/config/packages/trading.scalping.yml';
        if (!is_file($path)) {
            throw new \RuntimeException("Fichier YAML introuvable: $path");
        }
        $cfg = Yaml::parseFile($path);
        if (!is_array($cfg)) {
            throw new \RuntimeException("YAML invalide: $path");
        }
        return $cfg;
    }

    /**
     * @param array<int, mixed> $candles
     * @return array<int, array{high: float, low: float, close: float}>
     */
    private function normalizeCandles(array $candles): array
    {
        $out = [];
        foreach ($candles as $c) {
            if (is_object($c) && method_exists($c, 'getHigh') && method_exists($c, 'getLow') && method_exists($c, 'getClose')) {
                $out[] = [
                    'high'  => (float) $c->getHigh(),
                    'low'   => (float) $c->getLow(),
                    'close' => (float) $c->getClose(),
                ];
                continue;
            }
            if (is_array($c) && isset($c['high'], $c['low'], $c['close'])) {
                $out[] = [
                    'high'  => (float) $c['high'],
                    'low'   => (float) $c['low'],
                    'close' => (float) $c['close'],
                ];
                continue;
            }
        }

        if (count($out) < 2) {
            throw new \RuntimeException('Candles insuffisantes ou invalides pour calculer ATR');
        }
        return $out;
    }

    #[Route('/api/validate/mtf', name: 'api_validate_mtf', methods: ['GET'])]
    public function validateMtf(
        Request $request,
        Signal4hService $signal4h,
        Signal1hService $signal1h,
        Signal15mService $signal15m,
        Signal5mService $signal5m,
        Signal1mService $signal1m,
        SignalService $signalService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
    ): JsonResponse {
        $symbol  = $request->query->get('symbol');
        $symbols = $symbol ? [$symbol] : array_column($contractRepository->allActiveSymbols(), 'symbol');

        $results = [];
        foreach ($symbols as $sym) {
            $klines4h  = $klineRepository->findRecentBySymbolAndTimeframe($sym, '4h', 261);
            $klines1h  = $klineRepository->findRecentBySymbolAndTimeframe($sym, '1h', 221);
            $klines15m = $klineRepository->findRecentBySymbolAndTimeframe($sym, '15m', 300);
            $klines5m  = $klineRepository->findRecentBySymbolAndTimeframe($sym, '5m', 300);
            $klines1m  = $klineRepository->findRecentBySymbolAndTimeframe($sym, '1m', 300);
            $d = $signalService->evaluateAll([
                    '4h'  => $klines4h,
                    '1h'  => $klines1h,
                    '15m' => $klines15m,
                    '5m'  => $klines5m,
                    '1m'  => $klines1m,
                ]
            );
            $results[] = $d;
//            $sig4h  = $this->extractSignal($signal4h->evaluate($klines4h));
//            $sig1h  = $this->extractSignal($signal1h->evaluate($klines1h));
//            $sig15m = $this->extractSignal($signal15m->evaluate($klines15m));
//            $sig5m  = $this->extractSignal($signal5m->evaluate($klines5m));
//            $sig1m  = $this->extractSignal($signal1m->evaluate($klines1m));

//            if (in_array('NONE', [$sig4h, $sig1h, $sig15m, $sig5m, $sig1m], true)) {
//                continue;
//            }

//            $results[] = [
//                'symbol' => $sym,
//                'signals' => [
//                    '4h'  => $sig4h,
//                    '1h'  => $sig1h,
//                    '15m' => $sig15m,
//                    '5m'  => $sig5m,
//                    '1m'  => $sig1m,
//                ],
//            ];
        }

        return new JsonResponse([
            'count' => count($results),
            'results' => $results,
        ]);
    }


    /** @param array<int,mixed> $sig */
    private function extractSignal(array $sig): string
    {
        if (isset($sig['final']['signal'])) return strtoupper((string)$sig['final']['signal']);
        if (isset($sig['signal'])) return strtoupper((string)$sig['signal']);
        return 'NONE';
    }
}
