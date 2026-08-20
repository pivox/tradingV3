<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\PaperExecutionConsumer;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Replay\PaperReplayCheckpoint;
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
            $consumerId = 'paper-exec-' . substr($cell->id, 7, 16);
            $checkpoint = $this->replayCheckpoint($cell, $manifest, $consumerId);
            foreach ($this->reader->read($datasetPath, $consumerId, $checkpoint, $manifest) as $event) {
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

    private function replayCheckpoint(PaperExecutionCell $cell, PaperDatasetManifest $manifest, string $consumerId): ?PaperReplayCheckpoint
    {
        $checkpoint = $this->store->checkpoint($cell);
        $pending = $this->store->pendingEffects($cell);
        $position = $pending[0]->sourcePosition ?? $checkpoint->nextSourcePosition;
        if ($position === 0) {
            return null;
        }
        $dataset = $this->store->datasetIdentity($cell);
        if ($dataset['dataset_id'] !== $manifest->datasetId
            || $manifest->eventsFileSha256 === null
            || !hash_equals($dataset['events_file_sha256'], $manifest->eventsFileSha256)
        ) {
            throw new \LogicException('paper_execution_dataset_identity_conflict');
        }
        $events = $this->store->acknowledgedSources($cell);
        $last = $events[$position - 1] ?? null;
        if ($last === null) {
            throw new \LogicException('paper_execution_checkpoint_corrupt');
        }

        return new PaperReplayCheckpoint(
            $manifest->network,
            $dataset['dataset_id'],
            $consumerId,
            $last->eventId,
            $position - 1,
            $last->exchangeTimestamp,
            $dataset['events_file_sha256'],
        );
    }
}
