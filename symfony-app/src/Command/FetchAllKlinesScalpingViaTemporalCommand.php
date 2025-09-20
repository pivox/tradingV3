<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-all:klines-scalping-via-temporal',
    description: 'Déclenche via Temporal la récupération des klines SCALPING (4h,1h,15m,5m,1m) pour TOUS les contrats. Le fetch/persist se fait côté callback Symfony.'
)]
final class FetchAllKlinesScalpingViaTemporalCommand extends Command
{
    private const BASE_URL = 'http://nginx';
    private const CALLBACK = 'api/callback/bitmart/get-kline';

    /** SCALPING (ordre du plus large au plus fin) */
    private const SCALPING_TF = ['4h','1h','15m','5m','1m'];

    /** TF → pas en secondes (utile pour sinceTs et fenêtres) */
    private const STEP = [
        '1m'=>1, '5m'=>5, '15m'=>15, '1h'=>60, '4h'=>240,
    ];

    public function __construct(
        private readonly ContractRepository   $contracts,
        private readonly KlineRepository      $klines,
        private readonly BitmartOrchestrator  $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre de klines cible (hint au callback)', 200)
            ->addOption('since-db', null, InputOption::VALUE_NONE, 'Si présent, envoie sinceTs = dernier kline DB par (contrat,TF) pour ne demander que le delta')
            ->addOption('timeframe', null, InputOption::VALUE_REQUIRED, 'Un seul TF à traiter (4h,1h,15m,5m,1m). Si omis → tous les TF scalping')
            ->addOption('start-symbol', null, InputOption::VALUE_REQUIRED, 'Commencer à partir de ce symbole (ex: JUPUSDT)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Note libre jointe à l’enveloppe', 'depuis CLI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $limit    = (int)$input->getOption('limit');
        $sinceDb  = (bool)$input->getOption('since-db');
        $tfSingle = $input->getOption('timeframe');
        $startSym = $input->getOption('start-symbol');
        $note     = (string)$input->getOption('note');

        // TF cible(s)
        $tfs = $this->resolveTimeframes($tfSingle);
        if (empty($tfs)) {
            $io->error('Timeframe non supporté. Utilise 4h,1h,15m,5m,1m ou laisse vide pour tous.');
            return Command::FAILURE;
        }

        // Liste des contrats (tri par symbole, et point de départ éventuel)
        $qb = $this->contracts->createQueryBuilder('c')->orderBy('c.symbol', 'ASC');
        $iter = $qb->getQuery()->toIterable();

        $io->title('📈 Bitmart Klines (SCALPING) via Temporal (signals → callback)');
        $io->text(sprintf(
            'TF: %s | limit=%d | since-db=%s%s',
            implode(',', $tfs), $limit, $sinceDb ? 'ON' : 'OFF',
            $startSym ? " | start-symbol=".$startSym : ''
        ));

        // Référence de workflow (mêmes valeurs que ton code existant)
        $ref = new WorkflowRef(
            id:        'rate-limited-echo',
            type:      'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue',
        );

        $sent = 0;

        foreach ($iter as $contract) {
            /** @var object $contract */
            $symbol = method_exists($contract, 'getSymbol') ? strtoupper((string)$contract->getSymbol()) : null;
            if (!$symbol) { continue; }

            if (is_string($startSym) && $startSym !== '' && $symbol < strtoupper($startSym)) {
                continue; // saute jusqu’au point de départ
            }

            foreach ($tfs as $tf) {
                $step = self::STEP[$tf] ?? null;
                if (!$step) { continue; }

                $sinceTs = null;
                if ($sinceDb) {
                    // Dernier kline pour (contrat, step)
                    $last = $this->klines->createQueryBuilder('k')
                        ->andWhere('k.contract = :c')->setParameter('c', $contract)
                        ->andWhere('k.step = :s')->setParameter('s', $step)
                        ->orderBy('k.timestamp', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()->getOneOrNullResult();
                    if ($last && method_exists($last, 'getTimestamp')) {
                        /** @var \DateTimeInterface $ts */
                        $ts = $last->getTimestamp();
                        $sinceTs = $ts->getTimestamp(); // Unix ts
                    }
                }

                try {
                    $this->orchestrator->requestGetKlines(
                        ref:      $ref,
                        baseUrl:  self::BASE_URL,
                        callback: self::CALLBACK,
                        contract: $symbol,
                        timeframe:$tf,
                        limit:    $limit,
                        sinceTs:  $sinceTs,
                        note:     $note
                    );
                    $sent++;
                    $io->writeln("<info>{$symbol} {$tf}</info>: signal envoyé".($sinceTs ? " (sinceTs={$sinceTs})" : ''));
                } catch (\Throwable $e) {
                    $io->writeln("<error>{$symbol} {$tf}</error>: {$e->getMessage()}");
                    // on continue, les autres paires (symbole,tf) seront envoyées
                }
            }

        }

        $io->success(sprintf("Signals envoyés: %s. Le workflow throttle et POST sur %s/%s.", $sent, $this::BASE_URL, $this::CALLBACK));
        $io->writeln('ℹ️ Le fetch/persist est exécuté côté Symfony dans le contrôleur de callback.');
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTimeframes(mixed $tfSingle): array
    {
        if (is_string($tfSingle) && $tfSingle !== '') {
            $tfSingle = strtolower($tfSingle);
            return \in_array($tfSingle, self::SCALPING_TF, true) ? [$tfSingle] : [];
        }
        return self::SCALPING_TF;
    }
}
