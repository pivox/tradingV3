<?php

namespace App\Tests\Indicator\Snapshot;

use App\Common\Enum\Timeframe;
use App\Entity\IndicatorSnapshot;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Volume\Vwap;
use App\Indicator\Registry\ConditionRegistry;
use App\Repository\IndicatorSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class IndicatorSnapshotRegressionTest extends KernelTestCase
{
    private IndicatorContextBuilder $contextBuilder;
    private ConditionRegistry $conditionRegistry;
    private IndicatorSnapshotRepository $snapshotRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        $this->snapshotRepository = $container->get(IndicatorSnapshotRepository::class);

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

    protected function tearDown(): void
    {
        // Nettoyer la base de données après chaque test
        $this->entityManager->createQuery('DELETE FROM App\Entity\IndicatorSnapshot')->execute();
        $this->entityManager->clear();
    }

    public function testCreateAndSaveSnapshot(): void
    {
        // Créer un contexte avec des données réalistes
        $context = $this->createRealisticContext();

        // Créer un snapshot
        $snapshot = new IndicatorSnapshot();
        $snapshot
            ->setSymbol('BTCUSDT')
            ->setTimeframe(Timeframe::H1)
            ->setKlineTime(new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC')))
            ->setValues([
                'ema20' => (string)$context['ema'][20],
                'ema50' => (string)$context['ema'][50],
                'ema200' => (string)$context['ema'][200],
                'rsi' => $context['rsi'],
                'macd' => (string)$context['macd']['macd'],
                'macd_signal' => (string)$context['macd']['signal'],
                'macd_histogram' => (string)$context['macd']['hist'],
                'vwap' => (string)$context['vwap'],
                'atr' => (string)$context['atr'],
                'adx' => (string)$context['adx'][14],
                'close' => (string)$context['close'],
                'conditions_results' => $this->conditionRegistry->evaluate($context)
            ]);

        // Sauvegarder le snapshot
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        // Vérifier que le snapshot a été sauvegardé
        $this->assertNotNull($snapshot->getId());

        // Récupérer le snapshot depuis la base
        $retrievedSnapshot = $this->snapshotRepository->find($snapshot->getId());
        $this->assertNotNull($retrievedSnapshot);
        $this->assertEquals('BTCUSDT', $retrievedSnapshot->getSymbol());
        $this->assertEquals(Timeframe::H1, $retrievedSnapshot->getTimeframe());
        $this->assertEquals('2024-01-01 12:00:00', $retrievedSnapshot->getKlineTime()->format('Y-m-d H:i:s'));
    }

    public function testSnapshotRegressionComparison(): void
    {
        // Créer et sauvegarder un snapshot de référence
        $referenceContext = $this->createRealisticContext();
        $referenceSnapshot = $this->createSnapshotFromContext($referenceContext, 'BTCUSDT', Timeframe::H1, '2024-01-01 12:00:00');
        $this->entityManager->persist($referenceSnapshot);
        $this->entityManager->flush();

        // Recalculer les indicateurs avec les mêmes données
        $newContext = $this->createRealisticContext();
        $newSnapshot = $this->createSnapshotFromContext($newContext, 'BTCUSDT', Timeframe::H1, '2024-01-01 12:00:00');

        // Comparer les résultats avec une tolérance
        $this->compareSnapshots($referenceSnapshot, $newSnapshot, 0.001); // Tolérance de 0.1%
    }

    public function testConditionResultsRegression(): void
    {
        // Créer un contexte et évaluer les conditions
        $context = $this->createRealisticContext();
        $conditionsResults = $this->conditionRegistry->evaluate($context);

        // Sauvegarder les résultats dans un snapshot
        $snapshot = new IndicatorSnapshot();
        $snapshot
            ->setSymbol('ETHUSDT')
            ->setTimeframe(Timeframe::H4)
            ->setKlineTime(new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')))
            ->setValues([
                'conditions_results' => $conditionsResults,
                'context_hash' => md5(serialize($context))
            ]);

        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        // Récupérer et comparer
        $retrievedSnapshot = $this->snapshotRepository->find($snapshot->getId());
        $retrievedConditions = $retrievedSnapshot->getValue('conditions_results');

        $this->assertIsArray($retrievedConditions);
        $this->assertEquals(count($conditionsResults), count($retrievedConditions));

        // Vérifier que chaque condition a les mêmes résultats
        foreach ($conditionsResults as $conditionName => $expectedResult) {
            $this->assertArrayHasKey($conditionName, $retrievedConditions);
            $actualResult = $retrievedConditions[$conditionName];

            $this->assertEquals($expectedResult['passed'], $actualResult['passed']);
            $this->assertEquals($expectedResult['name'], $actualResult['name']);

            // Comparer les valeurs avec tolérance si elles sont numériques
            if (is_numeric($expectedResult['value']) && is_numeric($actualResult['value'])) {
                $this->assertEqualsWithDelta($expectedResult['value'], $actualResult['value'], 0.001);
            } else {
                $this->assertEquals($expectedResult['value'], $actualResult['value']);
            }
        }
    }

    public function testMultipleSnapshotsForSameSymbol(): void
    {
        $symbol = 'ADAUSDT';
        $timeframe = Timeframe::H1;

        // Créer plusieurs snapshots pour le même symbole
        $snapshots = [];
        for ($i = 0; $i < 5; $i++) {
            $context = $this->createRealisticContext();
            $klineTime = new \DateTimeImmutable("2024-01-01 " . sprintf('%02d:00:00', 12 + $i), new \DateTimeZone('UTC'));

            $snapshot = $this->createSnapshotFromContext($context, $symbol, $timeframe, $klineTime->format('Y-m-d H:i:s'));
            $snapshots[] = $snapshot;

            $this->entityManager->persist($snapshot);
        }

        $this->entityManager->flush();

        // Récupérer le dernier snapshot
        $lastSnapshot = $this->snapshotRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);
        $this->assertNotNull($lastSnapshot);
        $this->assertEquals('2024-01-01 16:00:00', $lastSnapshot->getKlineTime()->format('Y-m-d H:i:s'));

        // Récupérer tous les snapshots pour une période
        $startDate = new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC'));
        $endDate = new \DateTimeImmutable('2024-01-01 16:00:00', new \DateTimeZone('UTC'));

        $snapshotsInRange = $this->snapshotRepository->findBySymbolTimeframeAndDateRange($symbol, $timeframe, $startDate, $endDate);
        $this->assertCount(5, $snapshotsInRange);
    }

    public function testSnapshotUpsert(): void
    {
        $symbol = 'DOTUSDT';
        $timeframe = Timeframe::H4;
        $klineTime = new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC'));

        // Créer un snapshot initial
        $context = $this->createRealisticContext();
        $snapshot = $this->createSnapshotFromContext($context, $symbol, $timeframe, $klineTime->format('Y-m-d H:i:s'));

        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();
        $originalId = $snapshot->getId();

        // Modifier le snapshot
        $snapshot->setValue('rsi', 75.5);
        $snapshot->setValue('updated', true);

        // Utiliser upsert
        $this->snapshotRepository->upsert($snapshot);
        $this->entityManager->flush();

        // Vérifier que c'est le même enregistrement
        $this->assertEquals($originalId, $snapshot->getId());

        // Vérifier que les valeurs ont été mises à jour
        $retrievedSnapshot = $this->snapshotRepository->find($originalId);
        $this->assertEquals(75.5, $retrievedSnapshot->getValue('rsi'));
        $this->assertTrue($retrievedSnapshot->getValue('updated'));
    }

    private function createRealisticContext(): array
    {
        return $this->contextBuilder
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
            ->withDefaults()
            ->build();
    }

    private function createSnapshotFromContext(array $context, string $symbol, Timeframe $timeframe, string $klineTime): IndicatorSnapshot
    {
        $snapshot = new IndicatorSnapshot();
        $snapshot
            ->setSymbol($symbol)
            ->setTimeframe($timeframe)
            ->setKlineTime(new \DateTimeImmutable($klineTime, new \DateTimeZone('UTC')))
            ->setValues([
                'ema20' => $context['ema'][20] ?? null,
                'ema50' => $context['ema'][50] ?? null,
                'ema200' => $context['ema'][200] ?? null,
                'rsi' => $context['rsi'] ?? null,
                'macd' => $context['macd']['macd'] ?? null,
                'macd_signal' => $context['macd']['signal'] ?? null,
                'macd_histogram' => $context['macd']['hist'] ?? null,
                'vwap' => $context['vwap'] ?? null,
                'atr' => $context['atr'] ?? null,
                'adx' => $context['adx'][14] ?? null,
                'close' => $context['close'] ?? null,
                'context_hash' => md5(serialize($context))
            ]);

        return $snapshot;
    }

    private function compareSnapshots(IndicatorSnapshot $reference, IndicatorSnapshot $current, float $tolerance): void
    {
        $referenceValues = $reference->getValues();
        $currentValues = $current->getValues();

        foreach ($referenceValues as $key => $referenceValue) {
            if ($key === 'context_hash') {
                continue; // Ignorer le hash de contexte
            }

            $this->assertArrayHasKey($key, $currentValues, "La clé '$key' est manquante dans le snapshot actuel");

            $currentValue = $currentValues[$key];

            if (is_numeric($referenceValue) && is_numeric($currentValue)) {
                $this->assertEqualsWithDelta(
                    (float)$referenceValue,
                    (float)$currentValue,
                    $tolerance,
                    "La valeur de '$key' diffère de plus de $tolerance"
                );
            } else {
                $this->assertEquals($referenceValue, $currentValue, "La valeur de '$key' ne correspond pas");
            }
        }
    }
}

