<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WsAgentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:ws:agent', description: 'WebSocket agent pour écouter les ordres BitMart et mettre à jour la BDD')]
final class WsAgentCommand extends Command
{
    public function __construct(
        private readonly WsAgentService $wsAgentService,
        #[Autowire(service: 'monolog.logger.ws_agent')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('WebSocket Agent - BitMart Futures Orders');

        $this->logger->info('[WsAgent] Starting WebSocket agent');

        try {
            $this->wsAgentService->run();
        } catch (\Throwable $e) {
            $this->logger->error('[WsAgent] Fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $io->error(sprintf('Erreur fatale: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

