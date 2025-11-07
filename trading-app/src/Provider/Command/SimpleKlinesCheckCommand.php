<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Common\Enum\Timeframe;
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
    name: 'simple:klines-check',
    description: 'Vérification simple de la qualité des klines'
)]
class SimpleKlinesCheckCommand extends Command
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
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole à vérifier', 'BTCUSDT')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe à vérifier', '15m')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à vérifier', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vérification simple des klines');

        $symbol = $input->getOption('symbol');
        $timeframeStr = $input->getOption('timeframe');
        $limit = (int) $input->getOption('limit');

        try {
            $timeframe = Timeframe::from($timeframeStr);
        } catch (\ValueError $e) {
            $io->error("Timeframe invalide: $timeframeStr");
            return Command::FAILURE;
        }

        $io->section("Vérification: $symbol - {$timeframe->value}");

        // Vérifier les klines existantes
        $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);

        if (empty($klines)) {
            $io->writeln("❌ Aucune kline trouvée");
            return Command::FAILURE;
        }

        // Trier par ordre chronologique
        usort($klines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

        $firstKline = $klines[0];
        $lastKline = end($klines);

        $io->writeln("📊 Symbole: {$firstKline->getSymbol()}");
        $io->writeln("📅 Période: {$firstKline->getOpenTime()->format('Y-m-d H:i:s')} à {$lastKline->getOpenTime()->format('Y-m-d H:i:s')}");
        $io->writeln("📈 Dernière valeur: {$lastKline->getClosePriceFloat()} (volume: {$lastKline->getVolumeFloat()})");

        // Vérifier la récence
        $now = $this->clock->now();
        $timeSinceLastKline = $now->diff($lastKline->getOpenTime());
        $hoursSinceLastKline = $timeSinceLastKline->h + ($timeSinceLastKline->days * 24);

        $io->writeln("⏰ Dernière kline il y a: {$hoursSinceLastKline}h");

        if ($hoursSinceLastKline > 24) {
            $io->writeln("⚠️  Données anciennes (> 24h)");
        } else {
            $io->writeln("✅ Données récentes");
        }

        // Vérifier la consécutivité
        $this->checkConsecutivity($klines, $timeframe, $io);

        // Recommandations
        if ($hoursSinceLastKline > 24) {
            $io->newLine();
            $io->section('Recommandations');
            $io->writeln("💡 Pour récupérer les données récentes:");
            $io->writeln("   docker-compose exec trading-app-php bin/console bitmart:fetch-klines --symbol=$symbol --timeframe={$timeframe->value}");
            $io->writeln("   ou");
            $io->writeln("   docker-compose exec trading-app-php bin/console bitmart:fetch-all-klines");
        }

        return Command::SUCCESS;
    }

    private function checkConsecutivity(array $klines, Timeframe $timeframe, SymfonyStyle $io): void
    {
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
            }
        }

        if (empty($gaps)) {
            $io->writeln("✅ Données consécutives");
        } else {
            $io->writeln("❌ " . count($gaps) . " gap(s) détecté(s)");
            foreach ($gaps as $gap) {
                $io->writeln("   - Gap entre {$gap['from']->format('Y-m-d H:i:s')} et {$gap['to']->format('Y-m-d H:i:s')}");
            }
        }
    }
}
