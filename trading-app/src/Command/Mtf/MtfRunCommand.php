<?php

declare(strict_types=1);

namespace App\Command\Mtf;

use App\Domain\Mtf\Service\MtfRunService;
use App\Infrastructure\Http\BitmartRestClient;
use App\Repository\ContractRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mtf:run', description: 'Exécute un cycle MTF pour une liste de symboles (dry-run par défaut) avec un output détaillé')]
class MtfRunCommand extends Command
{
    public function __construct(
        private readonly MtfRunService $mtfRunService,
        private readonly BitmartRestClient $bitmartClient,
        private readonly ContractRepository $contractRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbols', null, InputOption::VALUE_OPTIONAL, 'Liste de symboles séparés par des virgules (ex: BTCUSDT,ETHUSDT). Par défaut: configurés dans le service')
            ->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'Exécuter en mode simulation (1|0)', '1')
            ->addOption('force-run', null, InputOption::VALUE_NONE, 'Force l\'exécution même si les switchs globaux ou symboles sont OFF')
            ->addOption('tf', null, InputOption::VALUE_OPTIONAL, 'Limiter l\'exécution à un unique timeframe (4h|1h|15m|5m|1m)')
            ->addOption('sync-contracts', null, InputOption::VALUE_NONE, 'Forcer la synchronisation (fetch + upsert) des contrats au démarrage (activé par défaut)')
            ->addOption('force-timeframe-check', null, InputOption::VALUE_NONE, 'Force l\'analyse du timeframe même si la dernière kline est récente');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbolsOpt = (string) ($input->getOption('symbols') ?? '');
        $symbols = array_values(array_filter(array_map('trim', $symbolsOpt !== '' ? explode(',', $symbolsOpt) : [])));
        $dryRun = ((string) $input->getOption('dry-run')) !== '0';
        $forceRun = (bool) $input->getOption('force-run');
        $currentTf = $input->getOption('tf');
        $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;
        $syncContractsOpt = (bool) $input->getOption('sync-contracts');
        $forceTimeframeCheck = (bool) $input->getOption('force-timeframe-check');

        $io->title('MTF Run');
        $io->text([
            'Options:',
            sprintf('- symbols: %s', $symbols ? implode(', ', $symbols) : '(par défaut)'),
            sprintf('- dry-run: %s', $dryRun ? 'oui' : 'non'),
            sprintf('- force-run: %s', $forceRun ? 'oui' : 'non'),
            sprintf('- tf: %s', $currentTf ?? '(multi-tf)'),
            sprintf('- force-timeframe-check: %s', $forceTimeframeCheck ? 'oui' : 'non'),
        ]);

