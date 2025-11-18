<?php

declare(strict_types=1);

namespace App\Trading\Listener;

use App\Trading\Event\PositionClosedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class PositionClosedLoggerListener
{
    public function __construct(
        private readonly LoggerInterface $tradingLogger,
    ) {}

    public function __invoke(PositionClosedEvent $event): void
    {
        $position = $event->positionHistory;
        $this->tradingLogger->info('[Trading] Position closed', [
            'symbol' => $position->symbol,
            'side' => $position->side->value,
            'entry_price' => $position->entryPrice->__toString(),
            'exit_price' => $position->exitPrice->__toString(),
            'size' => $position->size->__toString(),
            'realized_pnl' => $position->realizedPnl->__toString(),
            'fees' => $position->fees?->__toString(),
            'opened_at' => $position->openedAt->format('Y-m-d H:i:s'),
            'closed_at' => $position->closedAt->format('Y-m-d H:i:s'),
        ]);
    }
}


