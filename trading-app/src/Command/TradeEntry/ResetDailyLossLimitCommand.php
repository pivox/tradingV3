<?php

declare(strict_types=1);

namespace App\Command\TradeEntry;

use App\Config\TradeEntryConfigResolver;
use App\TradeEntry\Policy\DailyLossGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:daily-loss:reset',
    description: 'Réinitialise le daily loss limit pour un ou tous les modes de trading',
)]
final class ResetDailyLossLimitCommand extends Command
{
    public function __construct(
        private readonly DailyLossGuard $dailyLossGuard,
        private readonly TradeEntryConfigResolver $configResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Mode spécifique à réinitialiser (ex: regular, scalper, scalper_micro). Si non fourni, réinitialise tous les modes', null)
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Réinitialiser tous les modes activés (comportement par défaut)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer la réinitialisation sans confirmation')
            ->setHelp(<<<'HELP'
Cette commande réinitialise le daily loss limit en supprimant les fichiers de lock
et en réinitialisant la baseline avec les valeurs actuelles du compte (equity/available).

Exemples:

  # Réinitialiser tous les modes (comportement par défaut)
  php bin/console app:daily-loss:reset

  # Réinitialiser tous les modes explicitement
  php bin/console app:daily-loss:reset --all

  # Réinitialiser un mode spécifique
  php bin/console app:daily-loss:reset --mode=scalper_micro

  # Réinitialiser sans confirmation
  php bin/console app:daily-loss:reset --force

La réinitialisation va:
  - Supprimer le fichier de lock du mode
  - Réinitialiser la baseline avec la valeur actuelle du compte
  - Déverrouiller le mode si il était verrouillé
  - Remettre le PnL du jour à 0
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = $input->getOption('mode');
        $all = $input->getOption('all');
        $force = $input->getOption('force');

        // Déterminer les modes à réinitialiser
        $modesToReset = [];
        if ($mode !== null) {
            // Mode spécifique demandé
            $modesToReset = [$mode];
        } else {
            // Par défaut, réinitialiser tous les modes activés
            $enabledModes = $this->configResolver->getEnabledModes();
            if ($enabledModes === []) {
                $io->warning('Aucun mode activé trouvé. Utilisation du mode par défaut.');
                $modesToReset = [null]; // null = mode par défaut
            } else {
                $modesToReset = array_map(
                    fn(array $modeData): string => $modeData['name'],
                    $enabledModes
                );
            }
        }

        $io->title('Réinitialisation du Daily Loss Limit');

        // Afficher les modes qui seront réinitialisés
        $io->section('Modes à réinitialiser');
        if (count($modesToReset) === 1 && $modesToReset[0] === null) {
            $io->text('Mode par défaut (sera résolu automatiquement)');
        } else {
            $io->listing($modesToReset);
        }

        // Afficher l'état actuel avant réinitialisation
        $io->section('État actuel');
        $currentStates = [];
        foreach ($modesToReset as $modeToReset) {
            try {
                $state = $this->dailyLossGuard->checkAndMaybeLock($modeToReset);
                $currentStates[] = [
                    'Mode' => $modeToReset ?? '(défaut)',
                    'Date' => $state['date'] ?? 'N/A',
                    'Baseline' => $state['start_measure'] !== null ? number_format($state['start_measure'], 2) : 'N/A',
                    'Mesure actuelle' => number_format($state['measure_value'] ?? 0, 2),
                    'PnL aujourd\'hui' => number_format($state['pnl_today'] ?? 0, 2),
                    'Limite (USDT)' => number_format($state['limit_usdt'] ?? 0, 2),
                    'Verrouillé' => ($state['locked'] ?? false) ? 'OUI' : 'NON',
                ];
            } catch (\Throwable $e) {
                $io->warning(sprintf('Impossible de récupérer l\'état pour le mode "%s": %s', $modeToReset ?? 'défaut', $e->getMessage()));
            }
        }

        if ($currentStates !== []) {
            $io->table(
                array_keys($currentStates[0]),
                array_map(fn(array $row): array => array_values($row), $currentStates)
            );
        }

        // Confirmation
        if (!$force) {
            if (!$io->confirm(sprintf('Êtes-vous sûr de vouloir réinitialiser %d mode(s) ?', count($modesToReset)), false)) {
                $io->info('Opération annulée');
                return Command::SUCCESS;
            }
        }

        // Réinitialisation
        $io->section('Réinitialisation en cours');
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $newStates = [];

        $io->progressStart(count($modesToReset));

        foreach ($modesToReset as $modeToReset) {
            try {
                $newState = $this->dailyLossGuard->reset($modeToReset);
                $newStates[] = [
                    'Mode' => $modeToReset ?? '(défaut)',
                    'Date' => $newState['date'] ?? 'N/A',
                    'Nouvelle baseline' => number_format($newState['start_measure'] ?? 0, 2),
                    'Mesure' => $newState['measure'] ?? 'N/A',
                    'Limite (USDT)' => number_format($newState['limit_usdt'] ?? 0, 2),
                    'Verrouillé' => ($newState['locked'] ?? false) ? 'OUI' : 'NON',
                ];
                $successCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                $errors[] = sprintf('%s: %s', $modeToReset ?? 'défaut', $e->getMessage());
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Résultats
        $io->section('Résultats');
        if ($successCount > 0) {
            $io->success(sprintf('%d mode(s) réinitialisé(s) avec succès', $successCount));

            if ($newStates !== []) {
                $io->section('Nouvel état');
                $io->table(
                    array_keys($newStates[0]),
                    array_map(fn(array $row): array => array_values($row), $newStates)
                );
            }
        }

        if ($errorCount > 0) {
            $io->error(sprintf('%d erreur(s) rencontrée(s):', $errorCount));
            $io->listing($errors);
        }

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

