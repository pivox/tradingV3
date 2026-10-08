<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Lifecycle\PaperDatasetCandleWindow;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\PaperExecutionConsumer;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Persistence\PaperReplayBatchingStoreInterface;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use App\Trading\Paper\Runtime\PaperReplayStrategySelection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:paper-market:replay', description: 'Replay a verified public dataset through one explicit Paper execution cell.')]
final class PaperExecutionReplayCommand extends Command implements SignalableCommandInterface
{
    public const COMPLETION_SCHEMA = 'paper-replay-completion-v1';
    private const DEFAULT_BATCH_EVENTS = 5000;
    /**
     * No time-based commit by default: batch boundaries then depend only on the source
     * positions, so the same cell always produces the same journal (and journal checksum),
     * whatever the machine load or the number of cells running in parallel.
     */
    private const DEFAULT_BATCH_SECONDS = 0;

    public function __construct(
        private readonly PaperReplayReadinessService $readiness,
        private readonly PaperReplayReader $reader,
        private readonly PaperExecutionStoreInterface $store,
        private readonly PaperEventCoordinatorInterface $coordinator,
        private readonly ?PaperFakeRuntimeFactory $runtimeFactory = null,
        private readonly ?PaperDatasetCandleWindow $lifecycleCandles = null,
    ) {
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
            ->addOption('dataset-receipt', null, InputOption::VALUE_REQUIRED, 'Absolute private campaign receipt of the verified dataset')
            ->addOption('batch-events', null, InputOption::VALUE_REQUIRED, 'Source events per committed batch', (string) self::DEFAULT_BATCH_EVENTS)
            ->addOption('batch-seconds', null, InputOption::VALUE_REQUIRED, 'Also commit when a batch is older than this (0 = never; makes the journal load-dependent)', (string) self::DEFAULT_BATCH_SECONDS)
            ->addOption('per-event-commits', null, InputOption::VALUE_NONE, 'Former journal: one set of commits and full rows per source event');
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
        // An interrupted replay is never a success: the open batch is rolled back by the
        // database when the process exits, and no completion proof is printed.
        return 128 + $signal;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $started = hrtime(true);
        try {
            $batchEvents = $this->positiveIntOption($input, 'batch-events');
            $batchSeconds = $this->nonNegativeIntOption($input, 'batch-seconds');
            $datasetPath = $this->requiredOption($input, 'dataset');
            $receipt = $this->optionalOption($input, 'dataset-receipt');
            $preparation = $this->readiness->prepare(
                $datasetPath,
                $this->requiredOption($input, 'configuration'),
                $this->strategySelection($input),
                $this->requiredOption($input, 'run-id'),
                $receipt,
            );
            $preparation->assertRunnable();
            $snapshot = $preparation->snapshot;
            $manifest = $preparation->manifest;
            $eligibility = $preparation->eligibility;
            $cell = $preparation->cell;
            $this->store->registerSnapshot($snapshot);
            $this->store->registerCell($cell, $eligibility);
            if ($manifest->eventsFileSha256 === null) {
                throw new \LogicException('paper_execution_dataset_checksum_missing');
            }
            $this->store->bindDataset(
                $cell,
                $manifest->datasetId,
                $manifest->eventsFileSha256,
                $manifest->recorderVersion,
            );
            // MFE/MAE of the Paper trade lifecycle: candles of this verified dataset only (#132 j).
            $this->lifecycleCandles?->bind(
                rtrim($datasetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'events.ndjson',
                $manifest->eventsFileSha256,
            );

            $batching = $this->store instanceof PaperReplayBatchingStoreInterface
                && $this->runtimeFactory !== null
                && $input->getOption('per-event-commits') !== true;
            $fakeStateSha256 = null;
            if ($batching) {
                $fakeStateSha256 = $this->openBatchSession($cell);
            }

            $consumer = new PaperExecutionConsumer($this->coordinator, $this->store, $cell, $eligibility);
            $prepared = hrtime(true);
            $firstEvent = null;
            $consumed = 0;
            $lastCommit = hrtime(true);
            try {
                foreach ($this->reader->read(
                    $datasetPath,
                    $preparation->consumerId,
                    $preparation->checkpoint,
                    $manifest,
                    false,
                    $receipt !== null,
                    $preparation->replayOrder,
                ) as $event) {
                    $position = $this->reader->currentEventIndex();
                    if ($position === null) {
                        throw new \LogicException('paper_replay_event_position_missing');
                    }
                    $firstEvent ??= hrtime(true);
                    $consumer->consumeReplay($manifest->datasetId, $position, $event);
                    ++$consumed;
                    if ($batching
                        && $this->store instanceof PaperReplayBatchingStoreInterface
                        && ($this->store->pendingReplayBatchSize() >= $batchEvents
                            || ($batchSeconds > 0 && hrtime(true) - $lastCommit >= $batchSeconds * 1_000_000_000))
                    ) {
                        $fakeStateSha256 = $this->commitBatch($cell);
                        $lastCommit = hrtime(true);
                    }
                }
                if ($batching && $this->store instanceof PaperReplayBatchingStoreInterface) {
                    if ($this->store->pendingReplayBatchSize() > 0) {
                        $fakeStateSha256 = $this->commitBatch($cell);
                    }
                    $this->store->endReplayBatch();
                }
            } catch (\Throwable $failure) {
                if ($this->store instanceof PaperReplayBatchingStoreInterface) {
                    $this->store->abortReplayBatch();
                }

                throw $failure;
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
            if ($state->killed || $state->nextSourcePosition !== $manifest->eventCount) {
                throw new \LogicException('paper_replay_incomplete');
            }
            $output->writeln(json_encode([
                'schema_version' => self::COMPLETION_SCHEMA,
                'completed' => true,
                'cell_id' => $cell->id,
                'run_id' => $cell->runId,
                'dataset_id' => $manifest->datasetId,
                'events_file_sha256' => $manifest->eventsFileSha256,
                'event_count' => $manifest->eventCount,
                'next_source_position' => $state->nextSourcePosition,
                'journal_ordinal' => $state->journalOrdinal,
                'journal_checksum' => $state->journalChecksum,
                'fake_state_sha256' => $fakeStateSha256,
                'timings' => [
                    'prepare_seconds' => round(($prepared - $started) / 1e9, 3),
                    'load_seconds' => round((($firstEvent ?? hrtime(true)) - $prepared) / 1e9, 3),
                    'consume_seconds' => round((hrtime(true) - ($firstEvent ?? hrtime(true))) / 1e9, 3),
                    'consumed_events' => $consumed,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\LogicException|\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }
    }

    /** @return string|null digest of the durable fake exchange state the session resumes from */
    private function openBatchSession(PaperExecutionCell $cell): ?string
    {
        if (!$this->store instanceof PaperReplayBatchingStoreInterface || $this->runtimeFactory === null) {
            throw new \LogicException('paper_execution_replay_batch_unavailable');
        }
        $session = $this->store->beginReplayBatch($cell, fn (): iterable => $this->reader->replayedPrefix());
        try {
            $snapshot = $session['snapshot'];
            $canonical = $this->runtimeFactory->statePath($cell);
            $snapshotPath = null;
            if ($snapshot === null) {
                if (file_exists($canonical) || is_link($canonical)) {
                    // A durable fake exchange state without any committed source is not ours.
                    throw new \RuntimeException('paper_fake_state_unexpected_for_fresh_cell');
                }
            } else {
                $staged = $this->runtimeFactory->stagedSnapshotPath($cell, $snapshot['journal_ordinal']);
                foreach ([$canonical, $staged] as $candidate) {
                    if (!is_link($candidate) && is_file($candidate)
                        && hash_equals($snapshot['sha256'], (string) hash_file('sha256', $candidate))
                    ) {
                        $snapshotPath = $candidate;
                        break;
                    }
                }
                if ($snapshotPath === null) {
                    throw new \RuntimeException('paper_fake_state_snapshot_mismatch');
                }
            }
            $this->removeStagedSnapshots($cell, $snapshotPath);
            $this->runtimeFactory->forReplayCell($cell, $snapshotPath);
        } catch (\Throwable $failure) {
            $this->store->abortReplayBatch();

            throw $failure;
        }

        return $snapshot['sha256'] ?? null;
    }

    private function commitBatch(PaperExecutionCell $cell): string
    {
        if (!$this->store instanceof PaperReplayBatchingStoreInterface || $this->runtimeFactory === null) {
            throw new \LogicException('paper_execution_replay_batch_unavailable');
        }
        $factory = $this->runtimeFactory;
        $runtime = $factory->forCell($cell);
        $staged = null;
        $committed = $this->store->flushReplayBatch(
            static function (int $snapshotOrdinal) use ($factory, $runtime, $cell, &$staged): array {
                $staged = $factory->stagedSnapshotPath($cell, $snapshotOrdinal);

                return $runtime->stateStore->writeSnapshot($staged);
            },
        );
        if (!\is_string($staged) || !rename($staged, $factory->statePath($cell))) {
            // The committed snapshot stays at its staged path, which a resume also accepts.
            throw new \RuntimeException('paper_fake_state_snapshot_promotion_failed');
        }
        $this->syncDirectory(\dirname($factory->statePath($cell)));
        $this->removeStagedSnapshots($cell, null);

        return $committed['snapshot_sha256'];
    }

    private function removeStagedSnapshots(PaperExecutionCell $cell, ?string $keep): void
    {
        if ($this->runtimeFactory === null) {
            return;
        }
        $canonical = $this->runtimeFactory->statePath($cell);
        $prefix = substr($canonical, 0, -\strlen('.dat')) . '.';
        foreach (glob($prefix . '*.snapshot') ?: [] as $staged) {
            if ($staged !== $keep && preg_match('/\.[1-9][0-9]*\.snapshot\z/D', $staged) === 1 && !is_link($staged)) {
                @unlink($staged);
            }
        }
        // Temporary files of snapshots interrupted before their rename (never committed).
        $temporaryPrefix = \dirname($canonical) . '/.' . basename($prefix);
        foreach (glob($temporaryPrefix . '*.snapshot.tmp.*') ?: [] as $temporary) {
            if (preg_match('/\.[1-9][0-9]*\.snapshot\.tmp\.[0-9a-f]{16}\z/D', $temporary) === 1 && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }

    private function nonNegativeIntOption(InputInterface $input, string $name): int
    {
        $value = filter_var($input->getOption($name), FILTER_VALIDATE_INT);
        if (!\is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('--' . $name . ' must be a non-negative integer');
        }

        return $value;
    }

    private function positiveIntOption(InputInterface $input, string $name): int
    {
        $value = filter_var($input->getOption($name), FILTER_VALIDATE_INT);
        if (!\is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('--' . $name . ' must be a positive integer');
        }

        return $value;
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
