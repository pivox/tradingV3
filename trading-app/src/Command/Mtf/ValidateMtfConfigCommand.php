<?php

declare(strict_types=1);

namespace App\Command\Mtf;

use App\Config\MtfValidationConfig;
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
        private readonly ConditionRegistry $conditionRegistry
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie au format JSON')
            ->addOption('fail-on-warnings', null, InputOption::VALUE_NONE, 'Échoue si des warnings sont présents')
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
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonOutput = $input->getOption('json');
        $failOnWarnings = $input->getOption('fail-on-warnings');

        // Charger le registry avec les conditions
        $this->conditionRegistry->load($this->config);

        $io->title('Validation du fichier validations.yaml');

        $validator = new ValidationsYamlValidator($this->config, $this->conditionRegistry);
        $result = $validator->validate();

        if ($jsonOutput) {
            $output->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $result->isValid() && (!$failOnWarnings || !$result->hasWarnings()) ? Command::SUCCESS : Command::FAILURE;
        }

        // Affichage formaté
        $this->displayResults($io, $result);

        $exitCode = Command::SUCCESS;

        if ($result->hasErrors()) {
            $io->error(sprintf('❌ %d erreur(s) détectée(s)', $result->getErrorCount()));
            $exitCode = Command::FAILURE;
        } else {
            $io->success('✅ Aucune erreur détectée');
        }

        if ($result->hasWarnings()) {
            $io->warning(sprintf('⚠️  %d avertissement(s)', $result->getWarningCount()));
            if ($failOnWarnings) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
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

