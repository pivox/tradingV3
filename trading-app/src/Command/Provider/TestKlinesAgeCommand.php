<?php

declare(strict_types=1);

namespace App\Command\Provider;

use App\Common\Enum\Timeframe;
use App\Repository\KlineRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'test:klines-age',
    description: 'Teste la vérification de l\'âge des klines'
)]
class TestKlinesAgeCommand extends Command
{
    public function __construct(
        private readonly KlineRepository $klineRepository,
        private readonly ClockInterface $clock
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole à tester', 'BTCUSDT')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe à tester', '15m');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test de vérification de l\'âge des klines');

        $symbol = $input->getOption('symbol');
        $timeframeStr = $input->getOption('timeframe');

        try {
            $timeframe = Timeframe::from($timeframeStr);
        } catch (\ValueError $e) {
            $io->error("Timeframe invalide: $timeframeStr");
            return Command::FAILURE;
        }

        $io->section("Test pour $symbol - {$timeframe->value}");

        // Récupérer la dernière kline
        $lastKline = $this->klineRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);

        if (!$lastKline) {
            $io->writeln("❌ Aucune kline trouvée");
            return Command::FAILURE;
        }

        $now = $this->clock->now();
        $lastKlineTime = $lastKline->getOpenTime();

        $io->writeln("📊 Dernière kline: {$lastKlineTime->format('Y-m-d H:i:s')}");
        $io->writeln("⏰ Maintenant: {$now->format('Y-m-d H:i:s')}");

        $age = $now->diff($lastKlineTime);
        $ageHours = $age->h + ($age->days * 24);
        $io->writeln("🕐 Âge des données: {$ageHours}h ({$age->days}j {$age->h}h)");

        // Test de la logique MTF
        $io->section("Test de la logique MTF");

        // 1. Vérifier si trop récent
        $interval = new \DateInterval('PT' . $timeframe->getStepInMinutes() . 'M');
        $threshold = $now->sub($interval);
        $io->writeln("🔍 Seuil 'trop récent': {$threshold->format('Y-m-d H:i:s')}");

        if ($lastKlineTime > $threshold) {
            $io->writeln("⚠️  SKIP: Données trop récentes (pas encore prêtes)");
        } else {
            $io->writeln("✅ Données pas trop récentes");
        }

        // 2. Vérifier si trop ancien
        $maxAge = new \DateInterval('PT24H'); // 24 heures
        $maxAgeThreshold = $now->sub($maxAge);
        $io->writeln("🔍 Seuil 'trop ancien' (24h): {$maxAgeThreshold->format('Y-m-d H:i:s')}");

        if ($lastKlineTime < $maxAgeThreshold) {
            $io->writeln("⚠️  SKIP: Données trop anciennes (> 24h)");
            $io->writeln("💡 Le système devrait tenter de récupérer des données récentes");
        } else {
            $io->writeln("✅ Données récentes (< 24h)");
        }

        // Résumé
        $io->newLine();
        if ($lastKlineTime > $threshold) {
            $io->writeln("🎯 Résultat: SKIPPED - TOO_RECENT");
        } elseif ($lastKlineTime < $maxAgeThreshold) {
            $io->writeln("🎯 Résultat: SKIPPED - DATA_TOO_OLD");
        } else {
            $io->writeln("🎯 Résultat: READY - Données valides");
        }

        return Command::SUCCESS;
    }
}
