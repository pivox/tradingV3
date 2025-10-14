<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Domain\Common\Enum\Timeframe;
use App\Infrastructure\Http\BitmartRestClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:fetch-klines',
    description: 'Récupère les klines depuis BitMart Futures'
)]
final class FetchKlinesCommand extends Command
{
    public function __construct(
        private readonly BitmartRestClient $bitmartClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à récupérer (ex: BTCUSDT)')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe (4h|1h|15m|5m|1m)', '1h')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à récupérer', '100')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Format de sortie (table|json|csv)', 'table')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Sauvegarder les klines en base de données')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Date de début (Y-m-d H:i:s)')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Date de fin (Y-m-d H:i:s)')
            ->setHelp('
Cette commande récupère les klines (bougies) depuis l\'API BitMart Futures.

Exemples:
  php bin/console bitmart:fetch-klines BTCUSDT
  php bin/console bitmart:fetch-klines BTCUSDT --timeframe=4h --limit=50
  php bin/console bitmart:fetch-klines BTCUSDT --output=json
  php bin/console bitmart:fetch-klines BTCUSDT --save
  php bin/console bitmart:fetch-klines BTCUSDT --from="2024-01-01 00:00:00" --to="2024-01-02 00:00:00"
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframeStr = $input->getOption('timeframe');
        $limit = (int) $input->getOption('limit');
        $outputFormat = $input->getOption('output');
        $save = $input->getOption('save');
        $from = $input->getOption('from');
        $to = $input->getOption('to');

        try {
            // Validation du timeframe
            $timeframe = $this->validateTimeframe($timeframeStr);
            if (!$timeframe) {
                $io->error("Timeframe invalide: {$timeframeStr}. Valeurs acceptées: 4h, 1h, 15m, 5m, 1m");
                return Command::FAILURE;
            }

            // Validation des dates
            $fromDate = $from ? new \DateTimeImmutable($from, new \DateTimeZone('UTC')) : null;
            $toDate = $to ? new \DateTimeImmutable($to, new \DateTimeZone('UTC')) : null;

            $io->title('Récupération des klines BitMart Futures');
            $io->info(sprintf(
                'Symbole: %s | Timeframe: %s | Limite: %d',
                $symbol,
                $timeframe->value,
                $limit
            ));

            if ($fromDate && $toDate) {
                $io->info(sprintf(
                    'Période: %s à %s',
                    $fromDate->format('Y-m-d H:i:s'),
                    $toDate->format('Y-m-d H:i:s')
                ));
            }

            // Récupération des klines
            $klines = $this->bitmartClient->fetchKlines($symbol, $timeframe, $limit);

            if (empty($klines)) {
                $io->warning('Aucune kline trouvée');
                return Command::SUCCESS;
            }

            $io->success(sprintf('Récupéré %d kline(s)', count($klines)));

            // Filtrage par dates si spécifié
            if ($fromDate || $toDate) {
                $klines = $this->filterKlinesByDate($klines, $fromDate, $toDate);
                $io->info(sprintf('Après filtrage: %d kline(s)', count($klines)));
            }

            // Affichage des résultats
            switch ($outputFormat) {
                case 'json':
                    $this->outputJson($output, $klines);
                    break;
                case 'csv':
                    $this->outputCsv($output, $klines);
                    break;
                default:
                    $this->displayKlinesTable($io, $klines);
            }

            // Sauvegarde optionnelle
            if ($save) {
                $io->info('Sauvegarde des klines en base de données...');
                // TODO: Implémenter la sauvegarde en base
                $io->note('La sauvegarde en base de données sera implémentée prochainement');
            }

            // Statistiques
            $this->displayStatistics($io, $klines);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de la récupération des klines: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateTimeframe(string $timeframe): ?Timeframe
    {
        return match ($timeframe) {
            '4h' => Timeframe::TF_4H,
            '1h' => Timeframe::TF_1H,
            '15m' => Timeframe::TF_15M,
            '5m' => Timeframe::TF_5M,
            '1m' => Timeframe::TF_1M,
            default => null
        };
    }

    private function filterKlinesByDate(array $klines, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        return array_filter($klines, function ($kline) use ($from, $to) {
            $klineTime = $kline->openTime;
            
            if ($from && $klineTime < $from) {
                return false;
            }
            
            if ($to && $klineTime > $to) {
                return false;
            }
            
            return true;
        });
    }

    private function displayKlinesTable(SymfonyStyle $io, array $klines): void
    {
        $headers = ['Date/Heure', 'Open', 'High', 'Low', 'Close', 'Volume', 'Source'];
        $rows = [];

        foreach ($klines as $kline) {
            $rows[] = [
                $kline->openTime->format('Y-m-d H:i:s'),
                $kline->open->toScale(8)->__toString(),
                $kline->high->toScale(8)->__toString(),
                $kline->low->toScale(8)->__toString(),
                $kline->close->toScale(8)->__toString(),
                $kline->volume->toScale(2)->__toString(),
                $kline->source
            ];
        }

        $io->table($headers, $rows);
    }

    private function outputJson(OutputInterface $output, array $klines): void
    {
        $data = array_map(function ($kline) {
            return [
                'symbol' => $kline->symbol,
                'timeframe' => $kline->timeframe->value,
                'open_time' => $kline->openTime->format('Y-m-d H:i:s'),
                'open' => $kline->open->toScale(12)->__toString(),
                'high' => $kline->high->toScale(12)->__toString(),
                'low' => $kline->low->toScale(12)->__toString(),
                'close' => $kline->close->toScale(12)->__toString(),
                'volume' => $kline->volume->toScale(12)->__toString(),
                'source' => $kline->source
            ];
        }, $klines);

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function outputCsv(OutputInterface $output, array $klines): void
    {
        $output->writeln('symbol,timeframe,open_time,open,high,low,close,volume,source');
        
        foreach ($klines as $kline) {
            $output->writeln(sprintf(
                '%s,%s,%s,%s,%s,%s,%s,%s,%s',
                $kline->symbol,
                $kline->timeframe->value,
                $kline->openTime->format('Y-m-d H:i:s'),
                $kline->open->toScale(12)->__toString(),
                $kline->high->toScale(12)->__toString(),
                $kline->low->toScale(12)->__toString(),
                $kline->close->toScale(12)->__toString(),
                $kline->volume->toScale(12)->__toString(),
                $kline->source
            ));
        }
    }

    private function displayStatistics(SymfonyStyle $io, array $klines): void
    {
        if (empty($klines)) {
            return;
        }

        $io->section('Statistiques');

        $firstKline = $klines[0];
        $lastKline = end($klines);
        
        $prices = array_map(fn($k) => $k->close->toFloat(), $klines);
        $volumes = array_map(fn($k) => $k->volume->toFloat(), $klines);

        $stats = [
            'Période' => sprintf(
                '%s à %s',
                $firstKline->openTime->format('Y-m-d H:i:s'),
                $lastKline->openTime->format('Y-m-d H:i:s')
            ),
            'Nombre de klines' => count($klines),
            'Prix min' => number_format(min($prices), 8),
            'Prix max' => number_format(max($prices), 8),
            'Prix moyen' => number_format(array_sum($prices) / count($prices), 8),
            'Volume total' => number_format(array_sum($volumes), 2),
            'Volume moyen' => number_format(array_sum($volumes) / count($volumes), 2),
            'Variation' => sprintf(
                '%+.2f%%',
                (($lastKline->close->toFloat() - $firstKline->open->toFloat()) / $firstKline->open->toFloat()) * 100
            )
        ];

        foreach ($stats as $key => $value) {
            $io->writeln(sprintf('<info>%s:</info> %s', $key, $value));
        }
    }
}
