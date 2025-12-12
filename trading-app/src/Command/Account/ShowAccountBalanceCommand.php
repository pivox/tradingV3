<?php

declare(strict_types=1);

namespace App\Command\Account;

use App\Contract\Provider\AccountProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:account:balance',
    description: 'Affiche l\'état du compte Bitmart (balance disponible, equity, marge immobilisée, etc.).',
)]
final class ShowAccountBalanceCommand extends Command
{
    public function __construct(
        private readonly AccountProviderInterface $accountProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $info = $this->accountProvider->getAccountInfo();

        if ($info === null) {
            $balance = $this->accountProvider->getAccountBalance();
            $io->warning('Impossible de récupérer les informations complètes du compte, affichage du solde disponible uniquement.');
            $io->definitionList(
                ['Available balance (USDT)' => number_format($balance, 4, '.', '')],
            );

            return Command::SUCCESS;
        }

        $io->title(sprintf('Compte %s', strtoupper($info->currency)));

        $format = static fn(float $value): string => number_format($value, 6, '.', '');

        $io->definitionList(
            ['Available balance' => $format($info->availableBalance->toFloat())],
            ['Frozen balance' => $format($info->frozenBalance->toFloat())],
            ['Position deposit' => $format($info->positionDeposit->toFloat())],
            ['Unrealized PnL' => $format($info->unrealized->toFloat())],
            ['Equity' => $format($info->equity->toFloat())],
        );

        return Command::SUCCESS;
    }
}
