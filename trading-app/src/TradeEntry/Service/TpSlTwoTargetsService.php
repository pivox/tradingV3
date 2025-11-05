<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\Config\MtfValidationConfig;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Entity\Position;
use App\Repository\PositionRepository;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Policy\PreTradeChecks;
use App\TradeEntry\RiskSizer\StopLossCalculator;
use App\TradeEntry\RiskSizer\TakeProfitCalculator;
use App\TradeEntry\Types\Side as EntrySide;
use App\TradeEntry\TpSplit\TpSplitResolver;
use App\TradeEntry\TpSplit\Dto\TpSplitContext;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Runtime\Cache\DbValidationCache;

final class TpSlTwoTargetsService
{
    public function __construct(
        private readonly PreTradeChecks $pretrade,
        private readonly StopLossCalculator $slc,
        private readonly TakeProfitCalculator $tpc,
        private readonly MainProviderInterface $providers,
        private readonly PositionRepository $positions,
        private readonly MtfValidationConfig $mtfConfig,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $journeyLogger,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
        private readonly ?TpSplitResolver $tpSplitResolver = null,
        private readonly ?IndicatorProviderInterface $indicatorProvider = null,
        private readonly ?DbValidationCache $validationCache = null,
    ) {}

    /**
     * Calcule SL + TP1/TP2 (TP2 basé sur R2/S2), annule les ordres existants (SL si différent, TP) et soumet 2 TP.
     *
     * @return array{sl: float, tp1: float, tp2: float, submitted: array<int,array{order_id:string,price:float,size:int,type:string,side:string}>, cancelled: array<int,string>}
     */
    public function __invoke(TpSlTwoTargetsRequest $req, ?string $decisionKey = null): array
    {
        $symbol = strtoupper($req->symbol);
        $pretrade = $this->loadPretradeContext($symbol, $req->side);

        [$entryPrice, $size] = $this->resolveEntryAndSize($req, $symbol);

        $config = $this->buildConfigSnapshot($req);

        $computations = $this->computeTargets(
            $req,
            $decisionKey,
            $symbol,
            $pretrade,
            $entryPrice,
            $size,
            $config,
        );

        $orderProvider = $this->providers->getOrderProvider();
        $cancelled = $this->cancelExistingOrders(
            $req,
            $symbol,
            $orderProvider,
            $pretrade,
            $entryPrice,
            $computations['stop'],
        );

        $split = $this->determineSplit(
            $req,
            $symbol,
            $pretrade,
            $size,
            $config,
        );

        $submitted = $this->submitOrders(
            $req,
            $symbol,
            $decisionKey,
            $orderProvider,
            $split,
            $computations,
            \count($cancelled),
        );

        return [
            'sl' => $computations['stop'],
            'tp1' => $computations['tp1'],
            'tp2' => $computations['tp2'],
            'submitted' => $submitted,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * @return array{pre: object, tick: float, precision: int, contractSize: float, pivotLevels: array<string,mixed>}
     */
    private function loadPretradeContext(string $symbol, EntrySide $side): array
    {
        $dummy = new \App\TradeEntry\Dto\TradeEntryRequest(
            symbol: $symbol,
            side: $side,
        );
        $pre = $this->pretrade->run($dummy);

        return [
            'pre' => $pre,
            'tick' => $pre->tickSize,
            'precision' => $pre->pricePrecision,
            'contractSize' => $pre->contractSize,
            'pivotLevels' => is_array($pre->pivotLevels) ? $pre->pivotLevels : [],
        ];
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function resolveEntryAndSize(TpSlTwoTargetsRequest $req, string $symbol): array
    {
        $entryPrice = $req->entryPrice;
        $size = $req->size;

        if ($entryPrice === null || $size === null) {
            $pos = $this->resolvePosition($symbol, $req->side);
            if ($pos instanceof Position) {
                if ($entryPrice === null) {
                    $entryPrice = (float)($pos->getAvgEntryPrice() ?? 0.0);
                }
                if ($size === null) {
                    $size = (int)max(0, (int)($pos->getSize() ?? 0));
                }
            }
        }

        if ($entryPrice === null || $entryPrice <= 0.0 || $size === null || $size <= 0) {
            throw new \InvalidArgumentException('entryPrice et size requis (ou position introuvable)');
        }

        return [$entryPrice, $size];
    }

    /**
     * @return array{
     *     riskPct: float,
     *     initMargin: float,
     *     tpPolicy: string,
     *     tpMinKeepRatio: float,
     *     tpBufferPct: ?float,
     *     tpBufferTicks: ?int,
     *     pivotSlPolicy: string,
     *     pivotSlBufferPct: ?float,
     *     pivotSlMinKeepRatio: ?float,
     *     rMultiple: float,
     *     slFullByDefault: bool,
     * }
     */
    private function buildConfigSnapshot(TpSlTwoTargetsRequest $req): array
    {
        $defaults = $this->mtfConfig->getDefaults();
        $riskPctDefault = (float)($defaults['risk_pct_percent'] ?? 2.0);

        return [
            'riskPct' => $riskPctDefault > 1.0 ? $riskPctDefault / 100.0 : $riskPctDefault,
            'initMargin' => (float)($defaults['initial_margin_usdt'] ?? 100.0),
            'tpPolicy' => (string)($defaults['tp_policy'] ?? 'pivot_conservative'),
            'tpMinKeepRatio' => isset($defaults['tp_min_keep_ratio']) ? (float)$defaults['tp_min_keep_ratio'] : 0.95,
            'tpBufferPct' => isset($defaults['tp_buffer_pct']) ? (float)$defaults['tp_buffer_pct'] : null,
            'tpBufferTicks' => isset($defaults['tp_buffer_ticks']) ? (int)$defaults['tp_buffer_ticks'] : null,
            'pivotSlPolicy' => (string)($defaults['pivot_sl_policy'] ?? 'nearest_below'),
            'pivotSlBufferPct' => isset($defaults['pivot_sl_buffer_pct']) ? (float)$defaults['pivot_sl_buffer_pct'] : null,
            'pivotSlMinKeepRatio' => isset($defaults['pivot_sl_min_keep_ratio']) ? (float)$defaults['pivot_sl_min_keep_ratio'] : null,
            'rMultiple' => (float)($req->rMultiple ?? ($defaults['r_multiple'] ?? 2.0)),
            'slFullByDefault' => (bool)($defaults['sl_full_size'] ?? true),
        ];
    }

    /**
     * @return array{stop: float, tp1: float, tp2: float}
     */
    private function computeTargets(
        TpSlTwoTargetsRequest $req,
        ?string $decisionKey,
        string $symbol,
        array $pretrade,
        float $entryPrice,
        int $size,
        array $config,
    ): array {
        $tick = $pretrade['tick'];
        $precision = $pretrade['precision'];
        $contractSize = $pretrade['contractSize'];
        $pivotLevels = $pretrade['pivotLevels'];

        $stop = $this->computeStopLoss(
            $req,
            $entryPrice,
            $size,
            $pivotLevels,
            $tick,
            $precision,
            $contractSize,
            $config,
        );

        [$tp1, $tp2] = $this->computeTakeProfits(
            $req,
            $decisionKey,
            $symbol,
            $pivotLevels,
            $tick,
            $precision,
            $entryPrice,
            $stop,
            $config,
        );

        $this->journeyLogger->info('order_journey.tp2sl.compute', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'entry' => $entryPrice,
            'stop' => $stop,
            'tp1' => $tp1,
            'tp2' => $tp2,
            'reason' => 'tp_sl_two_targets_computed',
        ]);

        return [
            'stop' => $stop,
            'tp1' => $tp1,
            'tp2' => $tp2,
        ];
    }

    /**
     * @param array<string,mixed> $pivotLevels
     */
    private function computeStopLoss(
        TpSlTwoTargetsRequest $req,
        float $entryPrice,
        int $size,
        array $pivotLevels,
        float $tick,
        int $precision,
        float $contractSize,
        array $config,
    ): float {
        if (!empty($pivotLevels)) {
            $stop = $this->slc->fromPivot(
                entry: $entryPrice,
                side: $req->side,
                pivotLevels: $pivotLevels,
                policy: $config['pivotSlPolicy'],
                bufferPct: $config['pivotSlBufferPct'],
                pricePrecision: $precision,
            );
            if ($config['pivotSlMinKeepRatio'] !== null && $config['pivotSlMinKeepRatio'] > 0.0) {
                $minRisk = max(1e-8, abs($entryPrice - $stop) * $config['pivotSlMinKeepRatio']);
                if ($req->side === EntrySide::Long) {
                    if (abs(($entryPrice - $stop)) < $minRisk) {
                        $stop = max($tick, $entryPrice - $minRisk);
                    }
                } else {
                    if (abs(($stop - $entryPrice)) < $minRisk) {
                        $stop = $entryPrice + $minRisk;
                    }
                }
            }

            return $stop;
        }

        $riskUsdt = $config['initMargin'] * $config['riskPct'];

        return $this->slc->fromRisk(
            entry: $entryPrice,
            side: $req->side,
            riskUsdt: $riskUsdt,
            size: $size,
            contractSize: $contractSize,
            precision: $precision,
        );
    }

    /**
     * @param array<string,mixed> $pivotLevels
     * @return array{0: float, 1: float}
     */
    private function computeTakeProfits(
        TpSlTwoTargetsRequest $req,
        ?string $decisionKey,
        string $symbol,
        array $pivotLevels,
        float $tick,
        int $precision,
        float $entryPrice,
        float $stop,
        array $config,
    ): array {
        $rMultiple = $config['rMultiple'];
        $tp1Base = $this->tpc->fromRMultiple($entryPrice, $stop, $req->side, $rMultiple, $precision);
        $tp1 = $tp1Base;

        if (!empty($pivotLevels) && $rMultiple > 0.0) {
            $tp1 = $this->tpc->alignTakeProfitWithPivot(
                symbol: $symbol,
                side: $req->side,
                entry: $entryPrice,
                stop: $stop,
                baseTakeProfit: $tp1Base,
                rMultiple: $rMultiple,
                pivotLevels: $pivotLevels,
                policy: $config['tpPolicy'],
                bufferPct: $config['tpBufferPct'],
                bufferTicks: $config['tpBufferTicks'],
                tick: $tick,
                pricePrecision: $precision,
                minKeepRatio: $config['tpMinKeepRatio'],
                maxExtraR: null,
                decisionKey: $decisionKey,
            );
        }

        $tp2 = $this->resolveSecondTarget(
            $req,
            $pivotLevels,
            $tick,
            $precision,
            $entryPrice,
            $stop,
            $config,
        );

        return [$tp1, $tp2];
    }

    /**
     * @param array<string,mixed> $pivotLevels
     */
    private function resolveSecondTarget(
        TpSlTwoTargetsRequest $req,
        array $pivotLevels,
        float $tick,
        int $precision,
        float $entryPrice,
        float $stop,
        array $config,
    ): float {
        $key = $req->side === EntrySide::Long ? 'r2' : 's2';
        $tp2 = null;
        if (isset($pivotLevels[$key]) && is_finite((float)$pivotLevels[$key]) && (float)$pivotLevels[$key] > 0.0) {
            $tp2 = (float)$pivotLevels[$key];
            if ($config['tpBufferPct'] !== null && $config['tpBufferPct'] > 0.0) {
                $tp2 = $req->side === EntrySide::Long ? $tp2 * (1.0 - $config['tpBufferPct']) : $tp2 * (1.0 + $config['tpBufferPct']);
            }
            if ($config['tpBufferTicks'] !== null && $config['tpBufferTicks'] > 0) {
                $tp2 = $req->side === EntrySide::Long ? $tp2 - $config['tpBufferTicks'] * $tick : $tp2 + $config['tpBufferTicks'] * $tick;
            }

            if ($req->side === EntrySide::Long) {
                $tp2 = max($tp2, $entryPrice + $tick);
                $tp2 = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($tp2, $precision);
            } else {
                $tp2 = min($tp2, $entryPrice - $tick);
                $tp2 = \App\TradeEntry\Pricing\TickQuantizer::quantize($tp2, $precision);
            }

            return (float)$tp2;
        }

        return $this->tpc->fromRMultiple($entryPrice, $stop, $req->side, 2.0, $precision);
    }

    /**
     * @return array<int,string>
     */
    private function cancelExistingOrders(
        TpSlTwoTargetsRequest $req,
        string $symbol,
        OrderProviderInterface $orderProvider,
        array $pretrade,
        float $entryPrice,
        float $stop,
    ): array {
        try {
            $open = $orderProvider->getOpenOrders($symbol);
        } catch (\Throwable $e) {
            $this->journeyLogger->warning('order_journey.tp2sl.open_orders_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'reason' => 'fetch_open_orders_failed',
            ]);
            $open = [];
        }

        $tick = $pretrade['tick'];
        $closeSide = $req->side === EntrySide::Long ? OrderSide::SELL : OrderSide::BUY;
        $isSl = function (OrderDto $o) use ($req, $entryPrice): bool {
            if ($o->price === null) { return false; }
            $p = (float)$o->price->__toString();
            return $req->side === EntrySide::Long ? ($p < $entryPrice) : ($p > $entryPrice);
        };
        $priceOf = fn(OrderDto $o): ?float => $o->price ? (float)$o->price->__toString() : null;

        /** @var array<int,OrderDto> $closing */
        $closing = array_values(array_filter($open, fn(OrderDto $o) => $o->side === $closeSide));
        $cancelled = [];

        if ($req->cancelExistingStopLossIfDifferent) {
            $existingSl = null;
            foreach ($closing as $o) {
                if ($isSl($o)) {
                    $existingSl = $o;
                    break;
                }
            }
            if ($existingSl !== null) {
                $p = $priceOf($existingSl);
                if ($p !== null && abs($p - $stop) > max($tick, 1e-8)) {
                    try {
                        if ($orderProvider->cancelOrder($existingSl->orderId)) {
                            $cancelled[] = $existingSl->orderId;
                        }
                    } catch (\Throwable $e) {
                        $this->journeyLogger->warning('order_journey.tp2sl.cancel_sl_failed', [
                            'symbol' => $symbol,
                            'order_id' => $existingSl->orderId,
                            'error' => $e->getMessage(),
                            'reason' => 'cancel_sl_exception',
                        ]);
                    }
                }
            }
        }

        if ($req->cancelExistingTakeProfits) {
            foreach ($closing as $o) {
                if ($isSl($o)) {
                    continue;
                }
                try {
                    if ($orderProvider->cancelOrder($o->orderId)) {
                        $cancelled[] = $o->orderId;
                    }
                } catch (\Throwable $e) {
                    $this->journeyLogger->warning('order_journey.tp2sl.cancel_tp_failed', [
                        'symbol' => $symbol,
                        'order_id' => $o->orderId,
                        'error' => $e->getMessage(),
                        'reason' => 'cancel_tp_exception',
                    ]);
                }
            }
        }

        return $cancelled;
    }

    /**
     * @return array{ratio: float, size1: int, size2: int, slSize: int, bitmartCloseSide: int, size: int}
     */
    private function determineSplit(
        TpSlTwoTargetsRequest $req,
        string $symbol,
        array $pretrade,
        int $size,
        array $config,
    ): array {
        $ratio = $this->resolveSplitRatio($req, $symbol, $pretrade['pre']);
        $ratio = max(0.0, min(1.0, $ratio));

        $size1 = (int)floor($size * $ratio);
        $size2 = (int)max(0, $size - $size1);
        if ($size1 <= 0 || $size2 <= 0) {
            $size1 = $size;
            $size2 = 0;
        }

        $minVol = max(1, (int)$pretrade['pre']->minVolume);
        $size1 = $this->quantizeSize($size1, $minVol);
        $size2 = $this->quantizeSize($size2, $minVol);

        $maxVol = $pretrade['pre']->maxVolume;
        if ($maxVol !== null && $maxVol > 0) {
            $size1 = (int)min($size1, (int)$maxVol);
            $size2 = (int)min($size2, (int)$maxVol);
        }

        $slFull = (bool)($req->slFullSize ?? $config['slFullByDefault']);
        $slSize = $slFull ? $size : (int)max(0, $size - $size1 - $size2);
        $slSize = $this->quantizeSize($slSize, $minVol);

        $bitmartCloseSide = ($req->side === EntrySide::Long) ? 2 : 3;

        return [
            'ratio' => $ratio,
            'size1' => $size1,
            'size2' => $size2,
            'slSize' => $slSize,
            'bitmartCloseSide' => $bitmartCloseSide,
            'size' => $size,
        ];
    }

    private function resolveSplitRatio(
        TpSlTwoTargetsRequest $req,
        string $symbol,
        object $pre,
    ): float {
        $ratio = $req->splitPct;
        if ($ratio !== null) {
            return (float)$ratio;
        }

        if ($this->tpSplitResolver === null) {
            return 0.5;
        }

        $auto = $this->deriveMtfHints($symbol);
        $momentum = $req->momentum ?? $auto['momentum'];
        $mtfValid = $req->mtfValidCount ?? $auto['mtf_valid_count'];
        $pullback = (bool)($req->pullbackClear ?? false);
        $late = (bool)($req->lateEntry ?? false);

        $mark = (float)($pre->markPrice ?? $pre->bestAsk ?? $pre->bestBid ?? 0.0);
        $atrAbs = null;
        if ($this->indicatorProvider !== null) {
            try {
                $tf = $this->resolveAtrTimeframe();
                $atrAbs = $this->indicatorProvider->getAtr('tp_split', $symbol, $tf);
            } catch (\Throwable) {
                $atrAbs = null;
            }
        }
        $atrPct = ($atrAbs !== null && $mark > 0.0) ? (100.0 * $atrAbs / $mark) : 1.5;

        $ctx = new TpSplitContext(
            symbol: $symbol,
            momentum: strtolower($momentum),
            atrPct: $atrPct,
            mtfValidCount: max(0, min(3, (int)$mtfValid)),
            pullbackClear: $pullback,
            lateEntry: $late,
        );

        $resolved = $this->tpSplitResolver->resolve($ctx);

        return $resolved ?? 0.5;
    }

    private function quantizeSize(int $size, int $minVol): int
    {
        if ($size <= 0) {
            return 0;
        }

        $quantized = (int)floor($size / $minVol) * $minVol;
        if ($quantized > 0 && $quantized < $minVol) {
            return 0;
        }

        return $quantized;
    }

    /**
     * @param array{ratio: float, size1: int, size2: int, slSize: int, bitmartCloseSide: int, size: int} $split
     * @param array{stop: float, tp1: float, tp2: float} $computations
     * @return array<int,array{order_id:string,price:float,size:int,type:string,side:string,kind:string,client_order_id:string}>
     */
    private function submitOrders(
        TpSlTwoTargetsRequest $req,
        string $symbol,
        ?string $decisionKey,
        OrderProviderInterface $orderProvider,
        array $split,
        array $computations,
        int $cancelledCount,
    ): array {
        $closeSide = $req->side === EntrySide::Long ? OrderSide::SELL : OrderSide::BUY;
        $optionsBase = [
            'side' => $split['bitmartCloseSide'],
            'reduce_only' => true,
            'reduceOnly' => true,
        ];

        $baseCid = $this->makeBaseCid($symbol, $req->side, $decisionKey);
        $submitted = [];

        $submitLimit = function (string $suffix, float $price, int $size, string $kind, ?float $stopPrice = null) use (
            $orderProvider,
            $symbol,
            $closeSide,
            $optionsBase,
            $baseCid,
            &$submitted,
        ): void {
            if ($size <= 0) {
                return;
            }

            $options = $optionsBase + ['client_order_id' => $baseCid . '-' . $suffix];
            $dto = $orderProvider->placeOrder(
                symbol: $symbol,
                side: $closeSide,
                type: OrderType::LIMIT,
                quantity: (float)$size,
                price: $price,
                stopPrice: $stopPrice,
                options: $options,
            );

            if ($dto instanceof OrderDto) {
                $submitted[] = [
                    'order_id' => $dto->orderId,
                    'price' => (float)($dto->price?->__toString() ?? (string)$price),
                    'size' => $size,
                    'type' => 'limit',
                    'side' => $closeSide->value,
                    'kind' => $kind,
                    'client_order_id' => $options['client_order_id'],
                ];
            }
        };

        $submitLimit('SL', $computations['stop'], $split['slSize'], 'sl', $computations['stop']);
        $submitLimit('TP1', $computations['tp1'], $split['size1'], 'tp1');
        $submitLimit('TP2', $computations['tp2'], $split['size2'], 'tp2');

        $this->journeyLogger->info('order_journey.tp2sl.submit', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'submitted_count' => \count($submitted),
            'cancelled_count' => $cancelledCount,
            'ratio' => $split['ratio'],
            'size' => $split['size'],
            'size1' => $split['size1'],
            'size2' => $split['size2'],
            'sl_size' => $split['slSize'],
            'reason' => 'tp_two_targets_submitted',
        ]);

        return $submitted;
    }

