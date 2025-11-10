<?php

namespace App\Command\Audit;

use App\Config\MtfValidationConfig;
use App\Config\MtfValidationConfigProvider;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'test:cond',
    description: 'Add a short description for your command',
)]
class TestCondCommand extends Command
{
    public function __construct(
        private ConditionRegistry $conditionRegistry,
        private MtfValidationConfig $validationConfig,
        private ?MtfValidationConfigProvider $configProvider = null
    )
    {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Mode à tester (regular, scalping, etc.)', 'default')
            ->setDescription('Teste le chargement de la configuration MTF');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modeOption = $input->getOption('mode');

        // Déterminer quel config utiliser
        $config = $this->getConfigToUse($modeOption, $io);

        // Charger depuis la configuration MTF (règles + validations)
        $this->conditionRegistry->load($config);

        $validation = $this->conditionRegistry->getValidation();
        if (!$validation) {
            $io->error('Impossible de charger la configuration mtf_validation.');
            return Command::FAILURE;
        }

        $timeframes = implode(', ', array_keys($validation->getTimeframes()));
        $modeInfo = $modeOption !== 'default' ? " (mode: {$modeOption})" : '';
        $io->success(sprintf(
            'Configuration MTF chargée%s (start_from_timeframe=%s, timeframes=%s)',
            $modeInfo,
            $validation->getStartFromTimeframe(),
            $timeframes ?: 'aucun'
        ));

        return Command::SUCCESS;
    }

    private function getConfigToUse(string $modeOption, SymfonyStyle $io): MtfValidationConfig
    {
        // Si le provider n'est pas disponible, utiliser le config par défaut
        if ($this->configProvider === null || $modeOption === 'default') {
            return $this->validationConfig;
        }

        // Mode spécifique
        try {
            return $this->configProvider->getConfigForMode($modeOption);
        } catch (\Throwable $e) {
            $io->warning(sprintf('Impossible de charger le config pour le mode "%s": %s. Utilisation du config par défaut.', $modeOption, $e->getMessage()));
            return $this->validationConfig;
        }
    }
}
