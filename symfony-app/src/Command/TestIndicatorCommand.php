<?php

namespace App\Command;

use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Indicator\IndicatorValidatorClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test:indicator',
    description: 'Teste la validation Python (POST /validate) pour un contrat et un timeframe à partir des klines en base.'
)]
final class TestIndicatorCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContractRepository $contracts,
        private readonly KlineRepository $klines,
        private readonly IndicatorValidatorClient $indicatorClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('contract', InputArgument::REQUIRED, 'Symbole du contrat (ex: BTCUSDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (1m,5m,15m,1h,4h,...)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de klines à envoyer au validateur', 100)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Affiche la décision brute JSON en sortie')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $symbol    = strtoupper((string) $input->getArgument('contract'));
        $timeframe = strtolower((string) $input->getArgument('timeframe'));
        $limit     = (int) $input->getOption('limit');
        $asJson    = (bool) $input->getOption('json');

        $io->title('🔎 Test indicateur Python');
        $io->text(sprintf('Contrat: <info>%s</info> | Timeframe: <info>%s</info> | Limit: <info>%d</info>', $symbol, $timeframe, $limit));

        // 1) Trouver le contrat
        /** @var Contract|null $contract */
        $contract = $this->contracts->find($symbol);
        if (!$contract) {
            $io->error("Contrat introuvable: $symbol");
            return Command::FAILURE;
        }

        // 2) Mapping timeframe -> step (secondes)
        $stepSeconds = $this->stepFor($timeframe);
        if ($stepSeconds === null) {
            $io->error("Timeframe non supporté: $timeframe");
            return Command::FAILURE;
        }

        // 3) Lire les klines (les plus récents), puis remettre en ordre chrono ASC
        $rows = $this->klines->createQueryBuilder('k')
            ->andWhere('k.contract = :c')->setParameter('c', $contract)
            ->andWhere('k.step = :s')->setParameter('s', $stepSeconds)
            ->orderBy('k.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!$rows) {
            $io->warning('Aucun kline trouvé pour ce contrat/timeframe.');
            return Command::SUCCESS;
        }

        $rows = array_reverse($rows); // ASC

        // 4) Construire la payload klines -> format API Python
        $klinesPayload = array_map(function ($k) {
            /** @var \App\Entity\Kline $k */
            return [
                'timestamp' => $k->getTimestamp()->getTimestamp(),
                'open'      => (float) $k->getOpen(),
                'high'      => (float) $k->getHigh(),
                'low'       => (float) $k->getLow(),
                'close'     => (float) $k->getClose(),
                'volume'    => (float) $k->getVolume(),
            ];
        }, $rows);

        // 5) Appel du validateur Python
        $decision = $this->indicatorClient->validate($symbol, $timeframe, $klinesPayload);

        if ($asJson) {
            $output->writeln(json_encode($decision, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        // 6) Affichage lisible
        $valid = (bool)($decision['valid'] ?? false);
        $side  = $decision['side'] ?? null;
        $score = $decision['score'] ?? null;

        $io->section('Résultat du validateur');
        $io->writeln('Valid : ' . ($valid ? '<info>OUI</info>' : '<error>NON</error>'));
        $io->writeln('Side  : ' . ($side ? "<comment>$side</comment>" : '—'));
        $io->writeln('Score : ' . ($score !== null ? (string)$score : '—'));

        if (!empty($decision['reasons']) && is_array($decision['reasons'])) {
            $io->section('Raisons');
            foreach ($decision['reasons'] as $r) {
                $io->writeln(" • $r");
            }
        }

        if (!empty($decision['debug']) && is_array($decision['debug'])) {
            $io->section('Debug');
            foreach ($decision['debug'] as $k => $v) {
                $io->writeln(sprintf('%s: %s', $k, is_scalar($v) ? (string)$v : json_encode($v)));
            }
        }

        $io->success('Test terminé.');
        return Command::SUCCESS;
    }

    private function stepFor(string $tf): ?int
    {
        return match ($tf) {
            '1m' => 60,
            '3m' => 3 * 60,
            '5m' => 5 * 60,
            '15m'=> 15 * 60,
            '30m'=> 30 * 60,
            '1h' => 60 * 60,
            '2h' => 2 * 60 * 60,
            '4h' => 4 * 60 * 60,
            '6h' => 6 * 60 * 60,
            '12h'=> 12 * 60 * 60,
            '1d' => 24 * 60 * 60,
            '3d' => 3 * 24 * 60 * 60,
            '1w' => 7 * 24 * 60 * 60,
            default => null,
        };
    }
}
