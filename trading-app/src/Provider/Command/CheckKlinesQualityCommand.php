<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Repository\KlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'check:klines-quality',
    description: 'Vérifie la récence et la consécutivité des klines'
)]
class CheckKlinesQualityCommand extends Command
{
    public function __construct(
        private readonly KlineRepository $klineRepository,
        private readonly KlineProviderInterface $klineProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole à vérifier (par défaut: tous)')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe à vérifier (par défaut: tous)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à vérifier', 100)
            ->addOption('check-gaps', 'g', InputOption::VALUE_NONE, 'Vérifier les gaps dans les données')
            ->addOption('fix-missing', 'f', InputOption::VALUE_NONE, 'Tenter de récupérer les klines manquantes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vérification de la qualité des klines');

        $symbol = $input->getOption('symbol');
        $timeframeStr = $input->getOption('timeframe');
        $limit = (int) $input->getOption('limit');
        $checkGaps = $input->getOption('check-gaps');
        $fixMissing = $input->getOption('fix-missing');

        // Définir les timeframes à vérifier
        $timeframes = $timeframeStr
            ? [Timeframe::from($timeframeStr)]
            : [Timeframe::TF_1M, Timeframe::TF_5M, Timeframe::TF_15M, Timeframe::TF_30M, Timeframe::TF_1H, Timeframe::TF_4H];

        // Définir les symboles à vérifier
        $symbols = $symbol
            ? [$symbol]
            : $this->getActiveSymbols();

        $totalIssues = 0;
        $summary = [];

        foreach ($symbols as $currentSymbol) {
            $io->section("Symbole: $currentSymbol");
            $symbolIssues = 0;

            foreach ($timeframes as $tf) {
                $io->writeln("  Timeframe: {$tf->value}");

                $issues = $this->checkKlinesForSymbolAndTimeframe(
                    $currentSymbol,
                    $tf,
                    $limit,
                    $checkGaps,
                    $fixMissing,
                    $io
                );

                $symbolIssues += $issues;
                $totalIssues += $issues;
            }

            $summary[$currentSymbol] = $symbolIssues;
        }

        // Affichage du résumé
        $io->newLine();
        $io->section('Résumé par symbole');

        $table = $io->createTable();
        $table->setHeaders(['Symbole', 'Problèmes détectés']);

        foreach ($summary as $sym => $issues) {
            $table->addRow([$sym, $issues > 0 ? "❌ $issues" : "✅ 0"]);
        }

        $table->render();

        $io->newLine();
        if ($totalIssues === 0) {
            $io->success('Aucun problème détecté dans les klines !');
        } else {
            $io->warning("$totalIssues problème(s) détecté(s) dans les klines.");
        }

        return $totalIssues > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function checkKlinesForSymbolAndTimeframe(
        string $symbol,
        Timeframe $timeframe,
        int $limit,
        bool $checkGaps,
        bool $fixMissing,
        SymfonyStyle $io
    ): int {
        $issues = 0;

        try {
            // Récupérer les klines
            $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);

            if (empty($klines)) {
                $io->writeln("    ❌ Aucune kline trouvée");
                return 1;
            }

            // Trier par ordre chronologique
            usort($klines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

            $firstKline = $klines[0];
            $lastKline = end($klines);
            $io->writeln("    📊 {$firstKline->getSymbol()} - {$firstKline->getTimeframe()->value}");
            $io->writeln("    📅 {$firstKline->getOpenTime()->format('Y-m-d H:i:s')} à {$lastKline->getOpenTime()->format('Y-m-d H:i:s')}");
            $io->writeln("    📈 {$lastKline->getClosePriceFloat()} (volume: {$lastKline->getVolumeFloat()})");

            // Vérifier la récence
            $now = $this->clock->now();
            $expectedNextTime = $lastKline->getOpenTime()->modify('+' . $timeframe->getStepInMinutes() . ' minutes');
            $tolerance = new \DateInterval('PT5M');

            $timeSinceLastKline = $now->diff($lastKline->getOpenTime());
            $hoursSinceLastKline = $timeSinceLastKline->h + ($timeSinceLastKline->days * 24);

            if ($now->sub($tolerance) > $expectedNextTime) {
                $io->writeln("    ⚠️  Données pas à jour (dernière: {$lastKline->getOpenTime()->format('Y-m-d H:i:s')}, il y a {$hoursSinceLastKline}h)");
                $issues++;
            } else {
                $io->writeln("    ✅ Données à jour");
            }

            // Vérifier la consécutivité
            $consecutiveIssues = $this->checkConsecutivity($klines, $timeframe, $io);
            $issues += $consecutiveIssues;

            // Vérifier les gaps si demandé
            if ($checkGaps) {
                $gapIssues = $this->checkGaps($symbol, $timeframe, $io);
                $issues += $gapIssues;
            }

            // Tenter de récupérer les données manquantes si demandé
            if ($fixMissing && $issues > 0) {
                $this->attemptToFixMissingData($symbol, $timeframe, $io);
            }

        } catch (\Exception $e) {
            $io->writeln("    ❌ Erreur: " . $e->getMessage());
            $issues++;
        }

        return $issues;
    }