    private function resolvePosition(string $symbol, EntrySide $side): ?Position
    {
        // Position entity side is LONG|SHORT
        $pos = $this->positions->findOneBySymbolSide($symbol, $side === EntrySide::Long ? 'LONG' : 'SHORT');
        return $pos;
    }

    private function resolveAtrTimeframe(): string
    {
        try {
            $cfg = $this->mtfConfig->getConfig();
            // optional override in mtf_validations.yaml: defaults.atr_tf
            $tf = $cfg['defaults']['atr_tf'] ?? null;
            if (is_string($tf) && $tf !== '') {
                return $tf;
            }
        } catch (\Throwable) {}
        return '5m';
    }

    /**
     * Déduit momentum et mtf_valid_count depuis le cache MTF si disponible.
     * Retourne des valeurs par défaut raisonnables sinon.
     * @return array{momentum:string, mtf_valid_count:int}
     */
    private function deriveMtfHints(string $symbol): array
    {
        $default = ['momentum' => 'moyen', 'mtf_valid_count' => 2];
        if ($this->validationCache === null) {
            return $default;
        }

        try {
            $states = $this->validationCache->getValidationStates($symbol);
            if (empty($states)) {
                return $default;
            }

            // Filtrer les états expirés puis trier par klineTime desc
            $states = array_values(array_filter($states, static fn($s) => method_exists($s, 'isExpired') ? !$s->isExpired() : true));
            if (empty($states)) {
                return $default;
            }
            usort($states, static function ($a, $b) {
                $tsa = ($a->klineTime ?? null) instanceof \DateTimeImmutable ? $a->klineTime->getTimestamp() : 0;
                $tsb = ($b->klineTime ?? null) instanceof \DateTimeImmutable ? $b->klineTime->getTimestamp() : 0;
                return $tsb <=> $tsa;
            });

            $latest = $states[0];
            $details = $latest->details ?? [];
            $collector = $details['mtf_collector'] ?? [];

            // Déterminer la base TF depuis la config (context) sinon fallback 3 TF usuelles
            $cfg = $this->mtfConfig->getConfig();
            $contextTfs = array_map('strtolower', (array)($cfg['validation']['context'] ?? ($cfg['mtf']['context'] ?? [])));
            if (empty($contextTfs)) {
                $contextTfs = ['1h','15m','5m'];
            }
            $contextSet = array_flip($contextTfs);

            $validCount = 0;
            foreach ($collector as $row) {
                $tf = strtolower((string)($row['tf'] ?? $row['timeframe'] ?? ''));
                $status = strtoupper((string)($row['status'] ?? ''));
                if ($tf !== '' && isset($contextSet[$tf]) && $status === 'VALID') {
                    $validCount++;
                }
            }
            $validCount = max(0, min(3, (int)$validCount));

            $momentum = 'moyen';
            if ($validCount >= 3) {
                $momentum = 'fort';
            } elseif ($validCount <= 1) {
                $momentum = 'faible';
            }

            return ['momentum' => $momentum, 'mtf_valid_count' => $validCount];
        } catch (\Throwable) {
            return $default;
        }
    }

    private function makeBaseCid(string $symbol, EntrySide $side, ?string $decisionKey): string
    {
        $sideTag = $side === EntrySide::Long ? 'L' : 'S';
        $base = null;
        if (\is_string($decisionKey) && $decisionKey !== '') {
            $san = preg_replace('/[^A-Za-z0-9:_-]/', '', $decisionKey) ?? 'key';
            $base = sprintf('TPSL-%s-%s-%s', strtoupper($symbol), $sideTag, substr($san, -20));
        }
        if ($base === null) {
            try {
                $rnd = bin2hex(random_bytes(3));
            } catch (\Throwable) { $rnd = substr(sha1(uniqid('', true)), 0, 6); }
            $base = sprintf('TPSL-%s-%s-%s', strtoupper($symbol), $sideTag, $rnd);
        }
        // Bitmart allows fairly long IDs; keep under 64 chars to be safe
        return substr($base, 0, 64);
    }
}
