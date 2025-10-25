<?php

declare(strict_types=1);

namespace App\Command\Provider;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use App\Provider\Bitmart\Dto\ContractDto;
use App\Repository\KlineRepository;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:fetch-all-klines',
    description: 'Récupère et persiste les klines 4h et 1h pour tous les contrats BitMart'
)]
final class FetchAllKlinesCommand extends Command
{
    private const RATE_LIMIT_DELAY_MS = 200; // 200ms entre chaque requête
    private const BATCH_SIZE = 10; // Traiter par lots de 10 contrats

    public function __construct(
        private readonly MainProviderInterface $providerService,
        private readonly KlineRepository $klineRepository,
        private readonly ClockInterface $clock
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à récupérer par contrat', '100')
            ->addOption('contracts', 'c', InputOption::VALUE_OPTIONAL, 'Nombre de contrats à traiter (0 = tous)', '0')
            ->addOption('timeframes', 't', InputOption::VALUE_OPTIONAL, 'Timeframes à traiter (4h,1h)', '4h,1h')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation sans persistance en base')
            ->setHelp('
Cette commande récupère et persiste les klines pour tous les contrats BitMart Futures.

Exemples:
  php bin/console bitmart:fetch-all-klines
  php bin/console bitmart:fetch-all-klines --limit=50 --contracts=5
  php bin/console bitmart:fetch-all-klines --timeframes=4h --dry-run
  php bin/console bitmart:fetch-all-klines --contracts=10 --limit=200
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        $maxContracts = (int) $input->getOption('contracts');
        $timeframesStr = $input->getOption('timeframes');
        $dryRun = $input->getOption('dry-run');

        try {
            $io->title('Récupération et persistance des klines pour tous les contrats');

            // Parse des timeframes
            $timeframes = $this->parseTimeframes($timeframesStr);
            if (empty($timeframes)) {
                $io->error('Aucun timeframe valide spécifié');
                return Command::FAILURE;
            }

            $io->info(sprintf(
                'Configuration: %d klines par contrat, %s contrats max, timeframes: %s',
                $limit,
                $maxContracts === 0 ? 'tous' : $maxContracts,
                implode(', ', array_map(fn($tf) => $tf->value, $timeframes))
            ));

            if ($dryRun) {
                $io->warning('Mode simulation activé - aucune donnée ne sera persistée');
            }

            // Récupérer tous les contrats
            $io->section('Récupération de la liste des contrats');
            $allContracts = $this->providerService->getContractProvider()->getContracts();

            if (empty($allContracts)) {
                $io->error('Aucun contrat trouvé');
                return Command::FAILURE;
            }

            // Filtrer les contrats actifs
            $io->info('Filtrage des contrats actifs...');
            $contracts = $this->filterActiveContracts($allContracts);

            if (empty($contracts)) {
                $io->error('Aucun contrat actif trouvé après filtrage');
                return Command::FAILURE;
            }

            $io->info(sprintf(
                'Contrats filtrés: %d actifs sur %d total',
                count($contracts),
                count($allContracts)
            ));

            // Limiter le nombre de contrats si spécifié
            if ($maxContracts > 0 && $maxContracts < count($contracts)) {
                $contracts = array_slice($contracts, 0, $maxContracts);
            }

            $io->success(sprintf('Trouvé %d contrat(s) à traiter', count($contracts)));

            // Traitement par lots
            $totalProcessed = 0;
            $totalKlines = 0;
            $errors = [];

            $batches = array_chunk($contracts, self::BATCH_SIZE);

            foreach ($batches as $batchIndex => $batch) {
                $io->section(sprintf('Traitement du lot %d/%d (%d contrats)',
                    $batchIndex + 1,
                    count($batches),
                    count($batch)
                ));

                foreach ($batch as $contractIndex => $contract) {
                    $symbol = $contract->symbol ?? 'UNKNOWN';
                    $currentIndex = $batchIndex * self::BATCH_SIZE + $contractIndex + 1;

                    $io->writeln(sprintf(
                        '<info>[%d/%d]</info> Traitement de <comment>%s</comment>',
                        $currentIndex,
                        count($contracts),
                        $symbol
                    ));

                    try {
                        $contractKlines = $this->processContract($symbol, $timeframes, $limit, $dryRun, $io);
                        $totalKlines += $contractKlines;
                        $totalProcessed++;

                        $io->writeln(sprintf(
                            '  ✓ %d klines récupérées et %s',
                            $contractKlines,
                            $dryRun ? 'simulées' : 'persistées'
                        ));

                    } catch (\Exception $e) {
                        $errors[] = [
                            'symbol' => $symbol,
                            'error' => $e->getMessage()
                        ];

                        $io->writeln(sprintf(
                            '  ✗ Erreur: <error>%s</error>',
                            $e->getMessage()
                        ));
                    }

                    // Délai de 200ms entre chaque requête
                    if ($currentIndex < count($contracts)) {
                        usleep(self::RATE_LIMIT_DELAY_MS * 1000);
                    }
                }

                // Pause plus longue entre les lots
                if ($batchIndex < count($batches) - 1) {
                    $io->writeln('<comment>Pause entre les lots...</comment>');
                    sleep(1);
                }
            }

            // Résumé final
            $this->displaySummary($io, $totalProcessed, $totalKlines, $errors, $dryRun);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de l\'exécution: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function parseTimeframes(string $timeframesStr): array
    {
        $timeframeStrings = array_map('trim', explode(',', $timeframesStr));
        $timeframes = [];

        foreach ($timeframeStrings as $tfStr) {
            $timeframe = match ($tfStr) {
                '4h' => Timeframe::TF_4H,
                '1h' => Timeframe::TF_1H,
                '15m' => Timeframe::TF_15M,
                '5m' => Timeframe::TF_5M,
                '1m' => Timeframe::TF_1M,
                default => null
            };

            if ($timeframe) {
                $timeframes[] = $timeframe;
            }
        }

        return $timeframes;
    }

    private function processContract(string $symbol, array $timeframes, int $limit, bool $dryRun, SymfonyStyle $io): int
    {
        $totalKlines = 0;


        foreach ($timeframes as $timeframe) {
            $io->writeln(sprintf('    Récupération des klines %s...', $timeframe->value));

            // Récupérer les klines
            $klines = $this->providerService->getKlineProvider()->getKlines($symbol, $timeframe);

            if (empty($klines)) {
                $io->writeln(sprintf('    ⚠ Aucune kline %s trouvée', $timeframe->value));
                continue;
            }

            // Persister en base (ou simuler)
            if (!$dryRun) {
                $this->klineRepository->upsertKlines($klines);
            }

            $totalKlines += count($klines);
            $io->writeln(sprintf('    ✓ %d klines %s traitées', count($klines), $timeframe->value));
        }

        return $totalKlines;
    }

    private function displaySummary(SymfonyStyle $io, int $totalProcessed, int $totalKlines, array $errors, bool $dryRun): void
    {
        $io->section('Résumé de l\'exécution');

        $summary = [
            'Contrats traités' => $totalProcessed,
            'Klines ' . ($dryRun ? 'simulées' : 'persistées') => $totalKlines,
            'Erreurs' => count($errors)
        ];

        foreach ($summary as $key => $value) {
            $io->writeln(sprintf('<info>%s:</info> %s', $key, $value));
        }

        if (!empty($errors)) {
            $io->section('Erreurs rencontrées');
            foreach ($errors as $error) {
                $io->writeln(sprintf(
                    '<error>%s:</error> %s',
                    $error['symbol'],
                    $error['error']
                ));
            }
        }

        if (!$dryRun && $totalKlines > 0) {
            $io->success(sprintf(
                '✅ %d klines ont été persistées en base de données pour %d contrats',
                $totalKlines,
                $totalProcessed
            ));
        } elseif ($dryRun) {
            $io->note('Mode simulation - aucune donnée persistée');
        }
    }

    /**
     * Filtre les contrats actifs selon les critères de trading
     */
    private function filterActiveContracts(array $contracts): array
    {
        $activeContracts = [];
        $blacklistedSymbols = $this->getBlacklistedSymbols();

        foreach ($contracts as $contract) {
            // Vérifier les critères de base
            if (!$this->isContractActive($contract)) {
                continue;
            }

            // Vérifier si le symbole n'est pas blacklisté
            $symbol = $contract->symbol?? '';
            if (in_array($symbol, $blacklistedSymbols)) {
                continue;
            }

            $activeContracts[] = $contract;
        }

        return $activeContracts;
    }

    /**
     * Vérifie si un contrat est actif selon les critères
     */
    private function isContractActive(ContractDto $contract): bool
    {
        // 1. Devise de quote doit être USDT
        $quoteCurrency = $contract->quoteCurrency ?? '';
        if ($quoteCurrency !== 'USDT') {
            return false;
        }

        // 2. Statut doit être "Trading"
        $status = $contract->status ?? '';
        if ($status !== 'Trading') {
            return false;
        }

        // 3. Volume 24h minimum de 500,000 USDT
        $volume24h = $contract->volume24h->toFloat() ?? 0;
        if ($volume24h < 500_000) {
            return false;
        }

        // 4. Vérifier l'open interest (si disponible)
        // Note: BitMart ne fournit pas toujours l'open interest dans l'API publique
        // On peut ajouter cette vérification si nécessaire

        // 5. Vérifier que le contrat n'est pas trop récent (moins de 880 heures = 36.67 jours)
        $openTimestamp = $contract->openTimestamp->getTimestamp() ?? 0;
        $tz = new DateTimeZone('UTC');
        if ($openTimestamp > 0) {
            $openDate = new \DateTimeImmutable('@' . ($openTimestamp / 1000), $tz);
            $minDate = (new \DateTimeImmutable('now', $tz))
                ->modify('-880 hours');

            if ($openDate > $minDate) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retourne la liste des symboles blacklistés
     * Pour l'instant, on peut hardcoder quelques symboles problématiques
     */
    private function getBlacklistedSymbols(): array
    {
        return [
            // Ajouter ici les symboles à blacklister
            // Exemples de symboles souvent problématiques :
            // 'LUNAUSDT',
            // 'USTCUSDT',
        ];
    }
}
