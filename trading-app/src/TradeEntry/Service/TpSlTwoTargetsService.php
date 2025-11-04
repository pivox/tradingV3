<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\Config\MtfValidationConfig;
use App\Contract\Provider\MainProviderInterface;
use App\Entity\Position;
use App\Repository\PositionRepository;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Policy\PreTradeChecks;
use App\TradeEntry\RiskSizer\StopLossCalculator;
use App\TradeEntry\RiskSizer\TakeProfitCalculator;
use App\TradeEntry\Types\Side as EntrySide;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
    ) {}

    /**
     * Calcule SL + TP1/TP2 (TP2 basé sur R2/S2), annule les ordres existants (SL si différent, TP) et soumet 2 TP.
     *
     * @return array{sl: float, tp1: float, tp2: float, submitted: array<int,array{order_id:string,price:float,size:int,type:string,side:string}>, cancelled: array<int,string>}
     */
    public function __invoke(TpSlTwoTargetsRequest $req, ?string $decisionKey = null): array
    {
        $symbol = strtoupper($req->symbol);

        // 1) Prétraitement/exchange state
        $dummy = new \App\TradeEntry\Dto\TradeEntryRequest(
            symbol: $symbol,
            side: $req->side,
        );
        $pre = $this->pretrade->run($dummy);

        $tick = $pre->tickSize;
        $precision = $pre->pricePrecision;
        $contractSize = $pre->contractSize;
        $pivotLevels = is_array($pre->pivotLevels) ? $pre->pivotLevels : [];

        // 2) Résoudre position/entrée/size
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

        // 3) Calcul SL (priorité pivot), TP1 (mécanisme actuel), TP2 (R2/S2)
        $defaults = $this->mtfConfig->getDefaults();
        $riskPctDefault = (float)($defaults['risk_pct_percent'] ?? 2.0);
        $riskPct = $riskPctDefault > 1.0 ? $riskPctDefault / 100.0 : $riskPctDefault;
        $initMargin = (float)($defaults['initial_margin_usdt'] ?? 100.0);
        $tpPolicy = (string)($defaults['tp_policy'] ?? 'pivot_conservative');
        $tpMinKeepRatio = isset($defaults['tp_min_keep_ratio']) ? (float)$defaults['tp_min_keep_ratio'] : 0.95;
        $tpBufferPct = isset($defaults['tp_buffer_pct']) ? (float)$defaults['tp_buffer_pct'] : null;
        $tpBufferTicks = isset($defaults['tp_buffer_ticks']) ? (int)$defaults['tp_buffer_ticks'] : null;
        $pivotSlPolicy = (string)($defaults['pivot_sl_policy'] ?? 'nearest_below');
        $pivotSlBufferPct = isset($defaults['pivot_sl_buffer_pct']) ? (float)$defaults['pivot_sl_buffer_pct'] : null;
        $pivotSlMinKeepRatio = isset($defaults['pivot_sl_min_keep_ratio']) ? (float)$defaults['pivot_sl_min_keep_ratio'] : null;
        $rMultiple = (float)($req->rMultiple ?? ($defaults['r_multiple'] ?? 2.0));

        // Stop par pivot si disponible, sinon par risque
        if (!empty($pivotLevels)) {
            $stop = $this->slc->fromPivot(
                entry: $entryPrice,
                side: $req->side,
                pivotLevels: $pivotLevels,
                policy: $pivotSlPolicy,
                bufferPct: $pivotSlBufferPct,
                pricePrecision: $precision,
            );
            // Appliquer garde min_keep_ratio si fourni
            if ($pivotSlMinKeepRatio !== null && $pivotSlMinKeepRatio > 0.0) {
                $minRisk = max(1e-8, abs($entryPrice - $stop) * $pivotSlMinKeepRatio);
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
        } else {
            $riskUsdt = $initMargin * $riskPct;
            $stop = $this->slc->fromRisk(
                entry: $entryPrice,
                side: $req->side,
                riskUsdt: $riskUsdt,
                size: $size,
                contractSize: $contractSize,
                precision: $precision,
            );
        }

        // TP1 = mécanique actuelle (R multiple aligné pivots si dispo)
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
                policy: $tpPolicy,
                bufferPct: $tpBufferPct,
                bufferTicks: $tpBufferTicks,
                tick: $tick,
                pricePrecision: $precision,
                minKeepRatio: $tpMinKeepRatio,
                maxExtraR: null,
                decisionKey: $decisionKey,
            );
        }

        // TP2 = R2/S2 si dispo, sinon fallback 2R
        $tp2 = null;
        $key = $req->side === EntrySide::Long ? 'r2' : 's2';
        if (isset($pivotLevels[$key]) && is_finite((float)$pivotLevels[$key]) && (float)$pivotLevels[$key] > 0.0) {
            $tp2 = (float)$pivotLevels[$key];
            // appliquer buffer si défini
            if ($tpBufferPct !== null && $tpBufferPct > 0.0) {
                $tp2 = $req->side === EntrySide::Long ? $tp2 * (1.0 - $tpBufferPct) : $tp2 * (1.0 + $tpBufferPct);
            }
            if ($tpBufferTicks !== null && $tpBufferTicks > 0) {
                $tp2 = $req->side === EntrySide::Long ? $tp2 - $tpBufferTicks * $tick : $tp2 + $tpBufferTicks * $tick;
            }
            // quantifier et assurer >/< entry d'au moins 1 tick
            if ($req->side === EntrySide::Long) {
                $tp2 = max($tp2, $entryPrice + $tick);
                $tp2 = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($tp2, $precision);
            } else {
                $tp2 = min($tp2, $entryPrice - $tick);
                $tp2 = \App\TradeEntry\Pricing\TickQuantizer::quantize($tp2, $precision);
            }
        } else {
            // Fallback: 2R depuis SL
            $tp2 = $this->tpc->fromRMultiple($entryPrice, $stop, $req->side, 2.0, $precision);
        }

        $this->journeyLogger->info('order_journey.tp2sl.compute', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'entry' => $entryPrice,
            'stop' => $stop,
            'tp1' => $tp1,
            'tp2' => $tp2,
            'reason' => 'tp_sl_two_targets_computed',
        ]);

        // 4) Annulations (SL si différent, TP existants)
        $cancelled = [];
        $orderProvider = $this->providers->getOrderProvider();
        $open = $orderProvider->getOpenOrders($symbol);

        // Détermination sens fermeture
        $closeSide = $req->side === EntrySide::Long ? OrderSide::SELL : OrderSide::BUY;
        $isSl = function (OrderDto $o) use ($req, $entryPrice): bool {
            if ($o->price === null) { return false; }
            $p = (float)$o->price->__toString();
            return $req->side === EntrySide::Long ? ($p < $entryPrice) : ($p > $entryPrice);
        };
        $priceOf = fn(OrderDto $o): ?float => $o->price ? (float)$o->price->__toString() : null;

        /** @var array<int,OrderDto> $closing */
        $closing = array_values(array_filter($open, fn(OrderDto $o) => $o->side === $closeSide));

        // Annuler SL si différent (tolérance = 1 tick)
        if ($req->cancelExistingStopLossIfDifferent) {
            $existingSl = null;
            foreach ($closing as $o) {
                if ($isSl($o)) { $existingSl = $o; break; }
            }
            if ($existingSl !== null) {
                $p = $priceOf($existingSl);
                if ($p !== null && abs($p - $stop) > max($tick, 1e-8)) {
                    if ($orderProvider->cancelOrder($existingSl->orderId)) {
                        $cancelled[] = $existingSl->orderId;
                    }
                }
            }
        }

        // Annuler TP existants
        if ($req->cancelExistingTakeProfits) {
            foreach ($closing as $o) {
                if (!$isSl($o)) {
                    if ($orderProvider->cancelOrder($o->orderId)) {
                        $cancelled[] = $o->orderId;
                    }
                }
            }
        }

        // 5) Soumettre SL (limité) et 2 ordres TP (split du size)
        $ratio = $req->splitPct ?? 0.5;
        $ratio = max(0.0, min(1.0, $ratio));
        $size1 = (int)floor($size * $ratio);
        $size2 = (int)max(0, $size - $size1);
        if ($size1 <= 0 || $size2 <= 0) {
            // fallback: tout sur TP1 si insuffisant
            $size1 = $size;
            $size2 = 0;
        }

        // Clip/quantize aux contraintes de volumes échange
        $minVol = (int)max(1, (int)$pre->minVolume);
        // Quantifier à un multiple du minVol
        $size1 = $size1 > 0 ? (int)floor($size1 / $minVol) * $minVol : 0;
        $size2 = $size2 > 0 ? (int)floor($size2 / $minVol) * $minVol : 0;
        if ($size1 > 0 && $size1 < $minVol) { $size1 = 0; }
        if ($size2 > 0 && $size2 < $minVol) { $size2 = 0; }

        $maxVol = $pre->maxVolume;
        if ($maxVol !== null && $maxVol > 0) {
            $size1 = (int)min($size1, (int)$maxVol);
            $size2 = (int)min($size2, (int)$maxVol);
        }

        $submitted = [];
        $bitmartCloseSide = ($req->side === EntrySide::Long) ? 2 : 3; // 2=close_long, 3=close_short

        $optionsBase = [
            'side' => $bitmartCloseSide,
            // Force reduce-only when supported by provider/API
            'reduce_only' => true,
            'reduceOnly' => true,
        ];

        // SL pleine taille (comportement codé en dur)
        $slSize = (int)$size;
        // Préparer un base CID pour tracer les ordres
        $baseCid = $this->makeBaseCid($symbol, $req->side, $decisionKey);

        if ($slSize > 0) {
            // Quantifier SL size au multiple minVol
            $slSize = (int)floor($slSize / $minVol) * $minVol;
            $options = $optionsBase + ['client_order_id' => $baseCid . '-SL'];
            // Submit SL as a stop-limit (triggered) by using stopPrice; keep limit price equal to stop for determinism
            $dto = $orderProvider->placeOrder(
                symbol: $symbol,
                side: $closeSide,
                type: OrderType::LIMIT,
                quantity: (float)$slSize,
                price: (float)$stop,
                stopPrice: (float)$stop,
                options: $options,
            );
            if ($dto instanceof OrderDto) {
                $submitted[] = [
                    'order_id' => $dto->orderId,
                    'price' => (float)($dto->price?->__toString() ?? (string)$stop),
                    'size' => $slSize,
                    'type' => 'limit',
                    'side' => $closeSide->value,
                    'kind' => 'sl',
                    'client_order_id' => $options['client_order_id'],
                ];
            }
        }

        // TP1
        if ($size1 > 0) {
            $options = $optionsBase + ['client_order_id' => $baseCid . '-TP1'];
            $dto = $orderProvider->placeOrder(
                symbol: $symbol,
                side: $closeSide,
                type: OrderType::LIMIT,
                quantity: (float)$size1,
                price: (float)$tp1,
                stopPrice: null,
                options: $options,
            );
            if ($dto instanceof OrderDto) {
                $submitted[] = [
                    'order_id' => $dto->orderId,
                    'price' => (float)($dto->price?->__toString() ?? (string)$tp1),
                    'size' => $size1,
                    'type' => 'limit',
                    'side' => $closeSide->value,
                    'kind' => 'tp1',
                    'client_order_id' => $options['client_order_id'],
                ];
            }
        }

        // TP2
        if ($size2 > 0) {
            $options = $optionsBase + ['client_order_id' => $baseCid . '-TP2'];
            $dto = $orderProvider->placeOrder(
                symbol: $symbol,
                side: $closeSide,
                type: OrderType::LIMIT,
                quantity: (float)$size2,
                price: (float)$tp2,
                stopPrice: null,
                options: $options,
            );
            if ($dto instanceof OrderDto) {
                $submitted[] = [
                    'order_id' => $dto->orderId,
                    'price' => (float)($dto->price?->__toString() ?? (string)$tp2),
                    'size' => $size2,
                    'type' => 'limit',
                    'side' => $closeSide->value,
                    'kind' => 'tp2',
                    'client_order_id' => $options['client_order_id'],
                ];
            }
        }

        $this->journeyLogger->info('order_journey.tp2sl.submit', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'submitted_count' => \count($submitted),
            'cancelled_count' => \count($cancelled),
            'reason' => 'tp_two_targets_submitted',
        ]);

        return [
            'sl' => $stop,
            'tp1' => $tp1,
            'tp2' => (float)$tp2,
            'submitted' => $submitted,
            'cancelled' => $cancelled,
        ];
    }

    private function resolvePosition(string $symbol, EntrySide $side): ?Position
    {
        // Position entity side is LONG|SHORT
        $pos = $this->positions->findOneBySymbolSide($symbol, $side === EntrySide::Long ? 'LONG' : 'SHORT');
        return $pos;
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
