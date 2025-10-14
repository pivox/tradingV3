<?php

namespace App\Command;

use App\Logging\LoggingExample;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-logging',
    description: 'Teste le système de logging multi-canaux avec des exemples',
)]
class TestLoggingCommand extends Command
{
    private LoggingExample $loggingExample;

    public function __construct(LoggingExample $loggingExample)
    {
        parent::__construct();
        $this->loggingExample = $loggingExample;
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'Canal spécifique à tester (validation, signals, positions, indicators, highconviction, pipeline_exec, global-severity)')
            ->addOption('count', null, InputOption::VALUE_OPTIONAL, 'Nombre d\'exemples à générer', 1)
            ->setHelp('Cette commande teste le système de logging multi-canaux en générant des exemples de logs pour chaque canal.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $channel = $input->getOption('channel');
        $count = (int) $input->getOption('count');

        $io->title('Test du Système de Logging Multi-Canaux');

        if ($channel) {
            $this->testSpecificChannel($io, $channel, $count);
        } else {
            $this->testAllChannels($io, $count);
        }

        $io->success('Test de logging terminé ! Consultez les fichiers de logs dans var/log/');
        $io->note('Pour visualiser les logs dans Grafana, accédez à http://localhost:3000');

        return Command::SUCCESS;
    }

    private function testSpecificChannel(SymfonyStyle $io, string $channel, int $count): void
    {
        $io->section("Test du canal : {$channel}");

        for ($i = 1; $i <= $count; $i++) {
            $io->writeln("Génération de l'exemple {$i}/{$count}...");
            
            switch ($channel) {
                case 'validation':
                    $this->loggingExample->logValidationExample();
                    break;
                case 'signals':
                    $this->loggingExample->logSignalsExample();
                    break;
                case 'positions':
                    $this->loggingExample->logPositionsExample();
                    break;
                case 'indicators':
                    $this->loggingExample->logIndicatorsExample();
                    break;
                case 'highconviction':
                    $this->loggingExample->logHighConvictionExample();
                    break;
                case 'pipeline_exec':
                    $this->loggingExample->logPipelineExecExample();
                    break;
                case 'global-severity':
                    $this->loggingExample->logGlobalSeverityExample();
                    break;
                default:
                    $io->error("Canal inconnu : {$channel}");
                    return;
            }
            
            // Petite pause entre les exemples
            usleep(100000); // 100ms
        }

        $io->success("Généré {$count} exemple(s) pour le canal {$channel}");
    }

    private function testAllChannels(SymfonyStyle $io, int $count): void
    {
        $channels = [
            'validation' => 'Validation des règles MTF et conditions YAML',
            'signals' => 'Signaux de trading (long/short)',
            'positions' => 'Suivi des positions ouvertes/SL/TP',
            'indicators' => 'Calculs d\'indicateurs techniques',
            'highconviction' => 'Stratégies High Conviction',
            'pipeline_exec' => 'Exécution du pipeline workflow',
            'global-severity' => 'Erreurs globales (severity ≥ error)'
        ];

        foreach ($channels as $channel => $description) {
            $io->section("Test du canal : {$channel}");
            $io->writeln("Description : {$description}");

            for ($i = 1; $i <= $count; $i++) {
                $io->writeln("Génération de l'exemple {$i}/{$count}...");
                
                switch ($channel) {
                    case 'validation':
                        $this->loggingExample->logValidationExample();
                        break;
                    case 'signals':
                        $this->loggingExample->logSignalsExample();
                        break;
                    case 'positions':
                        $this->loggingExample->logPositionsExample();
                        break;
                    case 'indicators':
                        $this->loggingExample->logIndicatorsExample();
                        break;
                    case 'highconviction':
                        $this->loggingExample->logHighConvictionExample();
                        break;
                    case 'pipeline_exec':
                        $this->loggingExample->logPipelineExecExample();
                        break;
                    case 'global-severity':
                        $this->loggingExample->logGlobalSeverityExample();
                        break;
                }
                
                // Petite pause entre les exemples
                usleep(100000); // 100ms
            }

            $io->success("Généré {$count} exemple(s) pour le canal {$channel}");
            $io->newLine();
        }
    }
}
