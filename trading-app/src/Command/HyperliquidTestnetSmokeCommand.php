<?php

declare(strict_types=1);

namespace App\Command;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbeInterface;
use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Hyperliquid\HyperliquidMutationReadinessGate;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidTestnetExecutionPortInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:hyperliquid:testnet:smoke',
    description: 'Submit one explicitly confirmed Hyperliquid testnet order plan.',
)]
final class HyperliquidTestnetSmokeCommand extends Command
{
    private const CONFIRMATION = 'CONFIRM_HYPERLIQUID_TESTNET_ONLY';
    private const READINESS_DECISION = 'ready_for_demo_testnet_trading_attempt';

    public function __construct(
        private readonly HyperliquidTestnetOrderPlanFileDecoder $decoder,
        private readonly HyperliquidTestnetExecutionPortInterface $port,
        private readonly HyperliquidMutationReadinessProbeInterface $readiness,
        private readonly HyperliquidMutationReadinessGate $readinessGate,
        private readonly HyperliquidConfig $config,
        private readonly HyperliquidKillSwitchTripInterface $durableTrip,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plan-file', InputArgument::REQUIRED, 'Path to a schema_version 1 JSON order-plan file.')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Exact privileged confirmation phrase.')
            ->addOption('readiness-decision', null, InputOption::VALUE_REQUIRED, 'Exact approved readiness decision.')
            ->setHelp(
                'Operator-only Hyperliquid testnet mutation command. Required confirmation: ' . self::CONFIRMATION
                . '. Required readiness decision: ' . self::READINESS_DECISION . '.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('confirm') !== self::CONFIRMATION) {
            return $this->refuse($output, 'Smoke execution refused: confirmation rejected.');
        }
        if ($input->getOption('readiness-decision') !== self::READINESS_DECISION) {
            return $this->refuse($output, 'Smoke execution refused: readiness decision rejected.');
        }

        try {
            $path = $input->getArgument('plan-file');
            if (!is_string($path)) {
                throw new \InvalidArgumentException('order_plan_path_invalid');
            }
            $request = ExecutionRequest::forPlan($this->decoder->decode($path), ExecutionMode::Live);
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution refused: order-plan file invalid.');
        }

        try {
            $report = $this->readiness->current();
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution refused: mutation readiness unavailable.');
        }
        try {
            if ($report->blockingErrors !== [] || $this->readinessGate->blockingReasons($report, $this->config) !== []) {
                return $this->refuse($output, 'Smoke execution refused: mutation readiness blocked.');
            }
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution refused: mutation readiness unavailable.');
        }

        try {
            $result = $this->port->execute($request);
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution failed.');
        }

        return $this->writeResult($output, $result, $request->orderPlan->clientOrderId);
    }

    private function writeResult(OutputInterface $output, ExecutionResult $result, ?string $submittedClientOrderId): int
    {
        if ($result->status === ExecutionStatus::Accepted) {
            if (!$this->acceptedResultIsProven($result, $submittedClientOrderId)) {
                try {
                    $this->durableTrip->trip(
                        'hyperliquid_smoke_accepted_result_ambiguous',
                        ['command' => 'app:hyperliquid:testnet:smoke'],
                    );
                } catch (\Throwable) {
                    // The fixed ambiguous result remains fail-closed even if durable persistence fails.
                }
                $output->writeln('status=ambiguous');

                return Command::FAILURE;
            }

            $output->writeln('status=accepted');
            $output->writeln('client_order_id=' . $result->clientOrderId);
            $output->writeln('exchange_order_id=' . $result->exchangeOrderId);

            return Command::SUCCESS;
        }

        $output->writeln('status=' . $result->status->value);

        return Command::FAILURE;
    }

    private function acceptedResultIsProven(ExecutionResult $result, ?string $submittedClientOrderId): bool
    {
        return $this->safeOpaqueIdentifier($submittedClientOrderId)
            && $this->safeOpaqueIdentifier($result->clientOrderId)
            && hash_equals($submittedClientOrderId, $result->clientOrderId)
            && $this->safeOpaqueIdentifier($result->exchangeOrderId)
            && ($result->metadata['protection_confirmed'] ?? null) === true;
    }

    private function safeOpaqueIdentifier(?string $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) === 1;
    }

    private function refuse(OutputInterface $output, string $message): int
    {
        $output->writeln($message);

        return Command::FAILURE;
    }
}
