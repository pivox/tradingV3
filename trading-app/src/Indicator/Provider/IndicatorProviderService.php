<?php

declare(strict_types=1);

namespace App\Indicator\Provider;

use App\Common\Dto\IndicatorSnapshotDto;
use App\Common\Enum\Timeframe;
use App\Contract\Indicator\Dto\ListIndicatorDto;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Entity\IndicatorSnapshot;
use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Context\EvaluationContext;
use App\Indicator\Core\AtrCalculator as CoreAtr;
use App\Indicator\Core\Momentum\Macd as CoreMacd;
use App\Indicator\Core\Momentum\Rsi as CoreRsi;
use App\Indicator\Core\Momentum\StochRsi as CoreStochRsi;
use App\Indicator\Core\Trend\Adx as CoreAdx;
use App\Indicator\Core\Trend\Ema as CoreEma;
use App\Indicator\Core\Trend\Sma as CoreSma;
use App\Indicator\Core\Volatility\Bollinger as CoreBollinger;
use App\Indicator\Core\Volume\Vwap as CoreVwap;
use App\Indicator\Registry\ConditionRegistry;
use App\Repository\IndicatorSnapshotRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: IndicatorProviderInterface::class)]
final class IndicatorProviderService implements IndicatorProviderInterface
    {
        public function __construct(
            private readonly KlineProviderInterface $klineProvider,
            private readonly ConditionRegistry $conditionRegistry,
            private readonly IndicatorSnapshotRepository $snapshotRepository,
            private readonly CoreRsi $rsiService,
            private readonly CoreEma $emaService,
            private readonly CoreMacd $macdService,
            private readonly CoreAdx $adxService,
            private readonly CoreBollinger $bollService,
            private readonly CoreVwap $vwapService,
            private readonly CoreAtr $atrService,
            private readonly CoreStochRsi $stochRsiService,
            private readonly CoreSma $smaService,
        ) {}

        public function getSnapshot(string $symbol, string $tximeframe): IndicatorSnapshotDto
        {
            $tf = Timeframe::from($timeframe);
            $klines = $this->klineProvider->getKlines($symbol, $tf, 200);
            if (empty($klines)) {
                throw new \RuntimeException("Aucune kline pour $symbol/$timeframe");
            }

            // Normalize arrays for calculation
            $closes = [];$highs=[];$lows=[];$vols=[];$openTimes=[];
            foreach ($klines as $k) {
                // KlineDto fields
                $closes[] = (float)$k->close->toFloat();
                $highs[]  = (float)$k->high->toFloat();
                $lows[]   = (float)$k->low->toFloat();
                $vols[]   = (float)$k->volume->toFloat();
                $openTimes[] = $k->openTime;
            }

            // Compute indicators using Core services
            $ema20 = $this->emaService->calculate($closes, 20);
            $ema50 = $this->emaService->calculate($closes, 50);
            $macd  = $this->macdService->calculate($closes, 12, 26, 9);
            $atr   = (function () use ($highs, $lows, $closes) {
                $n = min(count($highs), count($lows), count($closes));
                $ohlc = [];
                for ($i = 0; $i < $n; $i++) { $ohlc[] = ['high'=>$highs[$i],'low'=>$lows[$i],'close'=>$closes[$i]]; }
                return $this->atrService->compute($ohlc, 14, 'wilder');
            })();
            $rsi   = $this->rsiService->calculate($closes, 14);
            $vwap  = $this->vwapService->calculate($highs, $lows, $closes, $vols);
            $bb    = $this->bollService->calculate($closes, 20, 2.0);
            $ma9   = $this->smaService->calculate($closes, 9);
            $ma21  = $this->smaService->calculate($closes, 21);

            $lastTime = end($openTimes);
            if (!$lastTime instanceof \DateTimeImmutable) {
                $lastTime = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }

            // Build snapshot DTO (persist later with key symbol-timeframe)
            return new IndicatorSnapshotDto(
                symbol: $symbol,
                timeframe: $tf,
                klineTime: $lastTime,
                ema20: $ema20 !== null ? \Brick\Math\BigDecimal::of((string)$ema20) : null,
                ema50: $ema50 !== null ? \Brick\Math\BigDecimal::of((string)$ema50) : null,
                macd: isset($macd['macd']) && $macd['macd'] !== null ? \Brick\Math\BigDecimal::of((string)$macd['macd']) : null,
                macdSignal: isset($macd['signal']) && $macd['signal'] !== null ? \Brick\Math\BigDecimal::of((string)$macd['signal']) : null,
                macdHistogram: isset($macd['hist']) && $macd['hist'] !== null ? \Brick\Math\BigDecimal::of((string)$macd['hist']) : null,
                atr: $atr !== null ? \Brick\Math\BigDecimal::of((string)$atr) : null,
                rsi: $rsi,
                vwap: $vwap !== null ? \Brick\Math\BigDecimal::of((string)$vwap) : null,
                bbUpper: isset($bb['upper']) ? \Brick\Math\BigDecimal::of((string)$bb['upper']) : null,
                bbMiddle: isset($bb['middle']) ? \Brick\Math\BigDecimal::of((string)$bb['middle']) : null,
                bbLower: isset($bb['lower']) ? \Brick\Math\BigDecimal::of((string)$bb['lower']) : null,
                ma9: $ma9 !== null ? \Brick\Math\BigDecimal::of((string)$ma9) : null,
                ma21: $ma21 !== null ? \Brick\Math\BigDecimal::of((string)$ma21) : null,
                meta: ['key' => $symbol . '-' . $tf->value],
                source: 'PHP'
            );
        }

        public function saveIndicatorSnapshot(IndicatorSnapshotDto $snapshotDto): void
        {
            $snapshot = (new IndicatorSnapshot())
                ->setSymbol($snapshotDto->symbol)
                ->setTimeframe($snapshotDto->timeframe)
                ->setKlineTime($snapshotDto->klineTime)
                ->setSource($snapshotDto->source);

            $snapshot->setEma20($snapshotDto->ema20?->toFixed(12));
            $snapshot->setEma50($snapshotDto->ema50?->toFixed(12));
            $snapshot->setMacd($snapshotDto->macd?->toFixed(12));
            $snapshot->setMacdSignal($snapshotDto->macdSignal?->toFixed(12));
            $snapshot->setMacdHistogram($snapshotDto->macdHistogram?->toFixed(12));
            $snapshot->setAtr($snapshotDto->atr?->toFixed(12));
            $snapshot->setRsi($snapshotDto->rsi);
            $snapshot->setVwap($snapshotDto->vwap?->toFixed(12));
            $snapshot->setBbUpper($snapshotDto->bbUpper?->toFixed(12));
            $snapshot->setBbMiddle($snapshotDto->bbMiddle?->toFixed(12));
            $snapshot->setBbLower($snapshotDto->bbLower?->toFixed(12));
            $snapshot->setMa9($snapshotDto->ma9?->toFixed(12));
            $snapshot->setMa21($snapshotDto->ma21?->toFixed(12));
            $snapshot->setValue('meta', $snapshotDto->meta);
            $snapshot->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            $this->snapshotRepository->upsert($snapshot);
        }

        public function getListFromKlines(array $klines): ListIndicatorDto
        {
            // Helper to extract value from dto or array
            $get = function($kline, string $field) {
                if (is_object($kline)) {
                    $value = method_exists($kline, 'get' . ucfirst($field))
                        ? $kline->{'get' . ucfirst($field)}()
                        : (property_exists($kline, $field) ? $kline->$field : null);
                    if ($value instanceof \Brick\Math\BigDecimal) {
                        return (float)$value->toFloat();
                    }
                    if ($value instanceof \DateTimeInterface) {
                        return $value; // timestamps not needed here
                    }
                    return is_numeric($value) ? (float)$value : $value;
                }
                return isset($kline[$field]) ? (is_numeric($kline[$field]) ? (float)$kline[$field] : $kline[$field]) : null;
            };

            $closes = array_map(fn($k) => (float)$get($k, 'close'), $klines);
            $highs  = array_map(fn($k) => (float)$get($k, 'high'), $klines);
            $lows   = array_map(fn($k) => (float)$get($k, 'low'), $klines);
            $vols   = array_map(fn($k) => (float)$get($k, 'volume'), $klines);

            $rsi = $this->rsiService->calculate($closes, 14);
            $ema20 = $this->emaService->calculate($closes, 20);
            $ema50 = $this->emaService->calculate($closes, 50);
            $ema200 = $this->emaService->calculate($closes, 200);
            $sma9  = $this->smaService->calculate($closes, 9);
            $sma21 = $this->smaService->calculate($closes, 21);
            $macd = $this->macdService->calculate($closes, 12, 26, 9);
            $adx = $this->adxService->calculate($highs, $lows, $closes, 14);
            $boll = $this->bollService->calculate($closes, 20, 2.0);
            $vwap = $this->vwapService->calculate($highs, $lows, $closes, $vols);

            // ATR service expects OHLC arrays
            $ohlc = [];
            $n = min(count($highs), count($lows), count($closes));
            for ($i = 0; $i < $n; $i++) {
                $ohlc[] = ['high' => $highs[$i], 'low' => $lows[$i], 'close' => $closes[$i]];
            }
            $atr = $this->atrService->compute($ohlc, 14, 'wilder');

            $stochRsi = $this->stochRsiService->calculate($closes, 14, 14, 3, 3);

            $indicators = [
                'rsi' => $rsi,
                'ema' => [20 => $ema20, 50 => $ema50, 200 => $ema200],
                'sma' => [9 => $sma9, 21 => $sma21],
                'macd' => $macd,
                'bollinger' => $boll,
                'atr' => $atr,
                'vwap' => $vwap,
                'adx' => $adx,
                'stoch_rsi' => $stochRsi,
            ];

            $descriptions = [
                'rsi' => $this->rsiService->getDescription(false),
                'ema' => $this->emaService->getDescription(false),
                'sma' => $this->smaService->getDescription(false),
                'macd' => $this->macdService->getDescription(false),
                'bollinger' => $this->bollService->getDescription(false),
                'atr' => $this->atrService->getDescription(false),
                'vwap' => $this->vwapService->getDescription(false),
                'adx' => $this->adxService->getDescription(false),
                'stoch_rsi' => $this->stochRsiService->getDescription(false),
            ];

            // Return combined DTO (indicators + descriptions)
            return new ListIndicatorDto(indicators: $indicators, descriptions: $descriptions);
        }

        public function evaluateConditions(string $symbol, string $timeframe): array
        {
            // 1️⃣ Convertit le timeframe string en enum
            $timeframeEnum = Timeframe::from($timeframe);

            // 2️⃣ Récupère les klines normalisées
            $klines = $this->klineProvider->getKlines($symbol, $timeframeEnum, 150);

            // 2️⃣ Convertit les klines en format array pour le contexte
            $klinesArray = [];
            foreach ($klines as $kline) {
                $klinesArray[] = [
                    'open' => $kline->open->toFloat(),
                    'high' => $kline->high->toFloat(),
                    'low' => $kline->low->toFloat(),
                    'close' => $kline->close->toFloat(),
                    'volume' => $kline->volume->toFloat(),
                    'open_time' => $kline->openTime->getTimestamp()
                ];
            }

            // 3️⃣ Construit un contexte d'évaluation
            $context = new EvaluationContext($symbol, $timeframe, $klinesArray);

            // 4️⃣ Récupère toutes les conditions disponibles
            $conditionNames = $this->conditionRegistry->names();

            // 5️⃣ Exécute chaque condition enregistrée
            $results = [];
            foreach ($conditionNames as $name) {
                try {
                    /** @var ConditionInterface $condition */
                    $condition = $this->conditionRegistry->get($name);
                    $result = $condition->evaluate($context->toArray());
                    $results[$name] = $result;
                } catch (\Exception $e) {
                    // Log l'erreur et continue avec les autres conditions
                    error_log("Erreur condition $name: " . $e->getMessage());
                    continue;
                }
            }

            return $results;
        }
    }
