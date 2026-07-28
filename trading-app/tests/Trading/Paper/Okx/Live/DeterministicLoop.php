<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class DeterministicLoop implements LoopInterface
{
    /** @var list<array{0: float, 1: callable}> */
    public array $timers = [];

    /** @var list<array{0: float, 1: callable}> */
    public array $periodicTimers = [];

    /** @var array<int, callable> */
    public array $signals = [];

    public bool $stopped = false;
    public int $runCount = 0;

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    /** @param int|float $interval */
    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new DeterministicTimer((float) $interval, $callback, false);
        $this->timers[] = [$timer->getInterval(), $timer->getCallback()];

        return $timer;
    }

    /** @param int|float $interval */
    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new DeterministicTimer((float) $interval, $callback, true);
        $this->periodicTimers[] = [$timer->getInterval(), $timer->getCallback()];

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $callback = $timer->getCallback();
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (array $entry): bool => $entry[1] !== $callback,
        ));
        $this->periodicTimers = array_values(array_filter(
            $this->periodicTimers,
            static fn (array $entry): bool => $entry[1] !== $callback,
        ));
    }

    public function futureTick($listener): void
    {
        $listener();
    }

    public function addSignal($signal, $listener): void
    {
        $this->signals[(int) $signal] = $listener;
    }

    public function removeSignal($signal, $listener): void
    {
        if (($this->signals[(int) $signal] ?? null) === $listener) {
            unset($this->signals[(int) $signal]);
        }
    }

    public function run(): void
    {
        ++$this->runCount;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function fireNextTimer(): float
    {
        $entry = array_shift($this->timers);
        if (null === $entry) {
            throw new \LogicException('timer_not_found');
        }

        [$delay, $callback] = $entry;
        $callback();

        return $delay;
    }

    /** @return list<float> */
    public function timerIntervals(): array
    {
        return array_column($this->timers, 0);
    }

    public function timerCallback(float $interval): callable
    {
        foreach ($this->timers as [$candidate, $callback]) {
            if ($candidate === $interval) {
                return $callback;
            }
        }

        throw new \LogicException('timer_not_found');
    }

    public function fireTimerInterval(float $interval): float
    {
        foreach ($this->timers as $index => [$candidate, $callback]) {
            if ($candidate === $interval) {
                array_splice($this->timers, $index, 1);
                $callback();

                return $candidate;
            }
        }

        throw new \LogicException('timer_not_found');
    }

    public function firePeriodic(int $index = 0): void
    {
        $entry = $this->periodicTimers[$index] ?? null;
        if (null === $entry) {
            throw new \LogicException('periodic_timer_not_found');
        }

        $entry[1]();
    }

    public function firePeriodicInterval(float $interval): void
    {
        foreach ($this->periodicTimers as [$candidate, $callback]) {
            if ($candidate === $interval) {
                $callback();

                return;
            }
        }

        throw new \LogicException('periodic_timer_not_found');
    }

    public function signal(int $signal): void
    {
        $listener = $this->signals[$signal] ?? null;
        if (null === $listener) {
            throw new \LogicException('signal_not_found');
        }

        $listener($signal);
    }
}

final readonly class DeterministicTimer implements TimerInterface
{
    /** @param callable $callback */
    public function __construct(
        private float $interval,
        private mixed $callback,
        private bool $periodic,
    ) {
    }

    public function getInterval(): float
    {
        return $this->interval;
    }

    public function getCallback(): callable
    {
        if (!is_callable($this->callback)) {
            throw new \LogicException('timer_callback_invalid');
        }

        return $this->callback;
    }

    public function isPeriodic(): bool
    {
        return $this->periodic;
    }
}
