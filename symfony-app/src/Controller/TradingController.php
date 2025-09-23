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

    #[Route('/api/signal', name: 'api_signal', methods: ['GET'])]
    public function signal(Request $request, ContractRepository $contractRepository): JsonResponse
    {
        // 0) Charger la config YAML (trading.scalping.yml v1.2)
        $cfg = $this->loadScalpingConfig();
        $limit  = (int) ($request->query->get('limit') ?? 300);

        // Paramètres issus du YAML
        $riskPct     = (float)($cfg['risk']['fixed_risk_pct'] ?? 2.0);
        $atrPeriod   = (int)  ($cfg['atr']['period'] ?? 14);
        $atrK        = (float)($cfg['atr']['sl_multiplier'] ?? 1.5);
        $trailK      = (float)($cfg['atr']['trailing_multiplier'] ?? 2.5);
        $trailAfter  = (bool)  ($cfg['atr']['trail_after_tp1'] ?? true);
        $trailStep   = (float)($cfg['atr']['trail_step_pct'] ?? 0.10);

        $tickSize    = (float)($cfg['quantization']['tick_size'] ?? 0.01);
        $stepSize    = (float)($cfg['quantization']['step_size'] ?? 0.001);

        $minLiqRatio = (float)($cfg['liquidation_guard']['min_ratio'] ?? 3.0);
        $levCap      = (float)($cfg['liquidation_guard']['fallback_max_leverage'] ?? 10.0);

        $tpR         = (float)($cfg['order_plan']['brackets']['tp_split']['tp1_r_multiple'] ?? 2.0);
        $tp1Frac     = ((float)($cfg['order_plan']['brackets']['tp_split']['tp1_size_pct'] ?? 60)) / 100.0;

        $preferLimit = strtolower($cfg['order_plan']['routing']['prefer'] ?? 'limit') === 'limit';
        $fallbackMkt = strtolower($cfg['order_plan']['routing']['fallback'] ?? 'market') === 'market';
        $postOnly    = (bool)($cfg['order_plan']['routing']['post_only'] ?? true);

        $timeStopEn  = (bool)($cfg['order_plan']['time_stop']['enabled'] ?? true);
        $timeStopMin = (int)($cfg['order_plan']['time_stop']['max_minutes'] ?? 90);
        $timeStopMinR= (float)($cfg['order_plan']['time_stop']['min_progress_r'] ?? 0.5);

        // Sélection des symboles
        $symbol = $request->query->get('symbol');
        $symbols = $symbol ? [$symbol] : array_column($contractRepository->allActiveSymbols(), 'symbol');

        // 1) Cascade MTF 4h -> 1m
        $data = ['4h'=>[], '1h'=>[], '15m'=>[], '5m'=>[], '1m'=>[]];

        foreach ($symbols as $sym) {
            $sig4h = $this->tradingService->getSignal($sym, '4h', $limit);
            if (($sig4h['result']['signal'] ?? 'NONE') !== 'NONE') {
                $data['4h'][$sym] = $sig4h;
            }
        }
        foreach (array_keys($data['4h']) as $sym) {
            $sig1h = $this->tradingService->getSignal($sym, '1h', $limit);
            if (($sig1h['result']['signal'] ?? 'NONE') !== 'NONE') {
                $data['1h'][$sym] = $sig1h;
            }
        }
        foreach (array_keys($data['1h']) as $sym) {
            $sig15 = $this->tradingService->getSignal($sym, '15m', $limit);
            if (($sig15['result']['signal'] ?? 'NONE') !== 'NONE') {
                $data['15m'][$sym] = $sig15;
            }
        }
        foreach (array_keys($data['15m']) as $sym) {
            $sig5 = $this->tradingService->getSignal($sym, '5m', $limit);
            if (($sig5['result']['signal'] ?? 'NONE') !== 'NONE') {
                $data['5m'][$sym] = $sig5;
            }
        }
        foreach (array_keys($data['5m']) as $sym) {
            $sig1 = $this->tradingService->getSignal($sym, '1m', $limit);
            if (($sig1['result']['signal'] ?? 'NONE') !== 'NONE') {
                $data['1m'][$sym] = $sig1;
            }
        }

        // 2) Respecter max_concurrent_positions
        $opened = [];
        $maxConcurrent = (int)($cfg['risk']['max_concurrent_positions'] ?? 4);
        $alreadyOpen = (int)($this->tradingService->countOpenPositions());
        $slotsLeft = max(0, $maxConcurrent - $alreadyOpen);
        if ($slotsLeft <= 0) {
            return $this->json([
                'signals' => [
                    '4h'=>array_keys($data['4h']),'1h'=>array_keys($data['1h']),
                    '15m'=>array_keys($data['15m']),'5m'=>array_keys($data['5m']),
                    '1m'=>array_keys($data['1m']),
                ],
                'opened_positions' => [],
                'note' => 'Aucune position ouverte: max_concurrent_positions atteint.'
            ]);
        }

        // 3) Ouvrir les positions pour les symboles validés à 1m (en respectant le quota restant)
        foreach (array_slice(array_keys($data['1m']), 0, $slotsLeft) as $sym) {
            try {
                $lastPrice = $this->tradingService->getLastPrice($sym);
                $atrMethod = strtolower($cfg['atr']['method'] ?? 'wilder');

                // 🔒 1m : on exclut la bougie en cours (cutoff = open aligné actuel)
                $cutoff1m = TimeframeHelper::getAlignedOpen('1m');
                $lookback = max($atrPeriod + 2, 100);
                $candlesRaw = $this->klineRepository->findRecentBySymbolAndTimeframe($sym, '1m', $lookback + 5);
                $candlesRaw = array_values(array_filter($candlesRaw, fn($c) =>
                    ($c instanceof Kline ? $c->getTimestamp() : new \DateTimeImmutable('@'.(int)$c['ts']))
                    < $cutoff1m
                ));

                $ohlc = $this->normalizeCandles($candlesRaw);
                $atr = $this->atrCalculator->compute($ohlc, $atrPeriod, $atrMethod);

                if ($lastPrice <= 0 || $atr <= 0) {
                    $opened[] = ['symbol'=>$sym,'status'=>'SKIPPED','reason'=>'Prix/ATR invalides'];
                    continue;
                }

                $rawDir = strtoupper($data['1m'][$sym]['result']['direction'] ?? $data['1m'][$sym]['result']['signal'] ?? 'AUTO');
                $side = match ($rawDir) {
                    'LONG','BUY'  => 'LONG',
                    'SHORT','SELL'=> 'SHORT',
                    default       => $this->tradingService->decideSide($sym),
                };

                $slPrice = ($side === 'LONG')
                    ? $lastPrice - $atrK * $atr
                    : $lastPrice + $atrK * $atr;

                if (!$this->tradingService->passesLiquidationGuard($sym, $side, $lastPrice, $slPrice, $minLiqRatio)) {
                    $opened[] = ['symbol'=>$sym,'status'=>'SKIPPED','reason'=>'Liquidation guard'];
                    continue;
                }

                $sizing = $this->positionSizer->sizeFromFixedRisk(
                    symbol: $sym,
                    side: $side,
                    entryPrice: $lastPrice,
                    stopPrice: $slPrice,
                    equity: $this->tradingService->getEquity(),
                    riskPct: $riskPct,
                    maxLeverageCap: $levCap,
                    stepSize: $stepSize
                );

                if (($sizing['qty'] ?? 0) <= 0) {
                    $opened[] = ['symbol'=>$sym,'status'=>'SKIPPED','reason'=>'Taille nulle (stepSize/stopPct?)'];
                    continue;
                }

                $rDist = abs($lastPrice - $slPrice);
                $tp1   = ($side === 'LONG')
                    ? $lastPrice + $tpR * $rDist
                    : $lastPrice - $tpR * $rDist;

                // Quantization
                $qEntry = $this->tradingService->quantizePrice($lastPrice, $tickSize);
                $qSL    = $this->tradingService->quantizePrice($slPrice,  $tickSize);
                $qTP1   = $this->tradingService->quantizePrice($tp1,      $tickSize);
                $qQty   = $this->tradingService->quantizeQty($sizing['qty'], $stepSize);
                $tp1Qty = $this->tradingService->quantizeQty($qQty * $tp1Frac, $stepSize);
                $tp2Qty = $this->tradingService->quantizeQty($qQty - $tp1Qty,  $stepSize);

                $entryType = $preferLimit ? 'LIMIT_MAKER' : ($fallbackMkt ? 'MARKET_OR_LIMIT' : 'LIMIT');

                $providerResp = $this->tradingService->placeOrderPlan([
                    'symbol' => $sym,
                    'side'   => $side,
                    'entry'  => ['type'=>$entryType,'price'=>$qEntry,'qty'=>$qQty,'post_only'=>$postOnly],
                    'protect'=> ['sl'=>['price'=>$qSL]],
                    'take_profit' => array_values(array_filter([
                        ['price'=>$qTP1,'qty'=>$tp1Qty],
                        $trailAfter ? ['trailing'=>['mode'=>'ATR','atr_k'=>$trailK,'step_pct'=>$trailStep], 'qty'=>$tp2Qty] : null,
                    ])),
                    'meta' => [
                        'risk_amount'   => $sizing['riskAmount'] ?? null,
                        'stop_pct'      => $sizing['stopPct']    ?? null,
                        'leverage'      => $sizing['leverage']   ?? null,
                        'tp_r'          => $tpR,
                        'atr_period'    => $atrPeriod,
                        'atr_k'         => $atrK,
                        'trailing_k'    => $trailK,
                        'time_stop'     => [
                            'enabled'        => $timeStopEn,
                            'max_minutes'    => $timeStopMin,
                            'min_progress_r' => $timeStopMinR,
                        ],
                    ],
                ]);

                $opened[] = [
                    'symbol'            => $sym,
                    'status'            => 'PLACED',
                    'provider_response' => $providerResp,
                ];
            } catch (\Throwable $e) {
                $opened[] = ['symbol'=>$sym,'status'=>'ERROR','error'=>$e->getMessage()];
            }
        }

        return new JsonResponse($opened);
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
