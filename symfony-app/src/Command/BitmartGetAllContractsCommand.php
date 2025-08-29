<?php

namespace App\Command;

use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-all:contracts',
    description: 'Déclenche la récupération des contrats Bitmart via Temporal (envoi d’une enveloppe au workflow)'
)]
class BitmartGetAllContractsCommand extends Command
{

    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
    ) {
        parent::__construct();
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📦 Bitmart Contracts via Temporal (signal submit)');


        try {
            $ref = new WorkflowRef(
                id: 'rate-limited-echo',
                type: 'ApiRateLimiterClient',
                taskQueue: 'api_rate_limiter_queue');
            $this->bitmartOrchestrator->requestGetAllContracts(
                $ref,
                baseUrl: 'http://nginx',
                callback: 'api/callback/bitmart/fetch-all-contract',
                note: 'depuis CLI'
            );


            $io->success('✅ Signal(s) envoyé(s). Le workflow postera l’enveloppe au callback après 1 seconde (par item), en respectant la file.');
            $io->writeln('ℹ️ Le traitement réel (fetch Bitmart + persistance) s’exécute côté Symfony dans le contrôleur de callback.');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('❌ ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
