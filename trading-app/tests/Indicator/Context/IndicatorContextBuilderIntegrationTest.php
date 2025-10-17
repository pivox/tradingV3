<?php

namespace App\Tests\Indicator\Context;

use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Condition\ConditionRegistry;
use App\Indicator\Momentum\Rsi;
use App\Indicator\Momentum\Macd;
use App\Indicator\Trend\Ema;
use App\Indicator\Trend\Adx;
use App\Indicator\Volume\Vwap;
use App\Indicator\AtrCalculator;
use PHPUnit\Framework\TestCase;

class IndicatorContextBuilderIntegrationTest extends TestCase
{
    private IndicatorContextBuilder $contextBuilder;
    private ConditionRegistry $conditionRegistry;

    protected function setUp(): void
    {
        // Créer les dépendances nécessaires
        $rsi = new Rsi();
        $macd = new Macd();
        $ema = new Ema();
        $adx = new Adx();
        $vwap = new Vwap();
        $atrCalc = new AtrCalculator();

        $this->contextBuilder = new IndicatorContextBuilder($rsi, $macd, $ema, $adx, $vwap, $atrCalc);
        $this->conditionRegistry = new ConditionRegistry();
    }

    public function testBuildContextWithRealisticData(): void
    {
        // Créer un contexte avec des données réalistes de trading
        $context = $this->contextBuilder
            ->symbol('BTCUSDT')
            ->timeframe('1h')
            ->closes([
                50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900,
                51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900,
                52000, 52100, 52200, 52300, 52400, 52500, 52600, 52700, 52800, 52900,
                53000, 53100, 53200, 53300, 53400, 53500, 53600, 53700, 53800, 53900,
                54000, 54100, 54200, 54300, 54400, 54500, 54600, 54700, 54800, 54900,
                55000, 55100, 55200, 55300, 55400, 55500, 55600, 55700, 55800, 55900,
                56000, 56100, 56200, 56300, 56400, 56500, 56600, 56700, 56800, 56900,
                57000, 57100, 57200, 57300, 57400, 57500, 57600, 57700, 57800, 57900,
                58000, 58100, 58200, 58300, 58400, 58500, 58600, 58700, 58800, 58900,
                59000, 59100, 59200, 59300, 59400, 59500, 59600, 59700, 59800, 59900
            ])
            ->highs([
                50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000,
                51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900, 52000,
                52100, 52200, 52300, 52400, 52500, 52600, 52700, 52800, 52900, 53000,
                53100, 53200, 53300, 53400, 53500, 53600, 53700, 53800, 53900, 54000,
                54100, 54200, 54300, 54400, 54500, 54600, 54700, 54800, 54900, 55000,
                55100, 55200, 55300, 55400, 55500, 55600, 55700, 55800, 55900, 56000,
                56100, 56200, 56300, 56400, 56500, 56600, 56700, 56800, 56900, 57000,
                57100, 57200, 57300, 57400, 57500, 57600, 57700, 57800, 57900, 58000,
                58100, 58200, 58300, 58400, 58500, 58600, 58700, 58800, 58900, 59000,
                59100, 59200, 59300, 59400, 59500, 59600, 59700, 59800, 59900, 60000
            ])
            ->lows([
                49900, 50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800,
                50900, 51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800,
                51900, 52000, 52100, 52200, 52300, 52400, 52500, 52600, 52700, 52800,
                52900, 53000, 53100, 53200, 53300, 53400, 53500, 53600, 53700, 53800,
                53900, 54000, 54100, 54200, 54300, 54400, 54500, 54600, 54700, 54800,
                54900, 55000, 55100, 55200, 55300, 55400, 55500, 55600, 55700, 55800,
                55900, 56000, 56100, 56200, 56300, 56400, 56500, 56600, 56700, 56800,
                56900, 57000, 57100, 57200, 57300, 57400, 57500, 57600, 57700, 57800,
                57900, 58000, 58100, 58200, 58300, 58400, 58500, 58600, 58700, 58800,
                58900, 59000, 59100, 59200, 59300, 59400, 59500, 59600, 59700, 59800
            ])
            ->volumes([
                1000, 1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900,
                2000, 2100, 2200, 2300, 2400, 2500, 2600, 2700, 2800, 2900,
                3000, 3100, 3200, 3300, 3400, 3500, 3600, 3700, 3800, 3900,
                4000, 4100, 4200, 4300, 4400, 4500, 4600, 4700, 4800, 4900,
                5000, 5100, 5200, 5300, 5400, 5500, 5600, 5700, 5800, 5900,
                6000, 6100, 6200, 6300, 6400, 6500, 6600, 6700, 6800, 6900,
                7000, 7100, 7200, 7300, 7400, 7500, 7600, 7700, 7800, 7900,
                8000, 8100, 8200, 8300, 8400, 8500, 8600, 8700, 8800, 8900,
                9000, 9100, 9200, 9300, 9400, 9500, 9600, 9700, 9800, 9900,
                10000, 10100, 10200, 10300, 10400, 10500, 10600, 10700, 10800, 10900
            ])
            ->entryPrice(51200.0)
            ->stopLoss(51000.0)
            ->withDefaults()
            ->build();

        // Vérifier que le contexte contient toutes les données nécessaires
        $this->assertArrayHasKey('symbol', $context);
        $this->assertArrayHasKey('timeframe', $context);
        $this->assertArrayHasKey('close', $context);
        $this->assertArrayHasKey('ema', $context);
        $this->assertArrayHasKey('rsi', $context);
        $this->assertArrayHasKey('macd', $context);
        $this->assertArrayHasKey('vwap', $context);
        $this->assertArrayHasKey('atr', $context);
        $this->assertArrayHasKey('adx', $context);

        // Vérifier les valeurs
        $this->assertEquals('BTCUSDT', $context['symbol']);
        $this->assertEquals('1h', $context['timeframe']);
        $this->assertEquals(59900.0, $context['close']); // Dernière valeur
        $this->assertIsFloat($context['rsi']);
        $this->assertIsFloat($context['vwap']);
        $this->assertIsFloat($context['atr']);
        $this->assertIsArray($context['ema']);
        $this->assertIsArray($context['macd']);
        $this->assertIsArray($context['adx']);

        // Vérifier que les EMAs sont calculées
        $this->assertArrayHasKey(20, $context['ema']);
        $this->assertArrayHasKey(50, $context['ema']);
        $this->assertArrayHasKey(200, $context['ema']);
        $this->assertIsFloat($context['ema'][20]);
        $this->assertIsFloat($context['ema'][50]);
        $this->assertIsFloat($context['ema'][200]);

        // Vérifier que le MACD est calculé
        $this->assertArrayHasKey('macd', $context['macd']);
        $this->assertArrayHasKey('signal', $context['macd']);
        $this->assertArrayHasKey('hist', $context['macd']);
        $this->assertIsFloat($context['macd']['macd']);
        $this->assertIsFloat($context['macd']['signal']);
        $this->assertIsFloat($context['macd']['hist']);

        // Vérifier que l'ADX est calculé
        $this->assertArrayHasKey(14, $context['adx']);
        $this->assertIsFloat($context['adx'][14]);

        // Vérifier les paramètres configurables
        $this->assertEquals(51200.0, $context['entry_price']);
        $this->assertEquals(51000.0, $context['stop_loss']);
        $this->assertEquals(1.5, $context['atr_k']);
        $this->assertEquals(0.001, $context['min_atr_pct']);
        $this->assertEquals(0.03, $context['max_atr_pct']);
        $this->assertEquals(70.0, $context['rsi_lt_70_threshold']);
        $this->assertEquals(30.0, $context['rsi_cross_up_level']);
        $this->assertEquals(70.0, $context['rsi_cross_down_level']);
    }