        try {
            // Synchronisation des contrats (upsert) par défaut
            $io->section('Synchronisation des contrats (BitMart)');
            try {
                $fetchedContracts = [];
                if (!empty($symbols)) {
                    foreach ($symbols as $s) {
                        $fetchedContracts[] = $this->bitmartClient->fetchContractDetails($s);
                    }
                } else {
                    $fetchedContracts = $this->bitmartClient->fetchContracts();
                }

                $upserted = 0;
                if (!empty($fetchedContracts)) {
                    $upserted = $this->contractRepository->upsertContracts($fetchedContracts);
                    $io->success(sprintf('%d contrat(s) synchronisé(s) (upsert)', $upserted));
                } else {
                    $io->warning('Aucun contrat récupéré depuis l\'API BitMart');
                }
                
                // Ne pas écraser les symboles fournis par l'utilisateur
                if (empty($symbols)) {
                    $symbols = $this->contractRepository->allActiveSymbolNames();
                }
            } catch (\Throwable $e) {
                // On loggue mais on continue le run MTF
                $io->warning('Synchronisation des contrats échouée: ' . $e->getMessage());
            }

            $io->section('Exécution MTF en cours...');
            
            $generator = $this->mtfRunService->run($symbols, $dryRun, $forceRun, $currentTf, $forceTimeframeCheck);
            $summary = [];
            $details = [];
            
            // Consommer le generator et afficher le progrès en temps réel
            foreach ($generator as $yieldedData) {
                $symbol = $yieldedData['symbol'];
                $result = $yieldedData['result'];
                $progress = $yieldedData['progress'];
                
                if ($symbol === 'FINAL') {
                    // C'est le résultat final
                    $summary = $result;
                    break;
                }
                
                // Afficher le progrès pour chaque symbole
                $io->writeln(sprintf(
                    '[%s%%] %s - %s',
                    $progress['percentage'],
                    $symbol,
                    $progress['status']
                ));
                
                // Stocker le résultat pour l'affichage final
                $details[$symbol] = $result;
            }
            
            // Récupérer le résultat final du generator après avoir consommé tous les éléments
            try {
                $finalResult = $generator->getReturn();
                if ($finalResult && isset($finalResult['results'])) {
                    $details = $finalResult['results'];
                }
            } catch (\Exception $e) {
                // Si getReturn() échoue, on utilise les détails déjà collectés
                $io->writeln('Note: Impossible de récupérer le résultat final du generator');
            }

            $io->section('Résumé');
            $io->definitionList(
                ['Run ID' => $summary['run_id'] ?? '-'],
                ['Statut' => $summary['status'] ?? '-'],
                ['Temps d\'exécution (s)' => (string) ($summary['execution_time_seconds'] ?? '-')],
                ['Demandés' => (string) ($summary['symbols_requested'] ?? '-')],
                ['Traités' => (string) ($summary['symbols_processed'] ?? '-')],
                ['Succès' => (string) ($summary['symbols_successful'] ?? '-')],
                ['Échecs' => (string) ($summary['symbols_failed'] ?? '-')],
                ['Ignorés' => (string) ($summary['symbols_skipped'] ?? '-')],
                ['Taux de succès (%)' => (string) ($summary['success_rate'] ?? '-')],
                ['Dry run' => ($summary['dry_run'] ?? true) ? 'oui' : 'non'],
                ['Force run' => ($summary['force_run'] ?? false) ? 'oui' : 'non'],
                ['TF courant' => $summary['current_tf'] ?? '(multi-tf)'],
                ['Timestamp' => $summary['timestamp'] ?? '-'],
            );

            if (!empty($details)) {
                $io->section('Détails par symbole');
                foreach ($details as $symbol => $info) {
                    $status = $info['status'] ?? '-';
                    $io->writeln(sprintf('<info>%s</info> - statut: <comment>%s</comment>', $symbol, $status));

                    if (isset($info['reason'])) {
                        $io->writeln(sprintf('  Raison: %s', (string) $info['reason']));
                    }

                    if (isset($info['failed_timeframe'])) {
                        $io->writeln(sprintf('  Rejeté sur TF: %s', (string) $info['failed_timeframe']));
                    }

                    // Afficher les conditions échouées si disponibles
                    $failedLong = (array)($info['failed_conditions_long'] ?? []);
                    $failedShort = (array)($info['failed_conditions_short'] ?? []);
                    if (!empty($failedLong) || !empty($failedShort)) {
                        $io->writeln('  Conditions échouées:');
                        if (!empty($failedLong)) {
                            $io->writeln('    - LONG: ' . implode(', ', array_map('strval', $failedLong)));
                        }
                        if (!empty($failedShort)) {
                            $io->writeln('    - SHORT: ' . implode(', ', array_map('strval', $failedShort)));
                        }
                    }

                    if (isset($info['error'])) {
                        $io->writeln(sprintf('  Erreur: %s', (string) $info['error']));
                    }

                    if (isset($info['signal_side'])) {
                        $io->writeln(sprintf('  Signal side: %s', (string) $info['signal_side']));
                    }

                    if (isset($info['timeframe'])) {
                        $io->writeln(sprintf('  TF: %s', (string) $info['timeframe']));
                    }

                    if (array_key_exists('should_descend', $info)) {
                        $io->writeln(sprintf('  Should descend: %s', $info['should_descend'] ? 'oui' : 'non'));
                    }

                    if (isset($info['next_tf'])) {
                        $io->writeln(sprintf('  Next TF: %s', (string) ($info['next_tf'] ?? '-')));
                    }

                    if (isset($info['validation_state']) && is_array($info['validation_state'])) {
                        $io->writeln('  Validation:');
                        $io->writeln(sprintf('    - status: %s', (string) ($info['validation_state']['status'] ?? '-')));
                    }

                    if (isset($info['steps']) && is_array($info['steps'])) {
                        $io->writeln('  Étapes:');
                        foreach (['4h','1h','15m','5m','1m'] as $tf) {
                            if (!isset($info['steps'][$tf])) {
                                continue;
                            }
                            $step = $info['steps'][$tf];
                            $s = $step['status'] ?? '-';
                            $rs = $step['reason'] ?? '';
                            $kt = isset($step['kline_time']) && $step['kline_time'] instanceof \DateTimeImmutable ? $step['kline_time']->format('Y-m-d H:i:s') : ($step['kline_time'] ?? '-');
                            $io->writeln(sprintf('    - %s: %s%s%s', $tf, $s, $rs ? " (".$rs.")" : '', $kt ? " | kline_time=".$kt : ''));
                        }
                    }

                    if (isset($info['filters']) && is_array($info['filters'])) {
                        $io->writeln('  Filtres d\'exécution:');
                        $io->writeln(sprintf('    - statut: %s', (string) ($info['filters']['status'] ?? '-')));
                        if (!empty($info['filters']['details'])) {
                            $io->writeln('    - détails:');
                            foreach ((array) $info['filters']['details'] as $k => $v) {
                                $io->writeln(sprintf('        %s: %s', (string) $k, is_scalar($v) ? (string) $v : json_encode($v)));
                            }
                        }
                    }

                    $io->newLine();
                }

                // Ajouter les résumés demandés
                $this->displaySummaryByStatus($io, $details);
                $this->displaySummaryByReason($io, $details);
                $this->displaySummaryByRejectedTimeframe($io, $details);
                $this->displaySummaryByLastValidTimeframe($io, $details);
            }

            $io->success('MTF run terminé.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Affiche un résumé groupé par statut
     */
    private function displaySummaryByStatus(SymfonyStyle $io, array $details): void
    {
        $statusGroups = [];
        
        foreach ($details as $symbol => $info) {
            $status = $info['status'] ?? 'UNKNOWN';
            if (!isset($statusGroups[$status])) {
                $statusGroups[$status] = [];
            }
            $statusGroups[$status][] = $symbol;
        }

        if (!empty($statusGroups)) {
            $io->section('Résumé par statut');
            foreach ($statusGroups as $status => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $status, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Affiche un résumé groupé par raison
     */
    private function displaySummaryByReason(SymfonyStyle $io, array $details): void
    {
        $reasonGroups = [];
        
        foreach ($details as $symbol => $info) {
            $reason = $info['reason'] ?? 'N/A';
            if (!isset($reasonGroups[$reason])) {
                $reasonGroups[$reason] = [];
            }
            $reasonGroups[$reason][] = $symbol;
        }

        if (!empty($reasonGroups)) {
            $io->section('Résumé par raison');
            foreach ($reasonGroups as $reason => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $reason, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Affiche un résumé groupé par timeframe rejeté
     */
    private function displaySummaryByRejectedTimeframe(SymfonyStyle $io, array $details): void
    {
        $rejectedTfGroups = [];
        
        foreach ($details as $symbol => $info) {
            $rejectedTf = $info['failed_timeframe'] ?? null;
            if ($rejectedTf) {
                if (!isset($rejectedTfGroups[$rejectedTf])) {
                    $rejectedTfGroups[$rejectedTf] = [];
                }
                $rejectedTfGroups[$rejectedTf][] = $symbol;
            }
        }

        if (!empty($rejectedTfGroups)) {
            $io->section('Résumé par timeframe rejeté');
            foreach ($rejectedTfGroups as $tf => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $tf, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Affiche un résumé groupé par dernier timeframe validé
     */
    private function displaySummaryByLastValidTimeframe(SymfonyStyle $io, array $details): void
    {
        $lastValidTfGroups = [];
        
        foreach ($details as $symbol => $info) {
            $lastValidTf = $this->getLastValidTimeframe($info);
            if ($lastValidTf) {
                if (!isset($lastValidTfGroups[$lastValidTf])) {
                    $lastValidTfGroups[$lastValidTf] = [];
                }
                $lastValidTfGroups[$lastValidTf][] = $symbol;
            }
        }

        if (!empty($lastValidTfGroups)) {
            $io->section('Résumé par dernier TF validé');
            // Trier par ordre décroissant des timeframes (4h, 1h, 15m, 5m, 1m)
            $tfOrder = ['4h' => 5, '1h' => 4, '15m' => 3, '5m' => 2, '1m' => 1];
            uksort($lastValidTfGroups, function($a, $b) use ($tfOrder) {
                return ($tfOrder[$b] ?? 0) - ($tfOrder[$a] ?? 0);
            });
            
            foreach ($lastValidTfGroups as $tf => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $tf, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Détermine le dernier timeframe validé pour un symbole
     */
    private function getLastValidTimeframe(array $info): ?string
    {
        if (!isset($info['steps']) || !is_array($info['steps'])) {
            return null;
        }

        $timeframes = ['4h', '1h', '15m', '5m', '1m'];
        
        foreach ($timeframes as $tf) {
            if (isset($info['steps'][$tf])) {
                $step = $info['steps'][$tf];
                $status = $step['status'] ?? '';
                
                // Si le statut est 'success' ou 'valid', c'est le dernier TF validé
                if (in_array($status, ['success', 'valid', 'completed'])) {
                    return $tf;
                }
            }
        }

        return null;
    }
}
