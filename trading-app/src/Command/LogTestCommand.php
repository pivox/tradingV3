<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:log-test', description: 'Ecrit un message de test dans les logs')]
class LogTestCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.mtf')]
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = 'Test Monolog: ceci est un log INFO depuis app:log-test (canal mtf)';
        $this->logger->info($message, ['time' => date('c')]);
        $output->writeln('<info>'.$message.'</info>');
        return Command::SUCCESS;
    }
}
