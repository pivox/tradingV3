<?php

declare(strict_types=1);

namespace App\Command\Mtf;

use App\Config\MtfValidationConfig;
use App\Config\MtfValidationConfigProvider;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\Execution\ExecutionSelector;
use App\MtfValidator\Validator\Functional\FunctionalValidationResult;
use App\MtfValidator\Validator\Functional\FunctionalValidationRunner;
use App\MtfValidator\Validator\Functional\LogicalConsistencyChecker;
use App\MtfValidator\Validator\Functional\TestContextBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:validate:mtf-config-functional',
    description: 'Valide fonctionnellement le fichier validations.yaml avec des données simulées'
)]
final class ValidateMtfConfigFunctionalCommand extends Command
{
    public function __construct(
        private readonly MtfValidationConfig $config,
        private readonly ConditionRegistry $conditionRegistry,
        private readonly IndicatorEngineInterface $indicatorEngine,
        private readonly ExecutionSelector $executionSelector,
        private readonly TestContextBuilder $contextBuilder,
        private readonly LogicalConsistencyChecker $consistencyChecker,
        private readonly ?MtfValidationConfigProvider $configProvider = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie au format JSON')
            ->addOption('detailed', null, InputOption::VALUE_NONE, 'Afficher les détails de chaque test')
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Mode à valider (regular, scalping, ou "all" pour tous les modes activés)', 'default')
            ->setHelp(<<<'HELP'
Cette commande valide fonctionnellement le fichier validations.yaml en :
- Testant les règles avec des données simulées réalistes
- Vérifiant la cohérence logique entre les règles
- Testant les scénarios de validation MTF
- Testant l'execution selector avec différents contextes
- Générant un rapport détaillé

Exemples:
  <info>php bin/console app:validate:mtf-config-functional</info>
  <info>php bin/console app:validate:mtf-config-functional --json</info>
  <info>php bin/console app:validate:mtf-config-functional --detailed</info>
  <info>php bin/console app:validate:mtf-config-functional --mode=regular</info>
  <info>php bin/console app:validate:mtf-config-functional --mode=all</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonOutput = $input->getOption('json');
        $detailed = $input->getOption('detailed');
        $modeOption = $input->getOption('mode');

        // Déterminer quel(s) config(s) valider
        $configsToValidate = $this->getConfigsToValidate($modeOption, $io);

        if (empty($configsToValidate)) {
            $io->error('Aucun config à valider');
            return Command::FAILURE;
        }

        $allResults = [];
        $hasErrors = false;

        foreach ($configsToValidate as $modeName => $config) {
            if (count($configsToValidate) > 1) {
                $io->section(sprintf('Validation fonctionnelle du mode: %s', $modeName));
            } else {
                $io->title(sprintf('Validation fonctionnelle du fichier validations.yaml%s', $modeName !== 'default' ? " (mode: {$modeName})" : ''));
            }

            // Recharger le registry avec le config du mode
            $this->conditionRegistry->load($config);

            $runner = new FunctionalValidationRunner(
                $config,
                $this->conditionRegistry,
                $this->indicatorEngine,
                $this->executionSelector,
                $this->contextBuilder,
                $this->consistencyChecker
            );

            $io->section('Exécution des tests...');
            $result = $runner->run();
            $allResults[$modeName] = $result;

            // Vérifier si le résultat est valide (tous les tests passés et pas de problèmes de cohérence)
            $isValid = $result->getTotalRulesTested() > 0 
                && $result->getTotalRulesPassed() === $result->getTotalRulesTested()
                && !$result->hasConsistencyIssues();
            
            if (!$isValid) {
                $hasErrors = true;
            }

            if (!$jsonOutput && count($configsToValidate) > 1) {
                $this->displayResults($io, $result, $detailed);
            }
        }

        // Si plusieurs modes, afficher un résumé
        if (count($configsToValidate) > 1 && !$jsonOutput) {
            $io->section('Résumé global');
            $io->table(
                ['Mode', 'Règles testées', 'Règles réussies', 'Scénarios testés', 'Scénarios réussis', 'Statut'],
                array_map(function ($modeName, $result) {
                    return [
                        $modeName,
                        $result->getTotalRulesTested(),
                        $result->getTotalRulesPassed(),
                        $result->getTotalScenariosTested(),
                        $result->getTotalScenariosPassed(),
                        ($result->getTotalRulesTested() > 0 && $result->getTotalRulesPassed() === $result->getTotalRulesTested() && !$result->hasConsistencyIssues()) ? '✅ Valide' : '❌ Invalide',
                    ];
                }, array_keys($allResults), $allResults)
            );
        }

        // Pour JSON, retourner tous les résultats
        if ($jsonOutput) {
            $outputData = [];
            foreach ($allResults as $modeName => $result) {
                $outputData[$modeName] = $result->toArray();
            }
            $output->writeln(json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $this->determineExitCode(reset($allResults));
        }

        // Pour un seul mode, afficher les détails
        if (count($configsToValidate) === 1) {
            $result = reset($allResults);
            $this->displayResults($io, $result, $detailed);
        }

        return $this->determineExitCode(reset($allResults));
    }

    /**
     * Détermine quels configs valider selon l'option --mode
     * @return array<string, MtfValidationConfig>
     */
    private function getConfigsToValidate(string $modeOption, SymfonyStyle $io): array
    {
        $configs = [];

        // Si le provider n'est pas disponible, utiliser le config par défaut
        if ($this->configProvider === null) {
            $io->note('Provider de configs non disponible, utilisation du config par défaut');
            return ['default' => $this->config];
        }

        // Mode "all" : valider tous les modes activés
        if ($modeOption === 'all') {
            $enabledModes = $this->configProvider->getEnabledModes();
            if (empty($enabledModes)) {
                $io->warning('Aucun mode activé trouvé, utilisation du config par défaut');
                return ['default' => $this->config];
            }

            foreach ($enabledModes as $mode) {
                $modeName = $mode['name'] ?? 'unknown';
                try {
                    $configs[$modeName] = $this->configProvider->getConfigForMode($modeName);
                } catch (\Throwable $e) {
                    $io->error(sprintf('Impossible de charger le config pour le mode "%s": %s', $modeName, $e->getMessage()));
                }
            }
            return $configs;
        }

        // Mode spécifique
        if ($modeOption !== 'default' && $modeOption !== null) {
            try {
                $configs[$modeOption] = $this->configProvider->getConfigForMode($modeOption);
                return $configs;
            } catch (\Throwable $e) {
                $io->error(sprintf('Impossible de charger le config pour le mode "%s": %s', $modeOption, $e->getMessage()));
                $io->note('Utilisation du config par défaut');
                return ['default' => $this->config];
            }
        }

        // Par défaut : utiliser le config par défaut
        return ['default' => $this->config];
    }

    private function displayResults(SymfonyStyle $io, FunctionalValidationResult $result, bool $detailed): void
    {
        // Résumé général
        $io->section('Résumé');
        $io->table(
            ['Catégorie', 'Testé', 'Réussi', 'Échoué', 'Taux de succès'],
            [
                [
                    'Règles',
                    $result->getTotalRulesTested(),
                    $result->getTotalRulesPassed(),
                    $result->getTotalRulesTested() - $result->getTotalRulesPassed(),
                    round($result->getSuccessRate(), 2) . '%',
                ],
                [
                    'Scénarios',
                    $result->getTotalScenariosTested(),
                    $result->getTotalScenariosPassed(),
                    $result->getTotalScenariosTested() - $result->getTotalScenariosPassed(),
                    round($result->getScenarioSuccessRate(), 2) . '%',
                ],
                [
                    'Cohérence logique',
                    count($result->getConsistencyIssues()),
                    count($result->getConsistencyIssues()) === 0 ? '0' : '0',
                    count($result->getConsistencyIssues()),
                    count($result->getConsistencyIssues()) === 0 ? '100%' : '0%',
                ],
            ]
        );

        // Problèmes de cohérence logique
        if ($result->hasConsistencyIssues()) {
            $io->section('Problèmes de cohérence logique');
            foreach ($result->getConsistencyIssues() as $issue) {
                $severityIcon = match($issue->getSeverity()) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    'low' => '🟢',
                    default => '⚪',
                };
                $io->writeln(sprintf(
                    '%s [%s] %s',
                    $severityIcon,
                    $issue->getType(),
                    $issue->getMessage()
                ));
                if ($detailed && !empty($issue->getAffectedRules())) {
                    $io->writeln(sprintf('  Règles affectées: %s', implode(', ', $issue->getAffectedRules())));
                }
            }
        }

        // Résultats des scénarios
        if ($detailed && !empty($result->getScenarioResults())) {
            $io->section('Résultats des scénarios');
            foreach ($result->getScenarioResults() as $scenarioResult) {
                $icon = $scenarioResult->isPassed() ? '✅' : '❌';
                $io->writeln(sprintf(
                    '%s %s (%s/%s)',
                    $icon,
                    $scenarioResult->getScenarioName(),
                    $scenarioResult->getTimeframe(),
                    $scenarioResult->getSide()
                ));
                if (!$scenarioResult->isPassed() && $scenarioResult->getMessage()) {
                    $io->writeln(sprintf('  → %s', $scenarioResult->getMessage()));
                }
            }
        }

        // Résultats de l'execution selector
        if ($detailed && !empty($result->getExecutionSelectorResults())) {
            $io->section('Résultats Execution Selector');
            foreach ($result->getExecutionSelectorResults() as $selectorResult) {
                $icon = $selectorResult->isPassed() ? '✅' : '❌';
                $io->writeln(sprintf(
                    '%s %s: %s (attendu: %s)',
                    $icon,
                    $selectorResult->getTestName(),
                    $selectorResult->getActualTf(),
                    $selectorResult->getExpectedTf()
                ));
                if (!$selectorResult->isPassed() && $selectorResult->getMessage()) {
                    $io->writeln(sprintf('  → %s', $selectorResult->getMessage()));
                }
            }
        }

        // Statut final
        $io->section('Statut final');
        $allPassed = $result->getTotalRulesTested() > 0 
            && $result->getTotalRulesPassed() === $result->getTotalRulesTested()
            && !$result->hasConsistencyIssues();
        
        if ($allPassed) {
            $io->success('✅ Tous les tests sont passés');
        } else {
            $io->warning('⚠️  Certains tests ont échoué ou des problèmes de cohérence ont été détectés');
        }
    }

    private function determineExitCode(FunctionalValidationResult $result): int
    {
        // Échoue si :
        // - Des problèmes de cohérence logique de sévérité high
        // - Tous les tests de règles ont échoué
        // - Tous les scénarios ont échoué
        
        $hasHighSeverityIssues = false;
        foreach ($result->getConsistencyIssues() as $issue) {
            if ($issue->getSeverity() === 'high') {
                $hasHighSeverityIssues = true;
                break;
            }
        }
        
        if ($hasHighSeverityIssues) {
            return Command::FAILURE;
        }
        
        if ($result->getTotalRulesTested() > 0 && $result->getTotalRulesPassed() === 0) {
            return Command::FAILURE;
        }
        
        if ($result->getTotalScenariosTested() > 0 && $result->getTotalScenariosPassed() === 0) {
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
}

