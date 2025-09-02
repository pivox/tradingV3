<?php

namespace App\Command;

use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Indicator\IndicatorValidatorClient;
use App\Service\Persister\KlinePersister;
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
    description: 'Teste la validation Python (POST /validate) pour un contrat et un timeframe à partir des klines en base, en préfetchant depuis Bitmart.'
)]
final class TestIndicatorCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContractRepository $contracts,
        private readonly KlineRepository $klines,
        private readonly IndicatorValidatorClient $indicatorClient,
        private readonly BitmartFetcher $bitmartFetcher,   // ← ajout
        private readonly KlinePersister $klinePersister,   // ← ajout
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
            ->addOption('no-prefetch', null, InputOption::VALUE_NONE, 'Désactive le préfetch Bitmart (lecture DB uniquement)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $symbolArg = strtoupper((string) $input->getArgument('contract'));
        $timeframe = strtolower((string) $input->getArgument('timeframe'));
        $limit     = (int) $input->getOption('limit');
        $noPrefetch= (bool) $input->getOption('no-prefetch');

        $io->title('🔎 Test indicateur Python (simplifié)');
        $io->text(sprintf(
            'Contrat(s): <info>%s</info> | Timeframe: <info>%s</info> | Limit: <info>%d</info>',
            $symbolArg, $timeframe, $limit
        ));

        // 1) Récupération contrats
        if ($symbolArg === 'ALL') {
            $contracts = $this->contracts->createQueryBuilder('c')
                ->leftJoin('c.klines', 'k')
                ->andWhere('k.timestamp >= :t')->setParameter('t', '2025-08-31 12:00:00')
                ->select('DISTINCT c')
                ->getQuery()->getResult();
            if (!$contracts) {
                $io->error("Aucun contrat trouvé en base.");
                return Command::FAILURE;
            }
        } else {
            $c = $this->contracts->find($symbolArg);
            if (!$c) {
                $io->error("Contrat introuvable: $symbolArg");
                return Command::FAILURE;
            }
            $contracts = [$c];
        }

        // 2) TF -> step
        $stepSeconds = $this->stepFor($timeframe);
        if ($stepSeconds === null) {
            $io->error("Timeframe non supporté: $timeframe");
            return Command::FAILURE;
        }
        $stepMinutes = intdiv($stepSeconds, 60);

        $invalidCount = 0;

        // 3) Boucle contrats
        $i= 0;
        foreach ($contracts as $contract) {
            if ($i == 2000) break; // DEBUG
            $symbol = $contract->getSymbol();

            // Prefetch Bitmart (optionnel)
            if (!$noPrefetch) {
                try {
                    $now   = new \DateTimeImmutable();
                    $start = $now->sub(new \DateInterval('PT' . ($limit * $stepMinutes) . 'M'));
                    $end   = $now;
                    $dtos = $this->bitmartFetcher->fetchKlines($symbol, $start, $end, $stepMinutes);
                    $this->klinePersister->persistMany($contract, $dtos, $stepSeconds);
                } catch (\Throwable $e) {
                    // ignorer préfetch si erreur
                }
            }

            // Charger les klines
            $rows = $this->klines->createQueryBuilder('k')
                ->andWhere('k.contract = :c')->setParameter('c', $contract)
                ->andWhere('k.step = :s')->setParameter('s', $stepSeconds)
                ->orderBy('k.timestamp', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();

            if (!$rows) {
                $invalidCount++;
                continue;
            }

            $rows = array_reverse($rows);

            $klinesPayload = array_map(function ($k) {
                return [
                    'timestamp' => $k->getTimestamp()->getTimestamp(),
                    'open'      => (float) $k->getOpen(),
                    'high'      => (float) $k->getHigh(),
                    'low'       => (float) $k->getLow(),
                    'close'     => (float) $k->getClose(),
                    'volume'    => (float) $k->getVolume(),
                ];
            }, $rows);

            try {
                $decision = $this->indicatorClient->validate($symbol, $timeframe, $klinesPayload);
               if ($decision['long_score'] || $decision['short_score']) dump($decision);
                $valid = (bool)($decision['valid'] ?? false);
            } catch (\Throwable $e) {
                $valid = false;
            }

            if ($valid) {
                $io->writeln("{$symbol}: <info>OUI</info>");
            } else {
                $invalidCount++;
            }
            $i++;
        }

        // 4) Résumé
        $io->success("Nombre de contrats avec Valid = NON : {$invalidCount}");

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
