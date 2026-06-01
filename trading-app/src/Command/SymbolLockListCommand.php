<?php

declare(strict_types=1);

namespace App\Command;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;
use App\Service\SymbolExecutionLockManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:symbol-lock:list',
    description: 'List active cross-profile symbol execution locks'
)]
final class SymbolLockListCommand extends Command
{
    public function __construct(private readonly SymbolExecutionLockManager $locks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('exchange', null, InputOption::VALUE_REQUIRED, 'Exchange filter')
            ->addOption('market-type', null, InputOption::VALUE_REQUIRED, 'Market type filter')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED, 'Symbol filter')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = $this->contextFromInput($input, $io);
        if ($context === false) {
            return Command::FAILURE;
        }

        $locks = $this->locks->listActive(
            context: $context,
            symbol: $this->stringOption($input, 'symbol'),
            limit: max(1, (int) $input->getOption('limit')),
        );

        if ($locks === []) {
            $io->success('No active symbol execution locks.');

            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Exchange', 'Market', 'Symbol', 'Profile', 'Intent ID', 'Decision key', 'Locked at', 'Expires at'],
            array_map(static fn ($lock): array => [
                $lock->getId(),
                $lock->getExchange(),
                $lock->getMarketType(),
                $lock->getSymbol(),
                $lock->getOwnerProfile(),
                $lock->getOwnerOrderIntentId(),
                $lock->getOwnerDecisionKey(),
                $lock->getLockedAt()->format(\DateTimeInterface::ATOM),
                $lock->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ], $locks),
        );

        return Command::SUCCESS;
    }

    private function contextFromInput(InputInterface $input, SymfonyStyle $io): ExchangeContext|false|null
    {
        $exchangeValue = $this->stringOption($input, 'exchange');
        $marketTypeValue = $this->stringOption($input, 'market-type');
        if ($exchangeValue === null && $marketTypeValue === null) {
            return null;
        }

        $exchange = Exchange::tryFrom(strtolower((string) $exchangeValue));
        $marketType = MarketType::tryFrom(strtolower((string) $marketTypeValue));
        if (!$exchange instanceof Exchange || !$marketType instanceof MarketType) {
            $io->error('Both --exchange and --market-type must be valid when filtering by runtime context.');

            return false;
        }

        return new ExchangeContext($exchange, $marketType);
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
