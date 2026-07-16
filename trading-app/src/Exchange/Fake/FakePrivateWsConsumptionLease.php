<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final class FakePrivateWsConsumptionLease
{
    private bool $released = false;

    /**
     * @param resource|null $lockHandle
     */
    public function __construct(
        private mixed $lockHandle,
        private readonly \Closure $onRelease,
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        if (\is_resource($this->lockHandle)) {
            flock($this->lockHandle, \LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }

        ($this->onRelease)();
    }

    public function __destruct()
    {
        $this->release();
    }
}
