<?php

namespace App\Command;

use App\Entity\Contract;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'bitmart:kline:latest',
    description: 'Fetch and persist the latest 100 Klines for a given contract.'
)]
class KlineLatestCommand extends Command
{
    public function __construct(
        private BitmartFetcher $bitmartFetcher,
        private EntityManagerInterface $em,
        private KlinePersister $klinePersister
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Trading pair (e.g. BTCUSDT)')
            ->addArgument('step', InputArgument::OPTIONAL, 'Step in minutes (default: 1)', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $symbol = $input->getArgument('symbol');
            $step = (int)$input->getArgument('step');

            $contract = $this->em->getRepository(Contract::class)->find($symbol);
            if (!$contract) {
                $output->writeln("<error>Contract $symbol not found in DB</error>");
                return Command::FAILURE;
            }

            $output->writeln("⏳ Fetching latest 100 Klines for [$symbol] with step = {$step}min...");
            $klines = $this->bitmartFetcher->fetchLatestKlines($symbol, 100, $step);
            $this->klinePersister->persist($klines, $contract, $step);


            foreach ($klines as $kline) {
                try {
                    $date = (new \DateTimeImmutable())->setTimestamp($kline->timestamp)->format('Y-m-d H:i');
                } catch (\Throwable $e) {
                    $date = (string) $kline->timestamp;
                }

                $output->writeln(sprintf(
                    "[%s] O:%.4f H:%.4f L:%.4f C:%.4f V:%.2f",
                    $date,
                    $kline->open,
                    $kline->high,
                    $kline->low,
                    $kline->close,
                    $kline->volume
                ));
            }

            $output->writeln("<info>✅ Persisted " . count($klines) . " klines for $symbol</info>");
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
