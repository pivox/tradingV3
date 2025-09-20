<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-all:klines-scalping',
    description: 'Récupère 200 klines pour chaque contrat et chaque TF scalping (4h,1h,15m,5m,1m). Reprise DB avec --resume.'
)]
final class FetchAllKlinesAllContractsCommand extends Command
{
    private const LIMIT_PER_TF      = 200;   // objectif par TF
    private const BATCH_FLUSH_EVERY = 300;   // flush/clear périodique
    private const CHUNK_SIZE        = 500;   // persistance par lots

    /** @var array<string,int> TF => step(seconds) */
    private array $timeframes = ['5m'=>5];
   // private array $timeframes = ['4h'=>240];

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly KlineRepository $klines,
        private readonly BitmartFetcher $bitmartFetcher,
        private readonly KlinePersister $persister,
        private readonly LoggerInterface $logger,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Reprendre selon l’état en DB (skip si couverture suffisante)')
            ->addArgument('symbols', null, '', 'liste)')
            ->addOption('start-symbol', null, InputOption::VALUE_REQUIRED, 'Forcer un point de départ (ex: JUPUSDT)')
            ->addOption('start-tf', null, InputOption::VALUE_REQUIRED, 'Forcer un TF de départ pour le symbole de départ (ex: 1h)')
            ->addOption('tolerance', null, InputOption::VALUE_REQUIRED, 'Tolérance de barres manquantes avant skip (par défaut 0)', 0)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore la reprise DB et refetch tout')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Objectif de barres/TF (défaut 200)', self::LIMIT_PER_TF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (method_exists($this->logger, 'reset')) { $this->logger->reset(); }

        $io         = new SymfonyStyle($input, $output);
        $resume     = (bool)$input->getOption('resume');
        $force      = (bool)$input->getOption('force');
        $startSym   = $input->getOption('start-symbol');
        $startTf    = $input->getOption('start-tf');
        $tolerance  = max(0, (int)$input->getOption('tolerance'));
        $limitGoal  = max(1, (int)$input->getOption('limit'));
        $symbols  = $input->getArgument('symbols') ?? false;

        $io->title('Fetch klines (scalping) — reprise depuis la DB');

        // Curseur contrats dans l’ordre
        $qb = $this->contracts->createQueryBuilder('c')->orderBy('c.symbol', 'ASC');
        $iter = $qb->getQuery()->toIterable();

        $tfs = array_keys($this->timeframes);
        $started = empty($startSym) && empty($startTf);

        $persistedTotal = 0;
        $batchCount = 0;

        if ($symbols !== false) {
            $iter = $this->contracts->createQueryBuilder('c')
                ->where('c.symbol IN (:syms)')->setParameter('syms', explode(',', $symbols))
            ->orderBy('c.symbol', 'ASC')->getQuery()->toIterable();
        }

        foreach ($iter as $contract) {
            /** @var object $contract */
            $symbol = method_exists($contract, 'getSymbol') ? strtoupper((string)$contract->getSymbol()) : null;
            if (!$symbol) { continue; }

            // Respecter start-symbol si fourni
            if (!$started && is_string($startSym) && $symbol < strtoupper($startSym)) { continue; }

            foreach ($tfs as $tf) {
                if (!$started) {
                    if (strtoupper($symbol) === strtoupper((string)$startSym)) {
                        if (is_string($startTf) && $startTf !== '' && $tf !== $startTf) { continue; }
                        $started = true; // on démarre ici
                    } else {
                        continue;
                    }
                }

                $step = $this->timeframes[$tf];
                $now  = new \DateTimeImmutable();

                // Fenêtre cible « complète »
                $windowStart = $now->sub(new \DateInterval('PT' . (int)($limitGoal * $step) . 'M'));

                // 1) Logique de reprise basée DB
                //    a) si --force: on saute l’inspection DB
                //    b) sinon, on calcule la « couverture récente » dans la fenêtre et/ou le dernier timestamp
                $needFetch = true;
                $sinceTs   = null; // si renseigné, on ne fetch que > sinceTs

                if (!$force && $resume) {
                    // Compter les klines récentes dans la fenêtre (>= windowStart)
                    $recentCount = (int)$this->klines->createQueryBuilder('k')
                        ->select('COUNT(k.id)')
                        ->andWhere('k.contract = :c')->setParameter('c', $contract)
                        ->andWhere('k.step = :s')->setParameter('s', $step)
                        ->andWhere('k.timestamp >= :w')->setParameter('w', $windowStart)
                        ->getQuery()->getSingleScalarResult();

                    // Dernier kline (pour sinceTs fin)
                    $last = $this->klines->createQueryBuilder('k2')
                        ->andWhere('k2.contract = :c')->setParameter('c', $contract)
                        ->andWhere('k2.step = :s')->setParameter('s', $step)
                        ->orderBy('k2.timestamp', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()->getOneOrNullResult();

                    if ($recentCount >= ($limitGoal - $tolerance)) {
                        // On considère cette (pair) déjà couverte → skip
                        $io->writeln("<comment>{$symbol} {$tf}</comment>: skip (DB couvre {$recentCount}/{$limitGoal})");
                        $needFetch = false;
                    } else {
                        // On ne prend que le delta manquant (si on a un last)
                        if ($last && method_exists($last, 'getTimestamp')) {
                            /** @var \DateTimeInterface $ts */
                            $ts = $last->getTimestamp();
                            $sinceTs = $ts->getTimestamp(); // unix ts
                        }
                    }
                }

                if (!$needFetch) { $this->softPurge(); continue; }

                try {
                    // 2) Calcul période à récupérer
                    //    - si sinceTs présent → on repart juste après
                    //    - sinon → on prend la fenêtre "complète"
                    if ($sinceTs) {
                        $start = (new \DateTimeImmutable())->setTimestamp($sinceTs + $step);
                    } else {
                        $start = $windowStart;
                    }

                    // 3) Fetch → persist par chunks (économie mémoire)
                    $dtos = $this->bitmartFetcher->fetchKlines($symbol, $start, $now, $step);

                    $count = is_countable($dtos) ? count($dtos) : 0;
                    for ($offset = 0; $offset < $count; $offset += self::CHUNK_SIZE) {
                        $chunk = array_slice($dtos, $offset, self::CHUNK_SIZE);
                        $this->persister->persistMany($contract, $chunk, $step, flush: true);
                        $batchCount += count($chunk);
                        $persistedTotal += count($chunk);

                        if ($batchCount >= self::BATCH_FLUSH_EVERY) {
                            $this->softPurge();
                            $batchCount = 0;
                        }
                    }
                    unset($dtos);

                    $io->writeln("<info>{$symbol} {$tf}</info>: OK");
                } catch (\Throwable $e) {
                    $io->writeln("<error>{$symbol} {$tf}</error>: {$e->getMessage()}");
                    // on continue: la reprise DB gérera au prochain run
                }

                $this->softPurge(); // purge fine entre TF
            }

            $this->softPurge(); // purge entre contrats
        }

        $io->success("Terminé. Persisted: {$persistedTotal}");
        return Command::SUCCESS;
    }

    private function softPurge(): void
    {
        if (method_exists($this->persister, 'clear')) { $this->persister->clear(); }
        if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
        if (function_exists('gc_mem_caches')) { gc_mem_caches(); }
        if (method_exists($this->logger, 'reset')) { $this->logger->reset(); }
    }
}
