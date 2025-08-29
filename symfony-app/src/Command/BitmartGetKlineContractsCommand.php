<?php

namespace App\Command;

use App\Repository\ContractRepository;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-all:klines',
    description: 'Déclenche la récupération des klines Bitmart via Temporal (envoi d’une enveloppe au workflow)'
)]
class BitmartGetKlineContractsCommand extends Command
{

    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📦 Bitmart Klines via Temporal (signal submit)');

        try {
            $ref = new WorkflowRef(
                id: 'rate-limited-echo',
                type: 'ApiRateLimiterClient',
                taskQueue: 'api_rate_limiter_queue');
            $output->writeln('📦 Bitmart Klines via Temporal (signal submit)');
            $allContracts = array_map(fn($contractEntity) => $contractEntity->getSymbol(), $this->contractRepository->findAll());
            $output->writeln('ℹ️ ' . count($allContracts) . '
            1. Récupération des contrats Bitmart depuis la base de données'
            );
            foreach ($allContracts as $contract) {
                $output->writeln('➡️ Envoi du signal pour le contrat ' . $contract);
                $this->bitmartOrchestrator->requestGetKlines(
                    $ref,
                    baseUrl: 'http://nginx',
                    callback: 'api/callback/bitmart/get-kline',
                    contract: $contract,
                    timeframe: '4h',
                    limit: 100
                );
            }



            $io->success('✅ Signal(s) envoyé(s). Le workflow postera l’enveloppe au callback après 1 seconde (par item), en respectant la file.');
            $io->writeln('ℹ️ Le traitement réel (fetch Bitmart + persistance) s’exécute côté Symfony dans le contrôleur de callback.');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('❌ ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

}
