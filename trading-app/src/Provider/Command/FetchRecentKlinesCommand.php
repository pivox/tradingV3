<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\MainProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function PHPUnit\Framework\isInstanceOf;

#[AsCommand(
    name: 'fetch:recent-klines',
    description: 'Récupère les klines les plus récentes depuis l\'API Bitmart'
)]
class FetchRecentKlinesCommand extends Command
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole à récupérer (par défaut: BTCUSDT)')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe à récupérer (par défaut: 15m)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à récupérer', 100)
            ->addOption('all-symbols', 'a', InputOption::VALUE_NONE, 'Récupérer pour tous les symboles actifs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Récupération des klines récentes');

        $symbol = $input->getOption('symbol') ?? 'BTCUSDT';
        $timeframeStr = $input->getOption('timeframe') ?? '15m';
        $limit = (int) $input->getOption('limit');
        $allSymbols = $input->getOption('all-symbols');

        try {
            $timeframe = Timeframe::from($timeframeStr);
        } catch (\ValueError $e) {
            $io->error("Timeframe invalide: $timeframeStr");
            return Command::FAILURE;
        }

        $symbols = $allSymbols ? $this->getActiveSymbols() : [$symbol];
        $totalFetched = 0;
        $totalErrors = 0;

        foreach ($symbols as $currentSymbol) {
            $io->section("Symbole: $currentSymbol");

            try {
                $fetched = $this->mainProvider->getKlineProvider()->getKlines(symbol: $currentSymbol, timeframe: $timeframe);
                $totalFetched += count($fetched);
                $this->mainProvider->getKlineProvider()->saveKlines($fetched, $currentSymbol, $timeframe);
            } catch (\Exception $e) {
                $io->writeln("❌ Erreur pour $currentSymbol: " . $e->getMessage());
                $totalErrors++;
            }
        }

        $io->newLine();
        $io->success("Récupération terminée: $totalFetched klines récupérées, $totalErrors erreurs");

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function fetchKlinesForSymbol(string $symbol, Timeframe $timeframe, int $limit, SymfonyStyle $io): int
    {
        try {
            // Récupérer les klines depuis l'API Bitmart
            $io->writeln("  🔄 Récupération depuis l'API Bitmart...");

            $step = $this->convertTimeframeToStep($timeframe);
            $response = $this->mainProvider->getKlineProvider()->getKlines($symbol, $timeframe, $limit);

            if (!isset($response['data']['klines']) || empty($response['data']['klines'])) {
                $io->writeln("  ⚠️  Aucune donnée reçue de l'API");
                return 0;
            }

            $klines = $response['data']['klines'];
            $io->writeln("  📊 {$klines[0]['symbol']} - {$timeframe->value} - {$klines[0]['open_time']} à {$klines[count($klines)-1]['open_time']}");

            // Convertir et sauvegarder les klines
            $savedCount = 0;
            foreach ($klines as $klineData) {
                try {
                    $this->mainProvider->getKlineProvider()->saveKline($klineData);
                    $savedCount++;
                } catch (\Exception $e) {
                    $io->writeln("  ⚠️  Erreur lors de la sauvegarde d'une kline: " . $e->getMessage());
                }
            }

            $io->writeln("  ✅ $savedCount klines sauvegardées");
            return $savedCount;

        } catch (\Exception $e) {
            $io->writeln("  ❌ Erreur: " . $e->getMessage());
            throw $e;
        }
    }

    private function convertTimeframeToStep(Timeframe $timeframe): int
    {
        return match ($timeframe) {
            Timeframe::TF_1M => 1,
            Timeframe::TF_5M => 5,
            Timeframe::TF_15M => 15,
            Timeframe::TF_30M => 30,
            Timeframe::TF_1H => 60,
            Timeframe::TF_4H => 240,
        };
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
