<?php

declare(strict_types=1);

namespace App\Trading\Listener;

use App\Provider\Context\ExchangeContext;
use App\Service\SymbolExecutionLockManager;
use App\Trading\Event\PositionClosedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: PositionClosedEvent::class)]
final class SymbolExecutionLockReleaseListener
{
    public function __construct(
        private readonly SymbolExecutionLockManager $symbolExecutionLockManager,
    ) {
    }

    public function __invoke(PositionClosedEvent $event): void
    {
        $marketType = $event->extra['market_type'] ?? $event->extra['marketType'] ?? null;
        $context = ExchangeContext::fromValues($event->exchange, $marketType);

        $this->symbolExecutionLockManager->releaseForSymbol(
            $event->positionHistory->symbol,
            $context,
            'position_closed',
            true,
            $event->positionHistory->closedAt,
        );
    }
}