    private function checkConsecutivity(array $klines, Timeframe $timeframe, SymfonyStyle $io): int
    {
        $issues = 0;
        $stepMinutes = $timeframe->getStepInMinutes();
        $gaps = [];

        for ($i = 1; $i < count($klines); $i++) {
            $prevTime = $klines[$i-1]->getOpenTime();
            $currentTime = $klines[$i]->getOpenTime();
            $expectedTime = $prevTime->modify("+{$stepMinutes} minutes");

            if ($currentTime != $expectedTime) {
                $gapDuration = $currentTime->diff($expectedTime);
                $gaps[] = [
                    'from' => $prevTime,
                    'to' => $currentTime,
                    'expected' => $expectedTime,
                    'duration' => $gapDuration
                ];
                $issues++;
            }
        }

        if ($issues === 0) {
            $io->writeln("    ✅ Données consécutives");
        } else {
            $io->writeln("    ❌ {$issues} gap(s) détecté(s)");
            foreach ($gaps as $gap) {
                $io->writeln("      - Gap entre {$gap['from']->format('Y-m-d H:i:s')} et {$gap['to']->format('Y-m-d H:i:s')}");
            }
        }

        return $issues;
    }

    private function checkGaps(string $symbol, Timeframe $timeframe, SymfonyStyle $io): int
    {
        try {
            $hasGaps = $this->klineProvider->hasGaps($symbol, $timeframe);

            if ($hasGaps) {
                $gaps = $this->klineProvider->getGaps($symbol, $timeframe);
                $io->writeln("    ⚠️  {$gaps} gap(s) détecté(s) par la fonction PostgreSQL");
                return count($gaps);
            } else {
                $io->writeln("    ✅ Aucun gap détecté par PostgreSQL");
                return 0;
            }
        } catch (\Exception $e) {
            $io->writeln("    ❌ Erreur lors de la vérification des gaps: " . $e->getMessage());
            return 1;
        }
    }

    private function attemptToFixMissingData(string $symbol, Timeframe $timeframe, SymfonyStyle $io): void
    {
        try {
            $io->writeln("    🔄 Tentative de récupération des données manquantes...");

            // Ici on pourrait implémenter la logique pour récupérer les données manquantes
            // via l'API de l'exchange ou d'autres sources

            $io->writeln("    ℹ️  Fonctionnalité de récupération automatique non implémentée");
        } catch (\Exception $e) {
            $io->writeln("    ❌ Erreur lors de la récupération: " . $e->getMessage());
        }
    }

    private function getActiveSymbols(): array
    {
        // Récupérer les symboles actifs depuis la base de données
        $query = $this->entityManager->createQuery(
            'SELECT DISTINCT k.symbol FROM App\Provider\Entity\Kline k ORDER BY k.symbol'
        );

        $results = $query->getResult();
        return array_column($results, 'symbol');
    }
}
