<?php

declare(strict_types=1);

namespace App\Command\Mtf;

use App\Config\MtfValidationConfig;
use App\Config\MtfValidationConfigProvider;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\Validator\ValidationResult;
use App\MtfValidator\Validator\ValidationsYamlValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:validate:mtf-config',
    description: 'Valide techniquement le fichier validations.yaml (références, syntaxe, types)'
)]
final class ValidateMtfConfigCommand extends Command
{
    public function __construct(
        private readonly MtfValidationConfig $config,
        private readonly ConditionRegistry $conditionRegistry,
        private readonly ?MtfValidationConfigProvider $configProvider = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie au format JSON')
            ->addOption('fail-on-warnings', null, InputOption::VALUE_NONE, 'Échoue si des warnings sont présents')
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Mode à valider (regular, scalping, ou "all" pour tous les modes activés)', 'default')
            ->setHelp(<<<'HELP'
Cette commande valide techniquement le fichier validations.yaml pour détecter :
- Références manquantes (règles ou conditions PHP)
- Références circulaires dans les règles
- Syntaxe invalide
- Types de données incorrects
- Structure incorrecte

Exemples:
  <info>php bin/console app:validate:mtf-config</info>
  <info>php bin/console app:validate:mtf-config --json</info>
  <info>php bin/console app:validate:mtf-config --fail-on-warnings</info>
  <info>php bin/console app:validate:mtf-config --mode=regular</info>
  <info>php bin/console app:validate:mtf-config --mode=all</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonOutput = $input->getOption('json');
        $failOnWarnings = $input->getOption('fail-on-warnings');
        $modeOption = $input->getOption('mode');

        // Déterminer quel(s) config(s) valider
        $configsToValidate = $this->getConfigsToValidate($modeOption, $io);

        if (empty($configsToValidate)) {
            $io->error('Aucun config à valider');
            return Command::FAILURE;
        }

        $allResults = [];
        $hasErrors = false;
        $hasWarnings = false;

        foreach ($configsToValidate as $modeName => $config) {
            if (count($configsToValidate) > 1) {
                $io->section(sprintf('Validation du mode: %s', $modeName));
            } else {
                $io->title(sprintf('Validation du fichier validations.yaml%s', $modeName !== 'default' ? " (mode: {$modeName})" : ''));
            }

            // Charger le registry avec les conditions
            $this->conditionRegistry->load($config);

            $validator = new ValidationsYamlValidator($config, $this->conditionRegistry);
            $result = $validator->validate();
            $allResults[$modeName] = $result;

            if ($result->hasErrors()) {
                $hasErrors = true;
            }
            if ($result->hasWarnings()) {
                $hasWarnings = true;
            }

            if (!$jsonOutput && count($configsToValidate) > 1) {
                $this->displayResults($io, $result);
            }
        }

        // Si plusieurs modes, afficher un résumé
        if (count($configsToValidate) > 1 && !$jsonOutput) {
            $io->section('Résumé global');
            $io->table(
                ['Mode', 'Erreurs', 'Avertissements', 'Statut'],
                array_map(function ($modeName, $result) {
                    return [
                        $modeName,
                        $result->getErrorCount(),
                        $result->getWarningCount(),
                        $result->isValid() ? '✅ Valide' : '❌ Invalide',
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
            return (!$hasErrors && (!$failOnWarnings || !$hasWarnings)) ? Command::SUCCESS : Command::FAILURE;
        }

        // Pour un seul mode, afficher les détails
        if (count($configsToValidate) === 1) {
            $result = reset($allResults);
            $this->displayResults($io, $result);
        }

        $exitCode = Command::SUCCESS;

        if ($hasErrors) {
            $totalErrors = array_sum(array_map(fn($r) => $r->getErrorCount(), $allResults));
            $io->error(sprintf('❌ %d erreur(s) détectée(s) au total', $totalErrors));
            $exitCode = Command::FAILURE;
        } else {
            $io->success('✅ Aucune erreur détectée');
        }

        if ($hasWarnings) {
            $totalWarnings = array_sum(array_map(fn($r) => $r->getWarningCount(), $allResults));
            $io->warning(sprintf('⚠️  %d avertissement(s) au total', $totalWarnings));
            if ($failOnWarnings) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
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

    private function displayResults(SymfonyStyle $io, ValidationResult $result): void
    {
        // Afficher les erreurs
        if ($result->hasErrors()) {
            $io->section('Erreurs');
            $errors = $result->getErrors();
            foreach ($errors as $index => $error) {
                $io->writeln(sprintf(
                    '<error>[%d] %s</error>',
                    $index + 1,
                    $error->__toString()
                ));
            }
        }

        // Afficher les warnings
        if ($result->hasWarnings()) {
            $io->section('Avertissements');
            $warnings = $result->getWarnings();
            foreach ($warnings as $index => $warning) {
                $io->writeln(sprintf(
                    '<comment>[%d] %s</comment>',
                    $index + 1,
                    $warning->__toString()
                ));
            }
        }

        // Résumé
        $io->section('Résumé');
        $io->table(
            ['Type', 'Nombre'],
            [
                ['Erreurs', $result->getErrorCount()],
                ['Avertissements', $result->getWarningCount()],
                ['Statut', $result->isValid() ? '✅ Valide' : '❌ Invalide'],
            ]
        );
    }
}

