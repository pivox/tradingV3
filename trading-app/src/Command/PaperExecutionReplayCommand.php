<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\PaperExecutionConsumer;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:paper-market:replay', description: 'Replay a verified public dataset through one explicit Paper execution cell.')]
final class PaperExecutionReplayCommand extends Command
{
    public function __construct(
        private readonly PaperReplayReadinessService $readiness,
        private readonly PaperReplayReader $reader,
        private readonly PaperExecutionStoreInterface $store,
        private readonly PaperEventCoordinatorInterface $coordinator,
    ) {
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
            $datasetPath = $this->requiredOption($input, 'dataset');
            $preparation = $this->readiness->prepare(
                $datasetPath,
                $this->requiredOption($input, 'configuration'),
                $this->requiredOption($input, 'profile'),
                $this->requiredOption($input, 'run-id'),
            );
            $snapshot = $preparation->snapshot;
            $manifest = $preparation->manifest;
            $eligibility = $preparation->eligibility;
            $cell = $preparation->cell;
            $this->store->registerSnapshot($snapshot);
            $this->store->registerCell($cell, $eligibility);
            if ($manifest->eventsFileSha256 === null) {
                throw new \LogicException('paper_execution_dataset_checksum_missing');
            }
            $this->store->bindDataset($cell, $manifest->datasetId, $manifest->eventsFileSha256);

            $consumer = new PaperExecutionConsumer($this->coordinator, $this->store, $cell, $eligibility);
            foreach ($this->reader->read(
                $datasetPath,
                $preparation->consumerId,
                $preparation->checkpoint,
                $manifest,
                false,
            ) as $event) {
                $position = $this->reader->currentEventIndex();
                if ($position === null) {
                    throw new \LogicException('paper_replay_event_position_missing');
                }
                $consumer->consumeReplay($manifest->datasetId, $position, $event);
            }

            $state = $this->store->checkpoint($cell);
            $output->writeln(sprintf(
                'cell=%s network=%s venue=%s snapshot=%s profile=%s run=%s next_position=%d killed=%s',
                $cell->id,
                $cell->network->value,
                $cell->marketDataVenue->value,
                $cell->configurationSnapshotId,
                $cell->strategyProfile,
                $cell->runId,
                $state->nextSourcePosition,
                $state->killed ? 'yes' : 'no',
            ));

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\LogicException|\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }
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
