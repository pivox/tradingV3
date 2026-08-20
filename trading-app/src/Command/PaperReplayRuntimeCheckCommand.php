<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Runtime\PaperReplayPreparation;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:paper-market:runtime-check', description: 'Check one explicit public Paper replay cell without mutating state.')]
final class PaperReplayRuntimeCheckCommand extends Command
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
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Exact strategy profile')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Explicit Paper run ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $preparation = $this->readiness->prepare(
                $this->requiredOption($input, 'dataset'),
                $this->requiredOption($input, 'configuration'),
                $this->requiredOption($input, 'profile'),
                $this->requiredOption($input, 'run-id'),
            );
            $payload = $preparation->readinessPayload();
            $status = Command::SUCCESS;
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
}
