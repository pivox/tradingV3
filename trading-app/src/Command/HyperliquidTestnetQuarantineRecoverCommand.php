<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\Execution\Hyperliquid\HyperliquidQuarantineRecoveryInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidQuarantineRecoveryStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:hyperliquid:testnet:quarantine-recover',
    description: 'Transfer fallback quarantine to an already-tripped Hyperliquid testnet database kill switch.',
)]
final class HyperliquidTestnetQuarantineRecoverCommand extends Command
{
    private const CONFIRMATION = 'CONFIRM_HYPERLIQUID_TESTNET_QUARANTINE_TRANSFER';

    public function __construct(private readonly HyperliquidQuarantineRecoveryInterface $recovery)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Exact privileged confirmation phrase.')
            ->setHelp(
                'This command only transfers fallback marker quarantine to an already-tripped database kill switch. '
                . 'It never resets or untrips quarantine. Worker restart is required for retained-lock recovery. '
                . 'Required confirmation: ' . self::CONFIRMATION,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('confirm') !== self::CONFIRMATION) {
            $output->writeln('Quarantine recovery confirmation rejected.');

            return Command::FAILURE;
        }

        try {
            $status = $this->recovery->recoverFallbackMarker();
        } catch (\Throwable) {
            $output->writeln('Quarantine recovery refused: durable quarantine state is unreadable.');

            return Command::FAILURE;
        }

        return match ($status) {
            HyperliquidQuarantineRecoveryStatus::Transferred => $this->writeStatus(
                $output,
                'Fallback quarantine transferred to the already-tripped database kill switch.',
                Command::SUCCESS,
            ),
            HyperliquidQuarantineRecoveryStatus::NoMarker => $this->writeStatus(
                $output,
                'No fallback quarantine marker is present.',
                Command::SUCCESS,
            ),
            HyperliquidQuarantineRecoveryStatus::RepositoryNotTripped => $this->writeStatus(
                $output,
                'Quarantine recovery refused: database kill switch is not tripped.',
                Command::FAILURE,
            ),
        };
    }

    private function writeStatus(OutputInterface $output, string $message, int $exitCode): int
    {
        $output->writeln($message);

        return $exitCode;
    }
}
