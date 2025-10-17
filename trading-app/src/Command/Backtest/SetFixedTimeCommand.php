<?php

declare(strict_types=1);

namespace App\Command\Backtest;

use App\Service\BacktestClockService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:backtest:set-fixed-time',
    description: 'Définit une heure fixe pour le backtesting'
)]
final class SetFixedTimeCommand extends Command
{
    public function __construct(
        private readonly BacktestClockService $backtestClockService,
        private readonly ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('datetime', InputArgument::OPTIONAL, 'Date et heure au format Y-m-d H:i:s (UTC)')
            ->addOption('clear', 'c', InputOption::VALUE_NONE, 'Retire l\'heure fixe (retour au temps réel)')
            ->addOption('show', 's', InputOption::VALUE_NONE, 'Affiche l\'heure fixe actuelle')
            ->addOption('advance', 'a', InputArgument::OPTIONAL, 'Avance l\'heure fixe de X minutes')
            ->setHelp('
Cette commande permet de gérer l\'heure fixe pour le backtesting.

Exemples:
  php bin/console app:backtest:set-fixed-time "2024-01-15 10:30:00"
  php bin/console app:backtest:set-fixed-time --clear
  php bin/console app:backtest:set-fixed-time --show
  php bin/console app:backtest:set-fixed-time --advance=60
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear')) {
            $this->backtestClockService->clearFixedTime();
            $io->success('Heure fixe supprimée. Retour au temps réel.');
            return Command::SUCCESS;
        }

        if ($input->getOption('show')) {
            $fixedTime = $this->backtestClockService->getFixedTime();
            $configTime = $this->parameterBag->get('app.clock.fixed_time');
            
            if ($fixedTime) {
                $io->info("Heure fixe actuelle (service): {$fixedTime->format('Y-m-d H:i:s')} UTC");
            } else {
                $io->info('Aucune heure fixe définie dans le service (temps réel)');
            }
            
            if ($configTime) {
                $io->info("Heure fixe configurée: {$configTime} UTC");
            } else {
                $io->info('Aucune heure fixe configurée dans les paramètres');
            }
            
            return Command::SUCCESS;
        }

        if ($input->getOption('advance')) {
            $minutes = (int) $input->getOption('advance');
            if (!$this->backtestClockService->isFixedTimeEnabled()) {
                $io->error('Aucune heure fixe définie. Utilisez d\'abord --datetime pour définir une heure.');
                return Command::FAILURE;
            }
            $this->backtestClockService->advanceFixedTimeMinutes($minutes);
            $newTime = $this->backtestClockService->getFixedTime();
            $io->success("Heure fixe avancée de {$minutes} minutes: {$newTime->format('Y-m-d H:i:s')} UTC");
            return Command::SUCCESS;
        }

        $datetime = $input->getArgument('datetime');
        if (!$datetime) {
            $io->error('Vous devez fournir une date/heure ou utiliser une option (--clear, --show, --advance)');
            return Command::FAILURE;
        }

        try {
            $fixedTime = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
            $this->backtestClockService->setFixedTime($fixedTime);
            $io->success("Heure fixe définie: {$fixedTime->format('Y-m-d H:i:s')} UTC");
            $io->note('Tous les services utiliseront maintenant cette heure fixe pour le backtesting.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Format de date/heure invalide: {$e->getMessage()}");
            $io->note('Format attendu: Y-m-d H:i:s (ex: 2024-01-15 10:30:00)');
            return Command::FAILURE;
        }
    }
}
