<?php

namespace App\Command;

use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\ContractPersister;
use App\Service\Persister\KlinePersister;
use App\Service\SyncStatusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'bitmart:kline:sync-all',
    description: 'Fetch and persist contracts + latest Klines (4h,1h,15m, ..) from BitMart.'
)]
class KlineSyncAllCommand extends Command
{
    private const INTERVALS = [1,3,5,15,30,60,240,240,1440];

    public function __construct(
        private BitmartFetcher $bitmartFetcher,
        private ContractPersister $contractPersister,
        private KlinePersister $klinePersister,
        private SyncStatusService $syncStatusService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of contracts to process', null)
            ->addOption('symbol', null, InputOption::VALUE_OPTIONAL, 'Work on a specific contract symbol', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $limit = $input->getOption('limit');
            $symbolOption = $input->getOption('symbol');

            $output->writeln("<info>🔄 Fetching contracts from BitMart...</info>");
            $contractsDto = $this->bitmartFetcher->fetchContracts();

            if ($symbolOption !== null) {
                $contractsDto = array_filter($contractsDto, fn($dto) => $dto->symbol === $symbolOption);
                if (empty($contractsDto)) {
                    $output->writeln("<error>❌ Symbol {$symbolOption} not found in BitMart contracts.</error>");
                    return Command::FAILURE;
                }
                $output->writeln("<info>✅ Filtering for symbol: {$symbolOption}</info>");
            } elseif ($limit !== null) {
                $contractsDto = array_slice($contractsDto, 0, (int)$limit);
                $output->writeln("<info>⚠️ Limiting to {$limit} contracts for testing.</info>");
            }

            $contracts = [];
            foreach ($contractsDto as $dto) {
                $contract = $this->contractPersister->persistFromDto($dto, 'bitmart');
                $contracts[$dto->symbol] = $contract;
            }
            $this->contractPersister->flush();
            $output->writeln("<info>✅ " . count($contracts) . " contracts persisted.</info>");

            $totalSteps = count($contracts) * count(self::INTERVALS);
            $i = 0;

            foreach ($this->yieldContractsAndIntervals($contracts) as [$symbol, $contract, $step]) {
                $output->writeln("🔄 [$i/$totalSteps] Processing [$symbol][$step] min...");
                $i++;

                if ($this->syncStatusService->isKlineSynced($contract, $step)) {
                    $output->writeln("✅ [$symbol][$step]min already synced, skipping.");
                    continue;
                }

                $output->writeln("⏳ Fetching last 100 Klines for [$symbol][$step]min...");
                $klines = $this->bitmartFetcher->fetchLatestKlines($symbol, 100, $step);

                if (count($klines) === 0) {
                    $output->writeln("⚠️ No Klines found for [$symbol][$step]min.");
                    continue;
                }

                $this->klinePersister->persist($klines, $contract, $step);
                $output->writeln("✅ " . count($klines) . " Klines saved for [$symbol][$step]min.");

                sleep(1);
            }

            $output->writeln("<info>🎉 Synchronisation complete.</info>");
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Generator of [symbol, contract, step]
     */
    private function yieldContractsAndIntervals(array $contracts): \Generator
    {
        foreach ($contracts as $symbol => $contract) {
            foreach (self::INTERVALS as $step) {
                yield [$symbol, $contract, $step];
            }
        }
    }
}
