<?php

declare(strict_types=1);

namespace App\MtfValidator\Command;

use App\MtfValidator\Repository\MtfSwitchRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mtf:switch-off', description: 'Désactive temporairement une liste de symboles MTF')]
class MtfSwitchOffCommand extends Command
{
    public function __construct(
        private readonly MtfSwitchRepository $mtfSwitchRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbols', 's', InputOption::VALUE_REQUIRED, 'Liste de symboles séparés par des virgules (ex: BTCUSDT,ETHUSDT)')
            ->addOption('duration', 'd', InputOption::VALUE_OPTIONAL, 'Durée de désactivation (ex: 4h, 1d, 38640m)', '38640m')
            ->addOption('reason', 'r', InputOption::VALUE_OPTIONAL, 'Raison de la désactivation', 'TOO_RECENT')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mode simulation - ne pas appliquer les changements')
            ->setHelp('
Cette commande désactive temporairement une liste de symboles MTF.

Exemples:
  php bin/console mtf:switch-off --symbols="BTCUSDT,ETHUSDT" --duration="4h"
  php bin/console mtf:switch-off --symbols="BTCUSDT,ETHUSDT" --duration="38640m" --reason="TOO_RECENT"
  php bin/console mtf:switch-off --symbols="BTCUSDT,ETHUSDT" --dry-run

Formats de durée supportés:
  - 4h (4 heures)
  - 1d (1 jour)
  - 38640m (38640 minutes)
  - 1w (1 semaine)
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $symbolsInput = $input->getOption('symbols');
        $duration = $input->getOption('duration');
        $reason = $input->getOption('reason');
        $dryRun = $input->getOption('dry-run');

        if (!$symbolsInput) {
            $io->error('Vous devez fournir une liste de symboles avec l\'option --symbols');
            return Command::FAILURE;
        }

        // Parser la liste des symboles
        $symbols = array_map('trim', explode(',', $symbolsInput));
        $symbols = array_filter($symbols, fn($symbol) => !empty($symbol));

        if (empty($symbols)) {
            $io->error('Aucun symbole valide trouvé dans la liste fournie');
            return Command::FAILURE;
        }

        $io->title('Désactivation temporaire de symboles MTF');
        $io->text(sprintf('Nombre de symboles: %d', count($symbols)));
        $io->text(sprintf('Durée: %s', $duration));
        $io->text(sprintf('Raison: %s', $reason));
        $io->text(sprintf('Mode dry-run: %s', $dryRun ? 'OUI' : 'NON'));

        // Validation de la durée
        if (!$this->isValidDuration($duration)) {
            $io->error(sprintf('Format de durée invalide: %s. Utilisez des formats comme 4h, 1d, 38640m', $duration));
            return Command::FAILURE;
        }

        // Afficher les symboles qui seront affectés
        $io->section('Symboles à désactiver');
        $io->listing($symbols);

        if ($dryRun) {
            $io->warning('Mode dry-run activé - aucun changement ne sera appliqué');
            return Command::SUCCESS;
        }

        // Confirmation
        if (!$io->confirm(sprintf('Êtes-vous sûr de vouloir désactiver %d symboles pour %s?', count($symbols), $duration))) {
            $io->info('Opération annulée');
            return Command::SUCCESS;
        }

        // Traitement des symboles
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        $io->progressStart(count($symbols));

        foreach ($symbols as $symbol) {
            try {
                $this->mtfSwitchRepository->turnOffSymbolForDuration($symbol, $duration);
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = sprintf('%s: %s', $symbol, $e->getMessage());
            }
            
            $io->progressAdvance();
        }

        $io->progressFinish();

        // Résultats
        $io->section('Résultats');
        $io->success(sprintf('%d symboles désactivés avec succès', $successCount));

        if ($errorCount > 0) {
            $io->error(sprintf('%d erreurs rencontrées:', $errorCount));
            $io->listing($errors);
        }

        // Afficher un exemple de switch créé
        if ($successCount > 0) {
            $io->section('Exemple de switch créé');
            $exampleSymbol = $symbols[0];
            $exampleSwitch = $this->mtfSwitchRepository->findOneBy(['switchKey' => "SYMBOL:{$exampleSymbol}"]);
            
            if ($exampleSwitch) {
                $io->table(
                    ['Propriété', 'Valeur'],
                    [
                        ['ID', (string) $exampleSwitch->getId()],
                        ['Switch Key', $exampleSwitch->getSwitchKey()],
                        ['État', $exampleSwitch->isOn() ? 'ON' : 'OFF'],
                        ['Description', $exampleSwitch->getDescription() ?? 'N/A'],
                        ['Expire le', $exampleSwitch->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'Jamais'],
                        ['Créé le', $exampleSwitch->getCreatedAt()->format('Y-m-d H:i:s')],
                    ]
                );
            }
        }

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function isValidDuration(string $duration): bool
    {
        // Validation des formats de durée supportés
        $supportedFormats = [
            '/^\d+[smhdwy]$/',  // secondes, minutes, heures, jours, semaines, années
            '/^\d+[smhdwy]\s+\d+[smhdwy]$/',  // formats combinés comme "1d 2h"
        ];

        foreach ($supportedFormats as $pattern) {
            if (preg_match($pattern, $duration)) {
                try {
                    $testDate = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $convertedDuration = $this->convertDurationForPhp($duration);
                    $testDate->modify("+{$convertedDuration}");
                    return true;
                } catch (\Exception) {
                    // Continue avec le prochain format
                }
            }
        }

        return false;
    }

    private function convertDurationForPhp(string $duration): string
    {
        // Convertir les formats non supportés par PHP en formats supportés
        if (preg_match('/^(\d+)m$/', $duration, $matches)) {
            $minutes = (int) $matches[1];
            if ($minutes >= 60) {
                $hours = intval($minutes / 60);
                $remainingMinutes = $minutes % 60;
                if ($remainingMinutes > 0) {
                    return "{$hours}h {$remainingMinutes}m";
                } else {
                    return "{$hours}h";
                }
            }
            return $duration; // Moins de 60 minutes, PHP peut le gérer
        }

        return $duration;
    }
}
