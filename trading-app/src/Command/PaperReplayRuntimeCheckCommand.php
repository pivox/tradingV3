<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Runtime\PaperReplayPreparation;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use App\Trading\Paper\Runtime\PaperReplayStrategySelection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:paper-market:runtime-check', description: 'Check one explicit public Paper replay cell without mutating state.')]
final class PaperReplayRuntimeCheckCommand extends Command implements SignalableCommandInterface
{
    public function __construct(private readonly PaperReplayReadinessService $readiness)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dataset', null, InputOption::VALUE_REQUIRED, 'Absolute private dataset directory')
            ->addOption('configuration', null, InputOption::VALUE_REQUIRED, 'Absolute private JSON configuration snapshot')
            ->addOption('strategy-profile', null, InputOption::VALUE_REQUIRED, 'Exact legacy strategy profile')
            ->addOption('mode-id', null, InputOption::VALUE_REQUIRED, 'Exact modern mode ID')
            ->addOption('mode-version', null, InputOption::VALUE_REQUIRED, 'Exact modern mode version')
            ->addOption('setup-id', null, InputOption::VALUE_REQUIRED, 'Exact modern setup ID')
            ->addOption('setup-version', null, InputOption::VALUE_REQUIRED, 'Exact modern setup version')
            ->addOption('side', null, InputOption::VALUE_REQUIRED, 'Exact modern side')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Explicit Paper run ID')
            ->addOption('dataset-receipt', null, InputOption::VALUE_REQUIRED, 'Absolute private campaign receipt of the verified dataset');
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return array_values(array_filter([
            \defined('SIGINT') ? \SIGINT : null,
            \defined('SIGTERM') ? \SIGTERM : null,
            \defined('SIGHUP') ? \SIGHUP : null,
            \defined('SIGQUIT') ? \SIGQUIT : null,
        ], static fn (?int $signal): bool => $signal !== null));
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        return 128 + $signal;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $preparation = $this->readiness->prepare(
                $this->requiredOption($input, 'dataset'),
                $this->requiredOption($input, 'configuration'),
                $this->strategySelection($input),
                $this->requiredOption($input, 'run-id'),
                $this->optionalOption($input, 'dataset-receipt'),
            );
            $payload = $preparation->readinessPayload();
            $status = $payload['ready'] === true ? Command::SUCCESS : Command::INVALID;
        } catch (\InvalidArgumentException|\LogicException|\RuntimeException $exception) {
            $payload = [
                'schema_version' => PaperReplayPreparation::READINESS_SCHEMA,
                'ready' => false,
                'runtime_ready' => false,
                'blocker' => $exception->getMessage(),
            ];
            $status = Command::INVALID;
        }

        $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $status;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('--' . $name . ' is required');
        }

        return trim($value);
    }

    private function strategySelection(InputInterface $input): PaperReplayStrategySelection
    {
        return PaperReplayStrategySelection::fromOptions(
            $this->optionalOption($input, 'strategy-profile'),
            $this->optionalOption($input, 'mode-id'),
            $this->optionalOption($input, 'mode-version'),
            $this->optionalOption($input, 'setup-id'),
            $this->optionalOption($input, 'setup-version'),
            $this->optionalOption($input, 'side'),
        );
    }

    private function optionalOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }
}
