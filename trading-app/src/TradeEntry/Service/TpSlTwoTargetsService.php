<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\Config\TradeEntryConfig;
use App\Contract\Provider\MainProviderInterface;
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
        private readonly TradeEntryConfig $tradeEntryConfig,
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
            // Essayer d'abord depuis l'exchange (AccountProvider)
            $accountProvider = $this->providers->getAccountProvider();
            if ($accountProvider !== null) {
                try {
                    $openPositions = $accountProvider->getOpenPositions($symbol);
                    $targetSideValue = $req->side->value; // 'long' ou 'short'

                    foreach ($openPositions as $posDto) {
                        if ($posDto->symbol === $symbol && strtolower($posDto->side->value) === $targetSideValue) {
                            if ($entryPrice === null) {
                                $entryPrice = $posDto->entryPrice->toFloat();
                            }
                            if ($size === null) {
                                $size = (int)max(0, (int)$posDto->size->toFloat());
                            }
                            $this->journeyLogger->debug('order_journey.tp2sl.position_found', [
                                'symbol' => $symbol,
                                'side' => $targetSideValue,
                                'entry_price' => $entryPrice,
                                'size' => $size,
                                'source' => 'exchange',
                            ]);
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->journeyLogger->warning('order_journey.tp2sl.fetch_position_failed', [
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                        'reason' => 'fetch_from_exchange_failed',
                    ]);
                }
            }

            // Fallback sur la base de données locale si toujours pas trouvé
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
        }

        if ($entryPrice === null || $entryPrice <= 0.0 || $size === null || $size <= 0) {
            throw new \InvalidArgumentException('entryPrice et size requis (ou position introuvable)');
        }

        // 3) Calcul SL (priorité pivot), TP1 (mécanisme actuel), TP2 (R2/S2)
        $defaults = $this->tradeEntryConfig->getDefaults();
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
        $slFullByDefault = (bool)($defaults['sl_full_size'] ?? true);

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

        // Prix actuel pour validation
        $currentPrice = (float)($pre->markPrice ?? $pre->bestAsk ?? $pre->bestBid ?? $entryPrice);

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

        // Vérifier TP1 par rapport au prix actuel et chercher niveau suivant si nécessaire
        $tp1Original = $tp1; // Sauvegarder pour les logs

        // CRITIQUE: Garantir que TP1 est toujours au-dessus du prix actuel (long) ou en dessous (short)
        // Si TP1 est invalide, monter directement au prochain R (long) ou descendre au prochain S (short)
        // Pour TP1, chercher jusqu'à r5 (long) ou s5 (short) maximum
        if ($req->side === EntrySide::Long && $tp1 <= $currentPrice) {
            // Boucle sur r1 à r5 pour trouver le premier R au-dessus du prix actuel
            $keys = ['r1', 'r2', 'r3', 'r4', 'r5'];
            $nextTp1 = null;
            $foundKey = null;

            foreach ($keys as $key) {
                if (!isset($pivotLevels[$key])) {
                    continue;
                }

                $level = (float)$pivotLevels[$key];
                if (!is_finite($level) || $level <= 0.0) {
                    continue;
                }

                // Chercher le premier R strictement au-dessus du prix actuel
                if ($level > $currentPrice) {
                    $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($level, $precision);
                    // S'assurer que le niveau quantifié est toujours au-dessus du prix actuel
                    if ($quantized <= $currentPrice) {
                        $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($currentPrice + $tick, $precision);
                    }
                    $nextTp1 = $quantized;
                    $foundKey = $key;
                    break; // Prendre le premier R trouvé
                }
            }

            if ($nextTp1 !== null && $nextTp1 > $currentPrice) {
                // Utiliser directement ce niveau R comme TP1
                $tp1 = $nextTp1;
                $this->journeyLogger->info('order_journey.tp2sl.tp1_adjusted', [
                    'symbol' => $symbol,
                    'original_tp1' => $tp1Original,
                    'adjusted_tp1' => $tp1,
                    'current_price' => $currentPrice,
                    'pivot_key' => $foundKey,
                    'pivot_level' => $pivotLevels[$foundKey] ?? null,
                    'reason' => 'tp1_below_current_price_using_next_r',
                ]);
            } else {
                // Fallback: si aucun R valide trouvé jusqu'à r5, forcer au-dessus du prix actuel
                $this->journeyLogger->warning('order_journey.tp2sl.tp1_no_pivot_found', [
                    'symbol' => $symbol,
                    'original_tp1' => $tp1Original,
                    'current_price' => $currentPrice,
                    'reason' => 'no_valid_r_above_current_price_up_to_r5',
                ]);
                $tp1 = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($currentPrice + $tick * 10, $precision);
            }
        } elseif ($req->side === EntrySide::Short && $tp1 >= $currentPrice) {
            // Boucle sur s1 à s5 pour trouver le premier S en dessous du prix actuel
            $keys = ['s1', 's2', 's3', 's4', 's5'];
            $nextTp1 = null;
            $foundKey = null;

            foreach ($keys as $key) {
                if (!isset($pivotLevels[$key])) {
                    continue;
                }

                $level = (float)$pivotLevels[$key];
                if (!is_finite($level) || $level <= 0.0) {
                    continue;
                }

                // Chercher le premier S strictement en dessous du prix actuel
                if ($level < $currentPrice) {
                    $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantize($level, $precision);
                    // S'assurer que le niveau quantifié est toujours en dessous du prix actuel
                    if ($quantized >= $currentPrice) {
                        $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantize($currentPrice - $tick, $precision);
                    }
                    $nextTp1 = $quantized;
                    $foundKey = $key;
                    break; // Prendre le premier S trouvé
                }
            }

            if ($nextTp1 !== null && $nextTp1 < $currentPrice) {
                // Utiliser directement ce niveau S comme TP1
                $tp1 = $nextTp1;
                $this->journeyLogger->info('order_journey.tp2sl.tp1_adjusted', [
                    'symbol' => $symbol,
                    'original_tp1' => $tp1Original,
                    'adjusted_tp1' => $tp1,
                    'current_price' => $currentPrice,
                    'pivot_key' => $foundKey,
                    'pivot_level' => $pivotLevels[$foundKey] ?? null,
                    'reason' => 'tp1_above_current_price_using_next_s',
                ]);
            } else {
                // Fallback: si aucun S valide trouvé jusqu'à s5, forcer en dessous du prix actuel
                $this->journeyLogger->warning('order_journey.tp2sl.tp1_no_pivot_found', [
                    'symbol' => $symbol,
                    'original_tp1' => $tp1Original,
                    'current_price' => $currentPrice,
                    'reason' => 'no_valid_s_below_current_price_up_to_s5',
                ]);
                $tp1 = \App\TradeEntry\Pricing\TickQuantizer::quantize($currentPrice - $tick * 10, $precision);
            }
        }

        // Vérification finale absolue pour garantir la cohérence
        if ($req->side === EntrySide::Long && $tp1 <= $currentPrice) {
            $this->journeyLogger->warning('order_journey.tp2sl.tp1_validation_failed', [
                'symbol' => $symbol,
                'tp1' => $tp1,
                'current_price' => $currentPrice,
                'reason' => 'tp1_still_below_current_price_after_adjustment',
            ]);
            // Forcer un dernier ajustement : prendre le premier R disponible (r1 à r5) ou prix actuel + 10 ticks
            if (!empty($pivotLevels)) {
                $keys = ['r1', 'r2', 'r3', 'r4', 'r5'];
                foreach ($keys as $key) {
                    if (isset($pivotLevels[$key]) && (float)$pivotLevels[$key] > $currentPrice) {
                        $tp1 = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp((float)$pivotLevels[$key], $precision);
                        break;
                    }
                }
            }
            if ($tp1 <= $currentPrice) {
                $tp1 = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($currentPrice + $tick * 10, $precision);
            }
        } elseif ($req->side === EntrySide::Short && $tp1 >= $currentPrice) {
            $this->journeyLogger->warning('order_journey.tp2sl.tp1_validation_failed', [
                'symbol' => $symbol,
                'tp1' => $tp1,
                'current_price' => $currentPrice,
                'reason' => 'tp1_still_above_current_price_after_adjustment',
            ]);
            // Forcer un dernier ajustement : prendre le premier S disponible (s1 à s5) ou prix actuel - 10 ticks
            if (!empty($pivotLevels)) {
                $keys = ['s1', 's2', 's3', 's4', 's5'];
                foreach ($keys as $key) {
                    if (isset($pivotLevels[$key]) && (float)$pivotLevels[$key] < $currentPrice) {
                        $tp1 = \App\TradeEntry\Pricing\TickQuantizer::quantize((float)$pivotLevels[$key], $precision);
                        break;
                    }
                }
            }
            if ($tp1 >= $currentPrice) {
                $tp1 = \App\TradeEntry\Pricing\TickQuantizer::quantize($currentPrice - $tick * 10, $precision);
            }
        }

        // TP2 = R2/S2 ou niveau suivant si TP1 utilise déjà R2/S2
        // TP2 doit être vraiment différent de TP1 (niveau pivot suivant significatif, pas juste 1 tick)
        $tp2 = null;

        // Identifier quel pivot TP1 utilise (si disponible)
        $tp1PivotKey = $this->identifyPivotKeyUsed($tp1, $pivotLevels, $req->side, $tick);

        // Déterminer le niveau de départ pour TP2
        // Si TP1 utilise R1, TP2 commence à R2. Si TP1 utilise R2, TP2 commence à R3, etc.
        $keys = $req->side === EntrySide::Long ? ['r1', 'r2', 'r3', 'r4', 'r5', 'r6'] : ['s1', 's2', 's3', 's4', 's5', 's6'];
        $startKey = $req->side === EntrySide::Long ? 'r2' : 's2'; // Défaut R2/S2
        if ($tp1PivotKey !== null) {
            $tp1Index = array_search($tp1PivotKey, $keys, true);
            if ($tp1Index !== false && $tp1Index < count($keys) - 1) {
                // Commencer au niveau suivant après celui utilisé par TP1
                $startKey = $keys[$tp1Index + 1];
            } else {
                // TP1 utilise le dernier niveau, utiliser le même ou fallback 2R
                $startKey = $keys[min($tp1Index ?? 1, count($keys) - 1)];
            }
        }

        // Chercher le premier niveau pivot valide au-dessus du prix actuel (long) ou en dessous (short)
        // et qui est significativement supérieur à TP1 (long) ou inférieur (short)
        // Distance minimale = au moins 0.5% du prix d'entrée ou 50 ticks, ou 20% de la distance SL
        $minDiffFromTp1 = max(
            $tick * 50,
            $entryPrice * 0.005, // 0.5% du prix
            abs($entryPrice - $stop) * 0.2 // 20% de la distance SL
        );
        $tp2Found = false;

        // Trouver l'index de départ
        $startIndex = 0;
        if ($startKey !== null) {
            $startKeyIndex = array_search($startKey, $keys, true);
            if ($startKeyIndex !== false) {
                $startIndex = $startKeyIndex;
            }
        }

        // Parcourir depuis startKey
        for ($i = $startIndex; $i < count($keys); $i++) {
            $key = $keys[$i];

            if (!isset($pivotLevels[$key]) || !is_finite((float)$pivotLevels[$key]) || (float)$pivotLevels[$key] <= 0.0) {
                continue;
            }

            $rawLevel = (float)$pivotLevels[$key];

            // Vérifier que le niveau est valide par rapport au prix actuel
            if ($req->side === EntrySide::Long && $rawLevel <= $currentPrice + $tick) {
                continue; // Trop proche ou en dessous du prix actuel
            }
            if ($req->side === EntrySide::Short && $rawLevel >= $currentPrice - $tick) {
                continue; // Trop proche ou au-dessus du prix actuel
            }

            // Appliquer buffer
            $levelWithBuffer = $rawLevel;
            if ($tpBufferPct !== null && $tpBufferPct > 0.0) {
                $levelWithBuffer = $req->side === EntrySide::Long
                    ? $levelWithBuffer * (1.0 - $tpBufferPct)
                    : $levelWithBuffer * (1.0 + $tpBufferPct);
            }
            if ($tpBufferTicks !== null && $tpBufferTicks > 0) {
                $levelWithBuffer = $req->side === EntrySide::Long
                    ? $levelWithBuffer - $tpBufferTicks * $tick
                    : $levelWithBuffer + $tpBufferTicks * $tick;
            }

            // Quantifier
            if ($req->side === EntrySide::Long) {
                $levelWithBuffer = max($levelWithBuffer, $entryPrice + $tick);
                $levelWithBuffer = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($levelWithBuffer, $precision);
            } else {
                $levelWithBuffer = min($levelWithBuffer, $entryPrice - $tick);
                $levelWithBuffer = \App\TradeEntry\Pricing\TickQuantizer::quantize($levelWithBuffer, $precision);
            }

            // Vérifier que TP2 est significativement différent de TP1
            if ($req->side === EntrySide::Long) {
                if ($levelWithBuffer > $tp1 + $minDiffFromTp1) {
                    $tp2 = $levelWithBuffer;
                    $tp2Found = true;
                    $this->journeyLogger->debug('order_journey.tp2sl.tp2_selected', [
                        'symbol' => $symbol,
                        'tp1' => $tp1,
                        'tp2' => $tp2,
                        'pivot_key' => $key,
                        'raw_level' => $rawLevel,
                        'diff_from_tp1' => $tp2 - $tp1,
                        'reason' => 'tp2_pivot_selected',
                    ]);
                    break;
                }
            } else {
                if ($levelWithBuffer < $tp1 - $minDiffFromTp1) {
                    $tp2 = $levelWithBuffer;
                    $tp2Found = true;
                    $this->journeyLogger->debug('order_journey.tp2sl.tp2_selected', [
                        'symbol' => $symbol,
                        'tp1' => $tp1,
                        'tp2' => $tp2,
                        'pivot_key' => $key,
                        'raw_level' => $rawLevel,
                        'diff_from_tp1' => $tp1 - $tp2,
                        'reason' => 'tp2_pivot_selected',
                    ]);
                    break;
                }
            }
        }

        // Fallback: 2R depuis SL si aucun pivot valide trouvé
        if ($tp2 === null || !$tp2Found) {
            $tp2 = $this->tpc->fromRMultiple($entryPrice, $stop, $req->side, 2.0, $precision);
            // Garantir que TP2 est significativement différent de TP1
            if ($req->side === EntrySide::Long) {
                if ($tp2 <= $tp1 + $minDiffFromTp1) {
                    // Utiliser un multiple plus élevé ou forcer un écart minimal
                    $tp2 = max($tp1 + $minDiffFromTp1, $this->tpc->fromRMultiple($entryPrice, $stop, $req->side, 2.5, $precision));
                    $tp2 = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($tp2, $precision);
                }
            } else {
                if ($tp2 >= $tp1 - $minDiffFromTp1) {
                    $tp2 = min($tp1 - $minDiffFromTp1, $this->tpc->fromRMultiple($entryPrice, $stop, $req->side, 2.5, $precision));
                    $tp2 = \App\TradeEntry\Pricing\TickQuantizer::quantize($tp2, $precision);
                }
            }
        }

        $this->journeyLogger->info('order_journey.tp2sl.compute', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'entry' => $entryPrice,
            'current_price' => $currentPrice,
            'stop' => $stop,
            'tp1' => $tp1,
            'tp2' => $tp2,
            'tp1_pivot_key' => $this->identifyPivotKeyUsed($tp1, $pivotLevels, $req->side, $tick),
            'dry_run' => $req->dryRun ?? false,
            'reason' => 'tp_sl_two_targets_computed',
        ]);

        // 4) Annulations (SL si différent, TP existants)
        $cancelled = [];
        $orderProvider = $this->providers->getOrderProvider();
        $isDryRun = $req->dryRun ?? false;

        if ($isDryRun) {
            $this->journeyLogger->info('order_journey.tp2sl.dry_run', [
                'symbol' => $symbol,
                'decision_key' => $decisionKey,
                'reason' => 'dry_run_mode_active',
            ]);
        }
        try {
            $open = $orderProvider->getOpenOrders($symbol);
        } catch (\Throwable $e) {
            $open = [];
            $this->journeyLogger->warning('order_journey.tp2sl.open_orders_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'reason' => 'fetch_open_orders_failed',
            ]);
        }

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
        if ($req->cancelExistingStopLossIfDifferent && !$isDryRun) {
            $existingSl = null;
            foreach ($closing as $o) {
                if ($isSl($o)) { $existingSl = $o; break; }
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
        } elseif ($req->cancelExistingStopLossIfDifferent && $isDryRun) {
            // En mode dry-run, simuler les annulations qui seraient faites
            $existingSl = null;
            foreach ($closing as $o) {
                if ($isSl($o)) { $existingSl = $o; break; }
            }
            if ($existingSl !== null) {
                $p = $priceOf($existingSl);
                if ($p !== null && abs($p - $stop) > max($tick, 1e-8)) {
                    $cancelled[] = $existingSl->orderId . ' (DRY-RUN)';
                }
            }
        }

        // Annuler TP existants
        if ($req->cancelExistingTakeProfits && !$isDryRun) {
            foreach ($closing as $o) {
                if (!$isSl($o)) {
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
        } elseif ($req->cancelExistingTakeProfits && $isDryRun) {
            // En mode dry-run, simuler les annulations qui seraient faites
            foreach ($closing as $o) {
                if (!$isSl($o)) {
                    $cancelled[] = $o->orderId . ' (DRY-RUN)';
                }
            }
        }

        // 5) Soumettre SL (limité) et 2 ordres TP (split du size)
        // Détermination ratio TP1/TP2: priorité à la requête, sinon resolver basé sur contexte
        $ratio = $req->splitPct;
        if ($ratio === null && $this->tpSplitResolver !== null) {
            // Déduire automatiquement depuis le pipeline MTF si absent
            $auto = $this->deriveMtfHints($symbol);
            $momentum = $req->momentum ?? $auto['momentum'];
            $mtfValid = $req->mtfValidCount ?? $auto['mtf_valid_count'];
            $pullback = (bool)($req->pullbackClear ?? false);
            $late = (bool)($req->lateEntry ?? false);

            // ATR% via IndicatorProvider (fallback: estimer depuis EntryZone ATR si dispo)
            $mark = (float)($pre->markPrice ?? $pre->bestAsk ?? $pre->bestBid ?? 0.0);
            $atrAbs = null;
            if ($this->indicatorProvider !== null) {
                try {
                    $tf = $this->resolveAtrTimeframe();
                    $atrAbs = $this->indicatorProvider->getAtr(symbol: $symbol, tf: $tf);
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
            $ratio = $this->tpSplitResolver->resolve($ctx);
        }
        if ($ratio === null) { $ratio = 0.5; }


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

        // Déterminer taille SL (full vs résiduel après split)
        $slFull = (bool)($req->slFullSize ?? $slFullByDefault);
        $slSize = $slFull ? (int)$size : (int)max(0, $size - $size1 - $size2);

        $submitted = [];
        $bitmartCloseSide = ($req->side === EntrySide::Long) ? 2 : 3; // 2=close_long, 3=close_short

        $optionsBase = [
            'side' => $bitmartCloseSide,
            // Force reduce-only when supported by provider/API
            'reduce_only' => true,
            'reduceOnly' => true,
        ];

        // Quantifier SL size au multiple minVol
        $slSize = $slSize > 0 ? (int)floor($slSize / $minVol) * $minVol : 0;
        // Préparer un base CID pour tracer les ordres
        $baseCid = $this->makeBaseCid($symbol, $req->side, $decisionKey);

        if ($slSize > 0) {
            $options = $optionsBase + ['client_order_id' => $baseCid . '-SL'];
            if (!$isDryRun) {
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
            } else {
                // Mode dry-run : simuler le placement
                $submitted[] = [
                    'order_id' => 'DRY-RUN-SL-' . uniqid('', true),
                    'price' => (float)$stop,
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
            if (!$isDryRun) {
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
            } else {
                // Mode dry-run : simuler le placement
                $submitted[] = [
                    'order_id' => 'DRY-RUN-TP1-' . uniqid('', true),
                    'price' => (float)$tp1,
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
            if (!$isDryRun) {
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
            } else {
                // Mode dry-run : simuler le placement
                $submitted[] = [
                    'order_id' => 'DRY-RUN-TP2-' . uniqid('', true),
                    'price' => (float)$tp2,
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
            'ratio' => $ratio,
            'size' => $size,
            'size1' => $size1,
            'size2' => $size2,
            'sl_size' => $slSize,
            'dry_run' => $isDryRun,
            'reason' => $isDryRun ? 'tp_two_targets_dry_run' : 'tp_two_targets_submitted',
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

            // Déterminer la base TF depuis la config (list_tf ou context) sinon fallback 3 TF usuelles
            $cfg = $this->mtfConfig->getConfig();
            $mtfConfig = $cfg['signal']['mtf'] ?? $cfg['mtf'] ?? [];

            // Nouveau format: list_tf avec context_count
            if (isset($mtfConfig['list_tf']) && is_array($mtfConfig['list_tf'])) {
                $listTf = array_map('strtolower', $mtfConfig['list_tf']);
                $contextCount = (int)($mtfConfig['context_count'] ?? 2);
                $contextTfs = array_slice($listTf, 0, $contextCount);
            } else {
                // Ancien format: context séparé
                $contextTfs = array_map('strtolower', (array)($cfg['validation']['context'] ?? ($mtfConfig['context'] ?? [])));
            }

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

    /**
     * Identifie quelle clé pivot a été utilisée pour calculer TP1
     *
     * @param float $tp1
     * @param array<string,float> $pivotLevels
     * @param EntrySide $side
     * @param float $tick
     * @return string|null
     */
    private function identifyPivotKeyUsed(float $tp1, array $pivotLevels, EntrySide $side, float $tick): ?string
    {
        if (empty($pivotLevels)) {
            return null;
        }

        $keys = $side === EntrySide::Long
            ? ['r1', 'r2', 'r3', 'r4', 'r5', 'r6']
            : ['s1', 's2', 's3', 's4', 's5', 's6'];

        // Trouver le pivot le plus proche de TP1 (avec tolérance de 2 ticks)
        $tolerance = 2 * $tick;
        $closestKey = null;
        $minDiff = PHP_FLOAT_MAX;

        foreach ($keys as $key) {
            if (!isset($pivotLevels[$key])) {
                continue;
            }

            $level = (float)$pivotLevels[$key];
            if (!is_finite($level) || $level <= 0.0) {
                continue;
            }

            $diff = abs($level - $tp1);
            if ($diff < $tolerance && $diff < $minDiff) {
                $minDiff = $diff;
                $closestKey = $key;
            }
        }

        return $closestKey;
    }

    /**
     * Trouve le prochain niveau pivot valide au-dessus du prix actuel (long) ou en dessous (short)
     *
     * @param array<string,float> $pivotLevels
     * @param float $currentPrice
     * @param EntrySide $side
     * @param int $precision
     * @param float $tick
     * @param string|null $startFromKey Niveau de départ (ex: 'r2', 's2') - si null, commence depuis le début
     * @return float|null
     */
    private function findNextValidPivotLevel(
        array $pivotLevels,
        float $currentPrice,
        EntrySide $side,
        int $precision,
        float $tick,
        ?string $startFromKey = null
    ): ?float {
        if (empty($pivotLevels)) {
            return null;
        }

        // Déterminer les clés à parcourir selon le side
        $keys = $side === EntrySide::Long
            ? ['r1', 'r2', 'r3', 'r4', 'r5', 'r6']
            : ['s1', 's2', 's3', 's4', 's5', 's6'];

        // Si startFromKey est fourni, commencer après ce niveau
        if ($startFromKey !== null) {
            $startIndex = array_search(strtolower($startFromKey), $keys, true);
            if ($startIndex !== false) {
                $keys = array_slice($keys, $startIndex + 1);
            }
        }

        // Pour long: chercher le premier R au-dessus du prix actuel
        // Pour short: chercher le premier S en dessous du prix actuel
        foreach ($keys as $key) {
            if (!isset($pivotLevels[$key])) {
                continue;
            }

            $level = (float)$pivotLevels[$key];
            if (!is_finite($level) || $level <= 0.0) {
                continue;
            }

            if ($side === EntrySide::Long) {
                // Pour long: le niveau doit être > prix actuel (au moins strictement supérieur)
                if ($level > $currentPrice) {
                    $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($level, $precision);
                    // S'assurer que le niveau quantifié est toujours au-dessus du prix actuel
                    if ($quantized <= $currentPrice) {
                        $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantizeUp($currentPrice + $tick, $precision);
                    }
                    $this->journeyLogger->debug('order_journey.tp2sl.next_pivot_found', [
                        'side' => 'long',
                        'current_price' => $currentPrice,
                        'pivot_key' => $key,
                        'pivot_level' => $level,
                        'quantized' => $quantized,
                        'reason' => 'tp_below_current_price',
                    ]);
                    return $quantized;
                }
            } else {
                // Pour short: le niveau doit être < prix actuel (au moins strictement inférieur)
                if ($level < $currentPrice) {
                    $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantize($level, $precision);
                    // S'assurer que le niveau quantifié est toujours en dessous du prix actuel
                    if ($quantized >= $currentPrice) {
                        $quantized = \App\TradeEntry\Pricing\TickQuantizer::quantize($currentPrice - $tick, $precision);
                    }
                    $this->journeyLogger->debug('order_journey.tp2sl.next_pivot_found', [
                        'side' => 'short',
                        'current_price' => $currentPrice,
                        'pivot_key' => $key,
                        'pivot_level' => $level,
                        'quantized' => $quantized,
                        'reason' => 'tp_above_current_price',
                    ]);
                    return $quantized;
                }
            }
        }

        return null;
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
