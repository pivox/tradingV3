<?php

declare(strict_types=1);

namespace App\Command\Indicator;

use App\Contract\Indicator\IndicatorProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'indicator:validate-setup',
    description: 'Valide la configuration des indicateurs pour un symbole et timeframe donnés'
)]
class ValidateIndicatorSetupCommand extends Command
{
    public function __construct(
        private readonly IndicatorProviderInterface $indicatorProvider,
        private readonly \App\Indicator\Registry\ConditionRegistry $conditionRegistry,
        private readonly ?LoggerInterface $logger = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à valider (ex: BTCUSDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (ex: 1h, 4h, 15m)')
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Affichage détaillé des résultats')
            ->addOption('show-descriptions', 's', InputOption::VALUE_NONE, 'Afficher les descriptions des conditions')
            ->setHelp('Cette commande valide que toutes les conditions d\'indicateurs fonctionnent correctement pour le symbole et timeframe spécifiés.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = $input->getArgument('symbol');
        $timeframe = $input->getArgument('timeframe');
        $detailed = $input->getOption('detailed');
        $showDescriptions = $input->getOption('show-descriptions');

        $io->title("Validation de la configuration des indicateurs");
        $io->text([
            "Symbole: <info>$symbol</info>",
            "Timeframe: <info>$timeframe</info>",
            ""
        ]);

        try {
            // Valider la configuration
            $isValid = $this->validateSetup($symbol, $timeframe);

            if ($isValid) {
                $io->success("✅ Configuration des indicateurs valide pour $symbol sur $timeframe");

                if ($detailed || $showDescriptions) {
                    $this->displayDetailedResults($io, $symbol, $timeframe, $showDescriptions, $detailed);
                }

                return Command::SUCCESS;
            } else {
                $io->error("❌ Configuration des indicateurs invalide pour $symbol sur $timeframe");

                if ($detailed || $showDescriptions) {
                    $this->displayDetailedResults($io, $symbol, $timeframe, $showDescriptions, $detailed);
                }

                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error("Erreur lors de la validation: " . $e->getMessage());

            if ($this->logger) {
                $this->logger->error("Erreur validation indicateurs", [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            return Command::FAILURE;
        }
    }

    /**
     * Valide la configuration des indicateurs
     */
    public function validateSetup(string $symbol, string $timeframe): bool
    {
        try {
            $results = $this->indicatorProvider->evaluateConditions($symbol, $timeframe);

            foreach ($results as $name => $result) {
                if (!$result->ok) {
                    if ($this->logger) {
                        $this->logger->warning("Condition échouée", [
                            'condition' => $name,
                            'symbol' => $symbol,
                            'timeframe' => $timeframe,
                            'result' => $result->toArray()
                        ]);
                    }
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur lors de l'évaluation des conditions", [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'error' => $e->getMessage()
                ]);
            }
            throw $e;
        }
    }

    /**
     * Affiche les résultats détaillés
     */
    private function displayDetailedResults(SymfonyStyle $io, string $symbol, string $timeframe, bool $showDescriptions, bool $detailed = false): void
    {
        try {
            $results = $this->indicatorProvider->evaluateConditions($symbol, $timeframe);

            $io->section("Résultats détaillés des conditions");

            $table = $io->createTable();
            $table->setHeaders(['Condition', 'Statut', 'Valeur', 'Seuil', 'Description']);

            foreach ($results as $name => $result) {
                $status = $result->ok ? '✅ OK' : '❌ ÉCHEC';
                $value = $result->value !== null ? number_format($result->value, 4) : 'N/A';
                $threshold = $result->threshold !== null ? number_format($result->threshold, 4) : 'N/A';

                $description = '';
                if ($showDescriptions) {
                    // Récupérer la description depuis le registre des conditions
                    $description = $this->getConditionDescription($name);
                }

                $table->addRow([
                    $name,
                    $status,
                    $value,
                    $threshold,
                    $description
                ]);
            }

            $table->render();

            // Afficher les métadonnées si disponibles
            if ($io->isVerbose() || $detailed) {
                $io->section("Métadonnées des conditions");
                foreach ($results as $name => $result) {
                    if (!empty($result->meta)) {
                        $io->text("<info>$name:</info>");
                        $io->listing($result->meta);
                    }
                }
            }

        } catch (\Exception $e) {
            $io->error("Impossible d'afficher les résultats détaillés: " . $e->getMessage());
        }
    }

    /**
     * Récupère la description d'une condition
     */
    private function getConditionDescription(string $conditionName): string
    {
        try {
            $condition = $this->conditionRegistry->get($conditionName);
            if ($condition !== null) {
                return $condition->getDescription();
            }
        } catch (\Throwable $e) {
            // Ignore et retourne un fallback lisible
        }

        return sprintf("Description indisponible pour '%s'", $conditionName);
    }
}
