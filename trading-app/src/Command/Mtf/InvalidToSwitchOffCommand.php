<?php

declare(strict_types=1);

namespace App\Command\Mtf;

use App\Entity\MtfSwitch;
use App\Repository\MtfSwitchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mtf:invalid-to-switch-off',
    description: 'Désactive les symboles INVALID en les ajoutant à mtf_switch'
)]
final class InvalidToSwitchOffCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MtfSwitchRepository $mtfSwitchRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::OPTIONAL, 'Symbole spécifique à traiter (ex: HOOKUSDT)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans les appliquer')
            ->addOption('duration', 'd', InputOption::VALUE_OPTIONAL, 'Durée de désactivation (ex: 4h, 1d, 1w)', '1d')
            ->addOption('reason', 'r', InputOption::VALUE_OPTIONAL, 'Raison de la désactivation', 'INVALID_SIGNAL')
            ->addOption('all-invalid', null, InputOption::VALUE_NONE, 'Traite tous les symboles avec statut INVALID')
            ->setHelp('
Cette commande désactive les symboles marqués comme INVALID en les ajoutant à la table mtf_switch.

Exemples d\'utilisation:
  php bin/console mtf:invalid-to-switch-off HOOKUSDT
  php bin/console mtf:invalid-to-switch-off --all-invalid
  php bin/console mtf:invalid-to-switch-off HOOKUSDT --dry-run
  php bin/console mtf:invalid-to-switch-off HOOKUSDT --duration=4h --reason=INVALID_ALIGNMENT

Formats de durée supportés:
  - 4h (4 heures)
  - 1d (1 jour)
  - 1w (1 semaine)
  - 30m (30 minutes)
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getArgument('symbol');
        $dryRun = $input->getOption('dry-run');
        $duration = $input->getOption('duration');
        $reason = $input->getOption('reason');
        $allInvalid = $input->getOption('all-invalid');

        // Validation des paramètres
        if (!$symbol && !$allInvalid) {
            $io->error('Vous devez spécifier un symbole ou utiliser l\'option --all-invalid');
            return Command::FAILURE;
        }

        if (!$this->isValidDuration($duration)) {
            $io->error(sprintf('Format de durée invalide: %s. Utilisez des formats comme 4h, 1d, 1w', $duration));
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Mode DRY-RUN activé - Aucun changement ne sera appliqué');
        }

        try {
            if ($allInvalid) {
                return $this->processAllInvalidSymbols($io, $duration, $reason, $dryRun);
            } else {
                return $this->processSpecificSymbol($io, $symbol, $duration, $reason, $dryRun);
            }
        } catch (\Exception $e) {
            $io->error('Erreur lors du traitement: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function processSpecificSymbol(SymfonyStyle $io, string $symbol, string $duration, string $reason, bool $dryRun): int
    {
        $io->title("Traitement du symbole: $symbol");

        // Vérifier si le symbole a des signaux INVALID récents
        $invalidSignals = $this->findInvalidSignalsForSymbol($symbol);
        
        if (empty($invalidSignals)) {
            $io->warning("Aucun signal INVALID récent trouvé pour le symbole $symbol");
            $io->note("Le symbole sera quand même ajouté au switch avec la raison: $reason");
        } else {
            $io->section('Signaux INVALID trouvés:');
            foreach ($invalidSignals as $signal) {
                $io->text(sprintf('- %s: %s (%s)', $signal['tf'], $signal['side'], $signal['at_utc']));
            }
        }

        // Vérifier si le switch existe déjà
        $existingSwitch = $this->mtfSwitchRepository->findOneBy(['switchKey' => "SYMBOL:{$symbol}"]);
        
        if ($existingSwitch) {
            $io->section('Switch existant trouvé:');
            $io->text(sprintf('Statut actuel: %s', $existingSwitch->isOn() ? 'ON' : 'OFF'));
            $io->text(sprintf('Description: %s', $existingSwitch->getDescription() ?? 'N/A'));
            $io->text(sprintf('Mis à jour: %s', $existingSwitch->getUpdatedAt()->format('Y-m-d H:i:s')));
            
            if ($existingSwitch->isOff()) {
                $io->success("Le symbole $symbol est déjà désactivé dans mtf_switch");
                return Command::SUCCESS;
            }
        }

        $expiresAt = $this->parseDuration($duration);
        $description = "Désactivé automatiquement - $reason";

        $io->section('Action à effectuer:');
        $io->text(sprintf('Symbole: %s', $symbol));
        $io->text(sprintf('Durée: %s (expire le %s)', $duration, $expiresAt->format('Y-m-d H:i:s')));
        $io->text(sprintf('Raison: %s', $reason));
        $io->text(sprintf('Description: %s', $description));

        if (!$dryRun) {
            $this->createOrUpdateSwitch($symbol, $expiresAt, $reason, $description);
            $io->success("Switch créé/mis à jour pour $symbol - Désactivé jusqu'au " . $expiresAt->format('Y-m-d H:i:s'));
        } else {
            $io->info("DRY-RUN: Switch qui serait créé/mis à jour pour $symbol");
        }

        return Command::SUCCESS;
    }

    private function processAllInvalidSymbols(SymfonyStyle $io, string $duration, string $reason, bool $dryRun): int
    {
        $io->title('Traitement de tous les symboles INVALID');

        // Rechercher les symboles avec des signaux INVALID
        $invalidSymbols = $this->findAllInvalidSymbols();
        
        if (empty($invalidSymbols)) {
            $io->success('Aucun symbole INVALID trouvé');
            return Command::SUCCESS;
        }

        $io->section('Symboles INVALID trouvés: ' . count($invalidSymbols));
        $io->listing($invalidSymbols);

        $expiresAt = $this->parseDuration($duration);
        $description = "Désactivé automatiquement - $reason";

        if (!$dryRun) {
            $updatedCount = 0;
            foreach ($invalidSymbols as $symbol) {
                try {
                    $this->createOrUpdateSwitch($symbol, $expiresAt, $reason, $description);
                    $updatedCount++;
                    $io->text("✓ $symbol désactivé");
                } catch (\Exception $e) {
                    $io->error("✗ Erreur pour $symbol: " . $e->getMessage());
                }
            }
            $io->success("$updatedCount symboles désactivés dans mtf_switch jusqu'au " . $expiresAt->format('Y-m-d H:i:s'));
        } else {
            $io->info("DRY-RUN: " . count($invalidSymbols) . " symboles seraient désactivés dans mtf_switch");
        }

        return Command::SUCCESS;
    }

    private function findInvalidSignalsForSymbol(string $symbol): array
    {
        $sql = "
            SELECT timeframe as tf, side, kline_time as at_utc 
            FROM signals 
            WHERE symbol = :symbol 
            AND side = 'NONE' 
            AND kline_time >= NOW() - INTERVAL '24 hours'
            ORDER BY kline_time DESC
        ";
        
        $connection = $this->entityManager->getConnection();
        return $connection->fetchAllAssociative($sql, ['symbol' => $symbol]);
    }

    private function findAllInvalidSymbols(): array
    {
        $sql = "
            SELECT DISTINCT symbol 
            FROM signals 
            WHERE side = 'NONE' 
            AND kline_time >= NOW() - INTERVAL '24 hours'
            ORDER BY symbol
        ";
        
        $connection = $this->entityManager->getConnection();
        $results = $connection->fetchAllAssociative($sql);
        return array_column($results, 'symbol');
    }

    private function createOrUpdateSwitch(string $symbol, \DateTimeImmutable $expiresAt, string $reason, string $description): void
    {
        $switchKey = "SYMBOL:{$symbol}";
        $existingSwitch = $this->mtfSwitchRepository->findOneBy(['switchKey' => $switchKey]);
        
        if ($existingSwitch) {
            // Mettre à jour le switch existant
            $existingSwitch->setIsOn(false);
            $existingSwitch->setDescription($description);
            $existingSwitch->setExpiresAt($expiresAt);
            $existingSwitch->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } else {
            // Créer un nouveau switch
            $switch = MtfSwitch::createSymbolSwitch($symbol);
            $switch->setIsOn(false);
            $switch->setDescription($description);
            $switch->setExpiresAt($expiresAt);
            $this->entityManager->persist($switch);
        }
        
        $this->entityManager->flush();
    }

    private function isValidDuration(string $duration): bool
    {
        return preg_match('/^\d+[hmdw]$/', $duration) === 1;
    }

    private function parseDuration(string $duration): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        
        $matches = [];
        if (preg_match('/^(\d+)([hmdw])$/', $duration, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];
            
            return match ($unit) {
                'm' => $now->modify("+{$value} minutes"),
                'h' => $now->modify("+{$value} hours"),
                'd' => $now->modify("+{$value} days"),
                'w' => $now->modify("+{$value} weeks"),
                default => throw new \InvalidArgumentException("Unité de temps non supportée: $unit")
            };
        }
        
        throw new \InvalidArgumentException("Format de durée invalide: $duration");
    }
}
