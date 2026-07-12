<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Provider\Hyperliquid\HyperliquidReconciliationStatusInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class SymfonyLockHyperliquidExecutionLock implements HyperliquidExecutionLockInterface, HyperliquidReconciliationStatusInterface
{
    private const RESOURCE = 'hyperliquid.testnet.execution-reconciliation';
    private ?LockInterface $ownedLock = null;

    public function __construct(private readonly LockFactory $locks)
    {
    }

    public function acquire(): ?HyperliquidExecutionLockLeaseInterface
    {
        if ($this->ownedLock !== null) {
            return null;
        }

        $lock = $this->locks->createLock(self::RESOURCE, 300.0, false);
        if (!$lock->acquire(false)) {
            return null;
        }
        $this->ownedLock = $lock;

        return new SymfonyLockHyperliquidExecutionLockLease($lock, function (): void {
            $this->ownedLock = null;
        });
    }

    public function isInFlight(): bool
    {
        if ($this->ownedLock !== null) {
            return false;
        }

        try {
            $probe = $this->locks->createLock(self::RESOURCE, 300.0, false);
            if (!$probe->acquire(false)) {
                return true;
            }
            $probe->release();

            return false;
        } catch (\Throwable) {
            return true;
        }
    }
}

final class SymfonyLockHyperliquidExecutionLockLease implements HyperliquidExecutionLockLeaseInterface
{
    private bool $released = false;
    private bool $retained = false;

    public function __construct(
        private readonly LockInterface $lock,
        private readonly \Closure $onRelease,
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->lock->release();
        $this->released = true;
        ($this->onRelease)();
    }

    public function retain(): void
    {
        $this->retained = true;
    }

    public function __destruct()
    {
        if ($this->retained) {
            return;
        }
        try {
            $this->release();
        } catch (\Throwable) {
        }
    }
}
