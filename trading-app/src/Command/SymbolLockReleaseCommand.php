<?php

declare(strict_types=1);

namespace App\Command;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use App\Service\SymbolExecutionLockManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:symbol-lock:release',
    description: 'Release an active cross-profile symbol execution lock'
)]
final class SymbolLockReleaseCommand extends Command
{
    public function __construct(private readonly SymbolExecutionLockManager $locks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol to release')
            ->addOption('exchange', null, InputOption::VALUE_REQUIRED, 'Exchange', Exchange::BITMART->value)
            ->addOption('market-type', null, InputOption::VALUE_REQUIRED, 'Market type', MarketType::PERPETUAL->value)
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Release reason')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force release even if an open position or order exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = strtoupper(trim((string) $input->getArgument('symbol')));
        $reason = trim((string) $input->getOption('reason'));
        if ($reason === '') {
            $io->error('--reason is required.');

            return Command::FAILURE;
        }

        $exchange = Exchange::tryFrom(strtolower(trim((string) $input->getOption('exchange'))));
        $marketType = MarketType::tryFrom(strtolower(trim((string) $input->getOption('market-type'))));
        if (!$exchange instanceof Exchange || !$marketType instanceof MarketType) {
            $io->error('Invalid --exchange or --market-type.');

            return Command::FAILURE;
        }

        try {
            $released = $this->locks->releaseManual(
                symbol: $symbol,
                context: new ExchangeContext($exchange, $marketType),
                reason: $reason,
                force: (bool) $input->getOption('force'),
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$released) {
            $io->warning(sprintf('No active lock for %s:%s:%s.', $exchange->value, $marketType->value, $symbol));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Released lock for %s:%s:%s.', $exchange->value, $marketType->value, $symbol));

        return Command::SUCCESS;
    }
}
