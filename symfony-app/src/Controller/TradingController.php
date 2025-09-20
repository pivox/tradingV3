<?php
// src/Controller/TradingController.php
namespace App\Controller;

use App\Repository\ContractPipelineRepository;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use App\Service\ContractSignalWriter;
use App\Service\Indicator\AtrCalculator;
use App\Service\Risk\PositionSizer;
use App\Service\Signals\Timeframe\Signal4hService;
use App\Service\Signals\Timeframe\SignalService;
use App\Service\Trading\TradingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    ) {}

    #[Route('/api/signal', name: 'api_signal', methods: ['GET'])]
    public function signa(Request $request, ContractRepository $contractRepository): JsonResponse
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
        if (!$symbol) {
            $symbols = array_column($contractRepository->allActiveSymbols(), 'symbol');
        } else {
            $symbols = [$symbol];
        }

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

        // 2) Respecter max_concurrent_positions (si dispo côté broker)
        $opened = [];
        $maxConcurrent = (int)($cfg['risk']['max_concurrent_positions'] ?? 4);
        $alreadyOpen = (int)($this->tradingService->countOpenPositions()); // TODO: implémenter côté service
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
                // Le YAML dit wilder|simple
                $atrMethod = strtolower($cfg['atr']['method'] ?? 'wilder');

// Il faut au moins ($atrPeriod + 1) bougies pour TR ; on en prend un peu plus
                $lookback = max($atrPeriod + 2, 100);

// Récupère des candles sur 1m (ou 5m en fallback si tu préfères)
                $candlesRaw = $this->klineRepository->findRecentBySymbolAndTimeframe($sym, '1m', $lookback);

