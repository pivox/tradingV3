<?php

declare(strict_types=1);

namespace App\Indicator\Command;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use App\Contract\Indicator\IndicatorMainProviderInterface;
use App\Contract\Indicator\IndicatorProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:indicators:get',
    description: 'Récupère les indicateurs techniques pour un symbole et timeframe donnés'
)]
final class GetIndicatorsCommand extends Command
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly IndicatorMainProviderInterface $indicatorMain,
        private readonly IndicatorProviderInterface $indicatorProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole de trading (ex: BTC_USDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (ex: 1h, 4h, 1d)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à récupérer', 100)
            ->addOption('conditions', 'c', InputOption::VALUE_OPTIONAL, 'Évaluer des conditions spécifiques (séparées par des virgules)')
            ->addOption('all-conditions', 'a', InputOption::VALUE_NONE, 'Évaluer toutes les conditions disponibles')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Format de sortie (json, table)', 'table')
            ->addOption('exchange', null, InputOption::VALUE_OPTIONAL, 'Identifiant de l\'exchange (ex: bitmart)')
            ->addOption('market-type', null, InputOption::VALUE_OPTIONAL, 'Type de marché (perpetual|spot)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframeStr = strtolower($input->getArgument('timeframe'));
        $limit = (int) $input->getOption('limit');
        $format = $input->getOption('format');

        try {
            // Validation du timeframe
            $timeframe = Timeframe::from($timeframeStr);
        } catch (\ValueError $e) {
            $io->error("Timeframe invalide: {$timeframeStr}. Valeurs acceptées: " . implode(', ', array_column(Timeframe::cases(), 'value')));
            return Command::FAILURE;
        }

        $io->info("Récupération des klines pour {$symbol} sur {$timeframe->value}...");

        try {
            // Résoudre le contexte (défaut Bitmart/Perpetual) et récupérer les klines
            $exchangeOpt = $input->getOption('exchange');
            $marketTypeOpt = $input->getOption('market-type');
            $exchange = Exchange::BITMART;
            if (is_string($exchangeOpt) && $exchangeOpt !== '') {
                $exchange = match (strtolower(trim($exchangeOpt))) {
                    'bitmart' => Exchange::BITMART,
                    default => Exchange::BITMART,
                };
            }
            $marketType = MarketType::PERPETUAL;
            if (is_string($marketTypeOpt) && $marketTypeOpt !== '') {
                $marketType = match (strtolower(trim($marketTypeOpt))) {
                    'perpetual', 'perp', 'future', 'futures' => MarketType::PERPETUAL,
                    'spot' => MarketType::SPOT,
                    default => MarketType::PERPETUAL,
                };
            }
            $context = new ExchangeContext($exchange, $marketType);
            $klineProvider = $this->mainProvider->forContext($context)->getKlineProvider();
            $klines = $klineProvider->getKlines($symbol, $timeframe, $limit);

            if (empty($klines)) {
                $io->error("Aucune kline trouvée pour {$symbol} sur {$timeframe->value}");
                return Command::FAILURE;
            }

            $io->success(sprintf("Récupéré %d klines", count($klines)));

            // Affichage des klines récupérées
            $this->displayKlinesInfo($io, $klines);

            // Calcul via IndicatorProvider (DTO)
            $listDto = $this->indicatorProvider->getListFromKlines($klines);
            $indicators = $listDto->toArray();
            $descriptions = $listDto->getDescriptions();

            // Construction d'un contexte enrichi avec indicateurs
            $context = $this->buildSimpleContext($symbol, $timeframe->value, $klines, $indicators);

            // Affichage des indicateurs techniques
            $this->displayTechnicalIndicators($io, $indicators, $descriptions);

            // Évaluation des conditions si demandée
            $conditionsToEvaluate = $this->getConditionsToEvaluate($input);
            if (!empty($conditionsToEvaluate)) {
                $this->evaluateConditions($io, $context, $conditionsToEvaluate, $format);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Erreur lors de la récupération des données: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function buildSimpleContext(string $symbol, string $timeframe, array $klines, array $indicators): array
    {
        // Extraction des données OHLCV (gérer les objets DTO et BigDecimal)
        $getValue = function($kline, $property) {
            if (is_object($kline)) {
                $value = method_exists($kline, 'get' . ucfirst($property)) ? $kline->{'get' . ucfirst($property)}() :
                        (property_exists($kline, $property) ? $kline->$property : null);

                // Convertir BigDecimal en float
                if ($value instanceof \Brick\Math\BigDecimal) {
                    return (float) $value->toFloat();
                }

                return $value;
            }
            return $kline[$property] ?? null;
        };

        $closes = array_map(fn($k) => (float) $getValue($k, 'close'), $klines);
        $highs = array_map(fn($k) => (float) $getValue($k, 'high'), $klines);
        $lows = array_map(fn($k) => (float) $getValue($k, 'low'), $klines);
        $volumes = array_map(fn($k) => (float) $getValue($k, 'volume'), $klines);

        // Construction d'un contexte enrichi compatible avec les conditions
        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'close' => end($closes),
            'high' => end($highs),
            'low' => end($lows),
            'volume' => end($volumes),
            'closes' => $closes,
            'highs' => $highs,
            'lows' => $lows,
            'volumes' => $volumes,
            'indicators' => $indicators,
            // Structure compatible avec les conditions existantes
            'rsi' => $indicators['rsi'],
            'ema' => $indicators['ema'],
            'macd' => $indicators['macd'],
            'atr' => $indicators['atr'],
            'vwap' => $indicators['vwap'],
            'adx' => $indicators['adx'],
        ];
    }

    private function displayKlinesInfo(SymfonyStyle $io, array $klines): void
    {
        $io->section('Informations sur les Klines');

        $firstKline = reset($klines);
        $lastKline = end($klines);

        // Gérer les objets DTO et BigDecimal
        $getValue = function($kline, $property) {
            if (is_object($kline)) {
                $value = method_exists($kline, 'get' . ucfirst($property)) ? $kline->{'get' . ucfirst($property)}() :
                        (property_exists($kline, $property) ? $kline->$property : null);

                // Convertir BigDecimal en float
                if ($value instanceof \Brick\Math\BigDecimal) {
                    return (float) $value->toFloat();
                }

                return $value;
            }
            return $kline[$property] ?? null;
        };

        $formatValue = function($value) {
            if ($value instanceof \DateTimeImmutable) {
                return $value->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s');
            }
            return $value ?? 'N/A';
        };

        $info = [
            'Nombre de klines' => count($klines),
            'Première kline' => $formatValue($getValue($firstKline, 'openTime')),
            'Dernière kline' => $formatValue($getValue($lastKline, 'openTime')),
            'Prix d\'ouverture' => $formatValue($getValue($firstKline, 'open')),
            'Prix de clôture' => $formatValue($getValue($lastKline, 'close')),
            'Prix le plus haut' => $formatValue($getValue($lastKline, 'high')),
            'Prix le plus bas' => $formatValue($getValue($lastKline, 'low')),
            'Volume' => $formatValue($getValue($lastKline, 'volume')),
        ];

        $io->table(['Information', 'Valeur'], array_map(fn($k, $v) => [$k, $v], array_keys($info), $info));
    }

    // plus de calcul local: tout est géré par IndicatorProvider

    private function calculateWithTraderExtension(array $closes, array $highs, array $lows, array $volumes): array
    {
        $indicators = [];

        // RSI (14 périodes)
        $rsi = trader_rsi($closes, 14);
        $indicators['rsi'] = $rsi ? end($rsi) : null;

        // EMA (20, 50, 200 périodes)
        $ema20 = trader_ema($closes, 20);
        $ema50 = trader_ema($closes, 50);
        $ema200 = trader_ema($closes, 200);
        $indicators['ema'] = [
            20 => $ema20 ? end($ema20) : null,
            50 => $ema50 ? end($ema50) : null,
            200 => $ema200 ? end($ema200) : null,
        ];

        // MACD (12, 26, 9)
        $macd = trader_macd($closes, 12, 26, 9);
        if ($macd) {
            $indicators['macd'] = [
                'macd' => end($macd[0]) ?: null,
                'signal' => end($macd[1]) ?: null,
                'hist' => end($macd[2]) ?: null,
            ];
        } else {
            $indicators['macd'] = ['macd' => null, 'signal' => null, 'hist' => null];
        }

        // ATR (14 périodes)
        $atr = trader_atr($highs, $lows, $closes, 14);
        $indicators['atr'] = $atr ? end($atr) : null;

        // Bollinger Bands (20 périodes, 2 écarts-types)
        $bb = trader_bbands($closes, 20, 2.0, 2.0, TRADER_MA_TYPE_SMA);
        if ($bb) {
            $indicators['bollinger'] = [
                'upper' => end($bb[0]) ?: null,
                'middle' => end($bb[1]) ?: null,
                'lower' => end($bb[2]) ?: null,
            ];
        } else {
            $indicators['bollinger'] = ['upper' => null, 'middle' => null, 'lower' => null];
        }

        // SMA (9, 21 périodes)
        $sma9 = trader_sma($closes, 9);
        $sma21 = trader_sma($closes, 21);
        $indicators['sma'] = [
            9 => $sma9 ? end($sma9) : null,
            21 => $sma21 ? end($sma21) : null,
        ];

        // VWAP
        $indicators['vwap'] = $this->calculateVWAP($highs, $lows, $closes, $volumes);

        // ADX (14 périodes)
        $adx = trader_adx($highs, $lows, $closes, 14);
        $indicators['adx'] = $adx ? end($adx) : null;

        // Stochastic (14, 3, 3)
        $stoch = trader_stoch($highs, $lows, $closes, 14, 3, TRADER_MA_TYPE_SMA, 3, TRADER_MA_TYPE_SMA);
        if ($stoch) {
            $indicators['stochastic'] = [
                'k' => end($stoch[0]) ?: null,
                'd' => end($stoch[1]) ?: null,
            ];
        } else {
            $indicators['stochastic'] = ['k' => null, 'd' => null];
        }

        return $indicators;
    }

    private function calculateSimpleIndicators(array $closes, array $highs, array $lows, array $volumes): array
    {
        $indicators = [];

        // SMA simples
        $indicators['sma'] = [
            9 => $this->calculateSMA($closes, 9),
            21 => $this->calculateSMA($closes, 21),
        ];

        // EMA simples
        $indicators['ema'] = [
            20 => $this->calculateEMA($closes, 20),
            50 => $this->calculateEMA($closes, 50),
            200 => $this->calculateEMA($closes, 200),
        ];

        // RSI simple
        $indicators['rsi'] = $this->calculateRSI($closes, 14);

        // VWAP
        $indicators['vwap'] = $this->calculateVWAP($highs, $lows, $closes, $volumes);

        // ATR simple
        $indicators['atr'] = $this->calculateATR($highs, $lows, $closes, 14);

        // MACD simple
        $macd = $this->calculateMACD($closes, 12, 26, 9);
        $indicators['macd'] = [
            'macd' => $macd['macd'] ?? null,
            'signal' => $macd['signal'] ?? null,
            'hist' => $macd['hist'] ?? null,
        ];

        // Bollinger Bands simples
        $bb = $this->calculateBollingerBands($closes, 20, 2.0);
        $indicators['bollinger'] = [
            'upper' => $bb['upper'] ?? null,
            'middle' => $bb['middle'] ?? null,
            'lower' => $bb['lower'] ?? null,
        ];

        // ADX simple
        $indicators['adx'] = $this->calculateADX($highs, $lows, $closes, 14);

        // Stochastic simple
        $stoch = $this->calculateStochastic($highs, $lows, $closes, 14, 3, 3);
        $indicators['stochastic'] = [
            'k' => $stoch['k'] ?? null,
            'd' => $stoch['d'] ?? null,
        ];

        return $indicators;
    }

    private function calculateVWAP(array $highs, array $lows, array $closes, array $volumes): ?float
    {
        if (count($highs) !== count($lows) || count($lows) !== count($closes) || count($closes) !== count($volumes)) {
            return null;
        }

        $totalVolume = 0;
        $totalVolumePrice = 0;

        for ($i = 0; $i < count($closes); $i++) {
            $typicalPrice = ($highs[$i] + $lows[$i] + $closes[$i]) / 3;
            $totalVolumePrice += $typicalPrice * $volumes[$i];
            $totalVolume += $volumes[$i];
        }

        return $totalVolume > 0 ? $totalVolumePrice / $totalVolume : null;
    }

    private function calculateSMA(array $prices, int $period): ?float
    {
        if (count($prices) < $period) {
            return null;
        }

        $slice = array_slice($prices, -$period);
        return array_sum($slice) / $period;
    }

    private function calculateEMA(array $prices, int $period): ?float
    {
        if (count($prices) < $period) {
            return null;
        }

        $multiplier = 2 / ($period + 1);
        $ema = $prices[0];

        for ($i = 1; $i < count($prices); $i++) {
            $ema = ($prices[$i] * $multiplier) + ($ema * (1 - $multiplier));
        }

        return $ema;
    }

    private function calculateRSI(array $prices, int $period): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($prices); $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? abs($change) : 0;
        }

        if (count($gains) < $period) {
            return null;
        }

        $avgGain = array_sum(array_slice($gains, -$period)) / $period;
        $avgLoss = array_sum(array_slice($losses, -$period)) / $period;

        if ($avgLoss == 0) {
            return 100;
        }

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    private function calculateATR(array $highs, array $lows, array $closes, int $period): ?float
    {
        if (count($highs) < $period + 1) {
            return null;
        }

        $trueRanges = [];
        for ($i = 1; $i < count($highs); $i++) {
            $tr1 = $highs[$i] - $lows[$i];
            $tr2 = abs($highs[$i] - $closes[$i - 1]);
            $tr3 = abs($lows[$i] - $closes[$i - 1]);
            $trueRanges[] = max($tr1, $tr2, $tr3);
        }

        if (count($trueRanges) < $period) {
            return null;
        }

        return array_sum(array_slice($trueRanges, -$period)) / $period;
    }

    private function calculateMACD(array $prices, int $fastPeriod, int $slowPeriod, int $signalPeriod): array
    {
        if (count($prices) < $slowPeriod) {
            return ['macd' => null, 'signal' => null, 'hist' => null];
        }

        // Calculer les EMA rapide et lente
        $emaFast = $this->calculateEMA($prices, $fastPeriod);
        $emaSlow = $this->calculateEMA($prices, $slowPeriod);

        if ($emaFast === null || $emaSlow === null) {
            return ['macd' => null, 'signal' => null, 'hist' => null];
        }

        $macd = $emaFast - $emaSlow;

        // Pour calculer le signal, nous avons besoin de l'historique des valeurs MACD
        // Calculons un MACD complet avec historique
        $macdHistory = $this->calculateMACDHistory($prices, $fastPeriod, $slowPeriod);

        if (empty($macdHistory)) {
            return [
                'macd' => $macd,
                'signal' => null,
                'hist' => null
            ];
        }

        // Calculer l'EMA du MACD pour le signal
        $signal = $this->calculateEMA($macdHistory, $signalPeriod);
        $histogram = $signal !== null ? $macd - $signal : null;

        return [
            'macd' => $macd,
            'signal' => $signal,
            'hist' => $histogram
        ];
    }

    private function calculateMACDHistory(array $prices, int $fastPeriod, int $slowPeriod): array
    {
        if (count($prices) < $slowPeriod) {
            return [];
        }

        $macdHistory = [];

        // Calculer les EMA pour chaque période
        for ($i = $slowPeriod - 1; $i < count($prices); $i++) {
            $slice = array_slice($prices, 0, $i + 1);

            $emaFast = $this->calculateEMA($slice, $fastPeriod);
            $emaSlow = $this->calculateEMA($slice, $slowPeriod);

            if ($emaFast !== null && $emaSlow !== null) {
                $macdHistory[] = $emaFast - $emaSlow;
            }
        }

        return $macdHistory;
    }

    private function calculateBollingerBands(array $prices, int $period, float $stdDev): array
    {
        if (count($prices) < $period) {
            return ['upper' => null, 'middle' => null, 'lower' => null];
        }

        $slice = array_slice($prices, -$period);
        $middle = array_sum($slice) / $period;

        $variance = 0;
        foreach ($slice as $price) {
            $variance += pow($price - $middle, 2);
        }
        $variance /= $period;
        $std = sqrt($variance);

        return [
            'upper' => $middle + ($std * $stdDev),
            'middle' => $middle,
            'lower' => $middle - ($std * $stdDev)
        ];
    }

    private function calculateADX(array $highs, array $lows, array $closes, int $period): ?float
    {
        // Calcul ADX simplifié - pour une implémentation complète, il faudrait plus de complexité
        if (count($highs) < $period + 1) {
            return null;
        }

        // Calcul simplifié basé sur la volatilité
        $ranges = [];
        for ($i = 1; $i < count($highs); $i++) {
            $ranges[] = $highs[$i] - $lows[$i];
        }

        if (count($ranges) < $period) {
            return null;
        }

        $avgRange = array_sum(array_slice($ranges, -$period)) / $period;
        $maxRange = max($ranges);

        return $maxRange > 0 ? min(100, ($avgRange / $maxRange) * 100) : null;
    }

    private function calculateStochastic(array $highs, array $lows, array $closes, int $kPeriod, int $kSmooth, int $dSmooth): array
    {
        if (count($highs) < $kPeriod) {
            return ['k' => null, 'd' => null];
        }

        $sliceHighs = array_slice($highs, -$kPeriod);
        $sliceLows = array_slice($lows, -$kPeriod);
        $currentClose = end($closes);

        $highestHigh = max($sliceHighs);
        $lowestLow = min($sliceLows);

        if ($highestHigh == $lowestLow) {
            return ['k' => 50, 'd' => 50]; // Neutre si pas de range
        }

        $k = (($currentClose - $lowestLow) / ($highestHigh - $lowestLow)) * 100;

        return [
            'k' => $k,
            'd' => $k // Simplifié - normalement ce serait l'EMA de K
        ];
    }

    private function displayTechnicalIndicators(SymfonyStyle $io, array $indicators, array $descriptions = []): void
    {
        $io->section('Indicateurs Techniques');

        $tableData = [];

        $extractDesc = static function (array $catalog, string $key, string $fallback = ''): string {
            $value = $catalog[$key] ?? null;
            if (is_array($value)) {
                return (string)($value['description'] ?? $fallback);
            }
            if ($value === null) {
                return $fallback;
            }
            return (string)$value;
        };

        $descRsi   = $extractDesc($descriptions, 'rsi');
        $descEma   = $extractDesc($descriptions, 'ema');
        $descMacd  = $extractDesc($descriptions, 'macd');
        $descBoll  = $extractDesc($descriptions, 'bollinger');
        $descAtr   = $extractDesc($descriptions, 'atr');
        $descVwap  = $extractDesc($descriptions, 'vwap');
        $descAdx   = $extractDesc($descriptions, 'adx');
        $descStoch = $extractDesc($descriptions, 'stoch_rsi');
        $descSma   = $extractDesc($descriptions, 'sma', 'SMA: moyenne mobile simple (moyenne arithmétique).');

        // RSI
        $tableData[] = ['RSI (14)', $indicators['rsi'] ? number_format($indicators['rsi'], 2) : 'N/A', $descRsi];

        // EMAs
        $tableData[] = ['EMA 20', $indicators['ema'][20] ? number_format($indicators['ema'][20], 8) : 'N/A', $descEma];
        $tableData[] = ['EMA 50', $indicators['ema'][50] ? number_format($indicators['ema'][50], 8) : 'N/A', $descEma];
        $tableData[] = ['EMA 200', $indicators['ema'][200] ? number_format($indicators['ema'][200], 8) : 'N/A', $descEma];

        // SMAs
        $tableData[] = ['SMA 9', $indicators['sma'][9] ? number_format($indicators['sma'][9], 8) : 'N/A', $descSma];
        $tableData[] = ['SMA 21', $indicators['sma'][21] ? number_format($indicators['sma'][21], 8) : 'N/A', $descSma];

        // MACD
        $tableData[] = ['MACD', $indicators['macd']['macd'] ? number_format($indicators['macd']['macd'], 8) : 'N/A', $descMacd];
        $tableData[] = ['MACD Signal', $indicators['macd']['signal'] ? number_format($indicators['macd']['signal'], 8) : 'N/A', $descMacd];
        $tableData[] = ['MACD Histogram', $indicators['macd']['hist'] ? number_format($indicators['macd']['hist'], 8) : 'N/A', $descMacd];

        // Bollinger Bands
        $tableData[] = ['BB Upper', $indicators['bollinger']['upper'] ? number_format($indicators['bollinger']['upper'], 8) : 'N/A', $descBoll];
        $tableData[] = ['BB Middle', $indicators['bollinger']['middle'] ? number_format($indicators['bollinger']['middle'], 8) : 'N/A', $descBoll];
        $tableData[] = ['BB Lower', $indicators['bollinger']['lower'] ? number_format($indicators['bollinger']['lower'], 8) : 'N/A', $descBoll];

        // ATR
        $tableData[] = ['ATR (14)', $indicators['atr'] ? number_format($indicators['atr'], 8) : 'N/A', $descAtr];

        // VWAP
        $tableData[] = ['VWAP', $indicators['vwap'] ? number_format($indicators['vwap'], 8) : 'N/A', $descVwap];

        // ADX
        $tableData[] = ['ADX (14)', $indicators['adx'] ? number_format($indicators['adx'], 2) : 'N/A', $descAdx];

        // StochRSI (si disponible)
        if (isset($indicators['stoch_rsi'])) {
            $tableData[] = ['StochRSI %K', isset($indicators['stoch_rsi']['k']) && $indicators['stoch_rsi']['k'] !== null ? number_format($indicators['stoch_rsi']['k'], 2) : 'N/A', $descStoch];
            $tableData[] = ['StochRSI %D', isset($indicators['stoch_rsi']['d']) && $indicators['stoch_rsi']['d'] !== null ? number_format($indicators['stoch_rsi']['d'], 2) : 'N/A', $descStoch];
        }

        $io->table(['Indicateur', 'Valeur', 'Description'], $tableData);

        // Analyse des signaux
        $this->displaySignalAnalysis($io, $indicators);
    }

    private function displaySignalAnalysis(SymfonyStyle $io, array $indicators): void
    {
        $io->section('Analyse des Signaux');

        $signals = [];

        // Analyse RSI
        if ($indicators['rsi'] !== null) {
            if ($indicators['rsi'] > 70) {
                $signals[] = ['RSI', 'Surachat (>70)', '🔴'];
            } elseif ($indicators['rsi'] < 30) {
                $signals[] = ['RSI', 'Survente (<30)', '🟢'];
            } else {
                $signals[] = ['RSI', 'Neutre (30-70)', '🟡'];
            }
        }

        // Analyse MACD
        if ($indicators['macd']['macd'] !== null && $indicators['macd']['signal'] !== null) {
            if ($indicators['macd']['macd'] > $indicators['macd']['signal']) {
                $signals[] = ['MACD', 'Haussier (MACD > Signal)', '🟢'];
            } else {
                $signals[] = ['MACD', 'Baissier (MACD < Signal)', '🔴'];
            }
        }

        // Analyse des moyennes mobiles
        if ($indicators['ema'][20] !== null && $indicators['ema'][50] !== null) {
            if ($indicators['ema'][20] > $indicators['ema'][50]) {
                $signals[] = ['EMA', 'Tendance haussière (EMA20 > EMA50)', '🟢'];
            } else {
                $signals[] = ['EMA', 'Tendance baissière (EMA20 < EMA50)', '🔴'];
            }
        }

        // Analyse ADX
        if ($indicators['adx'] !== null) {
            if ($indicators['adx'] > 25) {
                $signals[] = ['ADX', 'Tendance forte (>25)', '🟢'];
            } elseif ($indicators['adx'] > 20) {
                $signals[] = ['ADX', 'Tendance modérée (20-25)', '🟡'];
            } else {
                $signals[] = ['ADX', 'Tendance faible (<20)', '🔴'];
            }
        }

        if (!empty($signals)) {
            $io->table(['Indicateur', 'Signal', 'Statut'], $signals);
        } else {
            $io->info('Aucun signal disponible (données insuffisantes)');
        }
    }

    private function getConditionsToEvaluate(InputInterface $input): array
    {
        $conditions = [];

        if ($input->getOption('all-conditions')) {
            $conditions = $this->indicatorMain->getEngine()->listConditionNames();
        } elseif ($input->getOption('conditions')) {
            $conditions = array_map('trim', explode(',', $input->getOption('conditions')));
        }

        return $conditions;
    }

    private function evaluateConditions(SymfonyStyle $io, array $context, array $conditions, string $format): void
    {
        $io->section('Évaluation des Conditions');

        try {
            $results = $this->indicatorMain->getEngine()->evaluateConditions($context, $conditions);

            if ($format === 'json') {
                $io->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->displayConditionsTable($io, $results);
            }

        } catch (\Exception $e) {
            $io->error("Erreur lors de l'évaluation des conditions: " . $e->getMessage());
        }
    }

    private function displayConditionsTable(SymfonyStyle $io, array $results): void
    {
        $tableData = [];
        foreach ($results as $name => $result) {
            $status = $result['passed'] ? '✅ PASS' : '❌ FAIL';
            $value = $result['value'] ?? 'N/A';
            $threshold = $result['threshold'] ?? 'N/A';

            $tableData[] = [
                $name,
                $status,
                $value,
                $threshold,
                json_encode($result['meta'] ?? [], JSON_UNESCAPED_UNICODE)
            ];
        }

        $io->table(
            ['Condition', 'Statut', 'Valeur', 'Seuil', 'Métadonnées'],
            $tableData
        );

        $passedCount = count(array_filter($results, fn($r) => $r['passed']));
        $totalCount = count($results);

        $io->info(sprintf("Résumé: %d/%d conditions passées", $passedCount, $totalCount));
    }
}
