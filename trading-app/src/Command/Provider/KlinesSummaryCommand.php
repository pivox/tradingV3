<?php

declare(strict_types=1);

namespace App\Command\Provider;

use App\Repository\KlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'klines:summary',
    description: 'Résumé de l\'état des klines'
)]
class KlinesSummaryCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Résumé de l\'état des klines');

        // Récupérer les statistiques générales
        $this->showGeneralStats($io);

        // Récupérer les symboles avec les données les plus récentes
        $this->showRecentSymbols($io);

        // Récupérer les symboles avec des données anciennes
        $this->showOldSymbols($io);

        return Command::SUCCESS;
    }

    private function showGeneralStats(SymfonyStyle $io): void
    {
        $io->section('Statistiques générales');

        // Nombre total de klines
        $totalKlines = $this->entityManager->createQuery('SELECT COUNT(k) FROM App\Entity\Kline k')->getSingleScalarResult();
        $io->writeln("📊 Total klines: $totalKlines");

        // Nombre de symboles uniques
        $uniqueSymbols = $this->entityManager->createQuery('SELECT COUNT(DISTINCT k.symbol) FROM App\Entity\Kline k')->getSingleScalarResult();
        $io->writeln("📈 Symboles uniques: $uniqueSymbols");

        // Timeframes disponibles
        $timeframes = $this->entityManager->createQuery('SELECT DISTINCT k.timeframe FROM App\Entity\Kline k ORDER BY k.timeframe')->getResult();
        $timeframeList = array_map(fn($tf) => $tf['timeframe']->value, $timeframes);
        $io->writeln("⏰ Timeframes: " . implode(', ', $timeframeList));
    }

    private function showRecentSymbols(SymfonyStyle $io): void
    {
        $io->section('Symboles avec données récentes (< 24h)');

        $query = $this->entityManager->createQuery('
            SELECT k.symbol, k.timeframe, MAX(k.openTime) as latest_time
            FROM App\Entity\Kline k
            WHERE k.openTime > :cutoff
            GROUP BY k.symbol, k.timeframe
            ORDER BY latest_time DESC
        ');

        $cutoff = new \DateTimeImmutable('-24 hours');
        $query->setParameter('cutoff', $cutoff);

        $results = $query->getResult();

        if (empty($results)) {
            $io->writeln("❌ Aucune donnée récente trouvée");
        } else {
            $table = $io->createTable();
            $table->setHeaders(['Symbole', 'Timeframe', 'Dernière kline']);

            foreach ($results as $result) {
                $latestTime = $result['latest_time'] instanceof \DateTimeInterface
                    ? $result['latest_time']->format('Y-m-d H:i:s')
                    : $result['latest_time'];

                $table->addRow([
                    $result['symbol'],
                    $result['timeframe']->value,
                    $latestTime
                ]);
            }

            $table->render();
        }
    }

    private function showOldSymbols(SymfonyStyle $io): void
    {
        $io->section('Symboles avec données anciennes (> 24h)');

        $query = $this->entityManager->createQuery('
            SELECT k.symbol, k.timeframe, MAX(k.openTime) as latest_time
            FROM App\Entity\Kline k
            WHERE k.openTime <= :cutoff
            GROUP BY k.symbol, k.timeframe
            ORDER BY latest_time ASC
        ');

        $cutoff = new \DateTimeImmutable('-24 hours');
        $query->setParameter('cutoff', $cutoff);

        $results = $query->getResult();

        if (empty($results)) {
            $io->writeln("✅ Toutes les données sont récentes");
        } else {
            $table = $io->createTable();
            $table->setHeaders(['Symbole', 'Timeframe', 'Dernière kline', 'Âge']);

            foreach ($results as $result) {
                $latestTime = $result['latest_time'] instanceof \DateTimeInterface
                    ? $result['latest_time']
                    : new \DateTimeImmutable($result['latest_time']);

                $age = $latestTime->diff(new \DateTimeImmutable());
                $ageText = $age->days > 0 ? "{$age->days}j" : "{$age->h}h";

                $table->addRow([
                    $result['symbol'],
                    $result['timeframe']->value,
                    $latestTime->format('Y-m-d H:i:s'),
                    $ageText
                ]);
            }

            $table->render();

            $io->writeln("💡 Pour mettre à jour les données:");
            $io->writeln("   docker-compose exec trading-app-php bin/console bitmart:fetch-all-klines");
        }
    }
}
