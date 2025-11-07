<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'test:bitmart-api',
    description: 'Teste directement l\'API Bitmart'
)]
class TestBitmartApiCommand extends Command
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole à tester', 'BTCUSDT')
            ->addOption('step', 't', InputOption::VALUE_OPTIONAL, 'Step en minutes', 15)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à récupérer', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test direct de l\'API Bitmart');

        $symbol = $input->getOption('symbol');
        $step = (int) $input->getOption('step');
        $limit = (int) $input->getOption('limit');

        $io->section("Test pour $symbol - Step: {$step}m");

        try {
            $io->writeln("🔄 Test de la santé de l'API...");
            $healthCheck = $this->mainProvider->healthCheck();
            $io->writeln($healthCheck ? "✅ API accessible" : "❌ API inaccessible");

            if (!$healthCheck) {
                return Command::FAILURE;
            }

            $io->writeln("🔄 Récupération du temps système...");
            $systemTime = $this->mainProvider->getSystemTimeMs()->getSystemTimeMs();
            $io->writeln("⏰ Temps système: " . date('Y-m-d H:i:s', (int)($systemTime / 1000)));

            $io->writeln("🔄 Récupération des klines...");
            $klines = $this->mainProvider->getKlineProvider()->getKlines($symbol, Timeframe::fromMinutes($step), $limit);

            if (empty($klines)) {
                $io->writeln("❌ Aucune kline reçue");
                return Command::FAILURE;
            }
            $io->writeln("🔄 Bid/Ask pour $symbol...");
            $bidAsk = $this->mainProvider->getOrderProvider()->getOrderBookTop($symbol);
            if ($bidAsk === null) {
                $io->writeln("❌ Impossible de récupérer le bid/ask");
                return Command::FAILURE;
            }
            $io->writeln("💰 Bid: " . number_format($bidAsk->bid, 2) . " | Ask: " . number_format($bidAsk->ask, 2));

            $io->writeln("✅ " . count($klines) . " klines récupérées");

            // Afficher les détails des klines
            $table = $io->createTable();
            $table->setHeaders(['Index', 'Symbole', 'Open Time', 'Open', 'High', 'Low', 'Close', 'Volume']);

            foreach ($klines as $index => $kline) {
                $table->addRow([
                    $index + 1,
                     $kline->symbol ?? 'N/A',
                    $kline->openTime->format('Y-m-d H:s:i') ?? 'N/A',
                    number_format($kline->open ->toFloat()?? 0, 2),
                    number_format($kline->high ->toFloat() ?? 0, 2),
                    number_format($kline->low ->toFloat() ?? 0, 2),
                    number_format($kline->close ->toFloat() ?? 0, 2),
                    number_format($kline->volume ->toFloat() ?? 0, 0)
                ]);
            }

            $table->render();

            $io->success("Test terminé avec succès !");

        } catch (\Exception $e) {
            $io->error("Erreur lors du test: " . $e->getMessage());
            $io->writeln("Trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
