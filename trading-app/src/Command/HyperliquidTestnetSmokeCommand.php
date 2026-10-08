<?php

declare(strict_types=1);

namespace App\Command;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbeInterface;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Execution\Hyperliquid\HyperliquidMutationReadinessGate;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plan-file', InputArgument::REQUIRED, 'Path to a schema_version 1 JSON order-plan file.')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Exact privileged confirmation phrase.')
            ->addOption('readiness-decision', null, InputOption::VALUE_REQUIRED, 'Exact approved readiness decision.')
            ->addOption('mode-id', null, InputOption::VALUE_REQUIRED, 'Canonical mode id.')
            ->addOption('mode-version', null, InputOption::VALUE_REQUIRED, 'Exact mode version.')
            ->addOption('setup-id', null, InputOption::VALUE_REQUIRED, 'Canonical setup id.')
            ->addOption('setup-version', null, InputOption::VALUE_REQUIRED, 'Exact setup version.')
            ->addOption('side', null, InputOption::VALUE_REQUIRED, 'long or short.')
            ->setHelp(
                'Operator-only Hyperliquid testnet mutation command. Required confirmation: ' . self::CONFIRMATION
                . '. Required readiness decision: ' . self::READINESS_DECISION
                . '. The configured account must be exclusively controlled by this operator during execution.',
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
            $identity = $this->identity($input);
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution refused: canonical identity invalid.');
        }

        try {
            $path = $input->getArgument('plan-file');
            if (!is_string($path)) {
                throw new \InvalidArgumentException('order_plan_path_invalid');
            }
            $request = ExecutionRequest::forPlan(
                $this->decoder->decode($path),
                ExecutionMode::Live,
                ['canonical_identity' => $identity->toArray()],
            );
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution refused: order-plan file invalid.');
        }

        try {
            $report = $this->readiness->current($identity);
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution refused: mutation readiness unavailable.');
        }
        try {
            $blocked = $this->writeVerdicts($output, $report);
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution refused: mutation readiness unavailable.');
        }
        if ($blocked) {
            return $this->refuse($output, 'Smoke execution refused: mutation readiness blocked.');
        }

        try {
            $result = $this->port->execute($request);
        } catch (\Throwable) {
            return $this->refuse($output, 'Smoke execution failed.');
        }

        return $this->writeResult($output, $result, $request->orderPlan->clientOrderId);
    }

    private function identity(InputInterface $input): EffectiveTradingConfigRequest
    {
        $values = [];
        foreach (['mode-id', 'mode-version', 'setup-id', 'setup-version', 'side'] as $option) {
            $value = $input->getOption($option);
            if (!is_string($value)) {
                throw new \InvalidArgumentException('canonical_identity_option_missing');
            }
            $values[] = $value;
        }
        [$modeId, $modeVersion, $setupId, $setupVersion, $side] = $values;

        return new EffectiveTradingConfigRequest(
            $modeId,
            $modeVersion,
            $setupId,
            $setupVersion,
            'hyperliquid',
            'testnet',
            $side,
            ShadowExecutionCapability::Paper,
        );
    }

    private function writeVerdicts(OutputInterface $output, ExchangeReadinessReport $report): bool
    {
        $blocked = false;
        foreach ($this->readinessGate->verdicts($report, $this->config) as $condition => $passed) {
            $output->writeln(sprintf('verdict %s %s', $passed ? 'pass' : 'fail', $condition));
            $blocked = $blocked || !$passed;
        }
        $clean = $report->blockingErrors === [];
        $output->writeln(sprintf('verdict %s readiness_report_blocking_errors', $clean ? 'pass' : 'fail'));

        return $blocked || !$clean;
    }

    private function writeResult(OutputInterface $output, ExecutionResult $result, ?string $submittedClientOrderId): int
    {
        if ($result->status === ExecutionStatus::Accepted) {
            if (!$this->acceptedResultIsProven($result, $submittedClientOrderId)) {
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