// Normalise au format attendu par AtrCalculator::compute()
// array<int, array{high: float, low: float, close: float}>
                $ohlc = $this->normalizeCandles($candlesRaw);

                $atr = $this->atrCalculator->compute($ohlc, $atrPeriod, $atrMethod);

                if ($lastPrice <= 0 || $atr <= 0) {
                    $opened[] = ['symbol'=>$sym,'status'=>'SKIPPED','reason'=>'Prix/ATR invalides'];
                    continue;
                }

                // Sens de trade: essayer d’extraire du signal 1m ; fallback: decideSide()
                $rawDir = strtoupper($data['1m'][$sym]['result']['direction'] ?? $data['1m'][$sym]['result']['signal'] ?? 'AUTO');
                $side = match ($rawDir) {
                    'LONG','BUY'  => 'LONG',
                    'SHORT','SELL'=> 'SHORT',
                    default       => $this->tradingService->decideSide($sym),
                };

                // SL via ATR
                $slPrice = ($side === 'LONG')
                    ? $lastPrice - $atrK * $atr
                    : $lastPrice + $atrK * $atr;

                // Garde-fou liquidation (distance(liq) ≥ min_ratio * distance(stop))
                if (!$this->tradingService->passesLiquidationGuard($sym, $side, $lastPrice, $slPrice, $minLiqRatio)) {
                    $opened[] = ['symbol'=>$sym,'status'=>'SKIPPED','reason'=>'Liquidation guard'];
                    continue;
                }

                // Sizing risque fixe (levier dérivé, capé)
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

                // Cibles TP: TP1 à tpR*R + trailing ATR (si activé après TP1)
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

                // Routing à partir du YAML
                $entryType = $preferLimit ? 'LIMIT_MAKER' : ($fallbackMkt ? 'MARKET_OR_LIMIT' : 'LIMIT');
                $orderPlan = [
                    'symbol' => $sym,
                    'side'   => $side,
                    'entry'  => [
                        'type'      => $entryType,
                        'price'     => $qEntry,
                        'qty'       => $qQty,
                        'post_only' => $postOnly,
                    ],
                    'protect' => [
                        'sl' => ['price' => $qSL],
                    ],
                    'take_profit' => array_values(array_filter([
                        ['price' => $qTP1, 'qty' => $tp1Qty],
                        $trailAfter ? ['trailing' => ['mode'=>'ATR','atr_k'=>$trailK,'step_pct'=>$trailStep], 'qty'=>$tp2Qty] : null,
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
                ];

                // Placement des ordres
                $providerResp = $this->tradingService->placeOrderPlan($orderPlan);

                $opened[] = [
                    'symbol'            => $sym,
                    'status'            => 'PLACED',
                    'order_plan'        => $orderPlan,
                    'provider_response' => $providerResp,
                ];
            } catch (\Throwable $e) {
                $opened[] = ['symbol'=>$sym,'status'=>'ERROR','error'=>$e->getMessage()];
            }
        }

        // 4) Réponse JSON (signaux + positions)
        return new JsonResponse($opened);
    }

    #[Route('/api/validate/4h', name: 'api_4h_validate', methods: ['GET'])]
    public function validate4h(
        Request $request,
        SignalService $signalService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
        ContractSignalWriter $writer,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $symbol = $request->query->get('symbol');

        // Récupère la liste des symboles à traiter
        $symbols = $symbol
            ? [$symbol]
            : array_column($contractRepository->allActiveSymbols(), 'symbol');

        $results = [];
        foreach ($symbols as $sym) {
            // 1) Données récentes 4h
            $klines = $klineRepository->findRecentBySymbolAndTimeframe($sym, '4h', 221);

            // 2) Évaluation des signaux (retourne p.ex. ['ema50'=>..., 'ichimoku'=>..., 'signal'=>'LONG|NONE'])
            $sig4h = $signalService->evaluate($klines, '4h');

            // 3) Upsert + statut (validated si signal != NONE, sinon failed). Toujours persist.
            $contract = $contractRepository->findOneBy(['symbol' => $sym]);
            if (!$contract) {
                $results[] = [
                    'symbol'  => $sym,
                    'status'  => 'skipped',
                    'reason'  => 'contract_not_found',
                    'signal'  => $sig4h['signal'] ?? 'NONE',
                ];
                continue;
            }
            if ($sig4h['signal'] != 'NONE') {
                /** @var ContractPipeline $pipeline */
                $pipeline = $writer->saveAttempt($contract, '4h', $sig4h);
                $results[] = [
                    'symbol'     => $sym,
                    'timeframe'  => '4h',
                    'status'     => $pipeline->getStatus(),           // validated | failed
                    'retries'    => $pipeline->getRetries(),
                    'updated_at' => $pipeline->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                    'signals'    => $pipeline->getSignals()['4h'] ?? $sig4h,
                ];
            }

        }

        // 4) Flush en batch (meilleures perfs si beaucoup de symboles)
        $em->flush();

        return new JsonResponse([
            'timeframe' => '4h',
            'count'     => count($results),
            'results'   => $results,
        ]);
    }

    #[Route('/api/validate/1h', name: 'api_1h_validate', methods: ['GET'])]
    public function validate1h(
        Request $request,
        SignalService $signalService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
        ContractSignalWriter $writer,
        ContractPipelineRepository $contractPipelineRepository,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $symbol = $request->query->get('symbol');

        // Récupère la liste des symboles à traiter
        $symbols = $symbol
            ? [$symbol]
            : array_column($contractPipelineRepository->getAllSymbolsWithActive4h(), 'symbol');
        $results = [];
        $symbols = $contractPipelineRepository->getAllSymbolsWithActive4h();
        foreach ($symbols as $sym) {
            // 1) Données récentes 1h
            $klines = $klineRepository->findRecentBySymbolAndTimeframe($sym, '1h', 221);
            // 2) Évaluation des signaux (retourne p.ex. ['ema50'=>..., 'ichimoku'=>..., 'signal'=>'LONG|NONE'])
            $sig1h = $signalService->evaluate($klines, '1h');
            dd($sig1h);

            // 3) Upsert + statut (validated si signal != NONE, sinon failed). Toujours persist.
            $contract = $contractRepository->findOneBy(['symbol' => $sym]);
            if (!$contract) {
                $results[] = [
                    'symbol'  => $sym,
                    'status'  => 'skipped',
                    'reason'  => 'contract_not_found',
                    'signal'  => $sig1h['signal'] ?? 'NONE',
                ];
                continue;
            }
            if ($sig1h['signal'] != 'NONE') {
                /** @var ContractPipeline $pipeline */
                $pipeline = $writer->saveAttempt($contract, '1h', $sig1h);
                $results[] = [
                    'symbol'     => $sym,
                    'timeframe'  => '1h',
                    'status'     => $pipeline->getStatus(),           // validated | failed
                    'retries'    => $pipeline->getRetries(),
                    'updated_at' => $pipeline->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                    'signals'    => $pipeline->getSignals()['1h'] ?? $sig1h,
                ];
            }

        }

        // 4) Flush en batch (meilleures perfs si beaucoup de symboles)
        $em->flush();

        return new JsonResponse([
            'timeframe' => '1h',
            'count'     => count($results),
            'results'   => $results,
        ]);
    }

    #[Route('/api/validate/15m', name: 'api_15m_validate', methods: ['GET'])]
    public function validate15m(
        Request $request,
        SignalService $signalService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
        ContractSignalWriter $writer,
        EntityManagerInterface $em
    ): JsonResponse
    {
        //@TODO en priorité, télécharger les klines pour test, puis appliquer le signalService
        $symbol = $request->query->get('symbol');

        // Récupère la liste des symboles à traiter
        $symbols = $symbol
            ? [$symbol]
            : array_column($contractRepository->allActiveSymbols(), 'symbol');

        $results = [];
        foreach ($symbols as $sym) {
            // 1) Données récentes 15m
            $klines = $klineRepository->findRecentBySymbolAndTimeframe($sym, '15m', 300);


            // 2) Évaluation des signaux (retourne p.ex. ['ema50'=>..., 'ichimoku'=>..., 'signal'=>'LONG|NONE'])
            $sig15m = $signalService->evaluate($klines, '15m');

            // 3) Upsert + statut (validated si signal != NONE, sinon failed). Toujours persist.
            $contract = $contractRepository->findOneBy(['symbol' => $sym]);
            if (!$contract) {
                $results[] = [
                    'symbol'  => $sym,
                    'status'  => 'skipped',
                    'reason'  => 'contract_not_found',
                    'signal'  => $sig15m['signal'] ?? 'NONE',
                ];
                continue;
            }
            if ($sig15m['signal'] != 'NONE') {
                /** @var ContractPipeline $pipeline */
                $pipeline = $writer->saveAttempt($contract, '15m', $sig15m);
                $results[] = [
                    'symbol'     => $sym,
                    'timeframe'  => '15m',
                    'status'     => $pipeline->getStatus(),           // validated | failed
                    'retries'    => $pipeline->getRetries(),
                    'updated_at' => $pipeline->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                    'signals'    => $pipeline->getSignals()['15m'] ?? $sig15m,
                ];
            }

        }

        // 4) Flush en batch (meilleures perfs si beaucoup de symboles)
        $em->flush();

        return new JsonResponse([
            'timeframe' => '15m',
            'count'     => count($results),
            'results'   => $results,
        ]);
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
            // Si c'est une entité Doctrine avec getters :
            if (is_object($c) && method_exists($c, 'getHigh') && method_exists($c, 'getLow') && method_exists($c, 'getClose')) {
                $out[] = [
                    'high'  => (float) $c->getHigh(),
                    'low'   => (float) $c->getLow(),
                    'close' => (float) $c->getClose(),
                ];
                continue;
            }
            // Si c'est un array associatif :
            if (is_array($c) && isset($c['high'], $c['low'], $c['close'])) {
                $out[] = [
                    'high'  => (float) $c['high'],
                    'low'   => (float) $c['low'],
                    'close' => (float) $c['close'],
                ];
                continue;
            }
            // Sinon : ignorer / lever une exception selon ta tolérance
        }

        if (count($out) < 2) {
            throw new \RuntimeException('Candles insuffisantes ou invalide pour calculer ATR');
        }
        return $out;
    }
}
