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

    private function create(PaperExecutionCell $cell): PaperFakeRuntime
    {
        $root = $this->privateRoot();
        $digest = substr($cell->id, 7);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $digest)) {
            throw new \InvalidArgumentException('paper_fake_state_cell_digest_invalid');
        }
        $statePath = $root . '/' . $digest . '.dat';
        if (dirname($statePath) !== $root || basename($statePath) !== $digest . '.dat') {
            throw new \RuntimeException('paper_fake_state_path_mismatch');
        }
        if (is_link($statePath)) {
            throw new \RuntimeException('paper_fake_state_symlink_forbidden');
        }

        $cellSeed = $this->deterministicSeed->deriveHex(
            'paper-runtime.cell-seed.v1',
            ['cell_id' => $cell->id],
        );
        $state = new FakeExchangeStateStore($statePath, $cellSeed);
        $book = new FakeExchangeOrderBook($state);
        $clock = $this->serializableClock();
        $engine = new FakeExchangeMatchingEngine($state, $book, $clock);
        $adapter = new FakeExchangeAdapter($state, $book, $engine, $clock);

        return new PaperFakeRuntime($cell, $statePath, $state, $book, $engine, $adapter);
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