    public function testEvaluateAllConditionsWithRealisticData(): void
    {
        // Créer un contexte avec des données réalistes
        $context = $this->contextBuilder
            ->symbol('ETHUSDT')
            ->timeframe('4h')
            ->closes([
                3000, 3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090,
                3100, 3110, 3120, 3130, 3140, 3150, 3160, 3170, 3180, 3190,
                3200, 3210, 3220, 3230, 3240, 3250, 3260, 3270, 3280, 3290,
                3300, 3310, 3320, 3330, 3340, 3350, 3360, 3370, 3380, 3390,
                3400, 3410, 3420, 3430, 3440, 3450, 3460, 3470, 3480, 3490,
                3500, 3510, 3520, 3530, 3540, 3550, 3560, 3570, 3580, 3590,
                3600, 3610, 3620, 3630, 3640, 3650, 3660, 3670, 3680, 3690,
                3700, 3710, 3720, 3730, 3740, 3750, 3760, 3770, 3780, 3790,
                3800, 3810, 3820, 3830, 3840, 3850, 3860, 3870, 3880, 3890,
                3900, 3910, 3920, 3930, 3940, 3950, 3960, 3970, 3980, 3990
            ])
            ->highs([
                3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090, 3100,
                3110, 3120, 3130, 3140, 3150, 3160, 3170, 3180, 3190, 3200,
                3210, 3220, 3230, 3240, 3250, 3260, 3270, 3280, 3290, 3300,
                3310, 3320, 3330, 3340, 3350, 3360, 3370, 3380, 3390, 3400,
                3410, 3420, 3430, 3440, 3450, 3460, 3470, 3480, 3490, 3500,
                3510, 3520, 3530, 3540, 3550, 3560, 3570, 3580, 3590, 3600,
                3610, 3620, 3630, 3640, 3650, 3660, 3670, 3680, 3690, 3700,
                3710, 3720, 3730, 3740, 3750, 3760, 3770, 3780, 3790, 3800,
                3810, 3820, 3830, 3840, 3850, 3860, 3870, 3880, 3890, 3900,
                3910, 3920, 3930, 3940, 3950, 3960, 3970, 3980, 3990, 4000
            ])
            ->lows([
                2990, 3000, 3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080,
                3090, 3100, 3110, 3120, 3130, 3140, 3150, 3160, 3170, 3180,
                3190, 3200, 3210, 3220, 3230, 3240, 3250, 3260, 3270, 3280,
                3290, 3300, 3310, 3320, 3330, 3340, 3350, 3360, 3370, 3380,
                3390, 3400, 3410, 3420, 3430, 3440, 3450, 3460, 3470, 3480,
                3490, 3500, 3510, 3520, 3530, 3540, 3550, 3560, 3570, 3580,
                3590, 3600, 3610, 3620, 3630, 3640, 3650, 3660, 3670, 3680,
                3690, 3700, 3710, 3720, 3730, 3740, 3750, 3760, 3770, 3780,
                3790, 3800, 3810, 3820, 3830, 3840, 3850, 3860, 3870, 3880,
                3890, 3900, 3910, 3920, 3930, 3940, 3950, 3960, 3970, 3980
            ])
            ->volumes([
                500, 550, 600, 650, 700, 750, 800, 850, 900, 950,
                1000, 1050, 1100, 1150, 1200, 1250, 1300, 1350, 1400, 1450,
                1500, 1550, 1600, 1650, 1700, 1750, 1800, 1850, 1900, 1950,
                2000, 2050, 2100, 2150, 2200, 2250, 2300, 2350, 2400, 2450,
                2500, 2550, 2600, 2650, 2700, 2750, 2800, 2850, 2900, 2950,
                3000, 3050, 3100, 3150, 3200, 3250, 3300, 3350, 3400, 3450,
                3500, 3550, 3600, 3650, 3700, 3750, 3800, 3850, 3900, 3950,
                4000, 4050, 4100, 4150, 4200, 4250, 4300, 4350, 4400, 4450,
                4500, 4550, 4600, 4650, 4700, 4750, 4800, 4850, 4900, 4950,
                5000, 5050, 5100, 5150, 5200, 5250, 5300, 5350, 5400, 5450
            ])
            ->withDefaults()
            ->build();

        // Évaluer toutes les conditions
        $results = $this->conditionRegistry->evaluate($context);

        // Vérifier que toutes les conditions ont été évaluées
        $this->assertNotEmpty($results, 'Aucune condition n\'a été évaluée');

        // Vérifier la structure des résultats
        foreach ($results as $conditionName => $result) {
            $this->assertIsArray($result, "Le résultat de la condition '$conditionName' doit être un tableau");
            $this->assertArrayHasKey('name', $result, "Le résultat de '$conditionName' doit avoir une clé 'name'");
            $this->assertArrayHasKey('passed', $result, "Le résultat de '$conditionName' doit avoir une clé 'passed'");
            $this->assertArrayHasKey('value', $result, "Le résultat de '$conditionName' doit avoir une clé 'value'");
            $this->assertArrayHasKey('threshold', $result, "Le résultat de '$conditionName' doit avoir une clé 'threshold'");
            $this->assertArrayHasKey('meta', $result, "Le résultat de '$conditionName' doit avoir une clé 'meta'");
            
            $this->assertIsBool($result['passed'], "Le champ 'passed' de '$conditionName' doit être un booléen");
            $this->assertIsArray($result['meta'], "Le champ 'meta' de '$conditionName' doit être un tableau");
        }

        // Afficher un résumé pour debug
        $this->displayResultsSummary($results);
    }

