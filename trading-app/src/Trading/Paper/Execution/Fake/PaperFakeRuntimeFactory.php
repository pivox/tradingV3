<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Fake\FakeDeterministicSeed;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PaperFakeRuntimeFactory
{
    /** @var array<string, PaperFakeRuntime> */
    private array $runtimes = [];

    private readonly FakeDeterministicSeed $deterministicSeed;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/paper-fake-state')]
        private readonly string $root,
        private readonly ClockInterface $clock,
        #[Autowire('%env(string:FAKE_EXCHANGE_DETERMINISTIC_SEED)%')]
        string $deterministicSeed = FakeExchangeStateStore::DEFAULT_DETERMINISTIC_SEED,
    ) {
        $this->deterministicSeed = new FakeDeterministicSeed($deterministicSeed);
    }

    public function forCell(PaperExecutionCell $cell): PaperFakeRuntime
    {
        return $this->runtimes[$cell->id] ??= $this->create($cell);
    }

    /**
     * Single-writer replay runtime: the fake exchange state lives in memory (every logical
     * revision kept) and is made durable only through replaySnapshot(). $snapshotPath is the
     * verified snapshot to resume from, or null for a cell that has not consumed anything.
     */
    public function forReplayCell(PaperExecutionCell $cell, ?string $snapshotPath): PaperFakeRuntime
    {
        if (isset($this->runtimes[$cell->id])) {
            throw new \LogicException('paper_fake_runtime_already_open');
        }
        if ($snapshotPath !== null && (is_link($snapshotPath) || !is_file($snapshotPath))) {
            throw new \RuntimeException('paper_fake_state_snapshot_missing');
        }

        return $this->runtimes[$cell->id] = $this->create($cell, $snapshotPath ?? false);
    }

    /** Canonical durable state file of a cell (the latest committed replay snapshot). */
    public function statePath(PaperExecutionCell $cell): string
    {
        return $this->cellStatePath($cell, '.dat');
    }

    /** Staging path of the snapshot recorded at a journal ordinal, before it is promoted. */
    public function stagedSnapshotPath(PaperExecutionCell $cell, int $journalOrdinal): string
    {
        if ($journalOrdinal < 1) {
            throw new \InvalidArgumentException('paper_fake_state_snapshot_ordinal_invalid');
        }

        return $this->cellStatePath($cell, '.' . $journalOrdinal . '.snapshot');
    }

    /**
     * @param string|false|null $replaySnapshot false: replay runtime without durable state yet
     */
    private function create(PaperExecutionCell $cell, string|false|null $replaySnapshot = null): PaperFakeRuntime
    {
        $statePath = $this->cellStatePath($cell, '.dat');
        if (is_link($statePath)) {
            throw new \RuntimeException('paper_fake_state_symlink_forbidden');
        }

        $cellSeed = $this->deterministicSeed->deriveHex(
            'paper-runtime.cell-seed.v1',
            ['cell_id' => $cell->id],
        );
        if ($replaySnapshot === null) {
            $state = new FakeExchangeStateStore($statePath, $cellSeed);
        } else {
            $state = new FakeExchangeStateStore($replaySnapshot === false ? null : $replaySnapshot, $cellSeed);
            $state->enableWriteBehind();
        }
        // Order ids (and the position/fill/funding ids derived from them) are unique per cell.
        $state->useOrderIdNamespace(substr($cell->id, 7, 16));
        $book = new FakeExchangeOrderBook($state);
        $clock = $this->serializableClock();
        $canonicalInstruments = $cell->isModern()
            ? new PaperCanonicalFakeInstrumentRegistry($cell, $state)
            : null;
        $engine = new FakeExchangeMatchingEngine(
            $state,
            $book,
            $clock,
            instruments: $canonicalInstruments,
        );
        $adapter = new FakeExchangeAdapter($state, $book, $engine, $clock, $canonicalInstruments);

        return new PaperFakeRuntime(
            $cell,
            $statePath,
            $state,
            $book,
            $engine,
            $adapter,
            $canonicalInstruments,
        );
    }

    private function cellStatePath(PaperExecutionCell $cell, string $suffix): string
    {
        $root = $this->privateRoot();
        $digest = substr($cell->id, 7);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $digest)) {
            throw new \InvalidArgumentException('paper_fake_state_cell_digest_invalid');
        }
        $path = $root . '/' . $digest . $suffix;
        if (dirname($path) !== $root || basename($path) !== $digest . $suffix) {
            throw new \RuntimeException('paper_fake_state_path_mismatch');
        }

        return $path;
    }

    private function privateRoot(): string
    {
        if (is_link($this->root)) {
            throw new \RuntimeException('paper_fake_state_symlink_forbidden');
        }
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new \RuntimeException('paper_fake_state_root_unavailable');
        }
        clearstatcache(true, $this->root);
        $permissions = fileperms($this->root);
        if ($permissions === false || ($permissions & 0077) !== 0) {
            throw new \RuntimeException('paper_fake_state_root_not_private');
        }
        $realRoot = realpath($this->root);
        if ($realRoot === false || !str_starts_with($realRoot, DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('paper_fake_state_root_unavailable');
        }

        return rtrim($realRoot, DIRECTORY_SEPARATOR);
    }

    private function serializableClock(): ClockInterface
    {
        return new class($this->clock) implements ClockInterface {
            public function __construct(private readonly ClockInterface $inner)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return \DateTimeImmutable::createFromInterface($this->inner->now());
            }
        };
    }
}
