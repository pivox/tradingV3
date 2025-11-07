<?php

declare(strict_types=1);

namespace App\Indicator\Provider;

use App\Common\Dto\IndicatorSnapshotDto;
use App\Common\Enum\Timeframe;
use App\Contract\Indicator\Dto\ListIndicatorDto;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Entity\IndicatorSnapshot;
use Brick\Math\RoundingMode;
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
        private array $atrList = [];
        /** @var array<string,array{dto: ListIndicatorDto,last_kline_time: \DateTimeInterface}> */
        private array $listPivot = [];

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

        /**
         * @return array<string,array<string,mixed>>
         */
        private function indicatorCatalog(): array
        {
            return [
                'rsi' => [
                    'description' => $this->rsiService->getDescription(false),
                    'periods' => [14],
                    'outputs' => ['rsi'],
                ],
                'ema' => [
                    'description' => $this->emaService->getDescription(false),
                    'periods' => [20, 50, 200],
                    'outputs' => [
                        ['key' => 'ema', 'period' => 20],
                        ['key' => 'ema', 'period' => 50],
                        ['key' => 'ema', 'period' => 200],
                    ],
                ],
                'sma' => [
                    'description' => $this->smaService->getDescription(false),
                    'periods' => [9, 21],
                    'outputs' => [
                        ['key' => 'sma', 'period' => 9],
                        ['key' => 'sma', 'period' => 21],
                    ],
                ],
                'macd' => [
                    'description' => $this->macdService->getDescription(false),
                    'parameters' => ['fast' => 12, 'slow' => 26, 'signal' => 9],
                    'outputs' => ['macd', 'signal', 'hist'],
                ],
                'bollinger' => [
                    'description' => $this->bollService->getDescription(false),
                    'parameters' => ['period' => 20, 'deviation' => 2.0],
                    'outputs' => ['upper', 'middle', 'lower'],
                ],
                'atr' => [
                    'description' => $this->atrService->getDescription(false),
                    'parameters' => ['period' => 14, 'method' => 'wilder'],
                    'outputs' => ['atr'],
                ],
                'vwap' => [
                    'description' => $this->vwapService->getDescription(false),
                    'outputs' => ['vwap'],
                ],
                'adx' => [
                    'description' => $this->adxService->getDescription(false),
                    'parameters' => ['period' => 14],
                    'outputs' => ['adx'],
                ],
                'stoch_rsi' => [
                    'description' => $this->stochRsiService->getDescription(false),
                    'parameters' => ['rsi_period' => 14, 'stoch_period' => 14, 'k' => 3, 'd' => 3],
                    'outputs' => ['k', 'd'],
                ],
                'pivot_levels' => [
                    'description' => 'Points pivots classiques (PP, R1-3, S1-3) calculés sur la dernière bougie.',
                    'outputs' => ['pp', 'r1', 'r2', 'r3', 's1', 's2', 's3'],
                ],
            ];
        }

        public function getSnapshot(string $symbol, string $timeframe): IndicatorSnapshotDto
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

            $scale = 12;
            $round = RoundingMode::HALF_UP;
            $snapshot->setEma20($snapshotDto->ema20?->toScale($scale, $round)->__toString());
            $snapshot->setEma50($snapshotDto->ema50?->toScale($scale, $round)->__toString());
            $snapshot->setMacd($snapshotDto->macd?->toScale($scale, $round)->__toString());
            $snapshot->setMacdSignal($snapshotDto->macdSignal?->toScale($scale, $round)->__toString());
            $snapshot->setMacdHistogram($snapshotDto->macdHistogram?->toScale($scale, $round)->__toString());
            $snapshot->setAtr($snapshotDto->atr?->toScale($scale, $round)->__toString());
            $snapshot->setRsi($snapshotDto->rsi);
            $snapshot->setVwap($snapshotDto->vwap?->toScale($scale, $round)->__toString());
            $snapshot->setBbUpper($snapshotDto->bbUpper?->toScale($scale, $round)->__toString());
            $snapshot->setBbMiddle($snapshotDto->bbMiddle?->toScale($scale, $round)->__toString());
            $snapshot->setBbLower($snapshotDto->bbLower?->toScale($scale, $round)->__toString());
            $snapshot->setMa9($snapshotDto->ma9?->toScale($scale, $round)->__toString());
            $snapshot->setMa21($snapshotDto->ma21?->toScale($scale, $round)->__toString());
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

            $pivotLevels = $this->computeClassicPivotLevels($highs, $lows, $closes);

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

            if ($pivotLevels !== null) {
                $indicators['pivot_levels'] = $pivotLevels;
            }

            $descriptions = $this->indicatorCatalog();

            // Return combined DTO (indicators + descriptions)
            return new ListIndicatorDto(indicators: $indicators, descriptions: $descriptions);
        }

        /**
         * @param float[] $highs
         * @param float[] $lows
         * @param float[] $closes
         * @return array<string,float>|null
         */
        private function computeClassicPivotLevels(array $highs, array $lows, array $closes): ?array
        {
            $count = min(count($highs), count($lows), count($closes));
            if ($count === 0) {
                return null;
            }

            $idx = $count - 1;
            $high = $highs[$idx];
            $low = $lows[$idx];
            $close = $closes[$idx];

            if (!is_finite($high) || !is_finite($low) || !is_finite($close)) {
                return null;
            }

            $pp = ($high + $low + $close) / 3.0;
            $range = $high - $low;
            $ppMinusLow = $pp - $low;
            $highMinusPp = $high - $pp;

            // Résistances (R1 à R6)
            $r1 = 2 * $pp - $low;
            $r2 = $pp + $range;
            $r3 = $high + 2 * $ppMinusLow;
            $r4 = $high + 3 * $ppMinusLow;
            $r5 = $high + 4 * $ppMinusLow;
            $r6 = $high + 5 * $ppMinusLow;

            // Supports (S1 à S6)
            $s1 = 2 * $pp - $high;
            $s2 = $pp - $range;
            $s3 = $low - 2 * $highMinusPp;
            $s4 = $low - 3 * $highMinusPp;
            $s5 = $low - 4 * $highMinusPp;
            $s6 = $low - 5 * $highMinusPp;

            return [
                'pp' => $pp,
                'r1' => $r1,
                'r2' => $r2,
                'r3' => $r3,
                'r4' => $r4,
                'r5' => $r5,
                'r6' => $r6,
                's1' => $s1,
                's2' => $s2,
                's3' => $s3,
                's4' => $s4,
                's5' => $s5,
                's6' => $s6,
            ];
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

        public function getAtr(?string $key = null, ?string $symbol = null, ?string $tf = null): ?float
        {
            $period = 14; // défaut (trading.yml)
            $method = 'wilder';

            $cacheKey = sprintf('%s|%s|%s', $key ?? '*', $symbol ?? '*', $tf ?? '*');
            if (array_key_exists($cacheKey, $this->atrList)) {
                $cached = $this->atrList[$cacheKey];
                return is_numeric($cached) ? (float)$cached : null;
            }

            if ($symbol === null || $tf === null) {
                return null;
            }

            try {
                $tfEnum = Timeframe::from((string)$tf);
                $klines = $this->klineProvider->getKlines((string)$symbol, $tfEnum, 200);
                if (empty($klines)) {
                    return null;
                }

                $ohlc = [];
                foreach ($klines as $k) {
                    $ohlc[] = [
                        'high' => (float)$k->high->toFloat(),
                        'low' => (float)$k->low->toFloat(),
                        'close' => (float)$k->close->toFloat(),
                    ];
                }

                $atr = $this->atrService->computeWithRules($ohlc, $period, $method, strtolower((string)$tf));
                if (is_numeric($atr)) {
                    $this->atrList[$cacheKey] = (float)$atr;
                    return (float)$atr;
                }
                return null;
            } catch (\Throwable $e) {
                return null;
            }
        }

            public function getListPivot(?string $key = null, ?string $symbol = null, ?string $tf = null): ?ListIndicatorDto
            {
                $cacheKey = sprintf('%s|%s|%s', $key ?? '*', $symbol ?? '*', $tf ?? '*');
                if ($symbol === null || $tf === null) {
                    return null;
                }

                try {
                    $tfEnum = Timeframe::from((string)$tf);
                } catch (\ValueError) {
                    return null;
                }

                if (isset($this->listPivot[$cacheKey])) {
                    $cached = $this->listPivot[$cacheKey];
                    if ($this->isPivotCacheFresh($cached, $tfEnum)) {
                        return $cached['dto'];
                    }
                }

                try {
                    $klines = $this->klineProvider->getKlines((string)$symbol, $tfEnum, 200);
                    if (empty($klines)) {
                        return null;
                    }

                    $dto = $this->getListFromKlines($klines);
                    $lastKline = end($klines);
                    $lastTime = $this->extractKlineOpenTime($lastKline)
                        ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                    $this->listPivot[$cacheKey] = [
                        'dto' => $dto,
                        'last_kline_time' => $lastTime,
                    ];

                    return $dto;
                } catch (\Throwable $e) {
                    return null;
                }
            }

        public function listAvailableIndicators(): array
        {
            return $this->indicatorCatalog();
        }

        public function clearCaches(): void
        {
            $this->atrList = [];
            $this->listPivot = [];
        }

        /**
         * @param mixed $cacheEntry
         */
        private function isPivotCacheFresh(mixed $cacheEntry, Timeframe $timeframe): bool
        {
            if (!is_array($cacheEntry)) {
                return false;
            }
            if (!isset($cacheEntry['dto'], $cacheEntry['last_kline_time'])) {
                return false;
            }
            if (!$cacheEntry['dto'] instanceof ListIndicatorDto) {
                return false;
            }
            $lastTime = $cacheEntry['last_kline_time'];
            if (!$lastTime instanceof \DateTimeInterface) {
                return false;
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $maxAge = $now->getTimestamp() - $timeframe->getStepInSeconds();

            return $lastTime->getTimestamp() >= $maxAge;
        }

        /**
         * @param mixed $kline
         */
        private function extractKlineOpenTime(mixed $kline): ?\DateTimeImmutable
        {
            if (is_object($kline)) {
                if (property_exists($kline, 'openTime') && $kline->openTime instanceof \DateTimeInterface) {
                    return \DateTimeImmutable::createFromInterface($kline->openTime);
                }
                if (method_exists($kline, 'getOpenTime')) {
                    $candidate = $kline->getOpenTime();
                    if ($candidate instanceof \DateTimeInterface) {
                        return \DateTimeImmutable::createFromInterface($candidate);
                    }
                }
            }

            if (is_array($kline)) {
                $candidate = $kline['openTime'] ?? $kline['open_time'] ?? null;
                if ($candidate instanceof \DateTimeInterface) {
                    return \DateTimeImmutable::createFromInterface($candidate);
                }
                if (is_numeric($candidate)) {
                    $timestamp = (int) $candidate;
                    if ($timestamp > 9999999999) {
                        $timestamp = (int) round($timestamp / 1000);
                    }
                    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
                }
            }

            return null;
        }
    }