    public function testContextWithInsufficientData(): void
    {
        // Créer un contexte avec des données insuffisantes
        $context = $this->contextBuilder
            ->symbol('ADAUSDT')
            ->timeframe('1d')
            ->closes([1.0, 1.1, 1.2, 1.3, 1.4]) // Seulement 5 valeurs
            ->highs([1.1, 1.2, 1.3, 1.4, 1.5])
            ->lows([0.9, 1.0, 1.1, 1.2, 1.3])
            ->volumes([100, 110, 120, 130, 140])
            ->withDefaults()
            ->build();

        // Vérifier que le contexte est créé même avec des données insuffisantes
        $this->assertArrayHasKey('symbol', $context);
        $this->assertEquals('ADAUSDT', $context['symbol']);
        $this->assertEquals('1d', $context['timeframe']);
        $this->assertEquals(1.4, $context['close']);

        // Les indicateurs qui nécessitent plus de données devraient être null
        $this->assertNull($context['ema']); // Pas assez de données pour EMA 20/50/200
        $this->assertNull($context['macd']); // Pas assez de données pour MACD
        $this->assertNull($context['rsi']); // Pas assez de données pour RSI
        $this->assertNull($context['adx']); // Pas assez de données pour ADX
    }

    private function displayResultsSummary(array $results): void
    {
        $total = count($results);
        $passed = 0;
        $failed = 0;
        $errors = 0;

        foreach ($results as $name => $result) {
            if (isset($result['meta']['error']) && $result['meta']['error']) {
                $errors++;
            } elseif ($result['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        echo "\n=== RÉSUMÉ DES CONDITIONS (INTÉGRATION) ===\n";
        echo "Total: $total\n";
        echo "Passées: $passed\n";
        echo "Échouées: $failed\n";
        echo "Erreurs: $errors\n";
        echo "Taux de réussite: " . ($total > 0 ? round(($passed / $total) * 100, 2) : 0) . "%\n";
        echo "==========================================\n";
    }
}

